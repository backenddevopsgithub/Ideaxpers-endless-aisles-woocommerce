<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

final class DryRunMemoryWpdb {
	public string $prefix     = 'wp_';
	public string $options    = 'wp_options';
	public int $insert_id     = 0;
	public int $rows_affected = 0;
	/** @var array<string,list<array<string,mixed>>> */
	public array $tables      = array();

	public function __construct() {
		$this->tables = array(
			'wp_ideaxperts_ea_dry_runs'          => array(),
			'wp_ideaxperts_ea_dry_run_items'     => array(),
			'wp_ideaxperts_ea_store_identifiers' => array(),
			'wp_ideaxperts_ea_mappings'          => array(),
			'wp_options'                         => array(),
		);
	}

	/** @param array<string,mixed> $data @param list<string>|null $formats */
	public function insert( string $table, array $data, ?array $formats = null ): int {
		++$this->insert_id;
		$data['id']                 = $this->insert_id;
		$this->tables[ $table ][]   = $data;
		$this->rows_affected        = 1;
		return 1;
	}

	/** @param array<string,mixed> $data */
	public function replace( string $table, array $data ): int {
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
	public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int {
		$count = 0;
		foreach ( $this->tables[ $table ] as $index => $row ) {
			if ( ! $this->matches_array( $row, $where ) ) {
				continue;
			}
			$this->tables[ $table ][ $index ] = array_merge( $row, $data );
			++$count;
		}
		$this->rows_affected = $count;
		return $count;
	}

	/** @param array<string,mixed> $where */
	public function delete( string $table, array $where, mixed $where_format = null ): int {
		$kept = array();
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
				static function ( array $match ) use ( $arg ): string {
					if ( '%d' === $match[0] ) {
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

	public function query( string $sql ): int {
		$sql = trim( $sql );
		if ( in_array( $sql, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
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
			$this->rows_affected = 1;
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
			foreach ( $this->tables[ $matches[1] ] as $index => $row ) {
				if ( ! $this->matches_sql( $row, $matches[3] ) ) {
					continue;
				}
				$this->tables[ $matches[1] ][ $index ] = array_merge( $row, $data );
				++$count;
			}
			$this->rows_affected = $count;
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
		$rows = $this->select_rows( $sql );
		return $rows[0] ?? null;
	}

	/** @return list<array<string,mixed>> */
	public function get_results( string $sql, mixed $output = null ): array {
		$rows = $this->select_rows( $sql );
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

	/** @return list<array<string,mixed>> */
	private function select_rows( string $sql ): array {
		$sql = preg_replace( '/\s+FOR UPDATE$/', '', trim( $sql ) ) ?? $sql;
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
		if ( 1 === preg_match( '/ORDER BY id (ASC|DESC)/', $sql, $order ) ) {
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
		$data = array();
		foreach ( preg_split( '/, (?=\w+ = )/', $set ) ?: array() as $part ) {
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
		if ( 1 === preg_match( '/^(\w+) = (NULL|\'(?:\\\\\'|[^\'])*\'|-?\d+)$/', $condition, $equals ) ) {
			$expected = 'NULL' === $equals[2] ? '' : trim( $equals[2], "'" );
			return (string) ( $row[ $equals[1] ] ?? '' ) === $expected;
		}
		return true;
	}
}
