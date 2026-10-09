<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

final class DryRunMemoryWpdb {
	public string $prefix     = 'wp_';
	public string $options    = 'wp_options';
	public int $insert_id     = 0;
	public int $rows_affected = 0;
	public string $last_error = '';
	/** @var list<string> */
	public array $queries = array();
	/** @var array<string,list<array<string,mixed>>>|null */
	private ?array $transaction_snapshot        = null;
	public string $fail_operation               = '';
	public string $fail_query_contains          = '';
	public string $fail_read_contains           = '';
	public int $fail_read_after                 = -1;
	private int $matching_read_calls            = 0;
	public int $fail_replace_after              = -1;
	public int $fail_import_action_insert_after = -1;
	private int $import_action_insert_calls     = 0;
	public int $fail_import_event_insert_after  = -1;
	public int $import_event_insert_calls       = 0;
	public string $fail_import_event_type       = '';
	public string $fail_insert_table_contains   = '';
	public string $fail_update_table_contains   = '';
	public string $throw_update_table_contains  = '';
	public string $fail_release_table_contains = '';
	private int $replace_calls                  = 0;
	/** @var callable|null */
	public $after_lock_insert = null;
	/** @var callable|null */
	public $before_mapping_lock = null;
	/** @var callable|null */
	public $after_heartbeat_update = null;
	/** @var list<int> */
	public array $heartbeat_update_results = array();
	/** @var callable|null */
	public $after_execution_refresh = null;
	/** @var list<int> */
	public array $execution_refresh_results = array();
	/** @var array<string,list<array<string,mixed>>> */
	public array $tables = array();

	public function __construct() {
		$this->tables = array(
			'wp_ideaxperts_ea_dry_runs'           => array(),
			'wp_ideaxperts_ea_dry_run_items'      => array(),
			'wp_ideaxperts_ea_store_identifiers'  => array(),
			'wp_ideaxperts_ea_dry_run_actions'    => array(),
			'wp_ideaxperts_ea_mappings'           => array(),
			'wp_ideaxperts_ea_import_runs'        => array(),
			'wp_ideaxperts_ea_import_items'       => array(),
			'wp_ideaxperts_ea_catalog_identities' => array(),
			'wp_ideaxperts_ea_store_identifier_reservations' => array(),
			'wp_ideaxperts_ea_import_actions'     => array(),
			'wp_ideaxperts_ea_import_events'      => array(),
			'wp_ideaxperts_ea_vendor_snapshots'   => array(),
			'wp_options'                          => array(),
		);
	}

