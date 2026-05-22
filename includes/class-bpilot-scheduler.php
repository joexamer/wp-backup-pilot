<?php
/**
 * Scheduled backups.
 *
 * @package BackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPILOT_Scheduler {
	const HOOK = 'bpilot_scheduled_backup';

	/**
	 * Sync schedule with settings.
	 *
	 * @return void
	 */
	public static function sync( $force = false ) {
		$settings  = BPILOT_Settings::get();
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( empty( $settings['schedule_enabled'] ) ) {
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::HOOK );
			}
			return;
		}

		$interval = $settings['schedule_interval'];
		if ( 'monthly' === $interval ) {
			$interval = 'bpilot_monthly';
		}

		if ( $timestamp && ! $force ) {
			return;
		}
		if ( $timestamp && $force ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, $interval, self::HOOK );
	}

	/**
	 * Register custom schedules.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( array $schedules ) {
		$schedules['weekly']       = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'backup-pilot' ),
		);
		$schedules['bpilot_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly', 'backup-pilot' ),
		);

		return $schedules;
	}
}
