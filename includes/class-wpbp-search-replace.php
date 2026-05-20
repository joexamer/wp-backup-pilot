<?php
/**
 * Serialized-safe database search and replace.
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- URL replacement must update WordPress tables directly.

class WPBP_Search_Replace {
	const CHUNK_ROWS = 200;

	/**
	 * Prepare a chunked search/replace state.
	 *
	 * @param string $search Search value.
	 * @param string $replace Replacement value.
	 * @return array
	 */
	public function prepare( $search, $replace ) {
		$database = new WPBP_Database();

		return array(
			'search'      => $search,
			'replace'     => $replace,
			'tables'      => $database->get_tables(),
			'table_index' => 0,
			'offset'      => 0,
			'stats'       => array(
				'tables'  => 0,
				'rows'    => 0,
				'changes' => 0,
			),
			'done'        => '' === $search || $search === $replace,
		);
	}

	/**
	 * Process one search/replace chunk.
	 *
	 * @param array $state Search/replace state.
	 * @return array|WP_Error
	 */
	public function process_chunk( array $state ) {
		global $wpdb;

		if ( ! empty( $state['done'] ) ) {
			return $state;
		}

		$tables = isset( $state['tables'] ) ? (array) $state['tables'] : array();
		$index  = isset( $state['table_index'] ) ? (int) $state['table_index'] : 0;
		if ( $index >= count( $tables ) ) {
			$state['done'] = true;
			return $state;
		}

		$table   = $tables[ $index ];
		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', ARRAY_A );
		if ( empty( $columns ) ) {
			++$state['table_index'];
			$state['offset'] = 0;
			return $state;
		}

		$primary      = $this->primary_key( $columns );
		$text_columns = $this->text_columns( $columns );
		if ( empty( $primary ) || empty( $text_columns ) ) {
			++$state['table_index'];
			$state['offset'] = 0;
			return $state;
		}

		$offset = isset( $state['offset'] ) ? (int) $state['offset'] : 0;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '` LIMIT %d OFFSET %d', self::CHUNK_ROWS, $offset ), ARRAY_A );
		if ( empty( $rows ) ) {
			++$state['table_index'];
			$state['offset'] = 0;
			++$state['stats']['tables'];
			return $state;
		}

		foreach ( $rows as $row ) {
			$updates = $this->updates_for_row( $row, $text_columns, $state['search'], $state['replace'] );
			if ( ! empty( $updates ) ) {
				$wpdb->update( $table, $updates, array( $primary => $row[ $primary ] ) );
				$state['stats']['changes'] += count( $updates );
			}
			++$state['stats']['rows'];
		}

		$state['offset'] = $offset + count( $rows );
		if ( count( $rows ) < self::CHUNK_ROWS ) {
			++$state['table_index'];
			$state['offset'] = 0;
			++$state['stats']['tables'];
		}

		return $state;
	}

	/**
	 * Replace values across WordPress tables.
	 *
	 * @param string $search Search value.
	 * @param string $replace Replacement value.
	 * @return array|WP_Error
	 */
	public function run( $search, $replace ) {
		global $wpdb;

		if ( '' === $search || $search === $replace ) {
			return array(
				'tables'  => 0,
				'rows'    => 0,
				'changes' => 0,
			);
		}

		$database = new WPBP_Database();
		$tables   = $database->get_tables();
		$stats    = array(
			'tables'  => 0,
			'rows'    => 0,
			'changes' => 0,
		);

		foreach ( $tables as $table ) {
			$columns = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', ARRAY_A );
			if ( empty( $columns ) ) {
				continue;
			}

			$primary = $this->primary_key( $columns );
			if ( empty( $primary ) ) {
				continue;
			}

			$text_columns = $this->text_columns( $columns );
			if ( empty( $text_columns ) ) {
				continue;
			}

			$offset = 0;
			$limit  = 200;

			do {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '` LIMIT %d OFFSET %d', $limit, $offset ), ARRAY_A );
				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$updates = $this->updates_for_row( $row, $text_columns, $search, $replace );
					if ( ! empty( $updates ) ) {
						$where = array( $primary => $row[ $primary ] );
						$wpdb->update( $table, $updates, $where );
						$stats['changes'] += count( $updates );
					}

					++$stats['rows'];
				}

				$offset += $limit;
			} while ( count( $rows ) === $limit );

			++$stats['tables'];
		}

		return $stats;
	}

	/**
	 * Replace inside a value while preserving serialization.
	 *
	 * @param mixed  $value Original value.
	 * @param string $search Search.
	 * @param string $replace Replace.
	 * @return mixed
	 */
	private function replace_value( $value, $search, $replace ) {
		if ( is_serialized( $value ) ) {
			$unserialized = maybe_unserialize( $value );
			$replaced     = $this->recursive_replace( $unserialized, $search, $replace );
			return maybe_serialize( $replaced );
		}

		return str_replace( $search, $replace, $value );
	}

	/**
	 * Build updates for a row.
	 *
	 * @param array  $row Row.
	 * @param array  $text_columns Text columns.
	 * @param string $search Search.
	 * @param string $replace Replace.
	 * @return array
	 */
	private function updates_for_row( array $row, array $text_columns, $search, $replace ) {
		$updates = array();
		foreach ( $text_columns as $column ) {
			if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] || false === strpos( (string) $row[ $column ], $search ) ) {
				continue;
			}

			$new_value = $this->replace_value( $row[ $column ], $search, $replace );
			if ( $new_value !== $row[ $column ] ) {
				$updates[ $column ] = $new_value;
			}
		}

		return $updates;
	}

	/**
	 * Recursively replace strings.
	 *
	 * @param mixed  $value Value.
	 * @param string $search Search.
	 * @param string $replace Replace.
	 * @return mixed
	 */
	private function recursive_replace( $value, $search, $replace ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->recursive_replace( $item, $search, $replace );
			}
			return $value;
		}

		if ( is_object( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value->{$key} = $this->recursive_replace( $item, $search, $replace );
			}
			return $value;
		}

		if ( is_string( $value ) ) {
			return str_replace( $search, $replace, $value );
		}

		return $value;
	}

	/**
	 * Find primary key column.
	 *
	 * @param array $columns Columns.
	 * @return string
	 */
	private function primary_key( array $columns ) {
		foreach ( $columns as $column ) {
			if ( isset( $column['Key'] ) && 'PRI' === $column['Key'] ) {
				return $column['Field'];
			}
		}

		return '';
	}

	/**
	 * Get text-like columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	private function text_columns( array $columns ) {
		$text = array();
		foreach ( $columns as $column ) {
			$type = strtolower( $column['Type'] );
			if ( preg_match( '/char|text|blob|json|enum|set/', $type ) ) {
				$text[] = $column['Field'];
			}
		}

		return $text;
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery
