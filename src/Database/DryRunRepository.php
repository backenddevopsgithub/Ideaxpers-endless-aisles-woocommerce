<?php
namespace IdeaXperts\EndlessAisles\Database;

use Closure;
use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;

defined( 'ABSPATH' ) || exit;

final class DryRunRepository {
	public const ACTIVE_STATUSES          = array( 'pending', 'scanning_store', 'fetching_catalog' );
	public const LOCK_OPTION              = 'ideaxperts_ea_active_dry_run_id';
	public const COMPLETED_RETENTION_DAYS = 90;
	public const CANCELLED_RETENTION_DAYS = 90;
	public const FAILED_RETENTION_DAYS    = 180;
	public const STALE_AFTER_SECONDS      = 21600;
	public const PURGE_BATCH_SIZE         = 25;

	private const RUN_FIELDS = array(
		'status',
		'completed_at',
		'current_api_page',
		'current_store_page',
		'products_inspected',
		'variations_inspected',
		'store_records_inspected',
		'match_counters',
		'warning_counters',
		'error_summary',
		'resume_cursor',
		'cancellation_at',
		'last_heartbeat_at',
	);

	public function claim_new( int $user_id, string $environment = 'qa' ): int {
		$this->recover_stale_active();
		$token   = $this->new_lock_token();
		$pending = $this->lock_payload( $token, 0 );
		if ( ! $this->insert_lock( $pending ) ) {
			return 0;
		}
		$run_id = $this->create( $user_id, $environment );
		if ( $run_id < 1 ) {
			$this->cas_delete_lock( $pending );
			return 0;
		}
		$owned = $this->lock_payload( $token, $run_id );
		if ( ! $this->cas_update_lock( $pending, $owned ) ) {
			$this->transition(
				$run_id,
				self::ACTIVE_STATUSES,
				array(
					'status'        => 'failed',
					'error_summary' => 'The dry run lost the active-run lock before it started.',
				)
			);
			return 0;
		}
		return $run_id;
	}

	public function claim_existing( int $run_id ): bool {
		$this->recover_stale_active();
		if ( $this->active_id() > 0 ) {
			return false;
		}
		$token   = $this->new_lock_token();
		$payload = $this->lock_payload( $token, $run_id );
		return $this->insert_lock( $payload );
	}

	public function release_lock( int $run_id ): void {
		$current = $this->lock_value();
		$parsed  = $this->parse_lock( $current );
		if ( ! $parsed || (int) $parsed['run_id'] !== $run_id ) {
			return;
		}
		$this->cas_delete_lock( $current );
	}

	public function create( int $user_id, string $environment = 'qa' ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			$wpdb->prefix . 'ideaxperts_ea_dry_runs',
			array(
				'status'                  => 'pending',
				'environment'             => 'local' === $environment ? 'local' : 'qa',
				'started_by'              => $user_id,
				'started_at'              => $now,
				'updated_at'              => $now,
				'last_heartbeat_at'       => $now,
				'current_api_page'        => 0,
				'current_store_page'      => 0,
				'products_inspected'      => 0,
				'variations_inspected'    => 0,
				'store_records_inspected' => 0,
				'resume_cursor'           => '',
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' )
		);
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	public function active_id(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE status IN (%s,%s,%s) ORDER BY id DESC LIMIT 1", self::ACTIVE_STATUSES[0], self::ACTIVE_STATUSES[1], self::ACTIVE_STATUSES[2] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return list<int> */
	public function active_ids(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE status IN (%s,%s,%s)", self::ACTIVE_STATUSES[0], self::ACTIVE_STATUSES[1], self::ACTIVE_STATUSES[2] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$ids[] = (int) $row['id'];
		}
		return $ids;
	}

	/** @return list<int> */
	public function fail_active_runs( string $message ): array {
		$failed = array();
		foreach ( $this->active_ids() as $run_id ) {
			if ( $this->transition(
				$run_id,
				self::ACTIVE_STATUSES,
				array(
					'status'        => 'failed',
					'error_summary' => $message,
					'completed_at'  => current_time( 'mysql', true ),
				)
			) ) {
				$failed[] = $run_id;
			}
		}
		return $failed;
	}

	/**
	 * @param list<string>        $from_statuses Empty list updates by id only.
	 * @param array<string,mixed> $fields
	 */
	public function transition( int $run_id, array $from_statuses, array $fields ): bool {
		global $wpdb;
		$data               = array_intersect_key( $fields, array_flip( self::RUN_FIELDS ) );
		$data['updated_at'] = current_time( 'mysql', true );
		$set                = array();
		$args               = array();
		foreach ( $data as $column => $value ) {
			$set[]  = $column . ' = %s';
			$args[] = null === $value ? null : (string) $value;
		}
		$table  = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$sql    = "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE id = %d';
		$args[] = $run_id;
		if ( $from_statuses ) {
			$placeholders = implode( ',', array_fill( 0, count( $from_statuses ), '%s' ) );
			$sql         .= " AND status IN ({$placeholders})";
			foreach ( $from_statuses as $status ) {
				$args[] = $status;
			}
		}
		$result = $wpdb->query( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false !== $result && (int) $wpdb->rows_affected > 0;
	}

	public function heartbeat( int $run_id ): bool {
		return $this->transition( $run_id, self::ACTIVE_STATUSES, array( 'last_heartbeat_at' => current_time( 'mysql', true ) ) );
	}

	public function recover_stale_active(): int {
		$this->release_stale_unbound_lock();
		$run_id = $this->active_id();
		if ( $run_id < 1 ) {
			return 0;
		}
		$run = $this->run( $run_id );
		if ( ! $run ) {
			return 0;
		}
		$heartbeat = (string) ( $run['last_heartbeat_at'] ?? '' );
		if ( '' === $heartbeat ) {
			$heartbeat = (string) ( $run['updated_at'] ?? '' );
		}
		$age = strtotime( current_time( 'mysql', true ) ) - strtotime( $heartbeat );
		if ( $age < self::STALE_AFTER_SECONDS ) {
			return 0;
		}
		$failed = $this->transition(
			$run_id,
			self::ACTIVE_STATUSES,
			array(
				'status'        => 'failed',
				'error_summary' => 'The dry run stopped sending heartbeats and was marked stale.',
			)
		);
		if ( ! $failed ) {
			return 0;
		}
		$this->release_lock( $run_id );
		return $run_id;
	}

	/** @return array<string,mixed>|null */
	public function run( int $run_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_dry_runs WHERE id = %d', $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed> $record */
	public function store_identifier( int $run_id, array $record ): bool {
		$data = array_merge(
			array(
				'run_id'                => $run_id,
				'wc_product_id'         => 0,
				'wc_variation_id'       => 0,
				'product_type'          => '',
				'product_status'        => '',
				'title'                 => '',
				'identifier_type'       => '',
				'identifier_source'     => '',
				'original_identifier'   => '',
				'normalized_identifier' => '',
				'parent_product_id'     => 0,
				'ea_product_id'         => '',
				'ea_option_id'          => '',
				'created_at'            => current_time( 'mysql', true ),
			),
			$record
		);
		return $this->with_active_run(
			$run_id,
			function () use ( $data ): void {
				global $wpdb;
				$wpdb->replace( $wpdb->prefix . 'ideaxperts_ea_store_identifiers', $data );
			}
		);
	}

	/** @return list<array<string,mixed>> */
	public function identifier_matches( int $run_id, string $normalized, string $type ): array {
		if ( '' === $normalized ) {
			return array();
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_store_identifiers';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d AND normalized_identifier = %s AND identifier_type = %s", $run_id, $normalized, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$unique = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = (int) ( $row['wc_product_id'] ?? 0 ) . ':' . (int) ( $row['wc_variation_id'] ?? 0 );
			if ( ! isset( $unique[ $key ] ) ) {
				$unique[ $key ] = $row;
			}
		}
		return array_values( $unique );
	}

	/** @return array{products:int,variations:int} */
	public function store_record_counts( int $run_id ): array {
		global $wpdb;
		$table      = $wpdb->prefix . 'ideaxperts_ea_store_identifiers';
		$rows       = $wpdb->get_results( $wpdb->prepare( "SELECT wc_product_id, wc_variation_id FROM {$table} WHERE run_id = %d", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$products   = array();
		$variations = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( (int) $row['wc_variation_id'] > 0 ) {
				$variations[ (int) $row['wc_variation_id'] ] = true;
			} else {
				$products[ (int) $row['wc_product_id'] ] = true;
			}
		}
		return array(
			'products'   => count( $products ),
			'variations' => count( $variations ),
		);
	}

	/** @return list<array<string,mixed>> */
	public function mappings( string $product_id, string $option_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_mappings';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE mapping_status = %s AND ea_product_id = %s AND ea_option_id = %s", 'active', $product_id, $option_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed>|null */
	public function mapping_for_store_item( int $wc_product_id, int $wc_variation_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_mappings';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE mapping_status = %s AND wc_product_id = %d AND wc_variation_id = %d", 'active', $wc_product_id, $wc_variation_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed> $item */
	public function item( int $run_id, array $item ): bool {
		$data = array_merge(
			array(
				'run_id'                    => $run_id,
				'ea_product_id'             => '',
				'ea_option_id'              => '',
				'original_upc'              => '',
				'normalized_upc'            => '',
				'wc_product_id'             => 0,
				'wc_variation_id'           => 0,
				'classification'            => 'manual_review',
				'review_flags'              => '[]',
				'review_reason'             => '',
				'vendor_title'              => '',
				'vendor_option_description' => '',
				'retail_price'              => '',
				'wholesale_price'           => '',
				'map_price'                 => '',
				'purchasable'               => 0,
				'discontinued'              => 0,
				'created_at'                => current_time( 'mysql', true ),
				'updated_at'                => current_time( 'mysql', true ),
			),
			$item
		);

		$data['review_flags'] = wp_json_encode( MatchClassifier::sanitize_flags( is_array( $data['review_flags'] ) ? $data['review_flags'] : json_decode( (string) $data['review_flags'], true ) ) );
		return $this->with_active_run(
			$run_id,
			function () use ( $data ): void {
				global $wpdb;
				$wpdb->replace( $wpdb->prefix . 'ideaxperts_ea_dry_run_items', $data );
			}
		);
	}

	public function vendor_upc_used_by_other_option( int $run_id, string $normalized_upc, string $product_id, string $option_id ): bool {
		if ( '' === $normalized_upc ) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_items';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE run_id = %d AND normalized_upc = %s AND NOT (ea_product_id = %s AND ea_option_id = %s)", $run_id, $normalized_upc, $product_id, $option_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $count > 0;
	}

	public function add_review_flag_for_upc( int $run_id, string $normalized_upc, string $flag ): void {
		$allowed = MatchClassifier::sanitize_flags( array( $flag ) );
		if ( '' === $normalized_upc || array() === $allowed ) {
			return;
		}
		$flag = $allowed[0];
		$this->with_active_run(
			$run_id,
			function () use ( $run_id, $normalized_upc, $flag ): void {
				global $wpdb;
				$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_items';
				$runs  = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.review_flags FROM {$table} i INNER JOIN {$runs} r ON r.id = i.run_id WHERE i.run_id = %d AND i.normalized_upc = %s AND r.status IN (%s,%s,%s)", $run_id, $normalized_upc, self::ACTIVE_STATUSES[0], self::ACTIVE_STATUSES[1], self::ACTIVE_STATUSES[2] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$flags = MatchClassifier::sanitize_flags( json_decode( (string) $row['review_flags'], true ) );
					if ( in_array( $flag, $flags, true ) ) {
						continue;
					}
					$flags[] = $flag;
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table} i INNER JOIN {$runs} r ON r.id = i.run_id SET i.review_flags = %s, i.updated_at = %s WHERE i.id = %d AND r.status IN (%s,%s,%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix schema constants.
							wp_json_encode( MatchClassifier::sanitize_flags( $flags ) ),
							current_time( 'mysql', true ),
							(int) $row['id'],
							self::ACTIVE_STATUSES[0],
							self::ACTIVE_STATUSES[1],
							self::ACTIVE_STATUSES[2]
						)
					);
				}
			}
		);
	}

	/** @return array<string,int> */
	public function classification_counts( int $run_id ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_items';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT classification, COUNT(*) AS total FROM {$table} WHERE run_id = %d GROUP BY classification", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[ (string) $row['classification'] ] = (int) $row['total'];
		}
		return $result;
	}

	/** @return array<string,int> */
	public function flag_counts( int $run_id ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_items';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT review_flags FROM {$table} WHERE run_id = %d", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			foreach ( MatchClassifier::sanitize_flags( json_decode( (string) $row['review_flags'], true ) ) as $flag ) {
				$result[ $flag ] = (int) ( $result[ $flag ] ?? 0 ) + 1;
			}
		}
		return $result;
	}

