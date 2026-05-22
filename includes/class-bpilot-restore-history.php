<?php
/**
 * Restore history and rollback helpers.
 *
 * @package BackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPILOT_Restore_History {
	const OPTION = 'bpilot_restore_history';

	/**
	 * Add a restore record.
	 *
	 * @param array $record Restore record.
	 * @return void
	 */
	public static function add( array $record ) {
		$history = self::all();
		array_unshift(
			$history,
			wp_parse_args(
				$record,
				array(
					'time'          => time(),
					'user_id'       => get_current_user_id(),
					'package'       => '',
					'safety_backup' => '',
					'status'        => 'completed',
				)
			)
		);

		update_option( self::OPTION, array_slice( $history, 0, 25 ), false );
	}

	/**
	 * Get history.
	 *
	 * @return array
	 */
	public static function all() {
		$history = get_option( self::OPTION, array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Get latest rollback package.
	 *
	 * @return string
	 */
	public static function latest_safety_backup() {
		foreach ( self::all() as $record ) {
			if ( ! empty( $record['safety_backup'] ) ) {
				return $record['safety_backup'];
			}
		}

		return '';
	}
}
