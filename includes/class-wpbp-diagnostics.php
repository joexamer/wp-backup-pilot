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
				'label'  => __( 'ZipArchive extension', 'wp-backup-pilot' ),
				'status' => class_exists( 'ZipArchive' ) ? 'ok' : 'error',
				'detail' => class_exists( 'ZipArchive' ) ? __( 'Available', 'wp-backup-pilot' ) : __( 'Missing. Backups cannot be packaged without ZipArchive.', 'wp-backup-pilot' ),
			),
			array(
				'label'  => __( 'Storage writable', 'wp-backup-pilot' ),
				'status' => is_writable( $paths['base'] ) || wp_mkdir_p( $paths['base'] ) ? 'ok' : 'error',
				'detail' => $paths['base'],
			),
			array(
				'label'  => __( 'Free disk space', 'wp-backup-pilot' ),
				'status' => false === $free || $free > 256 * MB_IN_BYTES ? 'ok' : 'warning',
				'detail' => false === $free ? __( 'Unable to detect.', 'wp-backup-pilot' ) : size_format( $free, 2 ),
			),
			array(
				'label'  => __( 'Multisite', 'wp-backup-pilot' ),
				'status' => is_multisite() ? 'warning' : 'ok',
				'detail' => is_multisite() ? __( 'Multisite is detected. Current support is experimental.', 'wp-backup-pilot' ) : __( 'Single site install.', 'wp-backup-pilot' ),
			),
			array(
				'label'  => __( 'PHP memory limit', 'wp-backup-pilot' ),
				'status' => 'ok',
				'detail' => ini_get( 'memory_limit' ),
			),
		);
	}
}
