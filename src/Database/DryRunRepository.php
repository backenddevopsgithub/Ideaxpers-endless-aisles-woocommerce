<?php
namespace IdeaXperts\EndlessAisles\Database;

use Closure;
use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;

defined( 'ABSPATH' ) || exit;

final class DryRunRepository {
	public const ACTIVE_STATUSES          = array( 'pending', 'scanning_store', 'fetching_catalog', 'cancelling', 'recovering' );
	public const LOCK_OPTION              = 'ideaxperts_ea_active_dry_run_id';
	public const COMPLETED_RETENTION_DAYS = 90;
	public const CANCELLED_RETENTION_DAYS = 90;
	public const FAILED_RETENTION_DAYS    = 180;
	public const STALE_AFTER_SECONDS      = 21600;
	public const PURGE_BATCH_SIZE         = 25;
	public const DEACTIVATING_OPTION      = 'ideaxperts_ea_deactivating';
	public const ACTION_BATCH_SIZE        = 25;
	public const MAX_DISPATCH_ATTEMPTS    = 5;
	public const DISPATCH_LEASE_SECONDS   = 300;
	// One page is bounded to 50 store products or one QA API page. Thirty minutes
	// accommodates slow WooCommerce hooks while still allowing deterministic recovery.
	public const EXECUTION_LEASE_SECONDS = 1800;

	private bool $session_usable = true;

	/** @return list<array<string,mixed>> */
	public function recent_runs( int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", min( 10, max( 1, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed>|null */
	public function latest_run( string $environment ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE environment = %s ORDER BY id DESC LIMIT 1", $environment ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** Counts saved UPC rows, distinct owners, duplicated UPC values and owners with conflicting values.
	 * @return array<string,int>|null
	 */
	public function discovery_summary( int $run_id ): ?array {
		global $wpdb;
		$table   = $wpdb->prefix . 'ideaxperts_ea_store_identifiers';
		$base    = $wpdb->prepare( "FROM {$table} WHERE run_id = %d AND identifier_type = 'upc'", $run_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$queries = array(
			'upc_records'        => "SELECT COUNT(*) {$base}",
			'owners_with_upc'    => "SELECT COUNT(DISTINCT wc_product_id,wc_variation_id) {$base}",
			'duplicate_upcs'     => "SELECT COUNT(*) FROM (SELECT normalized_identifier {$base} GROUP BY normalized_identifier HAVING COUNT(DISTINCT wc_product_id,wc_variation_id) > 1) duplicate_upcs",
			'conflicting_owners' => "SELECT COUNT(*) FROM (SELECT wc_product_id,wc_variation_id {$base} GROUP BY wc_product_id,wc_variation_id HAVING COUNT(DISTINCT normalized_identifier) > 1) conflicting_owners",
		);
		$result  = array();
		foreach ( $queries as $key => $sql ) {
			$value = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared run ID and fixed aggregate queries.
			if ( null === $value || ! empty( $wpdb->last_error ) ) {
				return null;
			}
			$result[ $key ] = max( 0, (int) $value );
		}
		return $result;
	}

	/** @return array<string,int>|null */
	public function review_summary( int $run_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_items';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(CASE WHEN classification = 'manual_review' OR (review_flags <> '[]' AND review_flags <> '') THEN 1 END) AS review_required, COUNT(CASE WHEN retail_price REGEXP '^[[:space:]]*[+-]?([0-9]+([.][0-9]*)?|[.][0-9]+)([eE][+-]?[0-9]+)?[[:space:]]*$' AND map_price REGEXP '^[[:space:]]*[+-]?([0-9]+([.][0-9]*)?|[.][0-9]+)([eE][+-]?[0-9]+)?[[:space:]]*$' AND retail_price + 0e0 < map_price + 0e0 THEN 1 END) AS retail_below_map FROM {$table} WHERE run_id = %d", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) && empty( $wpdb->last_error ) ? array_map( 'intval', $row ) : null;
	}

	private const RUN_FIELDS = array(
		'status',
		'completed_at',
		'current_api_page',
		'catalog_products_processed',
		'catalog_total_products',
		'catalog_total_pages',
		'worker_diagnostics',
		'store_products_scanned',
		'store_variations_scanned',
		'store_missing_upcs',
		'store_total_products',
		'store_total_pages',
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
		'claim_token',
		'claim_generation',
	);

	public function claim_new( int $user_id, string $environment = 'qa' ): int {
		$this->release_stale_unbound_lock();
		$token   = $this->new_lock_token();
		$pending = $this->lock_payload( $token, 0, 1 );
		if ( ! $this->insert_lock( $pending ) ) {
			return 0;
		}
		if ( get_option( self::DEACTIVATING_OPTION, false ) ) {
			$this->cas_delete_lock( $pending );
			return 0;
		}
		$run_id = $this->create( $user_id, $environment, $token );
		if ( $run_id < 1 ) {
			$this->cas_delete_lock( $pending );
			return 0;
		}
		$owned = $this->lock_payload( $token, $run_id, 1 );
		if ( ! $this->cas_update_lock( $pending, $owned ) ) {
			$this->transition(
				$run_id,
				self::ACTIVE_STATUSES,
				array(
					'status'        => 'failed',
					'error_summary' => 'The dry run lost the active-run lock before it started.',
				),
				$token
			);
			return 0;
		}
		return $run_id;
	}

	public function begin_deactivation(): bool {
		$marker = add_option( self::DEACTIVATING_OPTION, current_time( 'mysql', true ), '', false );
		if ( ! $marker && false === get_option( self::DEACTIVATING_OPTION, false ) ) {
			return false;
		}
		$current = $this->lock_value();
		$parsed  = $this->parse_lock( $current );
		if ( $parsed && 0 === $parsed['run_id'] ) {
			return $this->cas_delete_lock( $current );
		}
		return true;
	}

	public function reconcile_activation_placeholder(): bool {
		$current = $this->lock_value();
		$parsed  = $this->parse_lock( $current );
		if ( $parsed && 0 === $parsed['run_id'] && ! $this->cas_delete_lock( $current ) ) {
			return false;
		}
		return delete_option( self::DEACTIVATING_OPTION ) || false === get_option( self::DEACTIVATING_OPTION, false );
	}

	public function claim_token( int $run_id ): string {
		$run = $this->run( $run_id );
		return is_array( $run ) ? (string) ( $run['claim_token'] ?? '' ) : '';
	}

	public function claim_generation( int $run_id ): int {
		$run = $this->run( $run_id );
		return is_array( $run ) ? max( 1, (int) ( $run['claim_generation'] ?? 1 ) ) : 0;
	}

	public function lock_run_id(): int {
		$parsed = $this->parse_lock( $this->lock_value() );
		return $parsed ? $parsed['run_id'] : 0;
	}

	public function claim_existing( int $run_id ): bool {
		return '' !== $this->claim_existing_token( $run_id );
	}

	public function claim_existing_token( int $run_id ): string {
		$this->release_stale_unbound_lock();
		if ( get_option( self::DEACTIVATING_OPTION, false ) || $this->active_id() > 0 ) {
			return '';
		}
		$token               = $this->new_lock_token();
		$previous            = $this->run( $run_id );
		$previous_token      = is_array( $previous ) ? (string) ( $previous['claim_token'] ?? '' ) : '';
		$previous_generation = is_array( $previous ) ? max( 1, (int) ( $previous['claim_generation'] ?? 1 ) ) : 0;
		$generation          = $previous_generation + 1;
		if ( 0 === $generation ) {
			return '';
		}
		$payload = $this->lock_payload( $token, $run_id, $generation );
		if ( ! $this->insert_lock( $payload ) ) {
			return '';
		}
		// @phpstan-ignore-next-line -- Deactivation can begin concurrently after the first check.
		if ( get_option( self::DEACTIVATING_OPTION, false ) ) {
			$this->cas_delete_lock( $payload );
			return '';
		}
		$claimed = $this->with_locked_run(
			$run_id,
			$previous_token,
			array( 'failed' ),
			function ( array $run ) use ( $run_id, $token, $generation ): bool {
				return $this->update_run_row(
					$run_id,
					array(
						'claim_token'      => $token,
						'claim_generation' => $generation,
					)
				);
			},
			$previous_generation
		);
		if ( ! $claimed ) {
			$this->cas_delete_lock( $payload );
			return '';
		}
		return $token;
	}

	public function release_lock( int $run_id, string $token = '', int $generation = 0 ): void {
		if ( ! $this->session_usable ) {
			return;
		}
		$current = $this->lock_value();
		$parsed  = $this->parse_lock( $current );
		if ( ! $parsed || (int) $parsed['run_id'] !== $run_id || '' === $token || ! hash_equals( $parsed['token'], $token ) || ( $generation > 0 && $parsed['generation'] !== $generation ) ) {
			return;
		}
		$this->cas_delete_lock( $current );
	}

	public function create( int $user_id, string $environment = 'qa', string $claim_token = '' ): int {
		if ( ! $this->session_usable ) {
			return 0;
		}
		global $wpdb;
		$environment = in_array( $environment, array( 'qa', 'production', 'local' ), true ) ? $environment : 'qa';
		$now         = current_time( 'mysql', true );
		$ok          = $wpdb->insert(
			$wpdb->prefix . 'ideaxperts_ea_dry_runs',
			array(
				'status'                     => 'pending',
				'source_scope'               => 'local' === $environment ? 'local' : 'endless-aisles:' . $environment,
				'environment'                => $environment,
				'started_by'                 => $user_id,
				'started_at'                 => $now,
				'updated_at'                 => $now,
				'last_heartbeat_at'          => $now,
				'current_api_page'           => 0,
				'current_store_page'         => 0,
				'products_inspected'         => 0,
				'variations_inspected'       => 0,
				'store_records_inspected'    => 0,
				'resume_cursor'              => '',
				'claim_token'                => $claim_token,
				'claim_generation'           => 1,
				'catalog_products_processed' => 0,
				'store_products_scanned'     => 0,
				'store_variations_scanned'   => 0,
				'store_missing_upcs'         => 0,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d' )
		);
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	public function active_id(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$slots = implode( ',', array_fill( 0, count( self::ACTIVE_STATUSES ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE status IN ({$slots}) ORDER BY id DESC LIMIT 1", ...self::ACTIVE_STATUSES ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/** @return list<int> */
	public function active_ids(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$slots = implode( ',', array_fill( 0, count( self::ACTIVE_STATUSES ), '%s' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE status IN ({$slots})", ...self::ACTIVE_STATUSES ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
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
			$token      = $this->claim_token( $run_id );
			$generation = $this->claim_generation( $run_id );
			if ( null !== $this->begin_failure( $run_id, $token, $generation, $message ) ) {
				$failed[] = $run_id;
			}
		}
		return $failed;
	}

	/**
	 * @param list<string>        $from_statuses Empty list updates by id only.
	 * @param array<string,mixed> $fields
	 */
	public function transition( int $run_id, array $from_statuses, array $fields, string $claim_token = '', int $claim_generation = 0 ): bool {
		if ( ! $this->session_usable ) {
			return false;
		}
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
		$sql    = "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE id = %d AND claim_token = %s';
		$args[] = $run_id;
		$args[] = $claim_token;
		if ( $claim_generation > 0 ) {
			$sql   .= ' AND claim_generation = %d';
			$args[] = $claim_generation;
		}
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

	/** @phpstan-impure Ownership can change between calls in another database session. */
	public function heartbeat( int $run_id, string $claim_token = '', int $claim_generation = 0 ): bool {
		if ( ! $this->session_usable ) {
			return false;
		}
		// Legacy callers omit the generation; pin it before either ownership check.
		if ( 0 === $claim_generation ) {
			$claim_generation = $this->claim_generation( $run_id );
		}
		if ( $claim_generation < 1 ) {
			return false;
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$now    = current_time( 'mysql', true );
		$where  = $wpdb->prepare( "WHERE id = %d AND claim_token = %s AND claim_generation = %d AND status IN ('pending','scanning_store','fetching_catalog')", $run_id, $claim_token, $claim_generation );
		$sql    = $wpdb->prepare( "UPDATE {$table} SET last_heartbeat_at = %s, updated_at = %s", $now, $now ) . ' ' . $where; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Both fragments are prepared above.
		if ( false === $result ) {
			return false;
		}
		if ( $result > 0 ) {
			return true;
		}
		// MySQL reports zero changed rows for same-second timestamps. Confirm current
		// ownership and status again, so a concurrent claim or cancellation fails.
		$row = $wpdb->get_row( "SELECT id FROM {$table} {$where} LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table and the same prepared predicates as the UPDATE.
		return is_array( $row ) && empty( $wpdb->last_error );
	}

	/**
	 * @param list<string>        $statuses
	 * @param array<string,mixed> $fields
	 */
	public function update_and_then( int $run_id, string $claim_token, array $statuses, array $fields, ?Closure $after_write = null, int $claim_generation = 0 ): bool {
		return $this->with_locked_run(
			$run_id,
			$claim_token,
			$statuses,
			function () use ( $run_id, $fields, $after_write ): bool {
				if ( ! $this->update_run_row( $run_id, $fields ) ) {
					return false;
				}
				return null === $after_write || true === $after_write();
			},
			$claim_generation
		);
	}

	/** @return array{run_id:int,claim_token:string,claim_generation:int}|null */
	public function request_cancellation( int $run_id, int $claim_generation ): ?array {
		$run = $this->run( $run_id );
		if ( ! $run || $claim_generation < 1 ) {
			return null;
		}
		$token = (string) ( $run['claim_token'] ?? '' );
		$ok    = $this->with_locked_run(
			$run_id,
			$token,
			array( 'pending', 'scanning_store', 'fetching_catalog' ),
			function () use ( $run_id, $token, $claim_generation ): bool {
				return $this->update_run_row(
					$run_id,
					array(
						'status'          => 'cancelling',
						'cancellation_at' => current_time( 'mysql', true ),
					)
				) && $this->mark_claim_intents_cancel_requested( $run_id, $token, $claim_generation );
			},
			$claim_generation
		);
		return $ok ? array(
			'run_id'           => $run_id,
			'claim_token'      => $token,
			'claim_generation' => $claim_generation,
		) : null;
	}

	/** @return array{run_id:int,claim_token:string,claim_generation:int}|null */
	public function begin_failure( int $run_id, string $claim_token, int $claim_generation, string $message, string $cursor = '', int $intent_id = 0, string $intent_token = '', string $execution_token = '' ): ?array {
		$fields = array(
			'status'        => 'recovering',
			'error_summary' => $message,
		);
		if ( '' !== $cursor ) {
			$fields['resume_cursor'] = $cursor;
		}
		$ok = $this->with_locked_run(
			$run_id,
			$claim_token,
			array( 'pending', 'scanning_store', 'fetching_catalog' ),
			function () use ( $run_id, $claim_token, $claim_generation, $fields, $intent_id, $intent_token, $execution_token ): bool {
				if ( $intent_id > 0 ) {
					global $wpdb;
					$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
					$intent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND intent_token = %s FOR UPDATE", $intent_id, $intent_token ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( ! is_array( $intent ) || ! empty( $wpdb->last_error ) || 'running' !== $intent['status'] || '' === $execution_token || ! hash_equals( (string) $intent['execution_token'], $execution_token ) || (string) $intent['lease_expires_at'] < current_time( 'mysql', true ) || (int) $intent['run_id'] !== $run_id || ! hash_equals( (string) $intent['claim_token'], $claim_token ) || (int) $intent['claim_generation'] !== $claim_generation ) {
						return false;
					}
				}

				return $this->update_run_row( $run_id, $fields ) && $this->mark_claim_intents_cancel_requested( $run_id, $claim_token, $claim_generation );
			},
			$claim_generation
		);
		return $ok ? array(
			'run_id'           => $run_id,
			'claim_token'      => $claim_token,
			'claim_generation' => $claim_generation,
		) : null;
	}

	public function finalize_cleanup( int $run_id, string $claim_token, int $claim_generation, string $status ): bool {
		if ( ! in_array( $status, array( 'cancelled', 'failed' ), true ) ) {
			return false;
		}
		$from = 'cancelled' === $status ? array( 'cancelling' ) : array( 'recovering' );
		return $this->with_locked_run(
			$run_id,
			$claim_token,
			$from,
			function () use ( $run_id, $claim_token, $claim_generation, $status ): bool {
				if ( $this->has_open_claim_intents( $run_id, $claim_token, $claim_generation ) ) {
					return false;
				}
				return $this->update_run_row(
					$run_id,
					array(
						'status'       => $status,
						'completed_at' => current_time( 'mysql', true ),
					)
				);
			},
			$claim_generation
		);
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
		$token      = (string) ( $run['claim_token'] ?? '' );
		$generation = max( 1, (int) ( $run['claim_generation'] ?? 1 ) );
		return null !== $this->begin_failure( $run_id, $token, $generation, 'The dry run stopped sending heartbeats and was marked stale.' ) ? $run_id : 0;
	}

	/** @return array<string,mixed>|null */
	public function run( int $run_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_dry_runs WHERE id = %d', $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed> $record */
	public function store_identifier( int $run_id, array $record, string $claim_token = '' ): bool {
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
			$claim_token,
			function () use ( $data ): bool {
				global $wpdb;
				return false !== $wpdb->replace( $wpdb->prefix . 'ideaxperts_ea_store_identifiers', $data );
			}
		);
	}

	/**
	 * @param list<array<string,mixed>> $records
	 * @param array<string,mixed>       $run_fields
	 */
	public function persist_store_page( int $run_id, string $claim_token, array $records, array $run_fields, ?Closure $after_write = null, int $claim_generation = 0 ): bool {
		return $this->with_active_run(
			$run_id,
			$claim_token,
			function () use ( $run_id, $records, $run_fields, $after_write ): bool {
				global $wpdb;
				foreach ( $records as $record ) {
					if ( false === $wpdb->replace( $wpdb->prefix . 'ideaxperts_ea_store_identifiers', $this->store_identifier_data( $run_id, $record ) ) ) {
						return false;
					}
				}
				$counts = $this->store_record_counts_checked( $run_id );
				if ( null === $counts ) {
					return false;
				}
				$fields = array_merge(
					$run_fields,
					array(
						'products_inspected'      => $counts['products'],
						'variations_inspected'    => $counts['variations'],
						'store_records_inspected' => $counts['products'] + $counts['variations'],
					)
				);
				if ( ! $this->update_run_row( $run_id, $fields ) ) {
					return false;
				}
				return null === $after_write || true === $after_write();
			},
			$claim_generation
		);
	}

	/**
	 * @param array<string,mixed> $record
	 * @return array<string,mixed>
	 */
	private function store_identifier_data( int $run_id, array $record ): array {
		return array_merge(
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
	}

	/** @return list<array<string,mixed>> */
	public function identifier_matches( int $run_id, string $normalized, string $type ): array {
		if ( '' === $normalized ) {
			return array();
		}
		global $wpdb;
		$this->clear_database_error();
		$table = $wpdb->prefix . 'ideaxperts_ea_store_identifiers';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d AND normalized_identifier = %s AND identifier_type = %s", $run_id, $normalized, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			throw new \RuntimeException( 'Catalog dry-run database read failed.' );
		}
		$unique = array();
		foreach ( $rows as $row ) {
			$key = (int) ( $row['wc_product_id'] ?? 0 ) . ':' . (int) ( $row['wc_variation_id'] ?? 0 );
			if ( ! isset( $unique[ $key ] ) ) {
				$unique[ $key ] = $row;
			}
		}
		return array_values( $unique );
	}

	/** @return array{products:int,variations:int} */
	public function store_record_counts( int $run_id ): array {
		return $this->store_record_counts_checked( $run_id ) ?? array(
			'products'   => 0,
			'variations' => 0,
		);
	}

	/** @return array{products:int,variations:int}|null */
	private function store_record_counts_checked( int $run_id ): ?array {
		global $wpdb;
		$this->clear_database_error();
		$table = $wpdb->prefix . 'ideaxperts_ea_store_identifiers';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT wc_product_id, wc_variation_id FROM {$table} WHERE run_id = %d", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || ( isset( $wpdb->last_error ) && '' !== $wpdb->last_error ) ) {
			return null;
		}
		$products   = array();
		$variations = array();
		foreach ( $rows as $row ) {
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
	public function mappings( string $product_id, string $option_id, string $source_scope = 'endless-aisles:qa' ): array {
		global $wpdb;
		$this->clear_database_error();
		$table = $wpdb->prefix . 'ideaxperts_ea_mappings';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE mapping_status = %s AND source_scope = %s AND ea_product_id = %s AND ea_option_id = %s", 'active', $source_scope, $product_id, $option_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			throw new \RuntimeException( 'Catalog dry-run database read failed.' );
		}
		return $rows;
	}

	/** @return array<string,mixed>|null */
	public function mapping_for_store_item( int $wc_product_id, int $wc_variation_id, string $source_scope = 'endless-aisles:qa' ): ?array {
		global $wpdb;
		$this->clear_database_error();
		$table = $wpdb->prefix . 'ideaxperts_ea_mappings';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE mapping_status = %s AND source_scope = %s AND wc_product_id = %d AND wc_variation_id = %d", 'active', $source_scope, $wc_product_id, $wc_variation_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			throw new \RuntimeException( 'Catalog dry-run database read failed.' );
		}
		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed> $item */
	public function item( int $run_id, array $item, string $claim_token = '' ): bool {
		$source = $this->source_fields( $run_id );
		$data   = array_merge(
			array(
				'run_id'                    => $run_id,
				'source_scope'              => $source['source_scope'],
				'environment'               => $source['environment'],
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
			$claim_token,
			function () use ( $data ): bool {
				global $wpdb;
				return false !== $wpdb->replace( $wpdb->prefix . 'ideaxperts_ea_dry_run_items', $data );
			}
		);
	}

	/**
	 * @param list<array<string,mixed>> $items
	 * @param list<string>              $duplicate_upcs
	 * @param array<string,mixed>       $run_fields
	 */
	public function persist_catalog_page( int $run_id, string $claim_token, array $items, array $duplicate_upcs, array $run_fields, ?Closure $after_write = null, int $claim_generation = 0 ): bool {
		return $this->with_active_run(
			$run_id,
			$claim_token,
			function () use ( $run_id, $items, $duplicate_upcs, $run_fields, $after_write ): bool {
				global $wpdb;
				foreach ( $items as $item ) {
					$data = $this->item_data( $run_id, $item );
					if ( false === $wpdb->replace( $wpdb->prefix . 'ideaxperts_ea_dry_run_items', $data ) ) {
						return false;
					}
				}
				foreach ( array_values( array_unique( $duplicate_upcs ) ) as $upc ) {
					if ( ! $this->add_review_flag_locked( $run_id, $upc, 'duplicate_vendor_upc' ) ) {
						return false;
					}
				}
				$match_counts = $this->classification_counts_checked( $run_id );
				if ( null === $match_counts ) {
					return false;
				}
				$flag_counts = $this->flag_counts_checked( $run_id );
				if ( null === $flag_counts ) {
					return false;
				}
				$fields = array_merge(
					$run_fields,
					array(
						'match_counters'   => wp_json_encode( $match_counts ),
						'warning_counters' => wp_json_encode( $flag_counts ),
					)
				);
				if ( ! $this->update_run_row( $run_id, $fields ) ) {
					return false;
				}
				return null === $after_write || true === $after_write();
			},
			$claim_generation
		);
	}

	/**
	 * @param list<array<string,mixed>>                         $records
	 * @param array<string,mixed>                               $run_fields
	 * @param array{action_type:string,hook:string,page:int}|null $next
	 */
	public function persist_store_page_execution( int $run_id, string $claim_token, int $claim_generation, int $intent_id, string $intent_token, string $execution_token, string $hook, int $page, array $records, array $run_fields, ?array $next ): bool {
		return $this->with_running_intent(
			$run_id,
			$claim_token,
			$claim_generation,
			$intent_id,
			$intent_token,
			$execution_token,
			'store',
			$hook,
			$page,
			'scanning_store',
			'current_store_page',
			function () use ( $run_id, $records, $run_fields ): bool {
				global $wpdb;
				foreach ( $records as $record ) {
					if ( false === $wpdb->replace( $wpdb->prefix . 'ideaxperts_ea_store_identifiers', $this->store_identifier_data( $run_id, $record ) ) ) {
						return false;
					}
				}
				$counts = $this->store_record_counts_checked( $run_id );
				return null !== $counts && $this->update_run_row(
					$run_id,
					array_merge(
						$run_fields,
						array(
							'products_inspected'      => $counts['products'],
							'variations_inspected'    => $counts['variations'],
							'store_records_inspected' => $counts['products'] + $counts['variations'],
						)
					)
				);
			},
			$next
		);
	}

	/**
	 * @param list<array<string,mixed>>                         $items
	 * @param list<string>                                      $duplicate_upcs
	 * @param array<string,mixed>                               $run_fields
	 * @param array{action_type:string,hook:string,page:int}|null $next
	 */
	public function persist_catalog_page_execution( int $run_id, string $claim_token, int $claim_generation, int $intent_id, string $intent_token, string $execution_token, string $hook, int $page, array $items, array $duplicate_upcs, array $run_fields, ?array $next, bool $keep_running = false ): bool {
		return $this->with_running_intent(
			$run_id,
			$claim_token,
			$claim_generation,
			$intent_id,
			$intent_token,
			$execution_token,
			'catalog',
			$hook,
			$page,
			'fetching_catalog',
			'current_api_page',
			function () use ( $run_id, $items, $duplicate_upcs, $run_fields ): bool {
				global $wpdb;
				foreach ( $items as $item ) {
					if ( false === $wpdb->replace( $wpdb->prefix . 'ideaxperts_ea_dry_run_items', $this->item_data( $run_id, $item ) ) ) {
						return false;
					}
				}
				foreach ( array_values( array_unique( $duplicate_upcs ) ) as $upc ) {
					if ( ! $this->add_review_flag_locked( $run_id, $upc, 'duplicate_vendor_upc' ) ) {
						return false;
					}
				}
				$matches = $this->classification_counts_checked( $run_id );
				$flags   = $this->flag_counts_checked( $run_id );
				return null !== $matches && null !== $flags && $this->update_run_row(
					$run_id,
					array_merge(
						$run_fields,
						array(
							'match_counters'   => wp_json_encode( $matches ),
							'warning_counters' => wp_json_encode( $flags ),
						)
					)
				);
			},
			$next,
			$keep_running
		);
	}

	/** @param array<string,mixed> $diagnostics */
	public function finish_catalog_execution( int $run_id, string $claim_token, int $claim_generation, int $intent_id, string $intent_token, string $execution_token, string $hook, int $next_page, array $diagnostics ): bool {
		return $this->with_running_intent(
			$run_id,
			$claim_token,
			$claim_generation,
			$intent_id,
			$intent_token,
			$execution_token,
			'catalog',
			$hook,
			$next_page,
			'fetching_catalog',
			'current_api_page',
			fn(): bool => $this->update_run_row(
				$run_id,
				array(
					'worker_diagnostics' => wp_json_encode( $diagnostics ),
					'last_heartbeat_at'  => current_time( 'mysql', true ),
				)
			),
			array(
				'action_type' => 'catalog',
				'hook'        => $hook,
				'page'        => $next_page,
			)
		);
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>
	 */
	private function item_data( int $run_id, array $item ): array {
		$source               = $this->source_fields( $run_id );
		$data                 = array_merge(
			array(
				'run_id'                    => $run_id,
				'source_scope'              => $source['source_scope'],
				'environment'               => $source['environment'],
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
		return $data;
	}

	/** @return array{source_scope:string,environment:string} */
	private function source_fields( int $run_id ): array {
		$run         = $this->run( $run_id );
		$environment = is_array( $run ) ? (string) ( $run['environment'] ?? 'qa' ) : 'qa';
		$environment = in_array( $environment, array( 'qa', 'production', 'local' ), true ) ? $environment : 'qa';
		$scope       = is_array( $run ) ? (string) ( $run['source_scope'] ?? '' ) : '';

		return array(
			'source_scope' => '' !== $scope ? $scope : ( 'local' === $environment ? 'local' : 'endless-aisles:' . $environment ),
			'environment'  => $environment,
		);
	}

	public function vendor_upc_used_by_other_option( int $run_id, string $normalized_upc, string $product_id, string $option_id ): ?bool {
		if ( '' === $normalized_upc ) {
			return false;
		}
		global $wpdb;
		$this->clear_database_error();
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_items';
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE run_id = %d AND normalized_upc = %s AND NOT (ea_product_id = %s AND ea_option_id = %s)", $run_id, $normalized_upc, $product_id, $option_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) || null === $value ) {
			return null;
		}
		$count = (int) $value;
		return $count > 0;
	}

	public function add_review_flag_for_upc( int $run_id, string $normalized_upc, string $flag, string $claim_token = '' ): bool {
		$allowed = MatchClassifier::sanitize_flags( array( $flag ) );
		if ( '' === $normalized_upc || array() === $allowed ) {
			return false;
		}
		$flag = $allowed[0];
		return $this->with_active_run(
			$run_id,
			$claim_token,
			function () use ( $run_id, $normalized_upc, $flag ): bool {
				return $this->add_review_flag_locked( $run_id, $normalized_upc, $flag );
			}
		);
	}

	/** @return array<string,int>|null */
	public function classification_counts( int $run_id ): ?array {
		return $this->classification_counts_checked( $run_id );
	}

	/** @return array<string,int>|null */
	private function classification_counts_checked( int $run_id ): ?array {
		global $wpdb;
		$this->clear_database_error();
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_items';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT classification, COUNT(*) AS total FROM {$table} WHERE run_id = %d GROUP BY classification", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return null;
		}
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[ (string) $row['classification'] ] = (int) $row['total'];
		}
		return $result;
	}

	/** @return array<string,int>|null */
	public function flag_counts( int $run_id ): ?array {
		return $this->flag_counts_checked( $run_id );
	}

	/** @return array<string,int>|null */
	private function flag_counts_checked( int $run_id ): ?array {
		global $wpdb;
		$this->clear_database_error();
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_items';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT review_flags FROM {$table} WHERE run_id = %d", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return null;
		}
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

	public function create_action_intent( int $run_id, string $claim_token, int $claim_generation, string $action_type, string $hook, int $page_number ): int {
		if ( ! $this->session_usable || $claim_generation < 1 || '' === $claim_token ) {
			return 0;
		}
		return $this->insert_action_intent( $run_id, $claim_token, $claim_generation, $action_type, $hook, $page_number );
	}

	public function create_maintenance_intent( string $action_type, string $hook, int $claim_generation = 0 ): int {
		if ( ! $this->session_usable ) {
			return 0;
		}
		global $wpdb;
		$table      = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$generation = $claim_generation;
		if ( $generation < 1 ) {
			$this->clear_database_error();
			$generation = (int) $wpdb->get_var( "SELECT COALESCE(MAX(claim_generation),0) + 1 FROM {$table} WHERE run_id = 0 AND action_type = 'purge'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internally constructed table and constant predicate.
			if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
				return 0;
			}
		}
		return $this->insert_action_intent( 0, '', max( 1, $generation ), $action_type, $hook, 0 );
	}

	private function insert_action_intent( int $run_id, string $claim_token, int $claim_generation, string $action_type, string $hook, int $page_number ): int {
		global $wpdb;
		$source = 0 === $run_id ? array(
			'source_scope' => 'local',
			'environment'  => 'local',
		) : $this->source_fields( $run_id );
		$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$now    = current_time( 'mysql', true );
		$token  = $this->new_lock_token();
		$ok     = $wpdb->insert(
			$table,
			array(
				'run_id'                    => $run_id,
				'source_scope'              => $source['source_scope'],
				'environment'               => $source['environment'],
				'claim_token'               => $claim_token,
				'claim_generation'          => $claim_generation,
				'intent_token'              => $token,
				'action_type'               => $action_type,
				'hook'                      => $hook,
				'page_number'               => $page_number,
				'status'                    => 'pending',
				'action_scheduler_id'       => null,
				'attempts'                  => 0,
				'available_at'              => $now,
				'last_attempt_at'           => null,
				'dispatch_token'            => '',
				'dispatch_started_at'       => null,
				'dispatch_lease_expires_at' => null,
				'execution_token'           => '',
				'started_at'                => null,
				'lease_expires_at'          => null,
				'created_at'                => $now,
				'updated_at'                => $now,
			)
		);
		if ( false !== $ok ) {
			return (int) $wpdb->insert_id;
		}
		$this->clear_database_error();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internally constructed table name.
				"SELECT * FROM {$table} WHERE run_id = %d AND claim_generation = %d AND action_type = %s AND page_number = %d",
				$run_id,
				$claim_generation,
				$action_type,
				$page_number
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) && hash_equals( (string) ( $row['claim_token'] ?? '' ), $claim_token ) ? (int) $row['id'] : 0;
	}

	/** @return array<string,mixed>|null */
	public function action_intent( int $intent_id, string $intent_token = '' ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $intent_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) || ( '' !== $intent_token && ! hash_equals( (string) ( $row['intent_token'] ?? '' ), $intent_token ) ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * Atomically authenticates an action and leases it to one callback.
	 *
	 * @return string The new execution token, or an empty string when the claim is stale or invalid.
	 */
	public function claim_intent_execution( int $intent_id, string $intent_token, int $run_id, string $claim_token, int $claim_generation, string $action_type, string $hook, int $page_number, string $run_status = '', string $cursor_column = '' ): string {
		global $wpdb;
		if ( ! $this->session_usable || $intent_id < 1 || '' === $intent_token || $claim_generation < 1 || '' === $action_type || '' === $hook || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return '';
		}
		$open      = true;
		$execution = $this->new_lock_token();
		$now       = current_time( 'mysql', true );
		$expires   = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::EXECUTION_LEASE_SECONDS );
		try {
			if ( $run_id > 0 ) {
				$run_table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
				$run       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$run_table} WHERE id = %d AND claim_token = %s AND claim_generation = %d FOR UPDATE", $run_id, $claim_token, $claim_generation ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! is_array( $run ) || '' !== (string) ( $wpdb->last_error ?? '' ) || $run_status !== (string) ( $run['status'] ?? '' ) || ! in_array( $cursor_column, array( 'current_store_page', 'current_api_page' ), true ) || ( 'catalog' === $action_type ? $page_number - 1 > (int) ( $run[ $cursor_column ] ?? -1 ) : $page_number - 1 !== (int) ( $run[ $cursor_column ] ?? -1 ) ) ) { // phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Both comparisons use runtime values.
					$this->rollback();
					$open = false;
					return '';
				}
			} elseif ( '' !== $run_status || '' !== $cursor_column || '' !== $claim_token ) {
				$this->rollback();
				$open = false;
				return '';
			}

			$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
			$intent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND intent_token = %s FOR UPDATE", $intent_id, $intent_token ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$valid  = is_array( $intent ) && '' === (string) ( $wpdb->last_error ?? '' ) &&
				(int) $intent['run_id'] === $run_id && hash_equals( (string) $intent['claim_token'], $claim_token ) &&
				(int) $intent['claim_generation'] === $claim_generation && (string) $intent['action_type'] === $action_type &&
				(string) $intent['hook'] === $hook && (int) $intent['page_number'] === $page_number;
			// Only a previously started catalog execution may reclaim an advanced cursor.
			if ( $valid && 'catalog' === $action_type && isset( $run ) && $page_number - 1 !== (int) $run['current_api_page'] && '' === (string) ( $intent['started_at'] ?? '' ) ) { // phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Runtime cursor comparison.
				$valid = false;
			}
			$status = is_array( $intent ) ? (string) ( $intent['status'] ?? '' ) : '';
			$leased = 'running' === $status && '' !== (string) ( $intent['lease_expires_at'] ?? '' ) && (string) $intent['lease_expires_at'] <= $now;
			if ( ! $valid || ( ! in_array( $status, array( 'dispatching', 'dispatched' ), true ) && ! $leased ) ) {
				$this->rollback();
				$open = false;
				return '';
			}
			$result = $wpdb->update(
				$table,
				array(
					'status'           => 'running',
					'execution_token'  => $execution,
					'started_at'       => $now,
					'lease_expires_at' => $expires,
					'updated_at'       => $now,
				),
				array(
					'id'              => $intent_id,
					'intent_token'    => $intent_token,
					'status'          => $status,
					'execution_token' => (string) ( $intent['execution_token'] ?? '' ),
				)
			);
			if ( false === $result || (int) $wpdb->rows_affected < 1 || false === $wpdb->query( 'COMMIT' ) ) {
				$this->rollback();
				$open = false;
				return '';
			}
			$open = false;
			return $execution;
		} catch ( \Throwable $exception ) {
			$this->rollback();
			$open = false;
			return '';
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	/** @phpstan-impure Ownership can change between calls in another database session. */
	public function refresh_intent_execution( int $intent_id, string $intent_token, string $execution_token ): bool {
		if ( ! $this->session_usable || $intent_id < 1 || '' === $intent_token || '' === $execution_token ) {
			return false;
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::EXECUTION_LEASE_SECONDS );
		$result  = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lease_expires_at = %s, updated_at = %s WHERE id = %d AND intent_token = %s AND execution_token = %s AND status = %s AND lease_expires_at >= %s", $expires, $now, $intent_id, $intent_token, $execution_token, 'running', $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $result ) {
			return false;
		}
		if ( (int) $wpdb->rows_affected > 0 ) {
			return true;
		}
		// Same-second refreshes can change no values. Recheck ownership and sample
		// the clock again so a lease that expires before confirmation cannot succeed.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND intent_token = %s AND execution_token = %s AND status = %s AND lease_expires_at >= %s LIMIT 1", $intent_id, $intent_token, $execution_token, 'running', current_time( 'mysql', true ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) && empty( $wpdb->last_error );
	}

	public function reclaim_expired_executions( int $limit = self::ACTION_BATCH_SIZE ): int {
		if ( ! $this->session_usable ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$now   = current_time( 'mysql', true );
		$this->clear_database_error();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s ORDER BY lease_expires_at ASC, id ASC LIMIT %d", 'running', $now, max( 1, min( self::ACTION_BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return 0;
		}
		$reclaimed = 0;
		foreach ( $rows as $row ) {
			$result = $wpdb->update(
				$table,
				array(
					'status'              => 'failed',
					'action_scheduler_id' => null,
					'execution_token'     => '',
					'lease_expires_at'    => null,
					'available_at'        => $now,
					'updated_at'          => $now,
				),
				array(
					'id'               => (int) $row['id'],
					'intent_token'     => (string) $row['intent_token'],
					'status'           => 'running',
					'execution_token'  => (string) $row['execution_token'],
					'lease_expires_at' => (string) $row['lease_expires_at'],
				)
			);
			if ( false !== $result && (int) $wpdb->rows_affected > 0 ) {
				++$reclaimed;
			}
		}
		return $reclaimed;
	}

	/** @return list<array<string,mixed>> */
	public function dispatchable_intents( int $limit = self::ACTION_BATCH_SIZE ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internally constructed table name.
				"SELECT * FROM {$table} WHERE status IN (%s,%s) AND available_at <= %s AND attempts < %d ORDER BY id ASC LIMIT %d",
				'pending',
				'failed',
				current_time( 'mysql', true ),
				self::MAX_DISPATCH_ATTEMPTS,
				max( 1, min( self::ACTION_BATCH_SIZE, $limit ) )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** @return list<array<string,mixed>> */
	public function stale_dispatching_intents( int $limit = self::ACTION_BATCH_SIZE ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$now   = current_time( 'mysql', true );
		$this->clear_database_error();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND dispatch_token <> %s AND dispatch_lease_expires_at IS NOT NULL AND dispatch_lease_expires_at <= %s ORDER BY dispatch_lease_expires_at ASC, id ASC LIMIT %d", 'dispatching', '', $now, max( 1, min( self::ACTION_BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) && '' === (string) ( $wpdb->last_error ?? '' ) ? $rows : array();
	}

	/** @return list<array<string,mixed>> */
	public function exhausted_intents( int $limit = self::ACTION_BATCH_SIZE ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id > 0 AND status = %s AND attempts >= %d ORDER BY id ASC LIMIT %d", 'failed', self::MAX_DISPATCH_ATTEMPTS, max( 1, min( self::ACTION_BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	public function mark_intent_dispatching( int $intent_id, string $intent_token ): string {
		if ( ! $this->session_usable || $intent_id < 1 || '' === $intent_token ) {
			return '';
		}
		global $wpdb;
		$table     = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$now       = current_time( 'mysql', true );
		$expires   = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::DISPATCH_LEASE_SECONDS );
		$ownership = $this->new_lock_token();
		$result    = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internally constructed table name.
				"UPDATE {$table} SET status = %s, dispatch_token = %s, dispatch_started_at = %s, dispatch_lease_expires_at = %s, last_attempt_at = %s, updated_at = %s WHERE id = %d AND intent_token = %s AND status IN (%s,%s) AND available_at <= %s AND attempts < %d",
				'dispatching',
				$ownership,
				$now,
				$expires,
				$now,
				$now,
				$intent_id,
				$intent_token,
				'pending',
				'failed',
				$now,
				self::MAX_DISPATCH_ATTEMPTS
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false !== $result && 1 === (int) $wpdb->rows_affected ? $ownership : '';
	}

	public function mark_intent_dispatched( int $intent_id, string $intent_token, string $dispatch_token, int $scheduler_id ): bool {
		if ( '' === $dispatch_token || $scheduler_id < 1 ) {
			return false;
		}
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$intent = $this->action_intent( $intent_id, $intent_token );
			if ( ! $intent || ! hash_equals( (string) ( $intent['dispatch_token'] ?? '' ), $dispatch_token ) || ! in_array( (string) $intent['status'], array( 'dispatching', 'running', 'completed' ), true ) ) {
				return false;
			}
			$recorded = (int) ( $intent['action_scheduler_id'] ?? 0 );
			if ( $recorded > 0 ) {
				return false;
			}
			$status = (string) $intent['status'];
			global $wpdb;
			$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
			$result = $wpdb->update(
				$table,
				array(
					'status'                    => 'dispatching' === $status ? 'dispatched' : $status,
					'action_scheduler_id'       => $scheduler_id,
					'attempts'                  => (int) $intent['attempts'] + 1,
					'dispatch_token'            => '',
					'dispatch_started_at'       => null,
					'dispatch_lease_expires_at' => null,
					'updated_at'                => current_time( 'mysql', true ),
				),
				array(
					'id'             => $intent_id,
					'intent_token'   => $intent_token,
					'status'         => $status,
					'dispatch_token' => $dispatch_token,
				)
			);
			if ( false !== $result && 1 === (int) $wpdb->rows_affected ) {
				return true;
			}
		}
		return false;
	}

	public function mark_intent_dispatch_failed( int $intent_id, string $intent_token, string $dispatch_token ): bool {
		$intent = $this->action_intent( $intent_id, $intent_token );
		if ( ! $intent || 'dispatching' !== (string) $intent['status'] || '' === $dispatch_token || ! hash_equals( (string) ( $intent['dispatch_token'] ?? '' ), $dispatch_token ) ) {
			return false;
		}
		$attempts = (int) $intent['attempts'] + 1;
		$delay    = min( 3600, 30 * ( 2 ** min( 6, $attempts - 1 ) ) );
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$result = $wpdb->update(
			$table,
			array(
				'status'                    => 'failed',
				'attempts'                  => $attempts,
				'available_at'              => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) ) + $delay ),
				'dispatch_token'            => '',
				'dispatch_started_at'       => null,
				'dispatch_lease_expires_at' => null,
				'updated_at'                => current_time( 'mysql', true ),
			),
			array(
				'id'             => $intent_id,
				'intent_token'   => $intent_token,
				'status'         => 'dispatching',
				'dispatch_token' => $dispatch_token,
			)
		);
		return false !== $result && 1 === (int) $wpdb->rows_affected;
	}

	public function release_expired_dispatch( int $intent_id, string $intent_token, string $dispatch_token, string $lease_expires_at ): bool {
		$now = current_time( 'mysql', true );
		if ( '' === $dispatch_token || '' === $lease_expires_at || $lease_expires_at > $now ) {
			return false;
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$result = $wpdb->update(
			$table,
			array(
				'status'                    => 'failed',
				'available_at'              => $now,
				'dispatch_token'            => '',
				'dispatch_started_at'       => null,
				'dispatch_lease_expires_at' => null,
				'updated_at'                => $now,
			),
			array(
				'id'                        => $intent_id,
				'intent_token'              => $intent_token,
				'status'                    => 'dispatching',
				'dispatch_token'            => $dispatch_token,
				'dispatch_lease_expires_at' => $lease_expires_at,
			)
		);
		return false !== $result && 1 === (int) $wpdb->rows_affected;
	}

	public function complete_intent( int $intent_id, string $intent_token ): bool {
		return $this->update_intent( $intent_id, $intent_token, array( 'dispatched' ), array( 'status' => 'completed' ) );
	}

	public function complete_intent_execution( int $intent_id, string $intent_token, string $execution_token ): bool {
		if ( '' === $execution_token ) {
			return false;
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND intent_token = %s AND status = %s AND execution_token = %s AND lease_expires_at >= %s", 'completed', current_time( 'mysql', true ), $intent_id, $intent_token, 'running', $execution_token, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false !== $result && (int) $wpdb->rows_affected > 0;
	}

	public function cancel_intent( int $intent_id, string $intent_token ): bool {
		return $this->update_intent( $intent_id, $intent_token, array( 'cancel_requested' ), array( 'status' => 'cancelled' ) );
	}

	/** @return list<array<string,mixed>> */
	public function cancellation_intents( int $limit = self::ACTION_BATCH_SIZE ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d", 'cancel_requested', max( 1, min( self::ACTION_BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** @return list<array<string,mixed>> */
	public function cleanup_runs( int $limit = self::ACTION_BATCH_SIZE ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN (%s,%s) ORDER BY id ASC LIMIT %d", 'cancelling', 'recovering', max( 1, min( self::ACTION_BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	public function mark_maintenance_intents_cancel_requested(): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internally constructed table name.
				"UPDATE {$table} SET status = %s, updated_at = %s WHERE run_id = 0 AND status IN (%s,%s,%s,%s,%s)",
				'cancel_requested',
				current_time( 'mysql', true ),
				'pending',
				'dispatching',
				'dispatched',
				'failed',
				'running'
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false !== $result;
	}

	public function mark_claim_intents_cancel_requested( int $run_id, string $claim_token, int $claim_generation ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internally constructed table name.
				"UPDATE {$table} SET status = %s, updated_at = %s WHERE run_id = %d AND claim_token = %s AND claim_generation = %d AND status IN (%s,%s,%s,%s,%s)",
				'cancel_requested',
				current_time( 'mysql', true ),
				$run_id,
				$claim_token,
				$claim_generation,
				'pending',
				'dispatching',
				'dispatched',
				'failed',
				'running'
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false !== $result;
	}

	public function has_open_claim_intents( int $run_id, string $claim_token, int $claim_generation ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$this->clear_database_error();
		$id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internally constructed table name.
				"SELECT id FROM {$table} WHERE run_id = %d AND claim_token = %s AND claim_generation = %d AND status IN (%s,%s,%s,%s,%s,%s) LIMIT 1",
				$run_id,
				$claim_token,
				$claim_generation,
				'pending',
				'dispatching',
				'dispatched',
				'failed',
				'cancel_requested',
				'running'
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return '' !== (string) ( $wpdb->last_error ?? '' ) || null !== $id;
	}

	/**
	 * @param list<string>        $statuses Allowed current statuses.
	 * @param array<string,mixed> $fields   Replacement fields.
	 */
	private function update_intent( int $intent_id, string $intent_token, array $statuses, array $fields ): bool {
		if ( ! $this->session_usable || '' === $intent_token ) {
			return false;
		}
		global $wpdb;
		$table                = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
		$fields['updated_at'] = current_time( 'mysql', true );
		$set                  = array();
		$args                 = array();
		foreach ( $fields as $column => $value ) {
			$set[]  = $column . ' = %s';
			$args[] = null === $value ? null : (string) $value;
		}
		$args[]       = $intent_id;
		$args[]       = $intent_token;
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = array_merge( $args, $statuses );
		$result       = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . " WHERE id = %d AND intent_token = %s AND status IN ({$placeholders})", ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( false === $result ) {
			return false;
		}
		if ( (int) $wpdb->rows_affected > 0 ) {
			return true;
		}
		$current = $this->action_intent( $intent_id, $intent_token );
		if ( ! $current ) {
			return false;
		}
		foreach ( $fields as $column => $value ) {
			if ( (string) ( $current[ $column ] ?? '' ) !== (string) ( $value ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	private function clear_database_error(): void {
		global $wpdb;
		if ( property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
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

	/**
	 * @param list<int> $item_ids Dry-run item IDs.
	 * @return list<array<string,mixed>>
	 */
	public function items_by_ids( int $run_id, array $item_ids ): array {
		$item_ids = array_values( array_unique( array_filter( array_map( 'absint', $item_ids ) ) ) );
		if ( array() === $item_ids || count( $item_ids ) > 100000 ) {
			return array();
		}
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_items';
		$slots = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d AND id IN ({$slots}) ORDER BY id ASC", $run_id, ...$item_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return is_array( $rows ) ? $rows : array();
	}

	public function purge_expired(): int {
		$deleted = 0;
		foreach ( $this->purge_candidate_ids( self::PURGE_BATCH_SIZE ) as $run_id ) {
			$deleted += $this->delete_run( $run_id ) ? 1 : 0;
		}
		return $deleted;
	}

	public function has_purge_remaining(): bool {
		return array() !== $this->purge_candidate_ids( 1 );
	}

	/** @return list<int> */
	private function purge_candidate_ids( int $limit ): array {
		global $wpdb;
		$table            = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$now              = strtotime( current_time( 'mysql', true ) );
		$completed_cutoff = gmdate( 'Y-m-d H:i:s', $now - self::COMPLETED_RETENTION_DAYS * DAY_IN_SECONDS );
		$cancelled_cutoff = gmdate( 'Y-m-d H:i:s', $now - self::CANCELLED_RETENTION_DAYS * DAY_IN_SECONDS );
		$failed_cutoff    = gmdate( 'Y-m-d H:i:s', $now - self::FAILED_RETENTION_DAYS * DAY_IN_SECONDS );
		$sql              = "SELECT id FROM {$table} r WHERE (" .
			'(r.status = %s AND COALESCE(r.completed_at,r.updated_at) < %s AND r.id NOT IN (' .
			"SELECT keep_id FROM (SELECT MAX(id) AS keep_id FROM {$table} WHERE status = %s GROUP BY environment) latest_completed)) " .
			'OR (r.status = %s AND COALESCE(r.completed_at,r.updated_at) < %s) ' .
			'OR (r.status = %s AND COALESCE(r.completed_at,r.updated_at) < %s)) ORDER BY r.id ASC LIMIT %d';
		$rows             = $wpdb->get_results( $wpdb->prepare( $sql, 'completed', $completed_cutoff, 'completed', 'cancelled', $cancelled_cutoff, 'failed', $failed_cutoff, max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids              = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$ids[] = (int) $row['id'];
		}
		return $ids;
	}

	private function delete_run( int $run_id ): bool {
		global $wpdb;
		if ( ! $this->session_usable || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$open = true;
		try {
			if ( false === $wpdb->delete( $wpdb->prefix . 'ideaxperts_ea_dry_run_actions', array( 'run_id' => $run_id ), array( '%d' ) ) ||
				false === $wpdb->delete( $wpdb->prefix . 'ideaxperts_ea_dry_run_items', array( 'run_id' => $run_id ), array( '%d' ) ) ||
				false === $wpdb->delete( $wpdb->prefix . 'ideaxperts_ea_store_identifiers', array( 'run_id' => $run_id ), array( '%d' ) ) ||
				false === $wpdb->delete( $wpdb->prefix . 'ideaxperts_ea_dry_runs', array( 'id' => $run_id ), array( '%d' ) ) ) {
					$this->rollback();
					$open = false;
					return false;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$this->rollback();
				$open = false;
				return false;
			}
				$open = false;
				return true;
		} catch ( \Throwable $exception ) {
			$this->rollback();
			$open = false;
			return false;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	/** @param array<string,mixed> $fields */
	private function update_run_row( int $run_id, array $fields ): bool {
		if ( ! $this->session_usable ) {
			return false;
		}
		global $wpdb;
		$data               = array_intersect_key( $fields, array_flip( self::RUN_FIELDS ) );
		$data['updated_at'] = current_time( 'mysql', true );
		return false !== $wpdb->update( $wpdb->prefix . 'ideaxperts_ea_dry_runs', $data, array( 'id' => $run_id ) );
	}

	private function add_review_flag_locked( int $run_id, string $normalized_upc, string $flag ): bool {
		global $wpdb;
		$this->clear_database_error();
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_run_items';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, review_flags FROM {$table} WHERE run_id = %d AND normalized_upc = %s", $run_id, $normalized_upc ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || ( isset( $wpdb->last_error ) && '' !== $wpdb->last_error ) ) {
			return false;
		}
		foreach ( $rows as $row ) {
			$flags = MatchClassifier::sanitize_flags( json_decode( (string) $row['review_flags'], true ) );
			if ( in_array( $flag, $flags, true ) ) {
				continue;
			}
			$flags[] = $flag;
			if ( false === $wpdb->update(
				$table,
				array(
					'review_flags' => wp_json_encode( MatchClassifier::sanitize_flags( $flags ) ),
					'updated_at'   => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $row['id'] )
			) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param Closure():bool                                      $write
	 * @param array{action_type:string,hook:string,page:int}|null $next
	 */
	private function with_running_intent( int $run_id, string $claim_token, int $claim_generation, int $intent_id, string $intent_token, string $execution_token, string $action_type, string $hook, int $page, string $run_status, string $cursor_column, Closure $write, ?array $next, bool $keep_running = false ): bool {
		global $wpdb;
		if ( ! $this->session_usable || '' === $execution_token || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$open = true;
		try {
			$run_table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
			$run       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$run_table} WHERE id = %d AND claim_token = %s AND claim_generation = %d FOR UPDATE", $run_id, $claim_token, $claim_generation ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! is_array( $run ) || '' !== (string) ( $wpdb->last_error ?? '' ) || $run_status !== (string) ( $run['status'] ?? '' ) || ! in_array( $cursor_column, array( 'current_store_page', 'current_api_page' ), true ) || $page - 1 !== (int) ( $run[ $cursor_column ] ?? -1 ) ) { // phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Both comparisons use runtime values.
				$this->rollback();
				$open = false;
				return false;
			}
			$table  = $wpdb->prefix . 'ideaxperts_ea_dry_run_actions';
			$intent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND intent_token = %s FOR UPDATE", $intent_id, $intent_token ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$now    = current_time( 'mysql', true );
			$valid  = is_array( $intent ) && '' === (string) ( $wpdb->last_error ?? '' ) && 'running' === (string) ( $intent['status'] ?? '' ) &&
				hash_equals( (string) ( $intent['execution_token'] ?? '' ), $execution_token ) && (string) ( $intent['lease_expires_at'] ?? '' ) >= $now &&
				(int) $intent['run_id'] === $run_id && hash_equals( (string) $intent['claim_token'], $claim_token ) && (int) $intent['claim_generation'] === $claim_generation &&
				(string) $intent['action_type'] === $action_type && (string) $intent['hook'] === $hook && ( 'catalog' === $action_type ? (int) $intent['page_number'] <= $page : (int) $intent['page_number'] === $page );
			if ( ! $valid || true !== $write() ) {
				$this->rollback();
				$open = false;
				return false;
			}
			if ( null !== $next && $this->insert_action_intent( $run_id, $claim_token, $claim_generation, $next['action_type'], $next['hook'], $next['page'] ) < 1 ) {
				$this->rollback();
				$open = false;
				return false;
			}
			$completed = $wpdb->update(
				$table,
				array(
					'status'           => $keep_running ? 'running' : 'completed',
					'lease_expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::EXECUTION_LEASE_SECONDS ),
					'updated_at'       => $now,
				),
				array(
					'id'              => $intent_id,
					'intent_token'    => $intent_token,
					'status'          => 'running',
					'execution_token' => $execution_token,
				)
			);
			// The locked row already proved all fences. A running lease refresh may
			// be a same-second no-op; only false is a database failure.
			if ( false === $completed || false === $wpdb->query( 'COMMIT' ) ) {
				$this->rollback();
				$open = false;
				return false;
			}
			$open = false;
			return true;
		} catch ( \Throwable $exception ) {
			$this->rollback();
			$open = false;
			return false;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	/** @param Closure():bool $write */
	private function with_active_run( int $run_id, string $claim_token, Closure $write, int $claim_generation = 0 ): bool {
		return $this->with_locked_run( $run_id, $claim_token, array( 'pending', 'scanning_store', 'fetching_catalog' ), static fn( array $run ): bool => $write(), $claim_generation );
	}

	/** @param list<string> $statuses @param Closure(array<string,mixed>):bool $write */
	public function with_locked_run( int $run_id, string $claim_token, array $statuses, Closure $write, int $claim_generation = 0 ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		if ( ! $this->session_usable || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$open = true;
		try {
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$args         = array( $run_id, $claim_token );
			$generation   = $claim_generation > 0 ? ' AND claim_generation = %d' : '';
			if ( $claim_generation > 0 ) {
				$args[] = $claim_generation;
			}
			$args = array_merge( $args, $statuses );
			$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND claim_token = %s{$generation} AND status IN ({$placeholders}) FOR UPDATE", ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			if ( ! is_array( $row ) || ( isset( $wpdb->last_error ) && '' !== $wpdb->last_error ) || true !== $write( $row ) ) {
				$this->rollback();
				$open = false;
				return false;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$this->rollback();
				$open = false;
				return false;
			}
			$open = false;
			return true;
		} catch ( \Throwable $exception ) {
			$this->rollback();
			$open = false;
			return false;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	public function session_usable(): bool {
		return $this->session_usable;
	}

	private function rollback(): bool {
		global $wpdb;
		$result = $wpdb->query( 'ROLLBACK' );
		if ( false === $result ) {
			$this->session_usable = false;
			return false;
		}
		return true;
	}

	private function new_lock_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	private function lock_payload( string $token, int $run_id, int $generation ): string {
		return (string) wp_json_encode(
			array(
				'token'      => $token,
				'run_id'     => $run_id,
				'generation' => $generation,
				'claimed_at' => current_time( 'mysql', true ),
			)
		);
	}

	/** @return array{token:string,run_id:int,generation:int,claimed_at:string}|null */
	private function parse_lock( string $value ): ?array {
		$parsed = json_decode( $value, true );
		if ( ! is_array( $parsed ) || ! isset( $parsed['token'], $parsed['run_id'] ) ) {
			return null;
		}
		return array(
			'token'      => (string) $parsed['token'],
			'run_id'     => (int) $parsed['run_id'],
			'generation' => max( 1, (int) ( $parsed['generation'] ?? 1 ) ),
			'claimed_at' => (string) ( $parsed['claimed_at'] ?? '' ),
		);
	}

	private function lock_value(): string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_string( $value ) ? $value : '';
	}

	private function insert_lock( string $value ): bool {
		if ( ! $this->session_usable ) {
			return false;
		}
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::LOCK_OPTION, $value, 'no' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->flush_lock_cache();
		return false !== $result && (int) $wpdb->rows_affected > 0;
	}

	private function cas_update_lock( string $expected, string $replacement ): bool {
		if ( ! $this->session_usable ) {
			return false;
		}
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $replacement, self::LOCK_OPTION, $expected ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->flush_lock_cache();
		return false !== $result && (int) $wpdb->rows_affected > 0;
	}

	private function cas_delete_lock( string $expected ): bool {
		if ( ! $this->session_usable ) {
			return false;
		}
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
