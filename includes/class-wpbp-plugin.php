<?php
/**
 * Main plugin bootstrap.
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPBP_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var WPBP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin controller.
	 *
	 * @var WPBP_Admin
	 */
	private $admin;

	/**
	 * Get the singleton.
	 *
	 * @return WPBP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prepare secure storage on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		WPBP_Filesystem::ensure_storage();
		update_option( 'wpbp_version', WPBP_VERSION, false );
		WPBP_Scheduler::sync( true );
	}

	/**
	 * Cleanup scheduled events on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( WPBP_Scheduler::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, WPBP_Scheduler::HOOK );
		}
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		$backup_manager  = new WPBP_Backup_Manager();
		$restore_manager = new WPBP_Restore_Manager( $backup_manager );
		$job_manager     = new WPBP_Job_Manager( $backup_manager, $restore_manager );
		$this->admin     = new WPBP_Admin(
			$backup_manager,
			$restore_manager,
			$job_manager
		);

		add_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
		add_action( 'admin_post_wpbp_create_backup', array( $this->admin, 'handle_create_backup' ) );
		add_action( 'admin_post_wpbp_run_due_jobs', array( $this->admin, 'handle_run_due_jobs' ) );
		add_action( 'admin_post_wpbp_cancel_job', array( $this->admin, 'handle_cancel_job' ) );
		add_action( 'admin_post_wpbp_cleanup_jobs', array( $this->admin, 'handle_cleanup_jobs' ) );
		add_action( 'admin_post_wpbp_save_settings', array( $this->admin, 'handle_save_settings' ) );
		add_action( 'admin_post_wpbp_test_remote', array( $this->admin, 'handle_test_remote' ) );
		add_action( 'admin_post_wpbp_rollback_restore', array( $this->admin, 'handle_rollback_restore' ) );
		add_action( 'admin_post_wpbp_download_backup', array( $this->admin, 'handle_download_backup' ) );
		add_action( 'admin_post_wpbp_delete_backup', array( $this->admin, 'handle_delete_backup' ) );
		add_action( 'admin_post_wpbp_upload_backup', array( $this->admin, 'handle_upload_backup' ) );
		add_action( 'admin_post_wpbp_restore_backup', array( $this->admin, 'handle_restore_backup' ) );
		add_action( WPBP_Job_Manager::CRON_HOOK, array( $job_manager, 'run_job' ) );
		add_action( WPBP_Scheduler::HOOK, array( $this, 'run_scheduled_backup' ) );
		add_filter( 'cron_schedules', array( 'WPBP_Scheduler', 'schedules' ) );
		WPBP_Scheduler::sync();
	}

	/**
	 * Queue scheduled backup.
	 *
	 * @return void
	 */
	public function run_scheduled_backup() {
		$settings = WPBP_Settings::get();
		$manager  = new WPBP_Job_Manager( new WPBP_Backup_Manager(), new WPBP_Restore_Manager() );
		$manager->enqueue_backup( $settings['schedule_profile'] );
	}
}