	/** @param array<string,mixed> $data @param list<string>|null $formats */
	public function insert( string $table, array $data, ?array $formats = null ): int|false {
		if ( 'insert' === $this->fail_operation || ( '' !== $this->fail_insert_table_contains && str_contains( $table, $this->fail_insert_table_contains ) ) ) {
			$this->fail_insert_table_contains = '';
			return false;
		}
		if ( str_contains( $table, 'import_actions' ) ) {
			++$this->import_action_insert_calls;
			if ( $this->fail_import_action_insert_after >= 0 && $this->import_action_insert_calls > $this->fail_import_action_insert_after ) {
				return false;
			}
		}
		if ( str_contains( $table, 'import_events' ) ) {
			++$this->import_event_insert_calls;
			if ( '' !== $this->fail_import_event_type && (string) ( $data['event_type'] ?? '' ) === $this->fail_import_event_type ) {
				$this->fail_import_event_type = '';
				return false;
			}
			if ( $this->fail_import_event_insert_after >= 0 && $this->import_event_insert_calls > $this->fail_import_event_insert_after ) {
				return false;
			}
		}
		if ( str_contains( $table, 'dry_run_actions' ) ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if ( (int) $row['run_id'] === (int) $data['run_id'] &&
					(int) $row['claim_generation'] === (int) $data['claim_generation'] &&
					(string) $row['action_type'] === (string) $data['action_type'] &&
					(int) $row['page_number'] === (int) $data['page_number'] ) {
					$this->last_error = 'Duplicate entry';
					return false;
				}
			}
		}
		$unique_sets = array();
		if ( str_contains( $table, 'ideaxperts_ea_mappings' ) ) {
			$unique_sets = array( array( 'source_scope', 'ea_product_id', 'ea_option_id' ), array( 'wc_product_id', 'wc_variation_id' ) );
		} elseif ( str_contains( $table, 'import_runs' ) ) {
			$unique_sets = array( array( 'dry_run_id', 'approval_generation' ) );
		} elseif ( str_contains( $table, 'import_items' ) ) {
			$unique_sets = array( array( 'import_run_id', 'entity_kind', 'ea_product_id', 'ea_option_id' ), array( 'import_run_id', 'dry_run_item_id' ), array( 'operation_uuid' ) );
		} elseif ( str_contains( $table, 'catalog_identities' ) ) {
			$unique_sets = array( array( 'identity_key' ), array( 'source_scope', 'entity_kind', 'ea_product_id', 'ea_option_id' ), array( 'operation_uuid' ), array( 'wc_identity_key' ) );
		} elseif ( str_contains( $table, 'store_identifier_reservations' ) ) {
			$unique_sets = array( array( 'identifier_key' ), array( 'namespace', 'identifier_type', 'normalized_identifier' ) );
		} elseif ( str_contains( $table, 'import_actions' ) ) {
			$unique_sets = array( array( 'logical_key' ) );
		} elseif ( str_contains( $table, 'vendor_snapshots' ) ) {
			$unique_sets = array( array( 'source_scope', 'identity_key', 'payload_hash' ) );
		}
		foreach ( $this->tables[ $table ] ?? array() as $row ) {
			foreach ( $unique_sets as $keys ) {
				// MySQL UNIQUE indexes permit multiple NULL values.
				if ( array( 'wc_identity_key' ) === $keys && ( null === ( $row['wc_identity_key'] ?? null ) || null === ( $data['wc_identity_key'] ?? null ) ) ) {
					continue;
				}
				$same = true;
				foreach ( $keys as $key ) {
					if ( (string) ( $row[ $key ] ?? '' ) !== (string) ( $data[ $key ] ?? '' ) ) {
						$same = false;
						break;
					}
				}
				if ( $same ) {
					$this->last_error = 'Duplicate entry';
					return false;
				}
			}
		}
		++$this->insert_id;
		$data['id']               = $this->insert_id;
		$this->tables[ $table ][] = $data;
		$this->rows_affected      = 1;
		return 1;
	}

	/** @param array<string,mixed> $data */
	public function replace( string $table, array $data ): int|false {
		++$this->replace_calls;
		if ( 'replace' === $this->fail_operation || ( $this->fail_replace_after >= 0 && $this->replace_calls > $this->fail_replace_after ) ) {
			return false;
		}
		$keys = str_contains( $table, 'dry_run_items' )
			? array( 'run_id', 'ea_product_id', 'ea_option_id' )
			: array( 'run_id', 'wc_product_id', 'wc_variation_id', 'identifier_type', 'identifier_source' );
		foreach ( $this->tables[ $table ] as $index => $row ) {
			$same = true;
			foreach ( $keys as $key ) {
				if ( (string) ( $row[ $key ] ?? '' ) !== (string) ( $data[ $key ] ?? '' ) ) {
					$same = false;
					break;
				}
			}
			if ( $same ) {
				$data['id']                       = $row['id'];
				$this->tables[ $table ][ $index ] = $data;
				return 1;
			}
		}
		return $this->insert( $table, $data );
	}

	/** @param array<string,mixed> $data @param array<string,mixed> $where */
	public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
		if ( '' !== $this->throw_update_table_contains && str_contains( $table, $this->throw_update_table_contains ) ) {
			$this->throw_update_table_contains = '';
			throw new \RuntimeException( 'Injected update exception.' );
		}
		if ( 'released' === ( $data['reservation_status'] ?? '' ) && '' !== $this->fail_release_table_contains && str_contains( $table, $this->fail_release_table_contains ) ) {
			$this->fail_release_table_contains = '';
			return false;
		}
		if ( 'update' === $this->fail_operation || ( '' !== $this->fail_update_table_contains && str_contains( $table, $this->fail_update_table_contains ) ) ) {
			$this->fail_update_table_contains = '';
			return false;
		}
		if ( '' !== $this->fail_query_contains && 'action_scheduler_id' === $this->fail_query_contains && array_key_exists( 'action_scheduler_id', $data ) ) {
			$this->last_error = 'Injected query failure.';
			return false;
		}
		$count = 0;
		foreach ( $this->tables[ $table ] as $index => $row ) {
			if ( ! $this->matches_array( $row, $where ) ) {
				continue;
			}
			if ( str_contains( $table, 'catalog_identities' ) && null !== ( $data['wc_identity_key'] ?? null ) ) {
				foreach ( $this->tables[ $table ] as $other ) {
					if ( (int) $other['id'] !== (int) $row['id'] && ( $other['wc_identity_key'] ?? null ) === $data['wc_identity_key'] ) {
						$this->last_error = 'Duplicate entry';
						return false;
					}
				}
			}
			if ( str_contains( $table, 'dry_run_actions' ) && 'running' === ( $data['status'] ?? '' ) && array_intersect_key( $row, $data ) == $data ) {
				continue;
			}
			$this->tables[ $table ][ $index ] = array_merge( $row, $data );
			++$count;
		}
		$this->rows_affected = $count;
		return $count;
	}

	/** @param array<string,mixed> $where */
	public function delete( string $table, array $where, mixed $where_format = null ): int|false {
		if ( 'delete' === $this->fail_operation ) {
			return false;
		}
		$kept  = array();
		$count = 0;
		foreach ( $this->tables[ $table ] as $row ) {
			if ( $this->matches_array( $row, $where ) ) {
				++$count;
				continue;
			}
			$kept[] = $row;
		}
		$this->tables[ $table ] = $kept;
		$this->rows_affected    = $count;
		return $count;
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace_callback(
				'/%[sd]/',
				static function ( array $placeholder ) use ( $arg ): string {
					if ( '%d' === $placeholder[0] ) {
						return (string) (int) $arg;
					}
					return null === $arg ? 'NULL' : "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $arg ) . "'";
				},
				$query,
				1
			) ?? $query;
		}
		return $query;
	}

	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	public function query( string $sql ): int|false {
		$sql              = trim( $sql );
		$this->queries[]  = $sql;
		$this->last_error = '';
		if ( '' !== $this->fail_query_contains && str_contains( $sql, $this->fail_query_contains ) ) {
			$this->last_error = 'Injected query failure.';
			return false;
		}
		if ( 'START TRANSACTION' === $sql ) {
			if ( 'start' === $this->fail_operation ) {
				return false;
			}
			$this->transaction_snapshot = $this->tables;
			return 0;
		}
		if ( 'COMMIT' === $sql ) {
			if ( 'commit' === $this->fail_operation ) {
				return false;
			}
			$this->transaction_snapshot = null;
			return 0;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( 'rollback' === $this->fail_operation ) {
				return false;
			}
			if ( null !== $this->transaction_snapshot ) {
				$this->tables = $this->transaction_snapshot;
			}
			$this->transaction_snapshot = null;
			return 0;
		}
		if ( 1 === preg_match( '/^INSERT INTO (\S+) \(option_name, option_value, autoload\) VALUES \((NULL|\'(?:\\\\\'|[^\'])*\'), (NULL|\'(?:\\\\\'|[^\'])*\'), (NULL|\'(?:\\\\\'|[^\'])*\')\)$/', $sql, $insert ) ) {
			$name  = trim( $insert[2], "'" );
			$value = stripslashes( trim( $insert[3], "'" ) );
			foreach ( $this->tables[ $insert[1] ] as $row ) {
				if ( (string) ( $row['option_name'] ?? '' ) === $name ) {
					$this->rows_affected = 0;
					return 0;
				}
			}
			$this->tables[ $insert[1] ][] = array(
				'id'           => count( $this->tables[ $insert[1] ] ) + 1,
				'option_name'  => $name,
				'option_value' => $value,
				'autoload'     => trim( $insert[4], "'" ),
			);
			$this->rows_affected          = 1;
			if ( is_callable( $this->after_lock_insert ) ) {
				( $this->after_lock_insert )();
			}
			return 1;
		}
		if ( 1 === preg_match( '/^UPDATE (\S+) SET option_value = (NULL|\'(?:\\\\\'|[^\'])*\') WHERE option_name = (NULL|\'(?:\\\\\'|[^\'])*\') AND option_value = (NULL|\'(?:\\\\\'|[^\'])*\')$/', $sql, $update ) ) {
			$count = 0;
			foreach ( $this->tables[ $update[1] ] as $index => $row ) {
				if ( (string) ( $row['option_name'] ?? '' ) === trim( $update[3], "'" ) && (string) ( $row['option_value'] ?? '' ) === stripslashes( trim( $update[4], "'" ) ) ) {
					$this->tables[ $update[1] ][ $index ]['option_value'] = stripslashes( trim( $update[2], "'" ) );
					++$count;
				}
			}
			$this->rows_affected = $count;
			return $count;
		}
		if ( 1 === preg_match( '/^DELETE FROM (\S+) WHERE option_name = (NULL|\'(?:\\\\\'|[^\'])*\') AND option_value = (NULL|\'(?:\\\\\'|[^\'])*\')$/', $sql, $delete ) ) {
			$kept  = array();
			$count = 0;
			foreach ( $this->tables[ $delete[1] ] as $row ) {
				if ( (string) ( $row['option_name'] ?? '' ) === trim( $delete[2], "'" ) && (string) ( $row['option_value'] ?? '' ) === stripslashes( trim( $delete[3], "'" ) ) ) {
					++$count;
					continue;
				}
				$kept[] = $row;
			}
			$this->tables[ $delete[1] ] = $kept;
			$this->rows_affected        = $count;
			return $count;
		}
		if ( str_contains( $sql, 'INNER JOIN' ) && 1 === preg_match( '/SET i\\.review_flags = (NULL|\'(?:\\\\\'|[^\'])*\'), i\\.updated_at = (NULL|\'(?:\\\\\'|[^\'])*\') WHERE i\\.id = (\d+)/', $sql, $join ) ) {
			$item_id = (int) $join[3];
			$count   = 0;
			foreach ( $this->tables['wp_ideaxperts_ea_dry_run_items'] as $index => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) !== $item_id ) {
					continue;
				}
				$run = $this->run_row( (int) ( $row['run_id'] ?? 0 ) );
				if ( ! $run || ! in_array( (string) $run['status'], array( 'pending', 'scanning_store', 'fetching_catalog' ), true ) ) {
					continue;
				}
				$this->tables['wp_ideaxperts_ea_dry_run_items'][ $index ]['review_flags'] = trim( $join[1], "'" );
				$this->tables['wp_ideaxperts_ea_dry_run_items'][ $index ]['updated_at']   = trim( $join[2], "'" );
				++$count;
			}
			$this->rows_affected = $count;
			return $count;
		}
		if ( 1 === preg_match( '/^UPDATE (\S+) SET (.+) WHERE (.+)$/', $sql, $matches ) ) {
			$data  = $this->parse_set( $matches[2] );
			$count = 0;
			$is_heartbeat = str_starts_with( $matches[2], 'last_heartbeat_at = ' );
			$is_refresh = str_contains( $matches[1], 'ideaxperts_ea_dry_run_actions' ) && str_starts_with( $matches[2], 'lease_expires_at = ' );
			foreach ( $this->tables[ $matches[1] ] as $index => $row ) {
				if ( ! $this->matches_sql( $row, $matches[3] ) ) {
					continue;
				}
				if ( ( $is_heartbeat || $is_refresh ) && array_intersect_key( $row, $data ) == $data ) {
					continue;
				}
				$this->tables[ $matches[1] ][ $index ] = array_merge( $row, $data );
				++$count;
			}
			$this->rows_affected = $count;
			if ( $is_heartbeat ) {
				$this->heartbeat_update_results[] = $count;
				if ( is_callable( $this->after_heartbeat_update ) ) {
					$callback = $this->after_heartbeat_update;
					$this->after_heartbeat_update = null;
					$callback();
				}
			}
			if ( $is_refresh ) {
				$this->execution_refresh_results[] = $count;
				if ( is_callable( $this->after_execution_refresh ) ) {
					$callback = $this->after_execution_refresh;
					$this->after_execution_refresh = null;
					$callback();
				}
			}
			return $count;
		}
		return 0;
	}

	/** @return array<string,mixed>|null */
	private function run_row( int $run_id ): ?array {
		foreach ( $this->tables['wp_ideaxperts_ea_dry_runs'] as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $run_id ) {
				return $row;
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null|string|int */
	public function get_var( string $sql ): mixed {
		$this->last_error = '';
		if ( $this->should_fail_read( $sql ) ) {
			$this->last_error = 'Injected read failure.';
			return null;
		}
		if ( str_contains( $sql, 'ideaxperts_ea_store_identifiers' ) && ( str_contains( $sql, 'COUNT(DISTINCT' ) || str_contains( $sql, 'duplicate_upcs' ) || str_contains( $sql, 'conflicting_owners' ) ) ) {
			preg_match( '/run_id = (\d+)/', $sql, $match );
			$rows = array_filter( $this->tables['wp_ideaxperts_ea_store_identifiers'], static fn( array $row ): bool => (int) $row['run_id'] === (int) $match[1] && 'upc' === $row['identifier_type'] );
			$owners = array();
			$upcs = array();
			foreach ( $rows as $row ) {
				$owner = $row['wc_product_id'] . ':' . $row['wc_variation_id'];
				$owners[ $owner ][ $row['normalized_identifier'] ] = true;
				$upcs[ $row['normalized_identifier'] ][ $owner ] = true;
			}
			if ( str_contains( $sql, 'duplicate_upcs' ) ) {
				return count( array_filter( $upcs, static fn( array $values ): bool => count( $values ) > 1 ) );
			}
			if ( str_contains( $sql, 'conflicting_owners' ) ) {
				return count( array_filter( $owners, static fn( array $values ): bool => count( $values ) > 1 ) );
			}
			return count( $owners );
		}
		if ( str_contains( $sql, 'COUNT(*)' ) ) {
			return count( $this->select_rows( $sql ) );
		}
		$rows = $this->select_rows( $sql );
		if ( ! $rows ) {
			return null;
		}
		$first = $rows[0];
		if ( 1 === preg_match( '/^SELECT (\w+) FROM/', $sql, $column ) && isset( $first[ $column[1] ] ) ) {
			return $first[ $column[1] ];
		}
		return $first['id'] ?? reset( $first );
	}

	/** @return array<string,mixed>|null */
	public function get_row( string $sql, mixed $output = null ): ?array {
		$this->queries[] = $sql;
		$this->last_error = '';
		if ( $this->should_fail_read( $sql ) ) {
			$this->last_error = 'Injected read failure.';
			return null;
		}
		$rows = $this->select_rows( $sql );
		if ( str_contains( $sql, 'AS review_required' ) ) {
			$review = 0;
			$below = 0;
			foreach ( $rows as $row ) {
				$review += 'manual_review' === ( $row['classification'] ?? '' ) || ! in_array( $row['review_flags'] ?? '', array( '[]', '' ), true ) ? 1 : 0;
				$retail = (string) ( $row['retail_price'] ?? '' );
				$map = (string) ( $row['map_price'] ?? '' );
				$below += is_numeric( $retail ) && is_numeric( $map ) && (float) $retail < (float) $map ? 1 : 0;
			}
			return array( 'review_required' => $review, 'retail_below_map' => $below );
		}
		return $rows[0] ?? null;
	}

	/** @return list<array<string,mixed>> */
	public function get_results( string $sql, mixed $output = null ): array {
		if ( str_contains( $sql, 'information_schema.' ) ) {
			$rows = array();
			foreach ( \IdeaXperts\EndlessAisles\Database\Schema::table_names( $this->prefix ) as $table ) {
				if ( str_contains( $sql, 'information_schema.TABLES' ) ) {
					$rows[] = array( 'TABLE_NAME' => $table, 'ENGINE' => 'InnoDB' );
				} elseif ( str_contains( $sql, 'information_schema.COLUMNS' ) ) {
					foreach ( \IdeaXperts\EndlessAisles\Database\Schema::required_columns( $this->prefix )[ $table ] as $column ) {
						$rows[] = array( 'TABLE_NAME' => $table, 'COLUMN_NAME' => $column );
					}
				} else {
					foreach ( \IdeaXperts\EndlessAisles\Database\Schema::required_indexes( $this->prefix )[ $table ] ?? array() as $index => $definition ) {
						foreach ( $definition['columns'] as $offset => $column ) {
							$rows[] = array( 'TABLE_NAME' => $table, 'INDEX_NAME' => $index, 'NON_UNIQUE' => $definition['unique'] ? 0 : 1, 'SEQ_IN_INDEX' => $offset + 1, 'COLUMN_NAME' => $column );
						}
					}
				}
			}
			return $rows;
		}
		if ( str_contains( $sql, 'ideaxperts_ea_mappings' ) && str_contains( $sql, 'FOR UPDATE' ) && is_callable( $this->before_mapping_lock ) ) {
			$callback = $this->before_mapping_lock;
			$this->before_mapping_lock = null;
			$callback();
		}
		$this->last_error = '';
		if ( $this->should_fail_read( $sql ) ) {
			$this->last_error = 'Injected read failure.';
			return array();
		}
		$this->queries[] = $sql;
		$rows            = $this->select_rows( $sql );
		if ( str_contains( $sql, 'GROUP BY classification' ) ) {
			$counts = array();
			foreach ( $rows as $row ) {
				$key            = (string) $row['classification'];
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
			$grouped = array();
			foreach ( $counts as $classification => $total ) {
				$grouped[] = array(
					'classification' => $classification,
					'total'          => $total,
				);
			}
			return $grouped;
		}
		if ( str_contains( $sql, 'GROUP BY identifier_source' ) ) {
			$counts = array();
			foreach ( $rows as $row ) {
				$key            = (string) $row['identifier_source'];
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
			$grouped = array();
			foreach ( $counts as $source => $total ) {
				$grouped[] = array(
					'identifier_source' => $source,
					'total'             => $total,
				);
			}
			return $grouped;
		}
		return $rows;
	}

	private function should_fail_read( string $sql ): bool {
		if ( '' === $this->fail_read_contains || ! str_contains( $sql, $this->fail_read_contains ) ) {
			return false;
		}
		++$this->matching_read_calls;
		return $this->fail_read_after < 0 || $this->matching_read_calls > $this->fail_read_after;
	}

	/** @return list<array<string,mixed>> */
	private function select_rows( string $sql ): array {
		$sql = preg_replace( '/\s+FOR UPDATE$/', '', trim( $sql ) ) ?? $sql;
		if ( str_contains( $sql, 'COALESCE(r.completed_at,r.updated_at)' ) ) {
			return $this->purge_rows( $sql );
		}
		if ( str_contains( $sql, 'INNER JOIN' ) ) {
			return $this->select_joined_items( $sql );
		}
		if ( 1 !== preg_match( '/FROM (\S+)/', $sql, $from ) ) {
			return array();
		}
		$rows = $this->tables[ $from[1] ] ?? array();
		if ( 1 === preg_match( '/WHERE (.+?)(?: GROUP BY| ORDER BY| LIMIT|$)/', $sql, $where ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					fn( array $row ): bool => $this->matches_sql( $row, $where[1] )
				)
			);
		}
		if ( 1 === preg_match( '/ORDER BY (lease_expires_at|dispatch_lease_expires_at) ASC, id ASC/', $sql, $order ) ) {
			$column = $order[1];
			usort(
				$rows,
				static fn( array $left, array $right ): int => array( (string) $left[ $column ], (int) $left['id'] ) <=> array( (string) $right[ $column ], (int) $right['id'] )
			);
		} elseif ( 1 === preg_match( '/ORDER BY id (ASC|DESC)/', $sql, $order ) ) {
			usort(
				$rows,
				static function ( array $left, array $right ) use ( $order ): int {
					return 'DESC' === $order[1] ? (int) $right['id'] <=> (int) $left['id'] : (int) $left['id'] <=> (int) $right['id'];
				}
			);
		}
		$offset = 0;
		$limit  = null;
		if ( 1 === preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $sql, $limit_match ) ) {
			$limit  = (int) $limit_match[1];
			$offset = (int) $limit_match[2];
		} elseif ( 1 === preg_match( '/LIMIT (\d+)/', $sql, $limit_match ) ) {
			$limit = (int) $limit_match[1];
		}
		if ( null !== $limit ) {
			$rows = array_slice( $rows, $offset, $limit );
		}
		return $rows;
	}

	/** @return list<array<string,mixed>> */
	private function purge_rows( string $sql ): array {
		$rows   = $this->tables['wp_ideaxperts_ea_dry_runs'];
		$latest = array();
		foreach ( $rows as $row ) {
			if ( 'completed' === (string) ( $row['status'] ?? '' ) ) {
				$environment            = (string) ( $row['environment'] ?? 'qa' );
				$latest[ $environment ] = max( (int) ( $latest[ $environment ] ?? 0 ), (int) $row['id'] );
			}
		}
		preg_match_all( "/r\.status = '([^']+)' AND COALESCE\(r\.completed_at,r\.updated_at\) < '([^']+)'/", $sql, $matches, PREG_SET_ORDER );
		$cutoffs = array();
		foreach ( $matches as $match ) {
			$cutoffs[ $match[1] ] = $match[2];
		}
		$eligible = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $cutoffs, $latest ): bool {
					$status = (string) ( $row['status'] ?? '' );
					$anchor = (string) ( $row['completed_at'] ?? $row['updated_at'] ?? '' );
					if ( ! isset( $cutoffs[ $status ] ) || $anchor >= $cutoffs[ $status ] ) {
						return false;
					}
					return 'completed' !== $status || (int) ( $latest[ (string) ( $row['environment'] ?? 'qa' ) ] ?? 0 ) !== (int) $row['id'];
				}
			)
		);
		usort( $eligible, static fn( array $left, array $right ): int => (int) $left['id'] <=> (int) $right['id'] );
		$limit = 1;
		if ( 1 === preg_match( '/LIMIT (\d+)$/', $sql, $limit_match ) ) {
			$limit = (int) $limit_match[1];
		}
		return array_slice( $eligible, 0, $limit );
	}

	/** @return list<array<string,mixed>> */
	private function select_joined_items( string $sql ): array {
		$items = array();
		foreach ( $this->tables['wp_ideaxperts_ea_dry_run_items'] as $row ) {
			$run = $this->run_row( (int) ( $row['run_id'] ?? 0 ) );
			if ( ! $run ) {
				continue;
			}
			$combined           = $row;
			$combined['status'] = $run['status'];
			$items[]            = $combined;
		}
		if ( 1 === preg_match( '/WHERE (.+)$/', preg_replace( '/\s+FOR UPDATE$/', '', $sql ) ?? $sql, $where ) ) {
			$clause = str_replace( array( 'i.', 'r.' ), '', $where[1] );
			$items  = array_values(
				array_filter(
					$items,
					fn( array $row ): bool => $this->matches_sql( $row, $clause )
				)
			);
		}
		return $items;
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $where */
	private function matches_array( array $row, array $where ): bool {
		foreach ( $where as $key => $value ) {
			if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string,mixed> */
	private function parse_set( string $set ): array {
		$data  = array();
		$parts = preg_split( '/, (?=\w+ = )/', $set );
		foreach ( false !== $parts ? $parts : array() as $part ) {
			if ( 1 !== preg_match( '/^(\w+) = (NULL|\'(?:\\\\\'|[^\'])*\'|-?\d+)$/', trim( $part ), $match ) ) {
				continue;
			}
			$data[ $match[1] ] = 'NULL' === $match[2] ? null : trim( $match[2], "'" );
		}
		return $data;
	}

	/** @param array<string,mixed> $row */
	private function matches_sql( array $row, string $where ): bool {
		$where = trim( $where );
		if ( 1 === preg_match( '/^NOT \((.+)\)$/', $where, $not ) ) {
			return ! $this->matches_sql( $row, $not[1] );
		}
		$parts = $this->split_and( $where );
		foreach ( $parts as $part ) {
			if ( ! $this->matches_condition( $row, trim( $part ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return list<string> */
	private function split_and( string $where ): array {
		$parts  = array();
		$buffer = '';
		$depth  = 0;
		$length = strlen( $where );
		for ( $index = 0; $index < $length; ++$index ) {
			$buffer .= $where[ $index ];
			if ( '(' === $where[ $index ] ) {
				++$depth;
			} elseif ( ')' === $where[ $index ] ) {
				--$depth;
			}
			if ( 0 === $depth && str_ends_with( $buffer, ' AND ' ) ) {
				$parts[] = substr( $buffer, 0, -5 );
				$buffer  = '';
			}
		}
		if ( '' !== $buffer ) {
			$parts[] = $buffer;
		}
		return $parts;
	}

	/** @param array<string,mixed> $row */
	private function matches_condition( array $row, string $condition ): bool {
		if ( 1 === preg_match( '/^NOT \((.+)\)$/', $condition, $not ) ) {
			return ! $this->matches_sql( $row, $not[1] );
		}
		if ( 1 === preg_match( '/^(\w+) IN \((.+)\)$/', $condition, $in ) ) {
			$values = array_map(
				static fn( string $value ): string => trim( $value, " '" ),
				explode( ',', $in[2] )
			);
			return in_array( (string) ( $row[ $in[1] ] ?? '' ), $values, true );
		}
		if ( 1 === preg_match( '/^(\w+) IS NOT NULL$/', $condition, $not_null ) ) {
			return null !== ( $row[ $not_null[1] ] ?? null );
		}
		if ( 1 === preg_match( '/^(\w+) <> (NULL|\'(?:\\\\\'|[^\'])*\')$/', $condition, $not_equals ) ) {
			$expected = 'NULL' === $not_equals[2] ? '' : trim( $not_equals[2], "'" );
			return (string) ( $row[ $not_equals[1] ] ?? '' ) !== $expected;
		}
		if ( 1 === preg_match( '/^(\w+) = (NULL|\'(?:\\\\\'|[^\'])*\'|-?\d+)$/', $condition, $equals ) ) {
			$expected = 'NULL' === $equals[2] ? '' : trim( $equals[2], "'" );
			return (string) ( $row[ $equals[1] ] ?? '' ) === $expected;
		}
		if ( 1 === preg_match( '/^(\w+)\s*(>=|<=|>|<)\s*(\d+)$/', $condition, $comparison ) ) {
			$actual   = (int) ( $row[ $comparison[1] ] ?? 0 );
			$expected = (int) $comparison[3];
			return match ( $comparison[2] ) {
				'>=' => $actual >= $expected,
				'<=' => $actual <= $expected,
				'>'  => $actual > $expected,
				'<'  => $actual < $expected,
			};
		}
		if ( 1 === preg_match( '/^(\w+)\s*(>=|<=|>|<)\s*\'(.*)\'$/', $condition, $comparison ) ) {
			$actual   = (string) ( $row[ $comparison[1] ] ?? '' );
			$expected = stripslashes( $comparison[3] );
			return match ( $comparison[2] ) {
				'>=' => $actual >= $expected,
				'<=' => $actual <= $expected,
				'>'  => $actual > $expected,
				'<'  => $actual < $expected,
			};
		}
		return true;
	}
}
