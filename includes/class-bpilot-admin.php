<?php
/**
 * Admin UI and actions.
 *
 * @package BackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPILOT_Admin {
	/**
	 * Backup manager.
	 *
	 * @var BPILOT_Backup_Manager
	 */
	private $backups;

	/**
	 * Restore manager.
	 *
	 * @var BPILOT_Restore_Manager
	 */
	private $restore;

	/**
	 * Job manager.
	 *
	 * @var BPILOT_Job_Manager
	 */
	private $jobs;

	/**
	 * Constructor.
	 *
	 * @param BPILOT_Backup_Manager  $backups Backup manager.
	 * @param BPILOT_Restore_Manager $restore Restore manager.
	 * @param BPILOT_Job_Manager     $jobs Job manager.
	 */
	public function __construct( BPILOT_Backup_Manager $backups, BPILOT_Restore_Manager $restore, BPILOT_Job_Manager $jobs ) {
		$this->backups = $backups;
		$this->restore = $restore;
		$this->jobs    = $jobs;
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_management_page(
			__( 'Backup Pilot', 'backup-pilot' ),
			__( 'Backup Pilot', 'backup-pilot' ),
			'manage_options',
			'backup-pilot',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage backups.', 'backup-pilot' ) );
		}

		BPILOT_Filesystem::ensure_storage();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only admin navigation; capability checked above.
		$confirm = isset( $_GET['bpilot_confirm_restore'] ) ? sanitize_file_name( wp_unslash( $_GET['bpilot_confirm_restore'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only admin navigation; capability checked above.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'backups';
		$tabs    = array(
			'backups'     => __( 'Backups', 'backup-pilot' ),
			'jobs'        => __( 'Jobs', 'backup-pilot' ),
			'restore'     => __( 'Restore', 'backup-pilot' ),
			'settings'    => __( 'Settings', 'backup-pilot' ),
			'diagnostics' => __( 'Diagnostics', 'backup-pilot' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'backups';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Backup Pilot', 'backup-pilot' ); ?></h1>
			<?php $this->render_notice(); ?>
			<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page' => 'backup-pilot',
								'tab'  => $key,
							),
							admin_url( 'tools.php' )
						)
					);
					?>
										"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php if ( 'backups' === $tab ) : ?>
				<?php $this->render_backup_create_panel(); ?>
				<h2><?php esc_html_e( 'Backup History', 'backup-pilot' ); ?></h2>
				<?php $this->render_backup_table(); ?>
			<?php elseif ( 'jobs' === $tab ) : ?>
				<h2><?php esc_html_e( 'Background Jobs', 'backup-pilot' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px;">
					<?php wp_nonce_field( 'bpilot_run_due_jobs' ); ?>
					<input type="hidden" name="action" value="bpilot_run_due_jobs">
					<?php submit_button( __( 'Run Queued Jobs Now', 'backup-pilot' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px;">
					<?php wp_nonce_field( 'bpilot_cleanup_jobs' ); ?>
					<input type="hidden" name="action" value="bpilot_cleanup_jobs">
					<?php submit_button( __( 'Clear Failed, Cancelled, and Stale Jobs', 'backup-pilot' ), 'secondary', 'submit', false ); ?>
				</form>
				<?php $this->render_jobs_table(); ?>
			<?php elseif ( 'restore' === $tab ) : ?>
				<?php $this->render_restore_tools( $confirm ); ?>
			<?php elseif ( 'settings' === $tab ) : ?>
				<?php $this->render_settings_form(); ?>
			<?php elseif ( 'diagnostics' === $tab ) : ?>
				<?php $this->render_diagnostics(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Create backup action.
	 *
	 * @return void
	 */
	public function handle_create_backup() {
		$this->guard( 'bpilot_create_backup' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$profile = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : 'full';
		$this->jobs->enqueue_backup( $profile );
		$this->redirect( 'success', __( 'Backup job queued. It will run through WP-Cron, or you can run queued jobs now.', 'backup-pilot' ) );
	}

	/**
	 * Run due jobs action.
	 *
	 * @return void
	 */
	public function handle_run_due_jobs() {
		$this->guard( 'bpilot_run_due_jobs' );

		$count = $this->jobs->run_due_jobs();
		/* translators: %d: number of processed job chunks. */
		$this->redirect( 'success', sprintf( _n( 'Processed %d job chunk.', 'Processed %d job chunks.', $count, 'backup-pilot' ), $count ) );
	}

	/**
	 * Cancel job action.
	 *
	 * @return void
	 */
	public function handle_cancel_job() {
		$this->guard( 'bpilot_cancel_job' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$this->jobs->cancel( $job_id );
		$this->redirect( 'success', __( 'Job cancelled.', 'backup-pilot' ) );
	}

	/**
	 * Cleanup jobs action.
	 *
	 * @return void
	 */
	public function handle_cleanup_jobs() {
		$this->guard( 'bpilot_cleanup_jobs' );
		$count = $this->jobs->cleanup_jobs();
		/* translators: %d: number of cleaned up jobs. */
		$this->redirect( 'success', sprintf( _n( 'Cleaned up %d job.', 'Cleaned up %d jobs.', $count, 'backup-pilot' ), $count ) );
	}

	/**
	 * Save settings action.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		$this->guard( 'bpilot_save_settings' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		BPILOT_Settings::save( wp_unslash( $_POST ) );
		BPILOT_Scheduler::sync( true );
		$this->redirect( 'success', __( 'Settings saved.', 'backup-pilot' ) );
	}

	/**
	 * Test remote storage action.
	 *
	 * @return void
	 */
	public function handle_test_remote() {
		$this->guard( 'bpilot_test_remote' );
		$result = ( new BPILOT_Remote_Storage() )->test_connection();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message(), 'settings' );
		}

		$this->redirect( 'success', __( 'Remote storage connection test succeeded.', 'backup-pilot' ), 'settings' );
	}

	/**
	 * Queue rollback restore action.
	 *
	 * @return void
	 */
	public function handle_rollback_restore() {
		$this->guard( 'bpilot_rollback_restore' );
		$backup = BPILOT_Restore_History::latest_safety_backup();
		if ( ! $backup ) {
			$this->redirect( 'error', __( 'No rollback backup is available.', 'backup-pilot' ), 'restore' );
		}

		$path = $this->backups->resolve_backup( $backup );
		if ( is_wp_error( $path ) ) {
			$this->redirect( 'error', $path->get_error_message(), 'restore' );
		}

		$this->jobs->enqueue_restore( $backup, $path, false );
		$this->redirect( 'success', __( 'Rollback restore job queued.', 'backup-pilot' ), 'restore' );
	}

	/**
	 * Download backup action.
	 *
	 * @return void
	 */
	public function handle_download_backup() {
		$this->guard( 'bpilot_download_backup' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in guard(); token authorizes download.
		$token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$filename = $token ? get_transient( 'bpilot_download_' . $token ) : '';
		if ( ! $filename ) {
			$this->redirect( 'error', __( 'The download link has expired. Please generate a new one from Backup History.', 'backup-pilot' ), 'backups' );
		}
		delete_transient( 'bpilot_download_' . $token );
		$filename = sanitize_file_name( $filename );
		$path     = $this->backups->resolve_backup( $filename );

		if ( is_wp_error( $path ) ) {
			$this->redirect( 'error', $path->get_error_message() );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Large backup packages must be streamed directly.
		readfile( $path );
		exit;
	}

	/**
	 * Delete backup action.
	 *
	 * @return void
	 */
	public function handle_delete_backup() {
		$this->guard( 'bpilot_delete_backup' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$filename = isset( $_POST['backup'] ) ? sanitize_file_name( wp_unslash( $_POST['backup'] ) ) : '';
		$result   = $this->backups->delete( $filename );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}

		$this->redirect( 'success', __( 'Backup deleted.', 'backup-pilot' ) );
	}

	/**
	 * Upload package action.
	 *
	 * @return void
	 */
	public function handle_upload_backup() {
		$this->guard( 'bpilot_upload_backup' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in guard(); validated in import_upload().
		$file   = isset( $_FILES['backup_package'] ) ? wp_unslash( $_FILES['backup_package'] ) : array();
		$result = $this->backups->import_upload( $file );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'backup-pilot',
					'tab'                  => 'restore',
					'bpilot_notice'          => 'success',
					'bpilot_message'         => __( 'Package uploaded and validated.', 'backup-pilot' ),
					'bpilot_confirm_restore' => $result['filename'],
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Restore package action.
	 *
	 * @return void
	 */
	public function handle_restore_backup() {
		$this->guard( 'bpilot_restore_backup' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$filename     = isset( $_POST['backup'] ) ? sanitize_file_name( wp_unslash( $_POST['backup'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$rewrite_urls = ! empty( $_POST['rewrite_urls'] );
		$path         = $this->backups->resolve_backup( $filename );

		if ( is_wp_error( $path ) ) {
			$this->redirect( 'error', $path->get_error_message() );
		}

		$this->jobs->enqueue_restore( $filename, $path, $rewrite_urls );
		$this->redirect( 'success', __( 'Restore job queued. A pre-restore safety backup will be created before changes are applied.', 'backup-pilot' ), 'jobs' );
	}

	/**
	 * Render notice from query args.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Notice display after safe redirect.
		$type    = isset( $_GET['bpilot_notice'] ) ? sanitize_key( wp_unslash( $_GET['bpilot_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Notice display after safe redirect.
		$message = isset( $_GET['bpilot_message'] ) ? sanitize_text_field( wp_unslash( $_GET['bpilot_message'] ) ) : '';

		if ( ! $type || ! $message ) {
			return;
		}

		$class = 'success' === $type ? 'notice-success' : 'notice-error';
		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	/**
	 * Render backup creation panel.
	 *
	 * @return void
	 */
	private function render_backup_create_panel() {
		?>
		<div class="postbox" style="max-width:1100px;">
			<div class="inside">
				<h2><?php esc_html_e( 'Create Backup', 'backup-pilot' ); ?></h2>
				<p><?php esc_html_e( 'Queue a local package using the selected backup profile.', 'backup-pilot' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'bpilot_create_backup' ); ?>
					<input type="hidden" name="action" value="bpilot_create_backup">
					<label for="bpilot-profile"><?php esc_html_e( 'Profile', 'backup-pilot' ); ?></label>
					<select id="bpilot-profile" name="profile">
						<?php foreach ( $this->backups->profiles() as $profile_key => $profile_label ) : ?>
							<option value="<?php echo esc_attr( $profile_key ); ?>"><?php echo esc_html( $profile_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Queue Backup', 'backup-pilot' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render restore tools.
	 *
	 * @param string $confirm Backup to confirm.
	 * @return void
	 */
	private function render_restore_tools( $confirm ) {
		if ( $confirm ) {
			$this->render_restore_confirmation( $confirm );
		}

		$rollback = BPILOT_Restore_History::latest_safety_backup();
		?>
		<div style="display:grid;grid-template-columns:minmax(280px,1fr) minmax(280px,1fr);gap:20px;max-width:1100px;">
			<div class="postbox">
				<div class="inside">
					<h2><?php esc_html_e( 'Upload Package', 'backup-pilot' ); ?></h2>
					<p><?php esc_html_e( 'Upload a Backup Pilot ZIP package to validate it and prepare a staged restore.', 'backup-pilot' ); ?></p>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'bpilot_upload_backup' ); ?>
						<input type="hidden" name="action" value="bpilot_upload_backup">
						<input type="file" name="backup_package" accept=".zip" required>
						<?php submit_button( __( 'Upload Package', 'backup-pilot' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>
			<div class="postbox">
				<div class="inside">
					<h2><?php esc_html_e( 'Rollback Last Restore', 'backup-pilot' ); ?></h2>
					<p>
						<?php
						if ( $rollback ) {
							/* translators: %s: safety backup filename. */
							echo esc_html( sprintf( __( 'Latest safety backup: %s', 'backup-pilot' ), $rollback ) );
						} else {
							esc_html_e( 'No restore safety backup is available yet.', 'backup-pilot' );
						}
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'bpilot_rollback_restore' ); ?>
						<input type="hidden" name="action" value="bpilot_rollback_restore">
						<?php submit_button( __( 'Queue Rollback', 'backup-pilot' ), 'primary', 'submit', false, $rollback ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
				</div>
			</div>
		</div>
		<h2><?php esc_html_e( 'Restore History', 'backup-pilot' ); ?></h2>
		<?php $this->render_restore_history(); ?>
		<?php
	}

	/**
	 * Render backup history.
	 *
	 * @return void
	 */
	private function render_backup_table() {
		$items = $this->backups->list_backups();
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No backups have been created yet.', 'backup-pilot' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Package', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Created', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Source URL', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Size', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Status', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'backup-pilot' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $items as $item ) : ?>
				<tr>
					<td><code><?php echo esc_html( $item['filename'] ); ?></code></td>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created'] ) ); ?></td>
					<td><?php echo esc_html( isset( $item['manifest']['home_url'] ) ? $item['manifest']['home_url'] : '-' ); ?></td>
					<td><?php echo esc_html( BPILOT_Filesystem::format_bytes( $item['size'] ) ); ?></td>
					<td><?php echo $item['valid'] ? esc_html__( 'Valid', 'backup-pilot' ) : esc_html( $item['error'] ); ?></td>
					<td>
						<a class="button" href="<?php echo esc_url( $this->download_url( $item['filename'] ) ); ?>"><?php esc_html_e( 'Download', 'backup-pilot' ); ?></a>
						<?php if ( $item['valid'] ) : ?>
							<a class="button" href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'page' => 'backup-pilot',
										'tab'  => 'restore',
										'bpilot_confirm_restore' => $item['filename'],
									),
									admin_url( 'tools.php' )
								)
							);
							?>
													"><?php esc_html_e( 'Restore', 'backup-pilot' ); ?></a>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<?php wp_nonce_field( 'bpilot_delete_backup' ); ?>
							<input type="hidden" name="action" value="bpilot_delete_backup">
							<input type="hidden" name="backup" value="<?php echo esc_attr( $item['filename'] ); ?>">
							<?php submit_button( __( 'Delete', 'backup-pilot' ), 'delete small', 'submit', false ); ?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render recent background jobs.
	 *
	 * @return void
	 */
	private function render_jobs_table() {
		$jobs = $this->jobs->recent();
		if ( empty( $jobs ) ) {
			echo '<p>' . esc_html__( 'No background jobs yet.', 'backup-pilot' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Profile', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Status', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Progress', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Message', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Created', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Result', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Log', 'backup-pilot' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $jobs as $job ) : ?>
				<tr>
					<td><?php echo esc_html( ucfirst( $job['type'] ) ); ?></td>
					<td><?php echo esc_html( isset( $job['profile'] ) ? ucfirst( $job['profile'] ) : '-' ); ?></td>
					<td><?php echo esc_html( ucfirst( $job['status'] ) ); ?></td>
					<td>
						<?php
						$progress = isset( $job['progress'] ) && is_array( $job['progress'] ) ? $job['progress'] : array();
						$percent  = isset( $progress['percent'] ) ? (int) $progress['percent'] : 0;
						$label    = isset( $progress['label'] ) ? $progress['label'] : '';
						echo esc_html( $percent . '%' );
						if ( $label ) {
							echo '<br><small>' . esc_html( $label ) . '</small>';
						}
						?>
					</td>
					<td><?php echo esc_html( $job['message'] ); ?></td>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $job['created_at'] ) ); ?></td>
					<td>
						<?php
						if ( ! empty( $job['result']['filename'] ) ) {
							echo '<code>' . esc_html( $job['result']['filename'] ) . '</code>';
						} elseif ( ! empty( $job['result']['safety_backup'] ) ) {
							echo '<code>' . esc_html( $job['result']['safety_backup'] ) . '</code>';
						} else {
							echo esc_html__( '-', 'backup-pilot' );
						}
						?>
					</td>
					<td>
						<?php if ( in_array( $job['status'], array( 'queued', 'running' ), true ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'bpilot_cancel_job' ); ?>
								<input type="hidden" name="action" value="bpilot_cancel_job">
								<input type="hidden" name="job_id" value="<?php echo esc_attr( $job['id'] ); ?>">
								<?php submit_button( __( 'Cancel', 'backup-pilot' ), 'delete small', 'submit', false ); ?>
							</form>
						<?php else : ?>
							<?php esc_html_e( '-', 'backup-pilot' ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! empty( $job['logs'] ) && is_array( $job['logs'] ) ) : ?>
							<details>
								<summary><?php esc_html_e( 'View', 'backup-pilot' ); ?></summary>
								<ul>
									<?php foreach ( array_slice( $job['logs'], -8 ) as $log ) : ?>
										<li><small><?php echo esc_html( wp_date( 'H:i:s', $log['time'] ) . ' [' . $log['level'] . '] ' . $log['message'] ); ?></small></li>
									<?php endforeach; ?>
								</ul>
							</details>
						<?php else : ?>
							<?php esc_html_e( '-', 'backup-pilot' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render restore history.
	 *
	 * @return void
	 */
	private function render_restore_history() {
		$history = BPILOT_Restore_History::all();
		if ( empty( $history ) ) {
			echo '<p>' . esc_html__( 'No restores have completed yet.', 'backup-pilot' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Package', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Safety Backup', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'User', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Status', 'backup-pilot' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history as $record ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $record['time'] ) ); ?></td>
						<td><code><?php echo esc_html( $record['package'] ); ?></code></td>
						<td><code><?php echo esc_html( $record['safety_backup'] ); ?></code></td>
						<td><?php echo esc_html( get_the_author_meta( 'user_login', (int) $record['user_id'] ) ); ?></td>
						<td><?php echo esc_html( ucfirst( $record['status'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render production settings.
	 *
	 * @return void
	 */
	private function render_settings_form() {
		$settings = BPILOT_Settings::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:1100px;">
			<?php wp_nonce_field( 'bpilot_save_settings' ); ?>
			<input type="hidden" name="action" value="bpilot_save_settings">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Retention', 'backup-pilot' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Keep normal backups', 'backup-pilot' ); ?> <input type="number" min="0" name="retention_count" value="<?php echo esc_attr( $settings['retention_count'] ); ?>" style="width:80px;"></label>
						<label style="margin-left:12px;"><?php esc_html_e( 'Keep pre-restore backups', 'backup-pilot' ); ?> <input type="number" min="0" name="pre_restore_count" value="<?php echo esc_attr( $settings['pre_restore_count'] ); ?>" style="width:80px;"></label>
						<label style="margin-left:12px;"><?php esc_html_e( 'Delete older than days', 'backup-pilot' ); ?> <input type="number" min="0" name="retention_days" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" style="width:80px;"></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Schedule', 'backup-pilot' ); ?></th>
					<td>
						<label><input type="checkbox" name="schedule_enabled" value="1" <?php checked( $settings['schedule_enabled'] ); ?>> <?php esc_html_e( 'Enable scheduled backups', 'backup-pilot' ); ?></label>
						<select name="schedule_interval">
							<option value="daily" <?php selected( $settings['schedule_interval'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'backup-pilot' ); ?></option>
							<option value="weekly" <?php selected( $settings['schedule_interval'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'backup-pilot' ); ?></option>
							<option value="monthly" <?php selected( $settings['schedule_interval'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'backup-pilot' ); ?></option>
						</select>
						<select name="schedule_profile">
							<?php foreach ( $this->backups->profiles() as $profile_key => $profile_label ) : ?>
								<option value="<?php echo esc_attr( $profile_key ); ?>" <?php selected( $settings['schedule_profile'], $profile_key ); ?>><?php echo esc_html( $profile_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php $next = wp_next_scheduled( BPILOT_Scheduler::HOOK ); ?>
						<p class="description">
							<?php
							if ( $next ) {
								/* translators: %s: formatted date and time. */
								echo esc_html( sprintf( __( 'Next scheduled run: %s', 'backup-pilot' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) ) );
							} else {
								esc_html_e( 'No scheduled backup is currently registered.', 'backup-pilot' );
							}
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'S3-Compatible Storage', 'backup-pilot' ); ?></th>
					<td>
						<p><label><input type="checkbox" name="remote_enabled" value="1" <?php checked( $settings['remote_enabled'] ); ?>> <?php esc_html_e( 'Upload completed backups to S3-compatible storage', 'backup-pilot' ); ?></label></p>
						<p><input type="url" name="remote_endpoint" value="<?php echo esc_attr( $settings['remote_endpoint'] ); ?>" placeholder="https://s3.amazonaws.com" class="regular-text"> <?php esc_html_e( 'Endpoint', 'backup-pilot' ); ?></p>
						<p><input type="text" name="remote_region" value="<?php echo esc_attr( $settings['remote_region'] ); ?>" placeholder="us-east-1"> <?php esc_html_e( 'Region', 'backup-pilot' ); ?></p>
						<p><input type="text" name="remote_bucket" value="<?php echo esc_attr( $settings['remote_bucket'] ); ?>" placeholder="bucket-name"> <?php esc_html_e( 'Bucket', 'backup-pilot' ); ?></p>
						<p><input type="text" name="remote_access_key" value="<?php echo esc_attr( $settings['remote_access_key'] ); ?>" class="regular-text"> <?php esc_html_e( 'Access key', 'backup-pilot' ); ?></p>
						<p><input type="password" name="remote_secret_key" value="<?php echo esc_attr( $settings['remote_secret_key'] ); ?>" class="regular-text"> <?php esc_html_e( 'Secret key', 'backup-pilot' ); ?></p>
						<p><input type="text" name="remote_prefix" value="<?php echo esc_attr( $settings['remote_prefix'] ); ?>" class="regular-text"> <?php esc_html_e( 'Object prefix', 'backup-pilot' ); ?></p>
						<p><label><input type="checkbox" name="remote_path_style" value="1" <?php checked( $settings['remote_path_style'] ); ?>> <?php esc_html_e( 'Use path-style URLs', 'backup-pilot' ); ?></label></p>
						<p><label><input type="checkbox" name="remote_delete_local" value="1" <?php checked( $settings['remote_delete_local'] ); ?>> <?php esc_html_e( 'Delete local package after successful upload', 'backup-pilot' ); ?></label></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Package Encryption', 'backup-pilot' ); ?></th>
					<td>
						<input type="password" name="encryption_password" value="<?php echo esc_attr( $settings['encryption_password'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Optional ZIP password. Encrypted backups require the same password before restore.', 'backup-pilot' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'backup-pilot' ) ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:1100px;margin-top:8px;">
			<?php wp_nonce_field( 'bpilot_test_remote' ); ?>
			<input type="hidden" name="action" value="bpilot_test_remote">
			<?php submit_button( __( 'Test Remote Storage', 'backup-pilot' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render diagnostics table.
	 *
	 * @return void
	 */
	private function render_diagnostics() {
		?>
		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Check', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Status', 'backup-pilot' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'backup-pilot' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( BPILOT_Diagnostics::checks() as $check ) : ?>
					<tr>
						<td><?php echo esc_html( $check['label'] ); ?></td>
						<td><?php echo esc_html( ucfirst( $check['status'] ) ); ?></td>
						<td><?php echo esc_html( $check['detail'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<h2><?php esc_html_e( 'Suggested Test Matrix', 'backup-pilot' ); ?></h2>
		<p><?php esc_html_e( 'Before production release, test fresh installs, content-heavy installs, WooCommerce/page-builder data, PHP 7.4 through current PHP, Apache/Nginx, shared hosting, VPS, and each enabled object-storage provider.', 'backup-pilot' ); ?></p>
		<?php
	}

	/**
	 * Render staged restore confirmation.
	 *
	 * @param string $filename Backup filename.
	 * @return void
	 */
	private function render_restore_confirmation( $filename ) {
		$path = $this->backups->resolve_backup( $filename );
		if ( is_wp_error( $path ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $path->get_error_message() ) );
			return;
		}

		$inspect = $this->restore->inspect( $path );
		if ( is_wp_error( $inspect ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $inspect->get_error_message() ) );
			return;
		}

		$manifest   = $inspect['manifest'];
		$source_url = isset( $manifest['home_url'] ) ? untrailingslashit( $manifest['home_url'] ) : '';
		$current    = untrailingslashit( home_url() );
		$differs    = $source_url && $source_url !== $current;
		?>
		<div class="notice notice-warning">
			<h2><?php esc_html_e( 'Confirm Restore', 'backup-pilot' ); ?></h2>
			<p><strong><?php esc_html_e( 'This will replace this site database and managed wp-content folders.', 'backup-pilot' ); ?></strong></p>
			<ul>
				<li><?php esc_html_e( 'Package:', 'backup-pilot' ); ?> <code><?php echo esc_html( $filename ); ?></code></li>
				<li><?php esc_html_e( 'Created:', 'backup-pilot' ); ?> <?php echo esc_html( isset( $manifest['created_at'] ) ? $manifest['created_at'] : '-' ); ?></li>
				<li><?php esc_html_e( 'Source URL:', 'backup-pilot' ); ?> <?php echo esc_html( $source_url ? $source_url : '-' ); ?></li>
				<li><?php esc_html_e( 'Current URL:', 'backup-pilot' ); ?> <?php echo esc_html( $current ); ?></li>
				<li><?php esc_html_e( 'Tables:', 'backup-pilot' ); ?> <?php echo esc_html( isset( $manifest['database']['count'] ) ? $manifest['database']['count'] : '-' ); ?></li>
				<li><?php esc_html_e( 'Files:', 'backup-pilot' ); ?> <?php echo esc_html( isset( $manifest['files']['files'] ) ? $manifest['files']['files'] : '-' ); ?></li>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bpilot_restore_backup' ); ?>
				<input type="hidden" name="action" value="bpilot_restore_backup">
				<input type="hidden" name="backup" value="<?php echo esc_attr( $filename ); ?>">
				<?php if ( $differs ) : ?>
					<label>
						<input type="checkbox" name="rewrite_urls" value="1" checked>
						<?php
						/* translators: 1: source URL, 2: current site URL. */
						echo esc_html( sprintf( __( 'Replace %1$s with %2$s in the database.', 'backup-pilot' ), $source_url, $current ) );
						?>
					</label>
				<?php endif; ?>
				<p><?php submit_button( __( 'Restore This Backup', 'backup-pilot' ), 'primary', 'submit', false ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Create a short-lived download URL.
	 *
	 * @param string $filename Backup filename.
	 * @return string
	 */
	private function download_url( $filename ) {
		$token = wp_generate_password( 32, false, false );
		set_transient( 'bpilot_download_' . $token, sanitize_file_name( $filename ), 10 * MINUTE_IN_SECONDS );

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'bpilot_download_backup',
					'token'  => $token,
				),
				admin_url( 'admin-post.php' )
			),
			'bpilot_download_backup'
		);
	}

	/**
	 * Validate request.
	 *
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	private function guard( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage backups.', 'backup-pilot' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirect back to plugin page with a notice.
	 *
	 * @param string $type Notice type.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function redirect( $type, $message, $tab = '' ) {
		$args = array(
			'page'         => 'backup-pilot',
			'bpilot_notice'  => $type,
			'bpilot_message' => $message,
		);
		if ( $tab ) {
			$args['tab'] = sanitize_key( $tab );
		}

		wp_safe_redirect(
			add_query_arg(
				$args,
				admin_url( 'tools.php' )
			)
		);
		exit;
	}
}
