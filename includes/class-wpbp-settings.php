<?php
/**
 * Plugin settings.
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPBP_Settings {
	const OPTION = 'wpbp_settings';

	/**
	 * Get settings.
	 *
	 * @return array
	 */
	public static function get() {
		$defaults = array(
			'retention_count'     => 10,
			'retention_days'      => 0,
			'pre_restore_count'   => 5,
			'schedule_enabled'    => 0,
			'schedule_interval'   => 'daily',
			'schedule_profile'    => 'full',
			'remote_enabled'      => 0,
			'remote_endpoint'     => '',
			'remote_region'       => 'us-east-1',
			'remote_bucket'       => '',
			'remote_access_key'   => '',
			'remote_secret_key'   => '',
			'remote_prefix'       => 'wp-backup-pilot',
			'remote_path_style'   => 1,
			'remote_delete_local' => 0,
			'encryption_password' => '',
		);

		$settings = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
	}

	/**
	 * Save settings from request data.
	 *
	 * @param array $data Request data.
	 * @return void
	 */
	public static function save( array $data ) {
		$profiles = array_keys( ( new WPBP_Backup_Manager() )->profiles() );
		$interval = isset( $data['schedule_interval'] ) ? sanitize_key( $data['schedule_interval'] ) : 'daily';
		if ( ! in_array( $interval, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			$interval = 'daily';
		}

		$profile = isset( $data['schedule_profile'] ) ? sanitize_key( $data['schedule_profile'] ) : 'full';
		if ( ! in_array( $profile, $profiles, true ) ) {
			$profile = 'full';
		}

		update_option(
			self::OPTION,
			array(
				'retention_count'     => max( 0, absint( $data['retention_count'] ?? 10 ) ),
				'retention_days'      => max( 0, absint( $data['retention_days'] ?? 0 ) ),
				'pre_restore_count'   => max( 0, absint( $data['pre_restore_count'] ?? 5 ) ),
				'schedule_enabled'    => empty( $data['schedule_enabled'] ) ? 0 : 1,
				'schedule_interval'   => $interval,
				'schedule_profile'    => $profile,
				'remote_enabled'      => empty( $data['remote_enabled'] ) ? 0 : 1,
				'remote_endpoint'     => esc_url_raw( $data['remote_endpoint'] ?? '' ),
				'remote_region'       => sanitize_text_field( $data['remote_region'] ?? 'us-east-1' ),
				'remote_bucket'       => sanitize_text_field( $data['remote_bucket'] ?? '' ),
				'remote_access_key'   => sanitize_text_field( $data['remote_access_key'] ?? '' ),
				'remote_secret_key'   => sanitize_text_field( $data['remote_secret_key'] ?? '' ),
				'remote_prefix'       => trim( sanitize_text_field( $data['remote_prefix'] ?? 'wp-backup-pilot' ), '/' ),
				'remote_path_style'   => empty( $data['remote_path_style'] ) ? 0 : 1,
				'remote_delete_local' => empty( $data['remote_delete_local'] ) ? 0 : 1,
				'encryption_password' => sanitize_text_field( $data['encryption_password'] ?? '' ),
			),
			false
		);
	}
}
