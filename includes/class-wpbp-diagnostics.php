<?php
/**
 * Environment diagnostics.
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPBP_Diagnostics {
	/**
	 * Get environment checks.
	 *
	 * @return array
	 */
	public static function checks() {
		$paths = WPBP_Filesystem::paths();
		$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false;

		return array(
			array(
				'label'  => __( 'ZipArchive extension', 'backup-pilot-main' ),
				'status' => class_exists( 'ZipArchive' ) ? 'ok' : 'error',
				'detail' => class_exists( 'ZipArchive' ) ? __( 'Available', 'backup-pilot-main' ) : __( 'Missing. Backups cannot be packaged without ZipArchive.', 'backup-pilot-main' ),
			),
			array(
				'label'  => __( 'Storage writable', 'backup-pilot-main' ),
				'status' => WPBP_Filesystem::is_directory_writable( $paths['base'] ) || wp_mkdir_p( $paths['base'] ) ? 'ok' : 'error',
				'detail' => $paths['base'],
			),
			array(
				'label'  => __( 'Free disk space', 'backup-pilot-main' ),
				'status' => false === $free || $free > 256 * MB_IN_BYTES ? 'ok' : 'warning',
				'detail' => false === $free ? __( 'Unable to detect.', 'backup-pilot-main' ) : size_format( $free, 2 ),
			),
			array(
				'label'  => __( 'Multisite', 'backup-pilot-main' ),
				'status' => is_multisite() ? 'warning' : 'ok',
				'detail' => is_multisite() ? __( 'Multisite is detected. Current support is experimental.', 'backup-pilot-main' ) : __( 'Single site install.', 'backup-pilot-main' ),
			),
			array(
				'label'  => __( 'PHP memory limit', 'backup-pilot-main' ),
				'status' => 'ok',
				'detail' => ini_get( 'memory_limit' ),
			),
		);
	}
}
