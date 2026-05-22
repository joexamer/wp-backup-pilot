<?php
/**
 * Backup retention.
 *
 * @package BackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPILOT_Retention {
	/**
	 * Apply retention rules.
	 *
	 * @return int Number deleted.
	 */
	public function apply() {
		$settings = BPILOT_Settings::get();
		$paths    = BPILOT_Filesystem::paths();
		$files    = glob( trailingslashit( $paths['backups'] ) . '*.zip' );
		if ( empty( $files ) ) {
			return 0;
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);

		$normal = array();
		$pre    = array();
		foreach ( $files as $file ) {
			$basename = basename( $file );
			if ( 0 === strpos( $basename, 'backup-pilot-pre-restore-' ) || 0 === strpos( $basename, 'wp-backup-pilot-pre-restore-' ) ) {
				$pre[] = $file;
			} else {
				$normal[] = $file;
			}
		}

		return $this->trim_set( $normal, (int) $settings['retention_count'], (int) $settings['retention_days'] )
			+ $this->trim_set( $pre, (int) $settings['pre_restore_count'], (int) $settings['retention_days'] );
	}

	/**
	 * Trim one backup set.
	 *
	 * @param array $files Files.
	 * @param int   $keep Keep count.
	 * @param int   $days Max age in days.
	 * @return int
	 */
	private function trim_set( array $files, $keep, $days ) {
		$deleted = 0;
		$cutoff  = $days > 0 ? time() - ( $days * DAY_IN_SECONDS ) : 0;
		foreach ( $files as $index => $file ) {
			$too_many = $keep > 0 && $index >= $keep;
			$too_old  = $cutoff > 0 && filemtime( $file ) < $cutoff;
			if ( ( 0 === $keep || $too_many || $too_old ) && BPILOT_Filesystem::delete_file( $file ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}
}
