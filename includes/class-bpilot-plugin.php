<?php
/**
 * Main plugin bootstrap.
 *
 * @package BackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPILOT_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var BPILOT_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin controller.
	 *
	 * @var BPILOT_Admin
	 */
	private $admin;

	/**
	 * Get the singleton.
	 *
	 * @return BPILOT_Plugin
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
		BPILOT_Filesystem::ensure_storage();
		update_option( 'bpilot_version', BPILOT_VERSION, false );
		BPILOT_Scheduler::sync( true );
	}

	/**
	 * Cleanup scheduled events on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( BPILOT_Scheduler::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, BPILOT_Scheduler::HOOK );
		}
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		$backup_manager  = new BPILOT_Backup_Manager();
		$restore_manager = new BPILOT_Restore_Manager( $backup_manager );
		$job_manager     = new BPILOT_Job_Manager( $backup_manager, $restore_manager );
		$this->admin     = new BPILOT_Admin(
			$backup_manager,
			$restore_manager,
			$job_manager
		);

		add_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
		add_action( 'admin_post_bpilot_create_backup', array( $this->admin, 'handle_create_backup' ) );
		add_action( 'admin_post_bpilot_run_due_jobs', array( $this->admin, 'handle_run_due_jobs' ) );
		add_action( 'admin_post_bpilot_cancel_job', array( $this->admin, 'handle_cancel_job' ) );
		add_action( 'admin_post_bpilot_cleanup_jobs', array( $this->admin, 'handle_cleanup_jobs' ) );
		add_action( 'admin_post_bpilot_save_settings', array( $this->admin, 'handle_save_settings' ) );
		add_action( 'admin_post_bpilot_test_remote', array( $this->admin, 'handle_test_remote' ) );
		add_action( 'admin_post_bpilot_rollback_restore', array( $this->admin, 'handle_rollback_restore' ) );
		add_action( 'admin_post_bpilot_download_backup', array( $this->admin, 'handle_download_backup' ) );
		add_action( 'admin_post_bpilot_delete_backup', array( $this->admin, 'handle_delete_backup' ) );
		add_action( 'admin_post_bpilot_upload_backup', array( $this->admin, 'handle_upload_backup' ) );
		add_action( 'admin_post_bpilot_restore_backup', array( $this->admin, 'handle_restore_backup' ) );
		add_action( BPILOT_Job_Manager::CRON_HOOK, array( $job_manager, 'run_job' ) );
		add_action( BPILOT_Scheduler::HOOK, array( $this, 'run_scheduled_backup' ) );
		add_filter( 'cron_schedules', array( 'BPILOT_Scheduler', 'schedules' ) );
		BPILOT_Scheduler::sync();
	}

	/**
	 * Queue scheduled backup.
	 *
	 * @return void
	 */
	public function run_scheduled_backup() {
		$settings = BPILOT_Settings::get();
		$manager  = new BPILOT_Job_Manager( new BPILOT_Backup_Manager(), new BPILOT_Restore_Manager() );
		$manager->enqueue_backup( $settings['schedule_profile'] );
	}
}