	/** @return array<string,int> */
	public function discovery_counts( int $run_id ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_store_identifiers';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT identifier_source, COUNT(*) AS total FROM {$table} WHERE run_id = %d GROUP BY identifier_source", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[ (string) $row['identifier_source'] ] = (int) $row['total'];
		}
		return $result;
	}

	/** @return list<array<string,mixed>> */
	public function items( int $run_id, string $classification = '', string $search = '', int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_items';
		$where = 'run_id = %d';
		$args  = array( $run_id );
		if ( '' !== $classification ) {
			$where .= ' AND classification = %s';
			$args[] = $classification;
		}
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (ea_product_id LIKE %s OR ea_option_id LIKE %s OR original_upc LIKE %s OR wc_product_id = %d OR wc_variation_id = %d)';
			array_push( $args, $like, $like, $like, absint( $search ), absint( $search ) );
		}
		$per_page = min( 100, max( 1, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		array_push( $args, $per_page, $offset );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return is_array( $rows ) ? $rows : array();
	}

	public function purge_expired(): int {
		$now            = strtotime( current_time( 'mysql', true ) );
		$completed_keep = $this->latest_completed_ids();
		$deleted        = 0;
		foreach ( $this->finished_runs() as $run ) {
			if ( $deleted >= self::PURGE_BATCH_SIZE ) {
				break;
			}
			$status = (string) $run['status'];
			if ( in_array( $status, self::ACTIVE_STATUSES, true ) ) {
				continue;
			}
			$days = match ( $status ) {
				'failed'    => self::FAILED_RETENTION_DAYS,
				'cancelled' => self::CANCELLED_RETENTION_DAYS,
				default     => self::COMPLETED_RETENTION_DAYS,
			};
			$anchor = (string) ( $run['completed_at'] ?? '' );
			if ( '' === $anchor ) {
				$anchor = (string) ( $run['updated_at'] ?? '' );
			}
			$age = $now - strtotime( $anchor );
			if ( $age < $days * DAY_IN_SECONDS ) {
				continue;
			}
			if ( 'completed' === $status && in_array( (int) $run['id'], $completed_keep, true ) ) {
				continue;
			}
			$deleted += $this->delete_run( (int) $run['id'] ) ? 1 : 0;
		}
		return $deleted;
	}

	public function has_purge_remaining(): bool {
		return $this->purge_candidate_count() > 0;
	}

	private function purge_candidate_count(): int {
		$now            = strtotime( current_time( 'mysql', true ) );
		$completed_keep = $this->latest_completed_ids();
		$count          = 0;
		foreach ( $this->finished_runs() as $run ) {
			$status = (string) $run['status'];
			$days   = match ( $status ) {
				'failed'    => self::FAILED_RETENTION_DAYS,
				'cancelled' => self::CANCELLED_RETENTION_DAYS,
				default     => self::COMPLETED_RETENTION_DAYS,
			};
			$anchor = (string) ( $run['completed_at'] ?? '' );
			if ( '' === $anchor ) {
				$anchor = (string) ( $run['updated_at'] ?? '' );
			}
			if ( $now - strtotime( $anchor ) < $days * DAY_IN_SECONDS ) {
				continue;
			}
			if ( 'completed' === $status && in_array( (int) $run['id'], $completed_keep, true ) ) {
				continue;
			}
			++$count;
		}
		return $count;
	}

	/** @return list<int> */
	private function latest_completed_ids(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$ids   = array();
		foreach ( array( 'qa', 'local' ) as $environment ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE environment = %s AND status = %s ORDER BY id DESC LIMIT 1", $environment, 'completed' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** @return list<array<string,mixed>> */
	private function finished_runs(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$rows  = $wpdb->get_results( "SELECT id, status, environment, completed_at, updated_at FROM {$table} WHERE status IN ('completed','cancelled','failed') ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	private function delete_run( int $run_id ): bool {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'ideaxperts_ea_dry_run_items', array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'ideaxperts_ea_store_identifiers', array( 'run_id' => $run_id ), array( '%d' ) );
		return false !== $wpdb->delete( $wpdb->prefix . 'ideaxperts_ea_dry_runs', array( 'id' => $run_id ), array( '%d' ) );
	}

	/** @param Closure():void $write */
	private function with_active_run( int $run_id, Closure $write ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$wpdb->query( 'START TRANSACTION' );
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND status IN (%s,%s,%s) FOR UPDATE", $run_id, self::ACTIVE_STATUSES[0], self::ACTIVE_STATUSES[1], self::ACTIVE_STATUSES[2] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $active !== $run_id ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$write();
		$wpdb->query( 'COMMIT' );
		return true;
	}

	private function new_lock_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	private function lock_payload( string $token, int $run_id ): string {
		return (string) wp_json_encode(
			array(
				'token'      => $token,
				'run_id'     => $run_id,
				'claimed_at' => current_time( 'mysql', true ),
			)
		);
	}

	/** @return array{token:string,run_id:int,claimed_at:string}|null */
	private function parse_lock( string $value ): ?array {
		$parsed = json_decode( $value, true );
		if ( ! is_array( $parsed ) || ! isset( $parsed['token'], $parsed['run_id'] ) ) {
			return null;
		}
		return array(
			'token'      => (string) $parsed['token'],
			'run_id'     => (int) $parsed['run_id'],
			'claimed_at' => (string) ( $parsed['claimed_at'] ?? '' ),
		);
	}

	private function lock_value(): string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_string( $value ) ? $value : '';
	}

	private function insert_lock( string $value ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::LOCK_OPTION, $value, 'no' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->flush_lock_cache();
		return false !== $result && (int) $wpdb->rows_affected > 0;
	}

	private function cas_update_lock( string $expected, string $replacement ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $replacement, self::LOCK_OPTION, $expected ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->flush_lock_cache();
		return false !== $result && (int) $wpdb->rows_affected > 0;
	}

	private function cas_delete_lock( string $expected ): bool {
		global $wpdb;
		if ( '' === $expected ) {
			return false;
		}
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $expected ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->flush_lock_cache();
		return false !== $result && (int) $wpdb->rows_affected > 0;
	}

	private function release_stale_unbound_lock(): void {
		$current = $this->lock_value();
		$parsed  = $this->parse_lock( $current );
		if ( ! $parsed ) {
			return;
		}
		$run_id = $parsed['run_id'];
		if ( $run_id > 0 ) {
			$run = $this->run( $run_id );
			if ( $run && in_array( (string) $run['status'], self::ACTIVE_STATUSES, true ) ) {
				return;
			}
			if ( $run && ! in_array( (string) $run['status'], self::ACTIVE_STATUSES, true ) ) {
				$this->cas_delete_lock( $current );
				return;
			}
		}
		$age = strtotime( current_time( 'mysql', true ) ) - strtotime( $parsed['claimed_at'] );
		if ( $age >= self::STALE_AFTER_SECONDS ) {
			$this->cas_delete_lock( $current );
		}
	}

	private function flush_lock_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
