<?php
/**
 * Environment diagnostics.
 *
 * @package BackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPILOT_Diagnostics {
	/**
	 * Get environment checks.
	 *
	 * @return array
	 */
	public static function checks() {
		$paths = BPILOT_Filesystem::paths();
		$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( BPILOT_Filesystem::content_dir() ) : false;

		return array(
			array(
				'label'  => __( 'ZipArchive extension', 'backup-pilot' ),
				'status' => class_exists( 'ZipArchive' ) ? 'ok' : 'error',
				'detail' => class_exists( 'ZipArchive' ) ? __( 'Available', 'backup-pilot' ) : __( 'Missing. Backups cannot be packaged without ZipArchive.', 'backup-pilot' ),
			),
			array(
				'label'  => __( 'Storage writable', 'backup-pilot' ),
				'status' => BPILOT_Filesystem::is_directory_writable( $paths['base'] ) || wp_mkdir_p( $paths['base'] ) ? 'ok' : 'error',
				'detail' => $paths['base'],
			),
			array(
				'label'  => __( 'Free disk space', 'backup-pilot' ),
				'status' => false === $free || $free > 256 * MB_IN_BYTES ? 'ok' : 'warning',
				'detail' => false === $free ? __( 'Unable to detect.', 'backup-pilot' ) : size_format( $free, 2 ),
			),
			array(
				'label'  => __( 'Multisite', 'backup-pilot' ),
				'status' => is_multisite() ? 'warning' : 'ok',
				'detail' => is_multisite() ? __( 'Multisite is detected. Current support is experimental.', 'backup-pilot' ) : __( 'Single site install.', 'backup-pilot' ),
			),
			array(
				'label'  => __( 'PHP memory limit', 'backup-pilot' ),
				'status' => 'ok',
				'detail' => ini_get( 'memory_limit' ),
			),
		);
	}
}
