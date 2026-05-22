<?php
/**
 * Archive builder and validator.
 *
 * @package BackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPILOT_Archive {
	const ZIP_CHUNK_COUNT     = 250;
	const EXTRACT_CHUNK_COUNT = 250;

	/**
	 * Create a package ZIP from a working directory.
	 *
	 * @param string $source_dir Source directory.
	 * @param string $zip_path Destination ZIP.
	 * @return array|WP_Error
	 */
	public function create_zip( $source_dir, $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'bpilot_zip_missing', __( 'The PHP ZipArchive extension is required.', 'backup-pilot' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'bpilot_zip_open_failed', __( 'Could not create the backup ZIP file.', 'backup-pilot' ) );
		}
		$this->set_password( $zip );

		$counts = array(
			'files' => 0,
			'bytes' => 0,
		);

		$this->add_directory_to_zip( $zip, $source_dir, '', $counts );
		$zip->close();

		return $counts;
	}

	/**
	 * Validate and read package metadata.
	 *
	 * @param string $zip_path ZIP path.
	 * @return array|WP_Error
	 */
	public function inspect( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'bpilot_zip_missing', __( 'The PHP ZipArchive extension is required.', 'backup-pilot' ) );
		}

		if ( ! is_readable( $zip_path ) ) {
			return new WP_Error( 'bpilot_zip_unreadable', __( 'The backup package is not readable.', 'backup-pilot' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'bpilot_zip_invalid', __( 'The backup package could not be opened.', 'backup-pilot' ) );
		}
		$this->set_password( $zip );

		if ( false === $zip->locateName( 'manifest.json' ) ) {
			$zip->close();
			return new WP_Error( 'bpilot_zip_missing_entry', __( 'The package is missing manifest.json.', 'backup-pilot' ) );
		}

		$manifest_raw = $zip->getFromName( 'manifest.json' );
		$manifest     = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) ) {
			$zip->close();
			return new WP_Error( 'bpilot_manifest_invalid', __( 'The package manifest is invalid.', 'backup-pilot' ) );
		}

		$includes = isset( $manifest['includes'] ) && is_array( $manifest['includes'] ) ? $manifest['includes'] : array(
			'database' => true,
			'files'    => true,
		);
		if ( ! empty( $includes['database'] ) && false === $zip->locateName( 'database.sql' ) ) {
			$zip->close();
			return new WP_Error( 'bpilot_zip_missing_entry', __( 'The package is missing database.sql.', 'backup-pilot' ) );
		}

		$has_files = false;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( 0 === strpos( $name, 'files/' ) ) {
				$has_files = true;
				break;
			}
		}

		if ( ! empty( $includes['files'] ) && ! $has_files ) {
			$zip->close();
			return new WP_Error( 'bpilot_zip_missing_files', __( 'The package does not contain a files directory.', 'backup-pilot' ) );
		}

		$zip->close();

		return array(
			'manifest' => $manifest,
			'size'     => filesize( $zip_path ),
			'path'     => $zip_path,
		);
	}

	/**
	 * Build archive entry list for a directory.
	 *
	 * @param string $source_dir Source directory.
	 * @return array
	 */
	public function build_zip_file_list( $source_dir ) {
		$files    = array();
		$base     = trailingslashit( wp_normalize_path( $source_dir ) );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$path    = wp_normalize_path( $item->getPathname() );
			$files[] = array(
				'source' => $item->getPathname(),
				'local'  => ltrim( str_replace( $base, '', $path ), '/' ),
				'size'   => $item->getSize(),
			);
		}

		return $files;
	}

	/**
	 * Add one file batch to a ZIP.
	 *
	 * @param string $zip_path ZIP path.
	 * @param array  $files File list.
	 * @param int    $offset Start offset.
	 * @param int    $limit Batch size.
	 * @return array|WP_Error
	 */
	public function add_zip_chunk( $zip_path, array $files, $offset, $limit = self::ZIP_CHUNK_COUNT ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'bpilot_zip_missing', __( 'The PHP ZipArchive extension is required.', 'backup-pilot' ) );
		}

		$zip  = new ZipArchive();
		$mode = file_exists( $zip_path ) ? 0 : ZipArchive::CREATE;
		if ( true !== $zip->open( $zip_path, $mode ) ) {
			return new WP_Error( 'bpilot_zip_open_failed', __( 'Could not open the backup ZIP file.', 'backup-pilot' ) );
		}
		$this->set_password( $zip );

		$end   = min( count( $files ), $offset + $limit );
		$count = 0;
		$bytes = 0;
		for ( $i = $offset; $i < $end; $i++ ) {
			if ( empty( $files[ $i ]['source'] ) || empty( $files[ $i ]['local'] ) || ! is_readable( $files[ $i ]['source'] ) ) {
				continue;
			}

			if ( $zip->addFile( $files[ $i ]['source'], $files[ $i ]['local'] ) ) {
				$this->encrypt_name( $zip, $files[ $i ]['local'] );
				++$count;
				$bytes += isset( $files[ $i ]['size'] ) ? (int) $files[ $i ]['size'] : filesize( $files[ $i ]['source'] );
			}
		}

		$zip->close();

		return array(
			'files'       => $count,
			'bytes'       => $bytes,
			'next_offset' => $end,
			'done'        => $end >= count( $files ),
		);
	}

	/**
	 * Extract one package batch.
	 *
	 * @param string $zip_path ZIP path.
	 * @param string $destination Destination directory.
	 * @param int    $offset Start offset.
	 * @param int    $limit Batch size.
	 * @return array|WP_Error
	 */
	public function extract_chunk( $zip_path, $destination, $offset, $limit = self::EXTRACT_CHUNK_COUNT ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'bpilot_zip_open_failed', __( 'Could not open the backup package.', 'backup-pilot' ) );
		}
		$this->set_password( $zip );

		$end   = min( $zip->numFiles, $offset + $limit );
		$names = array();
		for ( $i = $offset; $i < $end; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false !== strpos( $name, '..' ) || 0 === strpos( $name, '/' ) || preg_match( '#^[A-Za-z]:#', $name ) ) {
				$zip->close();
				return new WP_Error( 'bpilot_zip_unsafe_path', __( 'The package contains an unsafe file path.', 'backup-pilot' ) );
			}
			$names[] = $name;
		}

		if ( ! empty( $names ) && ! $zip->extractTo( $destination, $names ) ) {
			$zip->close();
			return new WP_Error( 'bpilot_zip_extract_failed', __( 'Could not extract the backup package.', 'backup-pilot' ) );
		}

		$total = $zip->numFiles;
		$zip->close();

		return array(
			'entries'     => count( $names ),
			'next_offset' => $end,
			'total'       => $total,
			'done'        => $end >= $total,
		);
	}

	/**
	 * Extract a package.
	 *
	 * @param string $zip_path ZIP path.
	 * @param string $destination Destination directory.
	 * @return true|WP_Error
	 */
	public function extract( $zip_path, $destination ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'bpilot_zip_open_failed', __( 'Could not open the backup package.', 'backup-pilot' ) );
		}
		$this->set_password( $zip );

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false !== strpos( $name, '..' ) || 0 === strpos( $name, '/' ) || preg_match( '#^[A-Za-z]:#', $name ) ) {
				$zip->close();
				return new WP_Error( 'bpilot_zip_unsafe_path', __( 'The package contains an unsafe file path.', 'backup-pilot' ) );
			}
		}

		if ( ! $zip->extractTo( $destination ) ) {
			$zip->close();
			return new WP_Error( 'bpilot_zip_extract_failed', __( 'Could not extract the backup package.', 'backup-pilot' ) );
		}

		$zip->close();

		return true;
	}

	/**
	 * Add files recursively to ZIP.
	 *
	 * @param ZipArchive $zip ZIP object.
	 * @param string     $directory Directory.
	 * @param string     $prefix ZIP prefix.
	 * @param array      $counts Counters.
	 * @return void
	 */
	private function add_directory_to_zip( ZipArchive $zip, $directory, $prefix, array &$counts ) {
		$items = scandir( $directory );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path  = trailingslashit( $directory ) . $item;
			$local = ltrim( $prefix . '/' . $item, '/' );

			if ( is_dir( $path ) ) {
				$zip->addEmptyDir( $local );
				$this->add_directory_to_zip( $zip, $path, $local, $counts );
				continue;
			}

			if ( is_readable( $path ) && $zip->addFile( $path, $local ) ) {
				$this->encrypt_name( $zip, $local );
				++$counts['files'];
				$counts['bytes'] += filesize( $path );
			}
		}
	}

	/**
	 * Apply configured ZIP password when available.
	 *
	 * @param ZipArchive $zip ZIP object.
	 * @return void
	 */
	private function set_password( ZipArchive $zip ) {
		$settings = class_exists( 'BPILOT_Settings' ) ? BPILOT_Settings::get() : array();
		if ( ! empty( $settings['encryption_password'] ) && method_exists( $zip, 'setPassword' ) ) {
			$zip->setPassword( $settings['encryption_password'] );
		}
	}

	/**
	 * Encrypt one ZIP entry when supported.
	 *
	 * @param ZipArchive $zip ZIP object.
	 * @param string     $name Entry name.
	 * @return void
	 */
	private function encrypt_name( ZipArchive $zip, $name ) {
		$settings = class_exists( 'BPILOT_Settings' ) ? BPILOT_Settings::get() : array();
		if ( empty( $settings['encryption_password'] ) || ! method_exists( $zip, 'setEncryptionName' ) ) {
			return;
		}

		$method = defined( 'ZipArchive::EM_AES_256' ) ? ZipArchive::EM_AES_256 : ZipArchive::EM_TRAD_PKWARE;
		$zip->setEncryptionName( $name, $method );
	}
}
