<?php
/**
 * Backup orchestration.
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPBP_Backup_Manager {
	const DB_CHUNK_ROWS    = 500;
	const FILE_CHUNK_COUNT = 250;

	/**
	 * Create a full backup package.
	 *
	 * @param string $context Backup context.
	 * @param string $profile Backup profile.
	 * @return array|WP_Error
	 */
	public function create( $context = 'manual', $profile = 'full' ) {
		WPBP_Filesystem::ensure_storage();

		$work_dir = WPBP_Filesystem::create_work_dir( 'backup' );
		if ( is_wp_error( $work_dir ) ) {
			return $work_dir;
		}

		$context  = sanitize_key( $context );
		$profile  = $this->sanitize_profile( $profile );
		$includes = $this->profile_includes( $profile );
		$prefix   = 'pre-restore' === $context ? 'wp-backup-pilot-pre-restore' : 'wp-backup-pilot';
		$paths    = WPBP_Filesystem::paths();
		$filename = $prefix . '-' . gmdate( 'Ymd-His' ) . '.zip';
		$zip_path = trailingslashit( $paths['backups'] ) . $filename;

		try {
			wp_mkdir_p( trailingslashit( $work_dir ) . 'files/wp-content' );

			$db_info = array(
				'tables' => array(),
				'count'  => 0,
				'size'   => 0,
			);
			if ( ! empty( $includes['database'] ) ) {
				$database = new WPBP_Database();
				$db_info  = $database->export( trailingslashit( $work_dir ) . 'database.sql' );
				if ( is_wp_error( $db_info ) ) {
					WPBP_Filesystem::delete_tree( $work_dir );
					return $db_info;
				}
			}

			$file_info = ! empty( $includes['files'] ) ? $this->copy_site_files( trailingslashit( $work_dir ) . 'files/wp-content', $profile ) : array(
				'sections'  => array(),
				'files'     => 0,
				'bytes'     => 0,
				'checksums' => array(),
			);

			$manifest = $this->manifest( $db_info, $file_info, trailingslashit( $work_dir ) . 'database.sql', $context, $profile, $includes );
			file_put_contents( trailingslashit( $work_dir ) . 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

			$archive = new WPBP_Archive();
			$result  = $archive->create_zip( $work_dir, $zip_path );
			WPBP_Filesystem::delete_tree( $work_dir );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'filename' => $filename,
				'path'     => $zip_path,
				'size'     => filesize( $zip_path ),
				'manifest' => $manifest,
			);
		} catch ( Exception $exception ) {
			WPBP_Filesystem::delete_tree( $work_dir );
			return new WP_Error( 'wpbp_backup_failed', $exception->getMessage() );
		}
	}

	/**
	 * Prepare a resumable backup state.
	 *
	 * @param string $context Backup context.
	 * @param string $profile Backup profile.
	 * @return array|WP_Error
	 */
	public function prepare_chunked( $context = 'background', $profile = 'full' ) {
		WPBP_Filesystem::ensure_storage();

		$work_dir = WPBP_Filesystem::create_work_dir( 'chunked-backup' );
		if ( is_wp_error( $work_dir ) ) {
			return $work_dir;
		}

		$context   = sanitize_key( $context );
		$profile   = $this->sanitize_profile( $profile );
		$includes  = $this->profile_includes( $profile );
		$paths     = WPBP_Filesystem::paths();
		$filename  = 'wp-backup-pilot-' . gmdate( 'Ymd-His' ) . '.zip';
		$zip_path  = trailingslashit( $paths['backups'] ) . $filename;
		$sql_file  = trailingslashit( $work_dir ) . 'database.sql';
		$files_dir = trailingslashit( $work_dir ) . 'files/wp-content';
		wp_mkdir_p( $files_dir );

		$tables = array();
		if ( ! empty( $includes['database'] ) ) {
			$database = new WPBP_Database();
			$tables   = $database->get_tables();
			if ( empty( $tables ) ) {
				WPBP_Filesystem::delete_tree( $work_dir );
				return new WP_Error( 'wpbp_no_tables', __( 'No WordPress database tables were found to export.', 'wp-backup-pilot' ) );
			}

			$started = $database->start_export( $sql_file );
			if ( is_wp_error( $started ) ) {
				WPBP_Filesystem::delete_tree( $work_dir );
				return $started;
			}
		}

		$file_list      = ! empty( $includes['files'] ) ? $this->build_file_list( $profile ) : array();
		$file_list_path = trailingslashit( $work_dir ) . 'file-list.json';
		if ( false === file_put_contents( $file_list_path, wp_json_encode( $file_list ) ) ) {
			WPBP_Filesystem::delete_tree( $work_dir );
			return new WP_Error( 'wpbp_file_list_write_failed', __( 'Could not write the backup file list.', 'wp-backup-pilot' ) );
		}

		return array(
			'context'        => $context,
			'profile'        => $profile,
			'includes'       => $includes,
			'phase'          => ! empty( $includes['database'] ) ? 'database' : 'files',
			'work_dir'       => $work_dir,
			'filename'       => $filename,
			'zip_path'       => $zip_path,
			'sql_file'       => $sql_file,
			'files_dir'      => $files_dir,
			'file_list'      => $file_list_path,
			'tables'         => $tables,
			'table_index'    => 0,
			'table_offset'   => 0,
			'rows_exported'  => 0,
			'file_index'     => 0,
			'files_total'    => count( $file_list ),
			'files_copied'   => 0,
			'bytes_copied'   => 0,
			'file_checksums' => array(),
			'started_at'     => time(),
		);
	}

	/**
	 * Process one chunk of a resumable backup.
	 *
	 * @param array $state Backup state.
	 * @return array|WP_Error
	 */
	public function process_chunk( array $state ) {
		if ( empty( $state['phase'] ) || empty( $state['work_dir'] ) ) {
			return new WP_Error( 'wpbp_bad_state', __( 'Backup job state is incomplete.', 'wp-backup-pilot' ) );
		}

		switch ( $state['phase'] ) {
			case 'database':
				return $this->process_database_chunk( $state );
			case 'files':
				return $this->process_file_chunk( $state );
			case 'manifest':
				return $this->process_manifest_chunk( $state );
			case 'archive':
				return $this->process_archive_chunk( $state );
		}

		return new WP_Error( 'wpbp_bad_phase', __( 'Backup job phase is invalid.', 'wp-backup-pilot' ) );
	}

	/**
	 * Get progress details for a backup state.
	 *
	 * @param array $state Backup state.
	 * @return array
	 */
	public function progress_from_state( array $state ) {
		$phase       = isset( $state['phase'] ) ? $state['phase'] : 'queued';
		$table_count = ! empty( $state['tables'] ) ? count( $state['tables'] ) : 1;
		$table_index = isset( $state['table_index'] ) ? (int) $state['table_index'] : 0;
		$file_total  = isset( $state['files_total'] ) ? max( 1, (int) $state['files_total'] ) : 1;
		$file_index  = isset( $state['file_index'] ) ? (int) $state['file_index'] : 0;
		$zip_total   = isset( $state['zip_total'] ) ? max( 1, (int) $state['zip_total'] ) : 1;
		$zip_index   = isset( $state['zip_index'] ) ? (int) $state['zip_index'] : 0;

		if ( 'database' === $phase ) {
			$percent = min( 45, (int) floor( ( $table_index / max( 1, $table_count ) ) * 45 ) );
		} elseif ( 'files' === $phase ) {
			$percent = 45 + min( 35, (int) floor( ( $file_index / $file_total ) * 35 ) );
		} elseif ( 'manifest' === $phase ) {
			$percent = 85;
		} elseif ( 'archive' === $phase ) {
			$percent = 85 + min( 14, (int) floor( ( $zip_index / $zip_total ) * 14 ) );
		} elseif ( 'complete' === $phase ) {
			$percent = 100;
		} else {
			$percent = 0;
		}

		return array(
			'phase'   => $phase,
			'percent' => $percent,
			'label'   => $this->phase_label( $phase ),
		);
	}

	/**
	 * Available backup profiles.
	 *
	 * @return array
	 */
	public function profiles() {
		return array(
			'full'     => __( 'Full Site', 'wp-backup-pilot' ),
			'database' => __( 'Database Only', 'wp-backup-pilot' ),
			'uploads'  => __( 'Uploads Only', 'wp-backup-pilot' ),
			'files'    => __( 'Files Only', 'wp-backup-pilot' ),
		);
	}

	/**
	 * List local backup packages.
	 *
	 * @return array
	 */
	public function list_backups() {
		WPBP_Filesystem::ensure_storage();
		$paths = WPBP_Filesystem::paths();
		$files = glob( trailingslashit( $paths['backups'] ) . '*.zip' );

		if ( empty( $files ) ) {
			return array();
		}

		rsort( $files );
		$archive = new WPBP_Archive();
		$items   = array();

		foreach ( $files as $file ) {
			$inspect = $archive->inspect( $file );
			$items[] = array(
				'filename' => basename( $file ),
				'path'     => $file,
				'size'     => filesize( $file ),
				'created'  => filemtime( $file ),
				'valid'    => ! is_wp_error( $inspect ),
				'manifest' => is_wp_error( $inspect ) ? array() : $inspect['manifest'],
				'error'    => is_wp_error( $inspect ) ? $inspect->get_error_message() : '',
			);
		}

		return $items;
	}

	/**
	 * Resolve a local backup by filename.
	 *
	 * @param string $filename Backup filename.
	 * @return string|WP_Error
	 */
	public function resolve_backup( $filename ) {
		$filename = basename( sanitize_file_name( $filename ) );
		$paths    = WPBP_Filesystem::paths();
		$path     = trailingslashit( $paths['backups'] ) . $filename;

		if ( ! preg_match( '/\.zip$/i', $filename ) || ! is_readable( $path ) ) {
			return new WP_Error( 'wpbp_backup_not_found', __( 'Backup package not found.', 'wp-backup-pilot' ) );
		}

		return $path;
	}

	/**
	 * Delete a local backup package.
	 *
	 * @param string $filename Backup filename.
	 * @return true|WP_Error
	 */
	public function delete( $filename ) {
		$path = $this->resolve_backup( $filename );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		return @unlink( $path ) ? true : new WP_Error( 'wpbp_delete_failed', __( 'Could not delete the backup package.', 'wp-backup-pilot' ) );
	}

	/**
	 * Save an uploaded package into local backup storage.
	 *
	 * @param array $file Uploaded file array.
	 * @return array|WP_Error
	 */
	public function import_upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'wpbp_upload_missing', __( 'No uploaded backup package was received.', 'wp-backup-pilot' ) );
		}

		WPBP_Filesystem::ensure_storage();
		$paths    = WPBP_Filesystem::paths();
		$name     = sanitize_file_name( $file['name'] );
		$filename = 'imported-' . gmdate( 'Ymd-His' ) . '-' . $name;
		$target   = trailingslashit( $paths['backups'] ) . $filename;

		if ( ! preg_match( '/\.zip$/i', $name ) ) {
			return new WP_Error( 'wpbp_upload_type', __( 'Please upload a ZIP backup package.', 'wp-backup-pilot' ) );
		}

		if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) {
			return new WP_Error( 'wpbp_upload_move_failed', __( 'Could not store the uploaded package.', 'wp-backup-pilot' ) );
		}

		$archive = new WPBP_Archive();
		$inspect = $archive->inspect( $target );
		if ( is_wp_error( $inspect ) ) {
			@unlink( $target );
			return $inspect;
		}

		return array(
			'filename' => $filename,
			'path'     => $target,
			'manifest' => $inspect['manifest'],
		);
	}

	/**
	 * Copy wp-content paths into the package working tree.
	 *
	 * @param string $destination Destination wp-content directory.
	 * @return array
	 */
	private function copy_site_files( $destination, $profile = 'full' ) {
		$paths      = WPBP_Filesystem::paths();
		$exclude    = array(
			$paths['base'],
			WPBP_PLUGIN_DIR,
			trailingslashit( WP_CONTENT_DIR ) . 'cache',
			trailingslashit( WP_CONTENT_DIR ) . 'upgrade',
		);
		$sections   = $this->profile_sections( $profile );
		$copied     = array();
		$file_count = 0;
		$bytes      = 0;

		foreach ( $sections as $section ) {
			$source = trailingslashit( WP_CONTENT_DIR ) . $section;
			if ( ! is_dir( $source ) ) {
				continue;
			}

			$target = trailingslashit( $destination ) . $section;
			WPBP_Filesystem::copy_tree( $source, $target, $exclude );
			$copied[] = $section;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( dirname( $destination ), FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				++$file_count;
				$bytes += $item->getSize();
			}
		}

		return array(
			'sections'  => $copied,
			'files'     => $file_count,
			'bytes'     => $bytes,
			'checksums' => $this->file_checksums( trailingslashit( dirname( $destination ) ) ),
		);
	}

	/**
	 * Process one database export batch.
	 *
	 * @param array $state Backup state.
	 * @return array|WP_Error
	 */
	private function process_database_chunk( array $state ) {
		$tables = isset( $state['tables'] ) ? (array) $state['tables'] : array();
		$index  = isset( $state['table_index'] ) ? (int) $state['table_index'] : 0;

		if ( $index >= count( $tables ) ) {
			$database = new WPBP_Database();
			$finish   = $database->finish_export( $state['sql_file'] );
			if ( is_wp_error( $finish ) ) {
				return $finish;
			}

			$state['phase'] = ! empty( $state['includes']['files'] ) ? 'files' : 'manifest';
			return $state;
		}

		$database = new WPBP_Database();
		$result   = $database->export_table_chunk( $state['sql_file'], $tables[ $index ], (int) $state['table_offset'], self::DB_CHUNK_ROWS );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$state['rows_exported'] = (int) $state['rows_exported'] + (int) $result['rows'];
		$state['table_offset']  = (int) $result['next_offset'];

		if ( ! empty( $result['done'] ) ) {
			++$state['table_index'];
			$state['table_offset'] = 0;
		}

		return $state;
	}

	/**
	 * Process one file copy batch.
	 *
	 * @param array $state Backup state.
	 * @return array|WP_Error
	 */
	private function process_file_chunk( array $state ) {
		if ( empty( $state['file_list'] ) || ! is_readable( $state['file_list'] ) ) {
			return new WP_Error( 'wpbp_file_list_missing', __( 'Backup file list could not be read.', 'wp-backup-pilot' ) );
		}

		$list = json_decode( file_get_contents( $state['file_list'] ), true );
		if ( ! is_array( $list ) ) {
			return new WP_Error( 'wpbp_file_list_missing', __( 'Backup file list could not be read.', 'wp-backup-pilot' ) );
		}

		$index = isset( $state['file_index'] ) ? (int) $state['file_index'] : 0;
		$end   = min( count( $list ), $index + self::FILE_CHUNK_COUNT );

		for ( $i = $index; $i < $end; $i++ ) {
			$item = $list[ $i ];
			if ( empty( $item['source'] ) || empty( $item['relative'] ) || ! is_readable( $item['source'] ) ) {
				continue;
			}

			$target = trailingslashit( $state['files_dir'] ) . $item['relative'];
			wp_mkdir_p( dirname( $target ) );
			if ( copy( $item['source'], $target ) ) {
				++$state['files_copied'];
				$state['bytes_copied'] += filesize( $item['source'] );
				$state['file_checksums'][ 'files/wp-content/' . $item['relative'] ] = hash_file( 'sha256', $target );
			}
		}

		$state['file_index'] = $end;
		if ( $end >= count( $list ) ) {
			$state['phase'] = 'manifest';
		}

		return $state;
	}

	/**
	 * Write the manifest.
	 *
	 * @param array $state Backup state.
	 * @return array|WP_Error
	 */
	private function process_manifest_chunk( array $state ) {
		$db_info   = array(
			'tables' => isset( $state['tables'] ) ? $state['tables'] : array(),
			'count'  => ! empty( $state['tables'] ) ? count( $state['tables'] ) : 0,
			'rows'   => isset( $state['rows_exported'] ) ? (int) $state['rows_exported'] : 0,
			'size'   => ! empty( $state['includes']['database'] ) && is_readable( $state['sql_file'] ) ? filesize( $state['sql_file'] ) : 0,
		);
		$file_info = array(
			'sections'  => $this->profile_sections( isset( $state['profile'] ) ? $state['profile'] : 'full' ),
			'files'     => isset( $state['files_copied'] ) ? (int) $state['files_copied'] : 0,
			'bytes'     => isset( $state['bytes_copied'] ) ? (int) $state['bytes_copied'] : 0,
			'checksums' => isset( $state['file_checksums'] ) ? $state['file_checksums'] : array(),
		);

		$manifest = $this->manifest( $db_info, $file_info, $state['sql_file'], $state['context'], $state['profile'], $state['includes'] );
		$written  = file_put_contents( trailingslashit( $state['work_dir'] ) . 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
		if ( false === $written ) {
			return new WP_Error( 'wpbp_manifest_write_failed', __( 'Could not write the backup manifest.', 'wp-backup-pilot' ) );
		}

		$state['manifest'] = $manifest;
		$state['phase']    = 'archive';
		return $state;
	}

	/**
	 * Create final archive and clean up.
	 *
	 * @param array $state Backup state.
	 * @return array|WP_Error
	 */
	private function process_archive_chunk( array $state ) {
		$archive = new WPBP_Archive();

		if ( empty( $state['zip_file_list'] ) ) {
			if ( ! empty( $state['file_list'] ) && file_exists( $state['file_list'] ) ) {
				@unlink( $state['file_list'] );
			}

			$zip_files = $archive->build_zip_file_list( $state['work_dir'] );
			$zip_list  = trailingslashit( $state['work_dir'] ) . 'zip-list.json';
			if ( false === file_put_contents( $zip_list, wp_json_encode( $zip_files ) ) ) {
				return new WP_Error( 'wpbp_zip_list_write_failed', __( 'Could not write archive file list.', 'wp-backup-pilot' ) );
			}

			$state['zip_file_list'] = $zip_list;
			$state['zip_index']     = 0;
			$state['zip_total']     = count( $zip_files );
		}

		$zip_files = json_decode( file_get_contents( $state['zip_file_list'] ), true );
		if ( ! is_array( $zip_files ) ) {
			return new WP_Error( 'wpbp_zip_list_missing', __( 'Archive file list could not be read.', 'wp-backup-pilot' ) );
		}

		$result = $archive->add_zip_chunk( $state['zip_path'], $zip_files, (int) $state['zip_index'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$state['zip_index'] = (int) $result['next_offset'];
		if ( empty( $result['done'] ) ) {
			return $state;
		}

		WPBP_Filesystem::delete_tree( $state['work_dir'] );
		$state['phase']  = 'complete';
		$state['result'] = array(
			'filename' => $state['filename'],
			'path'     => $state['zip_path'],
			'size'     => filesize( $state['zip_path'] ),
			'manifest' => isset( $state['manifest'] ) ? $state['manifest'] : array(),
		);

		return $state;
	}

	/**
	 * Build a list of files to copy in chunks.
	 *
	 * @return array
	 */
	private function build_file_list( $profile = 'full' ) {
		$paths    = WPBP_Filesystem::paths();
		$exclude  = array(
			$paths['base'],
			WPBP_PLUGIN_DIR,
			trailingslashit( WP_CONTENT_DIR ) . 'cache',
			trailingslashit( WP_CONTENT_DIR ) . 'upgrade',
		);
		$sections = $this->profile_sections( $profile );
		$files    = array();

		foreach ( $sections as $section ) {
			$source = trailingslashit( WP_CONTENT_DIR ) . $section;
			if ( ! is_dir( $source ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $item ) {
				$path = $item->getPathname();
				if ( ! $item->isFile() || WPBP_Filesystem::is_excluded( $path, $exclude ) ) {
					continue;
				}

				$relative = ltrim( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( $path ) ), '/' );
				$files[]  = array(
					'source'   => $path,
					'relative' => $relative,
				);
			}
		}

		return $files;
	}

	/**
	 * Build checksums for files under a package files directory.
	 *
	 * @param string $files_root Files root.
	 * @return array
	 */
	private function file_checksums( $files_root ) {
		if ( ! is_dir( $files_root ) ) {
			return array();
		}

		$checksums = array();
		$base      = trailingslashit( wp_normalize_path( $files_root ) );
		$iterator  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $files_root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$path = wp_normalize_path( $item->getPathname() );
			$checksums[ 'files/' . ltrim( str_replace( $base, '', $path ), '/' ) ] = hash_file( 'sha256', $item->getPathname() );
		}

		return $checksums;
	}

	/**
	 * Get a readable phase label.
	 *
	 * @param string $phase Phase key.
	 * @return string
	 */
	private function phase_label( $phase ) {
		$labels = array(
			'queued'   => __( 'Queued', 'wp-backup-pilot' ),
			'database' => __( 'Exporting database', 'wp-backup-pilot' ),
			'files'    => __( 'Copying files', 'wp-backup-pilot' ),
			'manifest' => __( 'Writing manifest', 'wp-backup-pilot' ),
			'archive'  => __( 'Creating archive', 'wp-backup-pilot' ),
			'complete' => __( 'Complete', 'wp-backup-pilot' ),
		);

		return isset( $labels[ $phase ] ) ? $labels[ $phase ] : $phase;
	}

	/**
	 * Sanitize profile key.
	 *
	 * @param string $profile Profile.
	 * @return string
	 */
	private function sanitize_profile( $profile ) {
		$profile = sanitize_key( $profile );
		return array_key_exists( $profile, $this->profiles() ) ? $profile : 'full';
	}

	/**
	 * Get profile include flags.
	 *
	 * @param string $profile Profile.
	 * @return array
	 */
	private function profile_includes( $profile ) {
		$profile = $this->sanitize_profile( $profile );

		return array(
			'database' => in_array( $profile, array( 'full', 'database' ), true ),
			'files'    => in_array( $profile, array( 'full', 'uploads', 'files' ), true ),
		);
	}

	/**
	 * Get wp-content sections for a profile.
	 *
	 * @param string $profile Profile.
	 * @return array
	 */
	private function profile_sections( $profile ) {
		$profile = $this->sanitize_profile( $profile );
		if ( 'uploads' === $profile ) {
			return array( 'uploads' );
		}

		if ( 'files' === $profile || 'full' === $profile ) {
			return array( 'uploads', 'themes', 'plugins' );
		}

		return array();
	}

	/**
	 * Build manifest data.
	 *
	 * @param array  $db_info Database info.
	 * @param array  $file_info File info.
	 * @param string $database_file Database file path.
	 * @param string $context Backup context.
	 * @param string $profile Backup profile.
	 * @param array  $includes Include flags.
	 * @return array
	 */
	private function manifest( array $db_info, array $file_info, $database_file, $context, $profile, array $includes ) {
		global $wpdb, $wp_version;

		return array(
			'plugin'       => 'WP Backup Pilot',
			'plugin_slug'  => 'wp-backup-pilot',
			'version'      => WPBP_VERSION,
			'context'      => $context,
			'profile'      => $profile,
			'includes'     => $includes,
			'created_at'   => gmdate( 'c' ),
			'home_url'     => home_url(),
			'site_url'     => site_url(),
			'wp_version'   => $wp_version,
			'php_version'  => PHP_VERSION,
			'table_prefix' => $wpdb->prefix,
			'database'     => $db_info,
			'files'        => $file_info,
			'checksums'    => array(
				'database_sql' => ! empty( $includes['database'] ) && is_readable( $database_file ) ? hash_file( 'sha256', $database_file ) : '',
				'files'        => isset( $file_info['checksums'] ) ? $file_info['checksums'] : array(),
			),
		);
	}
}
