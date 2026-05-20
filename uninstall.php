<?php
/**
 * Uninstall cleanup for Backup Pilot.
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wpbp_settings' );
delete_option( 'wpbp_jobs' );
delete_option( 'wpbp_restore_history' );

$wpbp_timestamp = wp_next_scheduled( 'wpbp_scheduled_backup' );
if ( $wpbp_timestamp ) {
	wp_unschedule_event( $wpbp_timestamp, 'wpbp_scheduled_backup' );
}

// Backup archives are intentionally left in uploads to avoid destructive data loss.
