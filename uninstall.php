<?php
/**
 * Uninstall cleanup for Backup Pilot.
 *
 * @package BackupPilot
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bpilot_settings' );
delete_option( 'bpilot_jobs' );
delete_option( 'bpilot_restore_history' );

$bpilot_timestamp = wp_next_scheduled( 'bpilot_scheduled_backup' );
if ( $bpilot_timestamp ) {
	wp_unschedule_event( $bpilot_timestamp, 'bpilot_scheduled_backup' );
}

// Backup archives are intentionally left in uploads to avoid destructive data loss.
