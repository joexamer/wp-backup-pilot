<?php
/**
 * Uninstall cleanup for WP Backup Pilot.
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wpbp_settings' );
delete_option( 'wpbp_jobs' );
delete_option( 'wpbp_restore_history' );

$timestamp = wp_next_scheduled( 'wpbp_scheduled_backup' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'wpbp_scheduled_backup' );
}

// Backup archives are intentionally left in uploads to avoid destructive data loss.
