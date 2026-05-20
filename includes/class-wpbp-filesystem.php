<?php
/**
 * Filesystem helpers.
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPBP_Filesystem {
	const STORAGE_DIR = 'backup-pilot';

	/**
	 * Initialize the WordPress filesystem API.
	 *
	 * @return WP_Filesystem_Base|false
	 */
	public static function wp_filesystem() {
		global $wp_filesystem;

		if ( ! empty( $wp_filesystem ) ) {
			return $wp_filesystem;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! WP_Filesystem() ) {
			return false;
		}

		return $wp_filesystem;
	}

	/**
	 * Check whether a directory is writable.
	 *
	 * @param string $directory Directory path.
	 * @return bool
	 */
	public static function is_directory_writable( $directory ) {
		$wp_filesystem = self::wp_filesystem();
		if ( $wp_filesystem ) {
			return $wp_filesystem->is_writable( $directory );
		}

		return wp_is_writable( $directory );
	}

	/**
	 * Delete a file using WordPress APIs.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function delete_file( $path ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return true;
		}

		wp_delete_file( $path );

		return ! file_exists( $path );
	}

	/**
	 * Get upload based paths.
	 *
	 * @return array
	 */
	public static function paths() {
		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( $uploads['basedir'] ) . self::STORAGE_DIR;

		return array(
			'base'    => $base,
			'backups' => trailingslashit( $base ) . 'backups',
			'temp'    => trailingslashit( $base ) . 'temp',
			'imports' => trailingslashit( $base ) . 'imports',
		);
	}

	/**
	 * Ensure storage directories and basic access controls exist.
	 *
	 * @return void
	 */
	public static function ensure_storage() {
		$paths = self::paths();

		foreach ( $paths as $path ) {
			if ( ! is_dir( $path ) ) {
				wp_mkdir_p( $path );
			}

			self::write_protection_files( $path );
		}
	}

	/**
	 * Write basic protection files.
	 *
	 * @param string $directory Directory path.
	 * @return void
	 */
	public static function write_protection_files( $directory ) {
		if ( ! is_dir( $directory ) || ! self::is_directory_writable( $directory ) ) {
			return;
		}

		$index = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	/**
	 * Create a unique working directory.
	 *
	 * @param string $type Work type.
	 * @return string|WP_Error
	 */
	public static function create_work_dir( $type ) {
		self::ensure_storage();
		$paths = self::paths();
		$base  = trailingslashit( $paths['temp'] ) . sanitize_key( $type ) . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );

		if ( wp_mkdir_p( $base ) ) {
			self::write_protection_files( $base );
			return $base;
		}

		return new WP_Error( 'wpbp_work_dir_failed', __( 'Could not create a temporary working directory.', 'backup-pilot' ) );
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $path Path to delete.
	 * @return void
	 */
	public static function delete_tree( $path ) {
		if ( ! file_exists( $path ) ) {
			return;
		}

		$wp_filesystem = self::wp_filesystem();
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $path, true, 'd' );
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			self::delete_file( $path );
			return;
		}

		$items = scandir( $path );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			self::delete_tree( trailingslashit( $path ) . $item );
		}

		$wp_filesystem = self::wp_filesystem();
		if ( $wp_filesystem ) {
			$wp_filesystem->rmdir( $path );
		}
	}

	/**
	 * Copy a directory recursively.
	 *
	 * @param string $source Source directory.
	 * @param string $destination Destination directory.
	 * @param array  $exclude Absolute paths to exclude.
	 * @return bool
	 */
	public static function copy_tree( $source, $destination, array $exclude = array() ) {
		$source = wp_normalize_path( $source );

		if ( self::is_excluded( $source, $exclude ) ) {
			return true;
		}

		if ( is_file( $source ) ) {
			wp_mkdir_p( dirname( $destination ) );
			return copy( $source, $destination );
		}

		if ( ! is_dir( $source ) ) {
			return true;
		}

		wp_mkdir_p( $destination );
		$items = scandir( $source );
		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$from = trailingslashit( $source ) . $item;
			$to   = trailingslashit( $destination ) . $item;

			if ( ! self::copy_tree( $from, $to, $exclude ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether a path should be excluded.
	 *
	 * @param string $path Absolute path.
	 * @param array  $exclude Excluded absolute paths.
	 * @return bool
	 */
	public static function is_excluded( $path, array $exclude ) {
		$path = wp_normalize_path( $path );

		foreach ( $exclude as $excluded ) {
			$excluded = untrailingslashit( wp_normalize_path( $excluded ) );
			if ( $path === $excluded || 0 === strpos( $path, trailingslashit( $excluded ) ) ) {
				return true;
			}
		}

		$basename = basename( $path );
		if ( in_array( $basename, array( 'cache', '.cache', 'tmp', 'temp', 'logs', 'backup', 'backups' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Format bytes for admin display.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		if ( function_exists( 'size_format' ) ) {
			return size_format( $bytes, 2 );
		}

		return number_format_i18n( $bytes ) . ' B';
	}
}
