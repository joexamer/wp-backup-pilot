<?php
/**
 * Restore orchestration.
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPBP_Restore_Manager {
	const RESTORE_FILE_CHUNK_COUNT = 250;

	/**
	 * Backup manager.
	 *
	 * @var WPBP_Backup_Manager
	 */
	private $backups;

	/**
	 * Constructor.
	 *
	 * @param WPBP_Backup_Manager|null $backups Backup manager.
	 */
	public function __construct( WPBP_Backup_Manager $backups = null ) {
		$this->backups = $backups ? $backups : new WPBP_Backup_Manager();
	}

	/**
	 * Inspect a backup package.
	 *
	 * @param string $path Package path.
	 * @return array|WP_Error
	 */
	public function inspect( $path ) {
		$archive = new WPBP_Archive();
		return $archive->inspect( $path );
	}

	/**
	 * Restore a package into the current site.
	 *
	 * @param string $path Package path.
	 * @param bool   $rewrite_urls Whether URLs should be rewritten.
	 * @return array|WP_Error
	 */
	public function restore( $path, $rewrite_urls ) {
		$archive = new WPBP_Archive();
		$inspect = $archive->inspect( $path );
		if ( is_wp_error( $inspect ) ) {
			return $inspect;
		}

		$destination_url = untrailingslashit( home_url() );
		$safety_backup   = $this->backups->create( 'pre-restore' );
		if ( is_wp_error( $safety_backup ) ) {
			return new WP_Error(
				'wpbp_pre_restore_backup_failed',
				sprintf(
					__( 'Restore stopped because the automatic pre-restore backup failed: %s', 'wp-backup-pilot' ),
					$safety_backup->get_error_message()
				)
			);
		}

		$staging = $this->external_work_dir( 'restore' );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}

		try {
			$extract = $archive->extract( $path, $staging );
			if ( is_wp_error( $extract ) ) {
				WPBP_Filesystem::delete_tree( $staging );
				return $extract;
			}

			$sql_file = trailingslashit( $staging ) . 'database.sql';
			$db       = new WPBP_Database();
			$import   = $db->import( $sql_file );
			if ( is_wp_error( $import ) ) {
				WPBP_Filesystem::delete_tree( $staging );
				return $import;
			}

			$replace_stats = array();
			$source_urls   = array_filter(
				array_unique(
					array(
						isset( $inspect['manifest']['home_url'] ) ? untrailingslashit( $inspect['manifest']['home_url'] ) : '',
						isset( $inspect['manifest']['site_url'] ) ? untrailingslashit( $inspect['manifest']['site_url'] ) : '',
					)
				)
			);

			if ( $rewrite_urls ) {
				$replacer = new WPBP_Search_Replace();
				foreach ( $source_urls as $source_url ) {
					if ( $source_url === $destination_url ) {
						continue;
					}

					$replace_stats[] = $replacer->run( $source_url, $destination_url );
					if ( is_wp_error( end( $replace_stats ) ) ) {
						WPBP_Filesystem::delete_tree( $staging );
						return end( $replace_stats );
					}
				}
			}

			$this->keep_plugin_active();

			$file_restore = $this->restore_files( trailingslashit( $staging ) . 'files/wp-content' );
			WPBP_Filesystem::delete_tree( $staging );
			WPBP_Filesystem::ensure_storage();

			if ( is_wp_error( $file_restore ) ) {
				return $file_restore;
			}

			return array(
				'manifest'       => $inspect['manifest'],
				'files_restored' => $file_restore,
				'replace_stats'  => $replace_stats,
				'safety_backup'  => $safety_backup,
			);
		} catch ( Exception $exception ) {
			WPBP_Filesystem::delete_tree( $staging );
			return new WP_Error( 'wpbp_restore_failed', $exception->getMessage() );
		}
	}

	/**
	 * Prepare a chunked restore state.
	 *
	 * @param string $path Package path.
	 * @param bool   $rewrite_urls Whether URLs should be rewritten.
	 * @return array|WP_Error
	 */
	public function prepare_chunked( $path, $rewrite_urls ) {
		$archive = new WPBP_Archive();
		$inspect = $archive->inspect( $path );
		if ( is_wp_error( $inspect ) ) {
			return $inspect;
		}

		$safety_backup = $this->backups->create( 'pre-restore' );
		if ( is_wp_error( $safety_backup ) ) {
			return new WP_Error(
				'wpbp_pre_restore_backup_failed',
				sprintf( __( 'Restore stopped because the automatic pre-restore backup failed: %s', 'wp-backup-pilot' ), $safety_backup->get_error_message() )
			);
		}

		$staging = $this->external_work_dir( 'restore' );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}

		return array(
			'phase'            => 'extract',
			'path'             => $path,
			'manifest'         => $inspect['manifest'],
			'includes'         => isset( $inspect['manifest']['includes'] ) ? $inspect['manifest']['includes'] : array(
				'database' => true,
				'files'    => true,
			),
			'rewrite_urls'     => (bool) $rewrite_urls,
			'destination_url'  => untrailingslashit( home_url() ),
			'staging'          => $staging,
			'extract_index'    => 0,
			'extract_total'    => 1,
			'import_offset'    => 0,
			'import_statement' => '',
			'import_total'     => 1,
			'file_index'       => 0,
			'files_total'      => 1,
			'files_restored'   => 0,
			'safety_backup'    => $safety_backup,
		);
	}

	/**
	 * Process one chunked restore step.
	 *
	 * @param array $state Restore state.
	 * @return array|WP_Error
	 */
	public function process_chunk( array $state ) {
		switch ( isset( $state['phase'] ) ? $state['phase'] : '' ) {
			case 'extract':
				return $this->process_extract_chunk( $state );
			case 'verify':
				return $this->process_verify_chunk( $state );
			case 'database':
				return $this->process_database_chunk( $state );
			case 'rewrite':
				return $this->process_rewrite_chunk( $state );
			case 'files_prepare':
				return $this->prepare_file_restore_chunk( $state );
			case 'files':
				return $this->process_file_restore_chunk( $state );
			case 'cleanup':
				return $this->process_cleanup_chunk( $state );
		}

		return new WP_Error( 'wpbp_restore_bad_phase', __( 'Restore job phase is invalid.', 'wp-backup-pilot' ) );
	}

	/**
	 * Progress details for restore state.
	 *
	 * @param array $state State.
	 * @return array
	 */
	public function progress_from_state( array $state ) {
		$phase         = isset( $state['phase'] ) ? $state['phase'] : 'queued';
		$extract_total = isset( $state['extract_total'] ) ? max( 1, (int) $state['extract_total'] ) : 1;
		$extract_index = isset( $state['extract_index'] ) ? (int) $state['extract_index'] : 0;
		$import_total  = isset( $state['import_total'] ) ? max( 1, (int) $state['import_total'] ) : 1;
		$import_offset = isset( $state['import_offset'] ) ? (int) $state['import_offset'] : 0;
		$files_total   = isset( $state['files_total'] ) ? max( 1, (int) $state['files_total'] ) : 1;
		$file_index    = isset( $state['file_index'] ) ? (int) $state['file_index'] : 0;

		if ( 'extract' === $phase ) {
			$percent = min( 20, (int) floor( ( $extract_index / $extract_total ) * 20 ) );
		} elseif ( 'verify' === $phase ) {
			$percent = 20;
		} elseif ( 'database' === $phase ) {
			$percent = 20 + min( 35, (int) floor( ( $import_offset / $import_total ) * 35 ) );
		} elseif ( 'rewrite' === $phase ) {
			$percent = 60;
		} elseif ( 'files_prepare' === $phase ) {
			$percent = 65;
		} elseif ( 'files' === $phase ) {
			$percent = 65 + min( 30, (int) floor( ( $file_index / $files_total ) * 30 ) );
		} elseif ( 'cleanup' === $phase ) {
			$percent = 98;
		} elseif ( 'complete' === $phase ) {
			$percent = 100;
		} else {
			$percent = 0;
		}

		return array(
			'phase'   => $phase,
			'percent' => $percent,
			'label'   => $this->phase_label( $phase ),
		);
	}

	/**
	 * Restore managed wp-content paths.
	 *
	 * @param string $source_wp_content Extracted wp-content path.
	 * @return array|WP_Error
	 */
	private function restore_files( $source_wp_content ) {
		if ( ! is_dir( $source_wp_content ) ) {
			return new WP_Error( 'wpbp_restore_files_missing', __( 'The package file contents are missing.', 'wp-backup-pilot' ) );
		}

		$sections        = array( 'uploads', 'themes', 'plugins' );
		$restored        = array();
		$preserve_plugin = trailingslashit( WP_CONTENT_DIR ) . 'plugins/wp-backup-pilot';
		$storage_paths   = WPBP_Filesystem::paths();
		$preserve_store  = $storage_paths['base'];
		$preserve_temp   = trailingslashit( sys_get_temp_dir() ) . 'wpbp-preserve-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $preserve_temp ) ) {
			return new WP_Error( 'wpbp_preserve_failed', __( 'Could not create a temporary plugin preservation directory.', 'wp-backup-pilot' ) );
		}

		$preserved_copy  = trailingslashit( $preserve_temp ) . 'wp-backup-pilot';
		$preserved_store = trailingslashit( $preserve_temp ) . 'storage';
		if ( is_dir( $preserve_plugin ) ) {
			WPBP_Filesystem::copy_tree( $preserve_plugin, $preserved_copy );
		}
		if ( is_dir( $preserve_store ) ) {
			WPBP_Filesystem::copy_tree( $preserve_store, $preserved_store );
		}

		foreach ( $sections as $section ) {
			$source = trailingslashit( $source_wp_content ) . $section;
			if ( ! is_dir( $source ) ) {
				continue;
			}

			$target = trailingslashit( WP_CONTENT_DIR ) . $section;
			WPBP_Filesystem::delete_tree( $target );
			wp_mkdir_p( $target );
			WPBP_Filesystem::copy_tree( $source, $target );
			$restored[] = $section;
		}

		if ( is_dir( $preserved_copy ) ) {
			WPBP_Filesystem::copy_tree( $preserved_copy, $preserve_plugin );
		}
		if ( is_dir( $preserved_store ) ) {
			WPBP_Filesystem::copy_tree( $preserved_store, $preserve_store );
		}

		WPBP_Filesystem::delete_tree( $preserve_temp );
		WPBP_Filesystem::ensure_storage();

		return $restored;
	}

	/**
	 * Keep this plugin active after the imported database replaces options.
	 *
	 * @return void
	 */
	private function keep_plugin_active() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( ! in_array( WPBP_PLUGIN_BASENAME, $active, true ) ) {
			$active[] = WPBP_PLUGIN_BASENAME;
			update_option( 'active_plugins', array_values( array_unique( $active ) ) );
		}
	}

	/**
	 * Extract package in chunks.
	 *
	 * @param array $state State.
	 * @return array|WP_Error
	 */
	private function process_extract_chunk( array $state ) {
		$archive = new WPBP_Archive();
		$result  = $archive->extract_chunk( $state['path'], $state['staging'], (int) $state['extract_index'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$state['extract_index'] = (int) $result['next_offset'];
		$state['extract_total'] = (int) $result['total'];
		if ( ! empty( $result['done'] ) ) {
			$state['phase'] = 'verify';
		}

		return $state;
	}

	/**
	 * Verify extracted package checksums.
	 *
	 * @param array $state State.
	 * @return array|WP_Error
	 */
	private function process_verify_chunk( array $state ) {
		$checksums = isset( $state['manifest']['checksums'] ) && is_array( $state['manifest']['checksums'] ) ? $state['manifest']['checksums'] : array();
		if ( ! empty( $state['includes']['database'] ) && ! empty( $checksums['database_sql'] ) ) {
			$sql_file = trailingslashit( $state['staging'] ) . 'database.sql';
			if ( ! is_readable( $sql_file ) || hash_file( 'sha256', $sql_file ) !== $checksums['database_sql'] ) {
				return new WP_Error( 'wpbp_checksum_database_failed', __( 'The database checksum does not match the package manifest.', 'wp-backup-pilot' ) );
			}
		}

		if ( ! empty( $checksums['files'] ) && is_array( $checksums['files'] ) ) {
			foreach ( $checksums['files'] as $relative => $hash ) {
				$file = trailingslashit( $state['staging'] ) . ltrim( $relative, '/' );
				if ( ! is_readable( $file ) || hash_file( 'sha256', $file ) !== $hash ) {
					return new WP_Error( 'wpbp_checksum_file_failed', sprintf( __( 'A package file failed checksum validation: %s', 'wp-backup-pilot' ), $relative ) );
				}
			}
		}

		$state['phase'] = ! empty( $state['includes']['database'] ) ? 'database' : 'rewrite';
		return $state;
	}

	/**
	 * Import database in chunks.
	 *
	 * @param array $state State.
	 * @return array|WP_Error
	 */
	private function process_database_chunk( array $state ) {
		$sql_file = trailingslashit( $state['staging'] ) . 'database.sql';
		$db       = new WPBP_Database();
		$result   = $db->import_chunk( $sql_file, (int) $state['import_offset'], isset( $state['import_statement'] ) ? $state['import_statement'] : '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$state['import_offset']    = (int) $result['next_offset'];
		$state['import_statement'] = $result['statement'];
		$state['import_total']     = (int) $result['total'];
		if ( ! empty( $result['done'] ) ) {
			$state['phase'] = 'rewrite';
		}

		return $state;
	}

	/**
	 * Run URL rewrite phase.
	 *
	 * @param array $state State.
	 * @return array|WP_Error
	 */
	private function process_rewrite_chunk( array $state ) {
		$source_urls = array_filter(
			array_unique(
				array(
					isset( $state['manifest']['home_url'] ) ? untrailingslashit( $state['manifest']['home_url'] ) : '',
					isset( $state['manifest']['site_url'] ) ? untrailingslashit( $state['manifest']['site_url'] ) : '',
				)
			)
		);

		if ( empty( $state['rewrite_urls'] ) || empty( $state['includes']['database'] ) || empty( $source_urls ) ) {
			$this->keep_plugin_active();
			$state['phase'] = ! empty( $state['includes']['files'] ) ? 'files_prepare' : 'cleanup';
			return $state;
		}

		$source_urls  = array_values( $source_urls );
		$source_index = isset( $state['rewrite_source_index'] ) ? (int) $state['rewrite_source_index'] : 0;
		if ( $source_index >= count( $source_urls ) ) {
			$this->keep_plugin_active();
			$state['phase'] = ! empty( $state['includes']['files'] ) ? 'files_prepare' : 'cleanup';
			return $state;
		}

		$source_url = $source_urls[ $source_index ];
		if ( $source_url === $state['destination_url'] ) {
			$state['rewrite_source_index'] = $source_index + 1;
			return $state;
		}

		$replacer = new WPBP_Search_Replace();
		if ( empty( $state['rewrite_state'] ) ) {
			$state['rewrite_state'] = $replacer->prepare( $source_url, $state['destination_url'] );
		}

		$rewrite_state = $replacer->process_chunk( $state['rewrite_state'] );
		if ( is_wp_error( $rewrite_state ) ) {
			return $rewrite_state;
		}

		$state['rewrite_state'] = $rewrite_state;
		if ( ! empty( $rewrite_state['done'] ) ) {
			if ( empty( $state['replace_stats'] ) || ! is_array( $state['replace_stats'] ) ) {
				$state['replace_stats'] = array();
			}
			$state['replace_stats'][]      = $rewrite_state['stats'];
			$state['rewrite_state']        = array();
			$state['rewrite_source_index'] = $source_index + 1;
		}

		return $state;
	}

	/**
	 * Prepare file restore preservation and file list.
	 *
	 * @param array $state State.
	 * @return array|WP_Error
	 */
	private function prepare_file_restore_chunk( array $state ) {
		$source_wp_content = trailingslashit( $state['staging'] ) . 'files/wp-content';
		if ( ! is_dir( $source_wp_content ) ) {
			$state['phase'] = 'cleanup';
			return $state;
		}

		$preserve_temp = trailingslashit( sys_get_temp_dir() ) . 'wpbp-preserve-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $preserve_temp ) ) {
			return new WP_Error( 'wpbp_preserve_failed', __( 'Could not create a temporary plugin preservation directory.', 'wp-backup-pilot' ) );
		}

		$storage_paths   = WPBP_Filesystem::paths();
		$preserve_plugin = trailingslashit( WP_CONTENT_DIR ) . 'plugins/wp-backup-pilot';
		if ( is_dir( $preserve_plugin ) ) {
			WPBP_Filesystem::copy_tree( $preserve_plugin, trailingslashit( $preserve_temp ) . 'wp-backup-pilot' );
		}
		if ( is_dir( $storage_paths['base'] ) ) {
			WPBP_Filesystem::copy_tree( $storage_paths['base'], trailingslashit( $preserve_temp ) . 'storage' );
		}

		$sections = array();
		foreach ( array( 'uploads', 'themes', 'plugins' ) as $section ) {
			if ( is_dir( trailingslashit( $source_wp_content ) . $section ) ) {
				$sections[] = $section;
				WPBP_Filesystem::delete_tree( trailingslashit( WP_CONTENT_DIR ) . $section );
				wp_mkdir_p( trailingslashit( WP_CONTENT_DIR ) . $section );
			}
		}

		$files = $this->build_restore_file_list( $source_wp_content );
		$list  = trailingslashit( $state['staging'] ) . 'restore-file-list.json';
		if ( false === file_put_contents( $list, wp_json_encode( $files ) ) ) {
			return new WP_Error( 'wpbp_restore_file_list_failed', __( 'Could not write restore file list.', 'wp-backup-pilot' ) );
		}

		$state['preserve_temp']     = $preserve_temp;
		$state['restore_file_list'] = $list;
		$state['files_total']       = count( $files );
		$state['file_index']        = 0;
		$state['file_sections']     = $sections;
		$state['phase']             = 'files';

		return $state;
	}

	/**
	 * Restore file batch.
	 *
	 * @param array $state State.
	 * @return array|WP_Error
	 */
	private function process_file_restore_chunk( array $state ) {
		$files = json_decode( file_get_contents( $state['restore_file_list'] ), true );
		if ( ! is_array( $files ) ) {
			return new WP_Error( 'wpbp_restore_file_list_missing', __( 'Restore file list could not be read.', 'wp-backup-pilot' ) );
		}

		$index = (int) $state['file_index'];
		$end   = min( count( $files ), $index + self::RESTORE_FILE_CHUNK_COUNT );
		for ( $i = $index; $i < $end; $i++ ) {
			if ( empty( $files[ $i ]['source'] ) || empty( $files[ $i ]['target'] ) || ! is_readable( $files[ $i ]['source'] ) ) {
				continue;
			}

			wp_mkdir_p( dirname( $files[ $i ]['target'] ) );
			if ( copy( $files[ $i ]['source'], $files[ $i ]['target'] ) ) {
				++$state['files_restored'];
			}
		}

		$state['file_index'] = $end;
		if ( $end >= count( $files ) ) {
			$state['phase'] = 'cleanup';
		}

		return $state;
	}

	/**
	 * Cleanup restore temp files and finish.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function process_cleanup_chunk( array $state ) {
		$storage_paths = WPBP_Filesystem::paths();
		if ( ! empty( $state['preserve_temp'] ) ) {
			if ( is_dir( trailingslashit( $state['preserve_temp'] ) . 'wp-backup-pilot' ) ) {
				WPBP_Filesystem::copy_tree( trailingslashit( $state['preserve_temp'] ) . 'wp-backup-pilot', trailingslashit( WP_CONTENT_DIR ) . 'plugins/wp-backup-pilot' );
			}
			if ( is_dir( trailingslashit( $state['preserve_temp'] ) . 'storage' ) ) {
				WPBP_Filesystem::copy_tree( trailingslashit( $state['preserve_temp'] ) . 'storage', $storage_paths['base'] );
			}
			WPBP_Filesystem::delete_tree( $state['preserve_temp'] );
		}

		WPBP_Filesystem::delete_tree( $state['staging'] );
		WPBP_Filesystem::ensure_storage();
		$state['phase']  = 'complete';
		$state['result'] = array(
			'filename'      => basename( $state['path'] ),
			'safety_backup' => isset( $state['safety_backup']['filename'] ) ? $state['safety_backup']['filename'] : '',
		);

		return $state;
	}

	/**
	 * Build file restore list.
	 *
	 * @param string $source_wp_content Source wp-content.
	 * @return array
	 */
	private function build_restore_file_list( $source_wp_content ) {
		$files    = array();
		$base     = trailingslashit( wp_normalize_path( $source_wp_content ) );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_wp_content, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$relative = ltrim( str_replace( $base, '', wp_normalize_path( $item->getPathname() ) ), '/' );
			$files[]  = array(
				'source' => $item->getPathname(),
				'target' => trailingslashit( WP_CONTENT_DIR ) . $relative,
			);
		}

		return $files;
	}

	/**
	 * Get restore phase label.
	 *
	 * @param string $phase Phase.
	 * @return string
	 */
	private function phase_label( $phase ) {
		$labels = array(
			'queued'        => __( 'Queued', 'wp-backup-pilot' ),
			'extract'       => __( 'Extracting package', 'wp-backup-pilot' ),
			'verify'        => __( 'Verifying package', 'wp-backup-pilot' ),
			'database'      => __( 'Importing database', 'wp-backup-pilot' ),
			'rewrite'       => __( 'Rewriting URLs', 'wp-backup-pilot' ),
			'files_prepare' => __( 'Preparing files', 'wp-backup-pilot' ),
			'files'         => __( 'Restoring files', 'wp-backup-pilot' ),
			'cleanup'       => __( 'Cleaning up', 'wp-backup-pilot' ),
			'complete'      => __( 'Complete', 'wp-backup-pilot' ),
		);

		return isset( $labels[ $phase ] ) ? $labels[ $phase ] : $phase;
	}

	/**
	 * Create a temporary directory outside wp-content.
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 */
	private function external_work_dir( $prefix ) {
		$path = trailingslashit( sys_get_temp_dir() ) . 'wpbp-' . sanitize_key( $prefix ) . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $path ) ) {
			return new WP_Error( 'wpbp_temp_failed', __( 'Could not create an external temporary directory.', 'wp-backup-pilot' ) );
		}

		return $path;
	}
}
