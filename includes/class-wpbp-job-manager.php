<?php
/**
 * Lightweight background job manager.
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPBP_Job_Manager {
	const OPTION    = 'wpbp_jobs';
	const CRON_HOOK = 'wpbp_run_job';

	/**
	 * Backup manager.
	 *
	 * @var WPBP_Backup_Manager
	 */
	private $backups;

	/**
	 * Restore manager.
	 *
	 * @var WPBP_Restore_Manager|null
	 */
	private $restore;

	/**
	 * Constructor.
	 *
	 * @param WPBP_Backup_Manager       $backups Backup manager.
	 * @param WPBP_Restore_Manager|null $restore Restore manager.
	 */
	public function __construct( WPBP_Backup_Manager $backups, WPBP_Restore_Manager $restore = null ) {
		$this->backups = $backups;
		$this->restore = $restore;
	}

	/**
	 * Enqueue a backup job.
	 *
	 * @param string $profile Backup profile.
	 * @return array
	 */
	public function enqueue_backup( $profile = 'full' ) {
		$job = array(
			'id'         => 'job-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ),
			'type'       => 'backup',
			'profile'    => sanitize_key( $profile ),
			'status'     => 'queued',
			'message'    => __( 'Waiting to start.', 'backup-pilot' ),
			'logs'       => array( $this->log_entry( __( 'Backup job queued.', 'backup-pilot' ) ) ),
			'progress'   => array(
				'phase'   => 'queued',
				'percent' => 0,
				'label'   => __( 'Queued', 'backup-pilot' ),
			),
			'state'      => array(),
			'created_at' => time(),
			'updated_at' => time(),
			'result'     => array(),
		);

		$jobs               = $this->all();
		$jobs[ $job['id'] ] = $job;
		$this->save( $jobs );
		wp_schedule_single_event( time() + 1, self::CRON_HOOK, array( $job['id'] ) );

		return $job;
	}

	/**
	 * Enqueue a restore job.
	 *
	 * @param string $filename Backup filename.
	 * @param string $path Package path.
	 * @param bool   $rewrite_urls Whether to rewrite URLs.
	 * @return array
	 */
	public function enqueue_restore( $filename, $path, $rewrite_urls ) {
		$job = array(
			'id'         => 'job-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ),
			'type'       => 'restore',
			'status'     => 'queued',
			'message'    => __( 'Waiting to start restore.', 'backup-pilot' ),
			'logs'       => array( $this->log_entry( __( 'Restore job queued.', 'backup-pilot' ) ) ),
			'progress'   => array(
				'phase'   => 'queued',
				'percent' => 0,
				'label'   => __( 'Queued', 'backup-pilot' ),
			),
			'state'      => array(),
			'payload'    => array(
				'filename'     => $filename,
				'path'         => $path,
				'rewrite_urls' => (bool) $rewrite_urls,
			),
			'created_at' => time(),
			'updated_at' => time(),
			'result'     => array(),
		);

		$jobs               = $this->all();
		$jobs[ $job['id'] ] = $job;
		$this->save( $jobs );
		wp_schedule_single_event( time() + 1, self::CRON_HOOK, array( $job['id'] ) );

		return $job;
	}

	/**
	 * Run a specific job.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function run_job( $job_id ) {
		$jobs = $this->all();
		if ( empty( $jobs[ $job_id ] ) || ! in_array( $jobs[ $job_id ]['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}

		$jobs[ $job_id ]['status']     = 'running';
		$jobs[ $job_id ]['message']    = __( 'Preparing backup job.', 'backup-pilot' );
		$jobs[ $job_id ]['updated_at'] = time();
		$this->append_log_to_job( $jobs[ $job_id ], __( 'Processing job chunk.', 'backup-pilot' ) );
		$this->save( $jobs );

		$state = $this->advance_job( $jobs[ $job_id ] );

		$jobs = $this->all();
		if ( is_wp_error( $state ) ) {
			$jobs[ $job_id ]['status']  = 'failed';
			$jobs[ $job_id ]['message'] = $state->get_error_message();
			$this->append_log_to_job( $jobs[ $job_id ], $state->get_error_message(), 'error' );
			$jobs[ $job_id ]['progress'] = array(
				'phase'   => 'failed',
				'percent' => 0,
				'label'   => __( 'Failed', 'backup-pilot' ),
			);
		} elseif ( isset( $state['phase'] ) && 'complete' === $state['phase'] ) {
			$jobs[ $job_id ]['status']  = 'completed';
			$jobs[ $job_id ]['message'] = 'restore' === $jobs[ $job_id ]['type'] ? __( 'Restore completed successfully.', 'backup-pilot' ) : __( 'Backup created successfully.', 'backup-pilot' );
			$this->append_log_to_job( $jobs[ $job_id ], $jobs[ $job_id ]['message'], 'success' );
			$jobs[ $job_id ]['progress'] = $this->progress_for_job( $jobs[ $job_id ], $state );
			$jobs[ $job_id ]['result']   = isset( $state['result'] ) ? $state['result'] : array();
			$jobs[ $job_id ]['state']    = array();
			$this->after_complete( $jobs[ $job_id ] );
		} else {
			$progress                   = $this->progress_for_job( $jobs[ $job_id ], $state );
			$jobs[ $job_id ]['status']  = 'queued';
			$jobs[ $job_id ]['message'] = $progress['label'];
			$this->append_log_to_job( $jobs[ $job_id ], $progress['label'] );
			$jobs[ $job_id ]['progress'] = $progress;
			$jobs[ $job_id ]['state']    = $state;
			wp_schedule_single_event( time() + 1, self::CRON_HOOK, array( $job_id ) );
		}

		$jobs[ $job_id ]['updated_at'] = time();
		$this->save( $jobs );
	}

	/**
	 * Run due queued jobs immediately.
	 *
	 * @return int
	 */
	public function run_due_jobs() {
		$count = 0;
		foreach ( $this->all() as $job_id => $job ) {
			if ( ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
				continue;
			}

			for ( $i = 0; $i < 50; $i++ ) {
				$this->run_job( $job_id );
				++$count;

				$jobs = $this->all();
				if ( empty( $jobs[ $job_id ] ) || ! in_array( $jobs[ $job_id ]['status'], array( 'queued', 'running' ), true ) ) {
					break;
				}
			}
		}

		return $count;
	}

	/**
	 * Cancel a queued/running job.
	 *
	 * @param string $job_id Job ID.
	 * @return bool
	 */
	public function cancel( $job_id ) {
		$jobs = $this->all();
		if ( empty( $jobs[ $job_id ] ) || ! in_array( $jobs[ $job_id ]['status'], array( 'queued', 'running' ), true ) ) {
			return false;
		}

		$this->cleanup_state( isset( $jobs[ $job_id ]['state'] ) ? $jobs[ $job_id ]['state'] : array() );
		$jobs[ $job_id ]['status']  = 'cancelled';
		$jobs[ $job_id ]['message'] = __( 'Job cancelled.', 'backup-pilot' );
		$this->append_log_to_job( $jobs[ $job_id ], __( 'Job cancelled by administrator.', 'backup-pilot' ), 'warning' );
		$jobs[ $job_id ]['updated_at'] = time();
		$this->save( $jobs );

		return true;
	}

	/**
	 * Clear failed/cancelled jobs and stale running jobs.
	 *
	 * @return int
	 */
	public function cleanup_jobs() {
		$jobs    = $this->all();
		$removed = 0;
		foreach ( $jobs as $job_id => $job ) {
			$stale = in_array( $job['status'], array( 'queued', 'running' ), true ) && ( time() - (int) $job['updated_at'] ) > DAY_IN_SECONDS;
			if ( in_array( $job['status'], array( 'failed', 'cancelled' ), true ) || $stale ) {
				$this->cleanup_state( isset( $job['state'] ) ? $job['state'] : array() );
				unset( $jobs[ $job_id ] );
				++$removed;
			}
		}

		$this->save( $jobs );
		return $removed;
	}

	/**
	 * Get recent jobs.
	 *
	 * @return array
	 */
	public function recent() {
		$jobs = $this->all();
		uasort(
			$jobs,
			static function ( $a, $b ) {
				return (int) $b['created_at'] <=> (int) $a['created_at'];
			}
		);

		return array_slice( $jobs, 0, 10, true );
	}

	/**
	 * Get all jobs.
	 *
	 * @return array
	 */
	private function all() {
		$jobs = get_option( self::OPTION, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	/**
	 * Persist jobs and trim old entries.
	 *
	 * @param array $jobs Jobs.
	 * @return void
	 */
	private function save( array $jobs ) {
		uasort(
			$jobs,
			static function ( $a, $b ) {
				return (int) $b['created_at'] <=> (int) $a['created_at'];
			}
		);

		update_option( self::OPTION, array_slice( $jobs, 0, 20, true ), false );
	}

	/**
	 * Advance a job one chunk.
	 *
	 * @param array $job Job data.
	 * @return array|WP_Error
	 */
	private function advance_job( array $job ) {
		$state = ! empty( $job['state'] ) && is_array( $job['state'] ) ? $job['state'] : array();

		if ( 'restore' === $job['type'] ) {
			if ( ! $this->restore ) {
				return new WP_Error( 'wpbp_restore_unavailable', __( 'Restore manager is unavailable.', 'backup-pilot' ) );
			}

			if ( empty( $state ) ) {
				$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
				return $this->restore->prepare_chunked( $payload['path'], ! empty( $payload['rewrite_urls'] ) );
			}

			return $this->restore->process_chunk( $state );
		}

		if ( empty( $state ) ) {
			return $this->backups->prepare_chunked( 'background', isset( $job['profile'] ) ? $job['profile'] : 'full' );
		}

		return $this->backups->process_chunk( $state );
	}

	/**
	 * Get progress for a job state.
	 *
	 * @param array $job Job.
	 * @param array $state State.
	 * @return array
	 */
	private function progress_for_job( array $job, array $state ) {
		if ( 'restore' === $job['type'] && $this->restore ) {
			return $this->restore->progress_from_state( $state );
		}

		return $this->backups->progress_from_state( $state );
	}

	/**
	 * Clean temporary paths from a state.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function cleanup_state( array $state ) {
		foreach ( array( 'work_dir', 'staging', 'preserve_temp' ) as $key ) {
			if ( ! empty( $state[ $key ] ) && is_dir( $state[ $key ] ) ) {
				WPBP_Filesystem::delete_tree( $state[ $key ] );
			}
		}
	}

	/**
	 * Run completion hooks.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	private function after_complete( array &$job ) {
		if ( 'backup' === $job['type'] && ! empty( $job['result']['path'] ) && file_exists( $job['result']['path'] ) ) {
			$remote = ( new WPBP_Remote_Storage() )->maybe_upload( $job['result']['path'] );
			if ( is_wp_error( $remote ) ) {
				$job['remote_error'] = $remote->get_error_message();
				$this->append_log_to_job( $job, $remote->get_error_message(), 'warning' );
			} else {
				$job['remote_uploaded'] = true;
				$this->append_log_to_job( $job, __( 'Remote upload completed.', 'backup-pilot' ), 'success' );
			}
		}

		$deleted = ( new WPBP_Retention() )->apply();
		if ( $deleted > 0 ) {
			$job['retention_deleted'] = $deleted;
			/* translators: %d: number of deleted backup packages. */
			$this->append_log_to_job( $job, sprintf( __( 'Retention deleted %d old backup package(s).', 'backup-pilot' ), $deleted ) );
		}

		if ( 'restore' === $job['type'] && ! empty( $job['result']['safety_backup'] ) ) {
			WPBP_Restore_History::add(
				array(
					'package'       => $job['payload']['filename'] ?? '',
					'safety_backup' => $job['result']['safety_backup'],
					'status'        => 'completed',
				)
			);
		}
	}

	/**
	 * Create a log entry.
	 *
	 * @param string $message Message.
	 * @param string $level Log level.
	 * @return array
	 */
	private function log_entry( $message, $level = 'info' ) {
		return array(
			'time'    => time(),
			'level'   => sanitize_key( $level ),
			'message' => wp_strip_all_tags( $message ),
		);
	}

	/**
	 * Append a bounded log entry to a job.
	 *
	 * @param array  $job Job.
	 * @param string $message Message.
	 * @param string $level Level.
	 * @return void
	 */
	private function append_log_to_job( array &$job, $message, $level = 'info' ) {
		if ( empty( $job['logs'] ) || ! is_array( $job['logs'] ) ) {
			$job['logs'] = array();
		}

		$last = end( $job['logs'] );
		if ( is_array( $last ) && isset( $last['message'] ) && $last['message'] === wp_strip_all_tags( $message ) ) {
			return;
		}

		$job['logs'][] = $this->log_entry( $message, $level );
		$job['logs']   = array_slice( $job['logs'], -50 );
	}
}
