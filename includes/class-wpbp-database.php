<?php
/**
 * Database export/import helpers.
 *
 * @package WPBackupPilot
 */

// phpcs:ignoreFile -- Chunked SQL export/import requires streaming file I/O and direct database access.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPBP_Database {
	const IMPORT_CHUNK_STATEMENTS = 300;

	/**
	 * Write SQL export header.
	 *
	 * @param string $file SQL file path.
	 * @return true|WP_Error
	 */
	public function start_export( $file ) {
		$handle = fopen( $file, 'wb' );
		if ( ! $handle ) {
			return new WP_Error( 'wpbp_sql_write_failed', __( 'Could not write the database export file.', 'backup-pilot' ) );
		}

		fwrite( $handle, "-- Backup Pilot database export\n" );
		fwrite( $handle, '-- Created: ' . gmdate( 'c' ) . "\n" );
		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );
		fclose( $handle );

		return true;
	}

	/**
	 * Export one chunk from a table.
	 *
	 * @param string $file SQL file path.
	 * @param string $table Table name.
	 * @param int    $offset Row offset.
	 * @param int    $limit Row limit.
	 * @return array|WP_Error
	 */
	public function export_table_chunk( $file, $table, $offset, $limit = 500 ) {
		global $wpdb;

		$handle = fopen( $file, 'ab' );
		if ( ! $handle ) {
			return new WP_Error( 'wpbp_sql_write_failed', __( 'Could not write the database export file.', 'backup-pilot' ) );
		}

		if ( 0 === (int) $offset ) {
			$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_N );
			if ( empty( $create[1] ) ) {
				fclose( $handle );
				/* translators: %s: database table name. */
				return new WP_Error( 'wpbp_sql_create_missing', sprintf( __( 'Could not read table structure for %s.', 'backup-pilot' ), $table ) );
			}

			fwrite( $handle, 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . "`;\n" );
			fwrite( $handle, $create[1] . ";\n\n" );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '` LIMIT %d OFFSET %d', $limit, $offset ), ARRAY_A );
		if ( empty( $rows ) ) {
			fclose( $handle );
			return array(
				'rows'        => 0,
				'next_offset' => $offset,
				'done'        => true,
			);
		}

		foreach ( $rows as $row ) {
			$this->write_insert( $handle, $table, $row );
		}

		fwrite( $handle, "\n" );
		fclose( $handle );

		return array(
			'rows'        => count( $rows ),
			'next_offset' => $offset + count( $rows ),
			'done'        => count( $rows ) < $limit,
		);
	}

	/**
	 * Finish SQL export.
	 *
	 * @param string $file SQL file path.
	 * @return true|WP_Error
	 */
	public function finish_export( $file ) {
		$handle = fopen( $file, 'ab' );
		if ( ! $handle ) {
			return new WP_Error( 'wpbp_sql_write_failed', __( 'Could not write the database export file.', 'backup-pilot' ) );
		}

		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $handle );

		return true;
	}

	/**
	 * Export prefixed WordPress tables to SQL.
	 *
	 * @param string $file SQL file path.
	 * @return array|WP_Error
	 */
	public function export( $file ) {
		global $wpdb;

		$tables = $this->get_tables();
		if ( empty( $tables ) ) {
			return new WP_Error( 'wpbp_no_tables', __( 'No WordPress database tables were found to export.', 'backup-pilot' ) );
		}

		$handle = fopen( $file, 'wb' );
		if ( ! $handle ) {
			return new WP_Error( 'wpbp_sql_write_failed', __( 'Could not write the database export file.', 'backup-pilot' ) );
		}

		fwrite( $handle, "-- Backup Pilot database export\n" );
		fwrite( $handle, '-- Created: ' . gmdate( 'c' ) . "\n" );
		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

		foreach ( $tables as $table ) {
			$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_N );
			if ( empty( $create[1] ) ) {
				continue;
			}

			fwrite( $handle, 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . "`;\n" );
			fwrite( $handle, $create[1] . ";\n\n" );

			$offset = 0;
			$limit  = 500;

			do {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '` LIMIT %d OFFSET %d', $limit, $offset ), ARRAY_A );
				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$this->write_insert( $handle, $table, $row );
				}

				fwrite( $handle, "\n" );
				$offset += $limit;
			} while ( count( $rows ) === $limit );
		}

		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $handle );

		return array(
			'tables' => $tables,
			'count'  => count( $tables ),
			'size'   => filesize( $file ),
		);
	}

	/**
	 * Import a SQL file.
	 *
	 * @param string $file SQL file path.
	 * @return true|WP_Error
	 */
	public function import( $file ) {
		global $wpdb;

		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'wpbp_sql_unreadable', __( 'The database file is not readable.', 'backup-pilot' ) );
		}

		$handle = fopen( $file, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'wpbp_sql_open_failed', __( 'Could not open the database file.', 'backup-pilot' ) );
		}

		$statement = '';

		while ( ( $line = fgets( $handle ) ) !== false ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) ) {
				continue;
			}

			$statement .= $line;

			if ( ';' === substr( rtrim( $line ), -1 ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Executing trusted SQL from a validated backup package.
				$result = $wpdb->query( $statement );
				if ( false === $result ) {
					fclose( $handle );
					/* translators: %s: excerpt from the failing SQL statement. */
					return new WP_Error( 'wpbp_sql_query_failed', sprintf( __( 'Database import failed near: %s', 'backup-pilot' ), substr( trim( $statement ), 0, 120 ) ) );
				}
				$statement = '';
			}
		}

		fclose( $handle );

		return true;
	}

	/**
	 * Import a SQL file batch.
	 *
	 * @param string $file SQL file path.
	 * @param int    $offset Byte offset.
	 * @param string $statement Partial statement.
	 * @param int    $limit Statement limit.
	 * @return array|WP_Error
	 */
	public function import_chunk( $file, $offset = 0, $statement = '', $limit = self::IMPORT_CHUNK_STATEMENTS ) {
		global $wpdb;

		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'wpbp_sql_unreadable', __( 'The database file is not readable.', 'backup-pilot' ) );
		}

		$handle = fopen( $file, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'wpbp_sql_open_failed', __( 'Could not open the database file.', 'backup-pilot' ) );
		}

		fseek( $handle, max( 0, (int) $offset ) );
		$executed = 0;

		while ( ! feof( $handle ) && $executed < $limit ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}

			$trimmed = trim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) ) {
				continue;
			}

			$statement .= $line;
			if ( ';' === substr( rtrim( $line ), -1 ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Executing trusted SQL from a validated backup package.
				$result = $wpdb->query( $statement );
				if ( false === $result ) {
					fclose( $handle );
					/* translators: %s: excerpt from the failing SQL statement. */
					return new WP_Error( 'wpbp_sql_query_failed', sprintf( __( 'Database import failed near: %s', 'backup-pilot' ), substr( trim( $statement ), 0, 120 ) ) );
				}
				$statement = '';
				++$executed;
			}
		}

		$next_offset = ftell( $handle );
		$done        = feof( $handle ) && '' === trim( $statement );
		fclose( $handle );

		return array(
			'statements'  => $executed,
			'next_offset' => $next_offset,
			'statement'   => $statement,
			'done'        => $done,
			'total'       => filesize( $file ),
		);
	}

	/**
	 * Get active WordPress tables by prefix.
	 *
	 * @return array
	 */
	public function get_tables() {
		global $wpdb;

		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return array_values( array_filter( array_map( 'strval', (array) $tables ) ) );
	}

	/**
	 * Write one INSERT statement.
	 *
	 * @param resource $handle File handle.
	 * @param string   $table Table name.
	 * @param array    $row Row data.
	 * @return void
	 */
	private function write_insert( $handle, $table, array $row ) {
		$columns = array_map(
			static function ( $column ) {
				return '`' . str_replace( '`', '``', $column ) . '`';
			},
			array_keys( $row )
		);

		$values = array_map(
			static function ( $value ) {
				if ( null === $value ) {
					return 'NULL';
				}

				return "'" . esc_sql( $value ) . "'";
			},
			array_values( $row )
		);

		fwrite( $handle, 'INSERT INTO `' . str_replace( '`', '``', $table ) . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ");\n" );
	}
}
