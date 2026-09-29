<?php
namespace IdeaXperts\EndlessAisles\Database;

use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\CatalogStateProviderInterface;
use IdeaXperts\EndlessAisles\Import\ImportPolicy;

defined( 'ABSPATH' ) || exit;

final class ImportRepository {
	public const RUN_STATES            = array( 'preparing', 'awaiting_confirmation', 'queued', 'running', 'pausing_stale', 'awaiting_reapproval', 'cancelling', 'cancelled', 'completed', 'completed_with_issues', 'failed_recoverable', 'failed_terminal' );
	public const ITEM_STATES           = array( 'pending', 'leased', 'validating', 'ready', 'applying', 'reconciling', 'applied', 'stale_snapshot', 'manual_required', 'blocked', 'retry_wait', 'cancelled', 'manual_recovery' );
	public const ACTION_STATES         = array( 'pending', 'dispatching', 'dispatched', 'running', 'completed', 'retry_wait', 'cancel_requested', 'cancelled', 'dead' );
	public const ITEM_LEASE_SECONDS    = 300;
	public const ACTION_LEASE_SECONDS  = 300;
	public const MAX_DISPATCH_ATTEMPTS = 5;
	public const BATCH_SIZE            = 25;
	public const MAX_MANIFEST_BYTES    = 1048576;
	public const MAX_EVENT_BYTES       = 4000;
	private const RUN_TRANSITIONS      = array(
		'preparing'             => array( 'awaiting_confirmation', 'failed_terminal' ),
		'awaiting_confirmation' => array( 'queued', 'cancelled' ),
		'queued'                => array( 'running', 'cancelling', 'failed_recoverable', 'failed_terminal' ),
		'running'               => array( 'pausing_stale', 'cancelling', 'completed', 'completed_with_issues', 'failed_recoverable', 'failed_terminal' ),
		'pausing_stale'         => array( 'awaiting_reapproval', 'cancelling', 'failed_recoverable' ),
		'awaiting_reapproval'   => array( 'queued', 'cancelling', 'failed_terminal' ),
		'failed_recoverable'    => array( 'running', 'cancelling', 'failed_terminal' ),
		'cancelling'            => array( 'cancelled', 'failed_recoverable' ),
	);
	private const ITEM_TRANSITIONS     = array(
		'pending'     => array( 'leased', 'cancelled' ),
		'retry_wait'  => array( 'leased', 'cancelled' ),
		'leased'      => array( 'validating', 'retry_wait', 'cancelled' ),
		'validating'  => array( 'ready', 'stale_snapshot', 'manual_required', 'blocked', 'retry_wait', 'cancelled' ),
		'ready'       => array( 'applying', 'retry_wait', 'cancelled' ),
		'applying'    => array( 'reconciling' ),
		'reconciling' => array( 'applied', 'manual_recovery' ),
	);

	private bool $session_usable = true;

	public function __construct( private readonly ?CatalogStateProviderInterface $catalog_state = null ) {}

	/** @param array<string,mixed> $manifest */
	public function create_from_manifest( array $manifest, string $manifest_hash, int $actor_id ): int {
		$json = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_MANIFEST_BYTES || ! ( new ApprovalManifest( new \IdeaXperts\EndlessAisles\Import\ImportPolicy() ) )->verify( $manifest, $manifest_hash ) ) {
			return 0;
		}
		$environment = (string) ( $manifest['environment'] ?? '' );
		$scope       = (string) ( $manifest['source_scope'] ?? '' );
		$items       = $manifest['items'] ?? null;
		if ( ! in_array( $environment, array( 'qa', 'production' ), true ) || 'endless-aisles:' . $environment !== $scope || ImportPolicy::VERSION !== (string) ( $manifest['policy_version'] ?? '' ) || (int) ( $manifest['dry_run_id'] ?? 0 ) < 1 || (int) ( $manifest['dry_run_generation'] ?? 0 ) < 1 || (int) ( $manifest['approval_generation'] ?? 0 ) < 1 || 64 !== strlen( (string) ( $manifest['matching_settings_hash'] ?? '' ) ) || ! is_array( $items ) || ! array_is_list( $items ) || array() === $items ) {
			return 0;
		}
		global $wpdb;
		if ( ! $this->begin() ) {
			return 0;
		}
		$open = true;
		try {
			$now   = current_time( 'mysql', true );
			$token = $this->token();
			$ok    = $wpdb->insert(
				$wpdb->prefix . 'ideaxperts_ea_import_runs',
				array(
					'dry_run_id'                    => (int) ( $manifest['dry_run_id'] ?? 0 ),
					'source_scope'                  => $scope,
					'environment'                   => $environment,
					'status'                        => 'queued',
					'approval_generation'           => (int) ( $manifest['approval_generation'] ?? 0 ),
					'approved_by'                   => $actor_id,
					'approved_at'                   => $now,
					'approval_manifest'             => $json,
					'manifest_hash'                 => $manifest_hash,
					'matching_settings_hash'        => (string) ( $manifest['matching_settings_hash'] ?? '' ),
					'policy_version'                => (string) ( $manifest['policy_version'] ?? '' ),
					'claim_token'                   => $token,
					'claim_generation'              => 1,
					'item_count'                    => count( $items ),
					'applied_count'                 => 0,
					'issue_count'                   => 0,
					'cancellation_requested_at'     => null,
					'cancellation_authoritative_at' => null,
					'last_heartbeat_at'             => $now,
					'failure_summary'               => '',
					'created_at'                    => $now,
					'updated_at'                    => $now,
					'completed_at'                  => null,
				)
			);
			if ( false === $ok ) {
				return $this->abort_int( $open );
			}
			$run_id = (int) $wpdb->insert_id;
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || ! $this->validate_manifest_item( $item, $environment ) || ! $this->insert_manifest_item( $run_id, $manifest, $item, $actor_id, $now ) || ! $this->insert_snapshot( $manifest, $item, $now ) ) {
					return $this->abort_int( $open );
				}
			}
			if ( ! $this->event(
				$run_id,
				0,
				(int) $manifest['dry_run_id'],
				'import_approved',
				$actor_id,
				array(
					'manifest_hash' => $manifest_hash,
					'item_count'    => count( $items ),
				)
			) || false === $wpdb->query( 'COMMIT' ) ) {
				return $this->abort_int( $open );
			}
			$open = false;
			return $run_id;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	/** @return array<string,mixed>|null */
	public function run( int $run_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_runs WHERE id = %d', $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function run_for_approval( int $dry_run_id, int $generation, string $manifest_hash, int $actor_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_runs WHERE dry_run_id = %d AND approval_generation = %d AND manifest_hash = %s AND approved_by = %d', $dry_run_id, $generation, $manifest_hash, $actor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function item( int $item_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_items WHERE id = %d', $item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** @return list<array<string,mixed>> */
	public function items( int $run_id, int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_items WHERE import_run_id = %d ORDER BY id ASC LIMIT %d', $run_id, max( 1, min( 100, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** @return list<array<string,mixed>> */
	public function pending_items_after( int $run_id, int $after_id = 0, int $limit = self::BATCH_SIZE ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_items WHERE import_run_id = %d AND status = %s AND id > %d ORDER BY id ASC LIMIT %d', $run_id, 'pending', max( 0, $after_id ), max( 1, min( self::BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,int> */
	public function item_status_counts( int $run_id ): array {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_items WHERE import_run_id = %d GROUP BY status', $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/** @return array<string,mixed>|null */
	public function latest_run(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_runs ORDER BY id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	public function next_approval_generation( int $dry_run_id ): int {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(approval_generation),0) FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_runs WHERE dry_run_id = %d', $dry_run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return max( 1, (int) $value + 1 );
	}

	/** @return list<int> */
	public function active_run_ids(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_import_runs';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE status IN (%s,%s,%s,%s,%s,%s) ORDER BY id ASC", 'queued', 'running', 'pausing_stale', 'awaiting_reapproval', 'failed_recoverable', 'cancelling' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( static fn( array $row ): int => (int) $row['id'], is_array( $rows ) ? $rows : array() );
	}

	public function start_run( int $run_id ): bool {
		return $this->transition_run( $run_id, array( 'queued', 'failed_recoverable' ), 'running' );
	}

	/** @param list<string> $from */
	public function transition_run( int $run_id, array $from, string $to ): bool {
		if ( ! in_array( $to, self::RUN_STATES, true ) || array() === $from ) {
			return false;
		}
		global $wpdb;
		if ( ! $this->begin() ) {
			return false;
		}
		$open = true;
		try {
			$run     = $this->locked_run( $run_id );
			$current = (string) ( $run['status'] ?? '' );
			if ( ! $run || ! in_array( $current, $from, true ) || ! in_array( $to, self::RUN_TRANSITIONS[ $current ] ?? array(), true ) ) {
				return $this->abort( $open );
			}
			$ok = $wpdb->update(
				$wpdb->prefix . 'ideaxperts_ea_import_runs',
				array(
					'status'     => $to,
					'updated_at' => current_time( 'mysql', true ),
				),
				array(
					'id'     => $run_id,
					'status' => $current,
				)
			);
			if ( false === $ok || 1 !== (int) $wpdb->rows_affected || ! $this->event( $run_id, 0, (int) $run['dry_run_id'], 'run_' . $to, 0, array(), $run ) || false === $wpdb->query( 'COMMIT' ) ) {
				return $this->abort( $open );
			}
			$open = false;
			return true;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	public function claim_item( int $item_id ): string {
		global $wpdb;
		if ( ! $this->begin() ) {
			return '';
		}
		$open = true;
		try {
			$item = $this->locked_item( $item_id );
			$now  = current_time( 'mysql', true );
			if ( ! $item || ! in_array( (string) $item['status'], array( 'pending', 'retry_wait' ), true ) || ( ! empty( $item['retry_at'] ) && (string) $item['retry_at'] > $now ) ) {
				$this->rollback();
				$open = false;
				return '';
			}
			$token   = $this->token();
			$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::ITEM_LEASE_SECONDS );
			$table   = $wpdb->prefix . 'ideaxperts_ea_import_items';
			$result  = $wpdb->update(
				$table,
				array(
					'status'           => 'leased',
					'execution_token'  => $token,
					'lease_expires_at' => $expires,
					'attempt_count'    => (int) $item['attempt_count'] + 1,
					'updated_at'       => $now,
				),
				array(
					'id'     => $item_id,
					'status' => $item['status'],
				)
			);
			$run     = $this->run( (int) $item['import_run_id'] );
			if ( false === $result || 1 !== (int) $wpdb->rows_affected || ! $run || ! $this->event( (int) $run['id'], $item_id, (int) $run['dry_run_id'], 'item_leased', 0, array(), $item ) || false === $wpdb->query( 'COMMIT' ) ) {
				$this->abort( $open );
				return '';
			}
			$open = false;
			return $token;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	public function begin_validation( int $item_id, string $token ): bool {
		return $this->transition_owned_item( $item_id, $token, array( 'leased' ), 'validating', array() );
	}

	public function accept_freshness( int $item_id, string $token ): bool {
		$item = $this->item( $item_id );
		if ( ! $item || 'validating' !== (string) $item['status'] || ! hash_equals( (string) $item['execution_token'], $token ) ) {
			return false;
		}
		$hashes = array( (string) $item['expected_vendor_hash'], (string) $item['expected_local_hash'], (string) $item['expected_mapping_hash'], (string) ( $item['expected_live_hash'] ?? '' ) );
		foreach ( $hashes as $hash ) {
			if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $hash ) ) {
				return false;
			}
		}
		global $wpdb;
		$identity = self::vendor_identity_key( (string) $item['source_scope'], (string) $item['entity_kind'], (string) $item['ea_product_id'], (string) $item['ea_option_id'] );
		$snapshot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_vendor_snapshots WHERE source_scope = %s AND environment = %s AND identity_key = %s AND payload_hash = %s ORDER BY id DESC LIMIT 1', $item['source_scope'], $item['environment'], $identity, $item['expected_vendor_hash'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$payload  = is_array( $snapshot ) ? json_decode( (string) ( $snapshot['payload'] ?? '' ), true ) : null;
		if ( ! is_array( $payload ) || ! hash_equals( (string) $item['expected_vendor_hash'], ApprovalManifest::hash( $payload ) ) ) {
			return false;
		}
		if ( ! $this->catalog_state ) {
			return false;
		}
		try {
			$live_hash = $this->catalog_state->fingerprint( $item, (string) $item['source_scope'], (string) $item['environment'], true );
		} catch ( \Throwable ) {
			return false;
		}
		if ( ! hash_equals( (string) $item['expected_live_hash'], $live_hash ) ) {
			return false;
		}
		$freshness_at       = current_time( 'mysql', true );
		$freshness_evidence = $this->freshness_evidence_token( $item, $live_hash, $freshness_at );
		if ( '' === $freshness_evidence ) {
			return false;
		}
		return $this->transition_owned_item(
			$item_id,
			$token,
			array( 'validating' ),
			'ready',
			array(
				'freshness_status'     => 'accepted',
				'live_freshness_hash'  => $live_hash,
				'live_freshness_token' => $freshness_evidence,
				'live_freshness_at'    => $freshness_at,
			)
		);
	}

	public function reject_stale( int $item_id, string $token ): bool {
		return $this->transition_owned_item(
			$item_id,
			$token,
			array( 'validating' ),
			'stale_snapshot',
			array(
				'freshness_status' => 'stale',
				'execution_token'  => '',
				'lease_expires_at' => null,
			)
		);
	}

	public function block_item( int $item_id, string $token, string $failure_code ): bool {
		return $this->transition_owned_item(
			$item_id,
			$token,
			array( 'leased', 'validating' ),
			'blocked',
			array(
				'failure_code'     => sanitize_key( $failure_code ),
				'execution_token'  => '',
				'lease_expires_at' => null,
			)
		);
	}

	/** Atomically reserve environment-scoped vendor identity. */
	public function reserve_catalog_identity( int $item_id, string $token ): int {
		global $wpdb;
		if ( ! $this->begin() ) {
			return 0;
		}
		$open = true;
		try {
			$item = $this->locked_item( $item_id );
			if ( ! $item || ! hash_equals( (string) $item['execution_token'], $token ) || ! in_array( (string) $item['status'], array( 'leased', 'validating', 'ready' ), true ) ) {
				return $this->abort_int( $open );
			}
			$key         = self::vendor_identity_key( (string) $item['source_scope'], (string) $item['entity_kind'], (string) $item['ea_product_id'], (string) $item['ea_option_id'] );
			$now         = current_time( 'mysql', true );
			$ok          = $wpdb->insert(
				$wpdb->prefix . 'ideaxperts_ea_catalog_identities',
				array(
					'identity_key'          => $key,
					'source_scope'          => $item['source_scope'],
					'environment'           => $item['environment'],
					'entity_kind'           => $item['entity_kind'],
					'ea_product_id'         => $item['ea_product_id'],
					'ea_option_id'          => $item['ea_option_id'],
					'owning_import_item_id' => $item_id,
					'reservation_status'    => 'reserved',
					'operation_uuid'        => $item['operation_uuid'],
					'wc_identity_key'       => null,
					'wc_product_id'         => null,
					'wc_variation_id'       => null,
					'ownership_mode'        => 'link' === $item['approved_action'] ? 'linked_existing' : 'vendor_created',
					'vendor_state_hash'     => '',
					'owner_token'           => $token,
					'created_at'            => $now,
					'updated_at'            => $now,
				)
			);
			$identity_id = (int) $wpdb->insert_id;
			if ( false === $ok ) {
				$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_catalog_identities WHERE identity_key = %s FOR UPDATE', $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( ! is_array( $existing ) || (int) $existing['owning_import_item_id'] !== $item_id || (string) $existing['operation_uuid'] !== (string) $item['operation_uuid'] || (string) $existing['source_scope'] !== (string) $item['source_scope'] || (string) $existing['environment'] !== (string) $item['environment'] ) {
					return $this->abort_int( $open );
				}
				$updated = $wpdb->update(
					$wpdb->prefix . 'ideaxperts_ea_catalog_identities',
					array(
						'owner_token' => $token,
						'updated_at'  => $now,
					),
					array( 'id' => (int) $existing['id'] )
				);
				if ( false === $updated ) {
					return $this->abort_int( $open );
				}
				$identity_id = (int) $existing['id'];
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return $this->abort_int( $open );
			}
			$open = false;
			return $identity_id;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	/**
	 * Reserve a store-level UPC. Production creation uses one installation-wide
	 * namespace; QA uses a preview namespace and can never authorize a real write.
	 */
	public function reserve_upc( int $identity_id, int $item_id, string $token ): int {
		global $wpdb;
		if ( ! $this->begin() ) {
			return 0;
		}
		$open = true;
		try {
			$item     = $this->locked_item( $item_id );
			$identity = $this->locked_identity( $identity_id );
			if ( ! $item || ! $identity || '' === (string) $item['normalized_upc'] || ! hash_equals( (string) $item['execution_token'], $token ) || (int) $identity['owning_import_item_id'] !== $item_id || ! hash_equals( (string) $identity['owner_token'], $token ) || (string) $identity['source_scope'] !== (string) $item['source_scope'] || (string) $identity['environment'] !== (string) $item['environment'] ) {
				return $this->abort_int( $open );
			}
			$namespace      = 'production' === $item['environment'] ? 'woocommerce_catalog' : 'preview:qa';
			$key            = hash( 'sha256', $namespace . "\0upc\0" . $item['normalized_upc'] );
			$now            = current_time( 'mysql', true );
			$ok             = $wpdb->insert(
				$wpdb->prefix . 'ideaxperts_ea_store_identifier_reservations',
				array(
					'namespace'             => $namespace,
					'identifier_type'       => 'upc',
					'normalized_identifier' => $item['normalized_upc'],
					'identifier_key'        => $key,
					'catalog_identity_id'   => $identity_id,
					'owning_import_item_id' => $item_id,
					'source_scope'          => $item['source_scope'],
					'environment'           => $item['environment'],
					'reservation_status'    => 'reserved',
					'owner_token'           => $token,
					'created_at'            => $now,
					'updated_at'            => $now,
				)
			);
			$reservation_id = (int) $wpdb->insert_id;
			if ( false === $ok ) {
				$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_store_identifier_reservations WHERE identifier_key = %s FOR UPDATE', $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( ! is_array( $existing ) || (int) $existing['catalog_identity_id'] !== $identity_id || (int) $existing['owning_import_item_id'] !== $item_id || (string) $existing['source_scope'] !== (string) $item['source_scope'] || (string) $existing['environment'] !== (string) $item['environment'] ) {
					return $this->abort_int( $open );
				}
				$updated = $wpdb->update(
					$wpdb->prefix . 'ideaxperts_ea_store_identifier_reservations',
					array(
						'owner_token' => $token,
						'updated_at'  => $now,
					),
					array( 'id' => (int) $existing['id'] )
				);
				if ( false === $updated ) {
					return $this->abort_int( $open );
				}
				$reservation_id = (int) $existing['id'];
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return $this->abort_int( $open );
			}
			$open = false;
			return $reservation_id;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	/** Final cancellation/freshness/ownership gate before any future external write. */
	public function acquire_final_write_permit( int $item_id, int $identity_id, string $token, int $approval_generation, int $action_id, string $logical_key, int $dispatch_generation, string $action_execution_token ): bool {
		global $wpdb;
		$hint        = $this->item( $item_id );
		$action_hint = $this->action( $action_id );
		if ( ! $hint || ! $action_hint || ! $this->catalog_state || '' === $logical_key || $dispatch_generation < 1 || '' === $action_execution_token ) {
			return false;
		}
		if ( ! $this->begin() ) {
			return false;
		}
		$open = true;
		try {
			// Canonical permit order: run -> import action -> item -> catalog identity -> UPC reservation.
			$run          = $this->locked_run( (int) $hint['import_run_id'] );
			$action       = $this->locked_action( $action_id );
			$item         = $this->locked_item( $item_id );
			$now          = current_time( 'mysql', true );
			$freshness_at = $item ? strtotime( (string) ( $item['live_freshness_at'] ?? '' ) ) : false;
			if ( ! $run || ! $action || ! $item ) {
				return $this->abort( $open );
			}
			if ( (int) $action['import_run_id'] !== (int) $run['id'] || (int) $action['import_item_id'] !== $item_id || (string) $action['logical_key'] !== $logical_key || (int) $action['dispatch_generation'] !== $dispatch_generation || 'running' !== (string) $action['status'] || ! hash_equals( (string) $action['execution_token'], $action_execution_token ) || empty( $action['lease_expires_at'] ) || (string) $action['lease_expires_at'] <= $now || (int) $action['claim_generation'] !== (int) $run['claim_generation'] || (string) $action['source_scope'] !== (string) $run['source_scope'] || (string) $action['environment'] !== (string) $run['environment'] ) {
				return $this->abort( $open );
			}
			try {
				// Re-read the authoritative catalog only after the run/item ownership locks are held.
				$current_live_hash = $this->catalog_state->fingerprint( $item, (string) $item['source_scope'], (string) $item['environment'], true );
			} catch ( \Throwable ) {
				return $this->abort( $open );
			}
			$expected_evidence = $this->freshness_evidence_token( $item, $current_live_hash, (string) ( $item['live_freshness_at'] ?? '' ) );
			if ( (int) $item['import_run_id'] !== (int) $hint['import_run_id'] || 'ready' !== $item['status'] || 'accepted' !== $item['freshness_status'] || '' === $expected_evidence || ! hash_equals( $expected_evidence, (string) ( $item['live_freshness_token'] ?? '' ) ) || ! hash_equals( (string) $item['expected_live_hash'], $current_live_hash ) || ! hash_equals( (string) $item['live_freshness_hash'], $current_live_hash ) || false === $freshness_at || $freshness_at < strtotime( $now ) - self::ITEM_LEASE_SECONDS || ! hash_equals( (string) $item['execution_token'], $token ) || (int) $item['approval_generation'] !== $approval_generation || empty( $item['lease_expires_at'] ) || (string) $item['lease_expires_at'] <= $now ) {
				return $this->abort( $open );
			}
			$id = $this->locked_identity( $identity_id );
			if ( 'running' !== $run['status'] || null !== $run['cancellation_authoritative_at'] || (int) $run['approval_generation'] !== $approval_generation || (string) $run['source_scope'] !== (string) $item['source_scope'] || (string) $run['environment'] !== (string) $item['environment'] || ! $id || 'reserved' !== (string) $id['reservation_status'] || (int) $id['owning_import_item_id'] !== $item_id || ! hash_equals( (string) $id['owner_token'], $token ) || (string) $id['source_scope'] !== (string) $item['source_scope'] || (string) $id['environment'] !== (string) $item['environment'] ) {
				return $this->abort( $open );
			}
			if ( '' !== (string) $item['normalized_upc'] ) {
				$namespace   = 'production' === $item['environment'] ? 'woocommerce_catalog' : 'preview:qa';
				$reservation = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_store_identifier_reservations WHERE catalog_identity_id = %d AND owning_import_item_id = %d FOR UPDATE', $identity_id, $item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( ! is_array( $reservation ) || ! hash_equals( (string) $reservation['owner_token'], $token ) || 'reserved' !== (string) $reservation['reservation_status'] || $namespace !== (string) $reservation['namespace'] || 'upc' !== (string) $reservation['identifier_type'] || (string) $item['normalized_upc'] !== (string) $reservation['normalized_identifier'] || (string) $reservation['source_scope'] !== (string) $item['source_scope'] || (string) $reservation['environment'] !== (string) $item['environment'] ) {
					return $this->abort( $open );
				}
			}
			$ok = $wpdb->update(
				$wpdb->prefix . 'ideaxperts_ea_import_items',
				array(
					'status'                  => 'applying',
					'apply_started_at'        => $now,
					'reconciliation_required' => 1,
					'updated_at'              => $now,
				),
				array(
					'id'              => $item_id,
					'status'          => 'ready',
					'execution_token' => $token,
				)
			);
			if ( false === $ok || 1 !== (int) $wpdb->rows_affected || ! $this->event( (int) $run['id'], $item_id, (int) $run['dry_run_id'], 'final_write_permit', 0, array(), $item ) || false === $wpdb->query( 'COMMIT' ) ) {
				return $this->abort( $open );
			}
			$open = false;
			return true;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	public function move_applying_to_reconciling( int $item_id, string $token ): bool {
		return $this->transition_owned_item( $item_id, $token, array( 'applying' ), 'reconciling', array( 'reconciliation_required' => 1 ) );
	}

	public function finish_reconciliation_without_write( int $item_id, string $token ): bool {
		return $this->transition_owned_item(
			$item_id,
			$token,
			array( 'reconciling' ),
			'manual_recovery',
			array(
				'execution_token'  => '',
				'lease_expires_at' => null,
				'failure_code'     => 'catalog_write_not_implemented',
			)
		);
	}

	public function reclaim_expired_pre_apply( int $limit = self::BATCH_SIZE ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_import_items';
		$now   = current_time( 'mysql', true );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN (%s,%s,%s) AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s ORDER BY lease_expires_at ASC, id ASC LIMIT %d", 'leased', 'validating', 'ready', $now, max( 1, min( self::BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! $this->begin() ) {
				break;
			}
			$open = true;
			try {
				$current = $this->locked_item( (int) $row['id'] );
				if ( ! $current || ! in_array( (string) $current['status'], array( 'leased', 'validating', 'ready' ), true ) || (string) $current['execution_token'] !== (string) $row['execution_token'] || (string) $current['lease_expires_at'] !== (string) $row['lease_expires_at'] || (string) $current['lease_expires_at'] > $now ) {
					$this->rollback();
					$open = false;
					continue;
				}
				$result = $wpdb->update(
					$table,
					array(
						'status'           => 'retry_wait',
						'execution_token'  => '',
						'lease_expires_at' => null,
						'retry_at'         => $now,
						'failure_code'     => 'lease_expired',
						'updated_at'       => $now,
					),
					array(
						'id'               => (int) $current['id'],
						'status'           => $current['status'],
						'execution_token'  => $current['execution_token'],
						'lease_expires_at' => $current['lease_expires_at'],
					)
				);
				$run    = $this->run( (int) $current['import_run_id'] );
				if ( false === $result || 1 !== (int) $wpdb->rows_affected || ! $run || ! $this->event( (int) $run['id'], (int) $current['id'], (int) $run['dry_run_id'], 'item_retry_wait', 0, array( 'reason' => 'lease_expired' ), $current ) || false === $wpdb->query( 'COMMIT' ) ) {
					$this->abort( $open );
					continue;
				}
				$open = false;
				++$count;
			} finally {
				if ( $open ) {
					$this->rollback();
				}
			}
		}
		return $count;
	}

	public function request_cancellation( int $run_id ): bool {
		global $wpdb;
		if ( ! $this->begin() ) {
			return false;
		}
		$open = true;
		try {
			$run = $this->locked_run( $run_id );
			if ( ! $run || ! in_array( (string) $run['status'], array( 'queued', 'running', 'pausing_stale', 'awaiting_reapproval', 'failed_recoverable', 'cancelling' ), true ) ) {
				return $this->abort( $open );
			}
			$now       = current_time( 'mysql', true );
			$run_table = $wpdb->prefix . 'ideaxperts_ea_import_runs';
			if ( false === $wpdb->update(
				$run_table,
				array(
					'status'                    => 'cancelling',
					'cancellation_requested_at' => ! empty( $run['cancellation_requested_at'] ) ? $run['cancellation_requested_at'] : $now,
					'updated_at'                => $now,
				),
				array( 'id' => $run_id )
			) ) {
				return $this->abort( $open );
			}
			$action_table   = $wpdb->prefix . 'ideaxperts_ea_import_actions';
			$actions_result = $wpdb->query( $wpdb->prepare( "UPDATE {$action_table} SET status = %s, updated_at = %s WHERE import_run_id = %d AND status IN (%s,%s,%s,%s,%s)", 'cancel_requested', $now, $run_id, 'pending', 'dispatching', 'dispatched', 'running', 'retry_wait' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$item_table     = $wpdb->prefix . 'ideaxperts_ea_import_items';
			$items_result   = $wpdb->query( $wpdb->prepare( "UPDATE {$item_table} SET status = %s, execution_token = %s, lease_expires_at = NULL, updated_at = %s WHERE import_run_id = %d AND status IN (%s,%s,%s,%s,%s)", 'cancelled', '', $now, $run_id, 'pending', 'leased', 'validating', 'ready', 'retry_wait' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $items_result || false === $actions_result || ! $this->event( $run_id, 0, (int) $run['dry_run_id'], 'run_cancelling', 0, array(), $run ) || false === $wpdb->query( 'COMMIT' ) ) {
				return $this->abort( $open );
			}
			$open = false;
			return true;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	public function finalize_cancellation( int $run_id ): bool {
		global $wpdb;
		if ( ! $this->begin() ) {
			return false;
		}
		$open = true;
		try {
			$run = $this->locked_run( $run_id );
			if ( ! $run || 'cancelling' !== $run['status'] ) {
				return $this->abort( $open );
			}
			$item_table   = $wpdb->prefix . 'ideaxperts_ea_import_items';
			$unresolved   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$item_table} WHERE import_run_id = %d AND status IN (%s,%s) LIMIT 1", $run_id, 'applying', 'reconciling' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$action_table = $wpdb->prefix . 'ideaxperts_ea_import_actions';
			$actionable   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$action_table} WHERE import_run_id = %d AND status IN (%s,%s,%s,%s,%s,%s) LIMIT 1", $run_id, 'pending', 'dispatching', 'dispatched', 'running', 'retry_wait', 'cancel_requested' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( null !== $unresolved || null !== $actionable ) {
				return $this->abort( $open );
			}
			$now = current_time( 'mysql', true );
			$ok  = $wpdb->update(
				$wpdb->prefix . 'ideaxperts_ea_import_runs',
				array(
					'status'                        => 'cancelled',
					'cancellation_authoritative_at' => $now,
					'completed_at'                  => $now,
					'updated_at'                    => $now,
				),
				array(
					'id'     => $run_id,
					'status' => 'cancelling',
				)
			);
			if ( false === $ok || 1 !== (int) $wpdb->rows_affected || ! $this->event( $run_id, 0, (int) $run['dry_run_id'], 'run_cancelled', 0, array(), $run ) || false === $wpdb->query( 'COMMIT' ) ) {
				return $this->abort( $open );
			}
			$open = false;
			return true;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	public function create_action( int $run_id, int $item_id, int $generation, string $action_type, string $hook ): int {
		global $wpdb;
		if ( ! $this->begin() ) {
			return 0;
		}
		$open = true;
		try {
			$run  = $this->locked_run( $run_id );
			$item = $this->locked_item( $item_id );
			if ( ! $run || ! in_array( (string) $run['status'], array( 'queued', 'running' ), true ) || (int) $run['claim_generation'] !== $generation || ! $item || (int) $item['import_run_id'] !== $run_id || (string) $item['source_scope'] !== (string) $run['source_scope'] || (string) $item['environment'] !== (string) $run['environment'] ) {
				return $this->abort_int( $open );
			}
			$logical   = hash( 'sha256', implode( "\0", array( $run_id, $item_id, $generation, $action_type ) ) );
			$now       = current_time( 'mysql', true );
			$ok        = $wpdb->insert(
				$wpdb->prefix . 'ideaxperts_ea_import_actions',
				array(
					'import_run_id'             => $run_id,
					'import_item_id'            => $item_id,
					'source_scope'              => (string) $run['source_scope'],
					'environment'               => (string) $run['environment'],
					'claim_generation'          => $generation,
					'action_type'               => $action_type,
					'logical_key'               => $logical,
					'hook'                      => $hook,
					'status'                    => 'pending',
					'available_at'              => $now,
					'attempts'                  => 0,
					'action_scheduler_id'       => null,
					'dispatch_generation'       => 0,
					'dispatch_token'            => '',
					'dispatch_started_at'       => null,
					'dispatch_lease_expires_at' => null,
					'dispatched_at'             => null,
					'execution_token'           => '',
					'started_at'                => null,
					'lease_expires_at'          => null,
					'failure_code'              => '',
					'created_at'                => $now,
					'updated_at'                => $now,
				)
			);
			$action_id = (int) $wpdb->insert_id;
			if ( false === $ok ) {
				$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_actions WHERE logical_key = %s FOR UPDATE', $logical ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( ! is_array( $existing ) || (int) $existing['import_run_id'] !== $run_id || (int) $existing['import_item_id'] !== $item_id || (int) $existing['claim_generation'] !== $generation || (string) $existing['action_type'] !== $action_type || (string) $existing['hook'] !== $hook || (string) $existing['source_scope'] !== (string) $run['source_scope'] || (string) $existing['environment'] !== (string) $run['environment'] || false === $wpdb->query( 'COMMIT' ) ) {
					return $this->abort_int( $open );
				}
				$open = false;
				return (int) $existing['id'];
			}
			if ( ! $this->event( $run_id, $item_id, (int) $run['dry_run_id'], 'action_created', 0, array( 'action_type' => $action_type ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				return $this->abort_int( $open );
			}
			$open = false;
			return $action_id;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	/** @return list<array<string,mixed>> */
	public function dispatchable_actions( int $limit = self::BATCH_SIZE ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_import_actions';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN (%s,%s) AND available_at <= %s AND attempts < %d ORDER BY id ASC LIMIT %d", 'pending', 'retry_wait', current_time( 'mysql', true ), self::MAX_DISPATCH_ATTEMPTS, max( 1, min( self::BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	public function claim_dispatch( int $action_id ): string {
		global $wpdb;
		$hint = $this->action( $action_id );
		if ( ! $hint || ! $this->begin() ) {
			return '';
		}
		$open = true;
		try {
			$run    = $this->locked_run( (int) $hint['import_run_id'] );
			$action = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_actions WHERE id = %d FOR UPDATE', $action_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$now    = current_time( 'mysql', true );
			if ( ! $run || 'running' !== (string) $run['status'] || ! is_array( $action ) || ! in_array( (string) $action['status'], array( 'pending', 'retry_wait' ), true ) || (string) $action['available_at'] > $now || (int) $action['attempts'] >= self::MAX_DISPATCH_ATTEMPTS ) {
				$this->rollback();
				$open = false;
				return '';
			}
			$token   = $this->token();
			$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::ACTION_LEASE_SECONDS );
			$table   = $wpdb->prefix . 'ideaxperts_ea_import_actions';
			$result  = $wpdb->update(
				$table,
				array(
					'status'                    => 'dispatching',
					'dispatch_generation'       => (int) $action['dispatch_generation'] + 1,
					'dispatch_token'            => $token,
					'dispatch_started_at'       => $now,
					'dispatch_lease_expires_at' => $expires,
					'action_scheduler_id'       => null,
					'dispatched_at'             => null,
					'updated_at'                => $now,
				),
				array(
					'id'                  => $action_id,
					'status'              => $action['status'],
					'dispatch_generation' => $action['dispatch_generation'],
				)
			);
			if ( false === $result || 1 !== (int) $wpdb->rows_affected || ! $this->action_event( $action, 'action_dispatching', 'dispatching' ) || false === $wpdb->query( 'COMMIT' ) ) {
				$this->rollback();
				$open = false;
				return '';
			}
			$open = false;
			return $token;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	public function record_dispatched( int $action_id, string $token, int $scheduler_id, int $dispatch_generation ): bool {
		$action = $this->action( $action_id );
		return $action && $this->transition_action(
			$action_id,
			array(
				'status'              => 'dispatching',
				'dispatch_token'      => $token,
				'dispatch_generation' => $dispatch_generation,
			),
			array(
				'status'                    => 'dispatched',
				'action_scheduler_id'       => $scheduler_id,
				'attempts'                  => (int) $action['attempts'] + 1,
				'dispatch_token'            => '',
				'dispatch_started_at'       => null,
				'dispatch_lease_expires_at' => null,
				'dispatched_at'             => current_time( 'mysql', true ),
			),
			'action_dispatched'
		);
	}

	public function claim_action_execution( int $action_id, string $logical_key, int $dispatch_generation ): string {
		global $wpdb;
		$hint = $this->action( $action_id );
		if ( ! $hint || ! $this->begin() ) {
			return '';
		}
		$open = true;
		try {
			$run    = $this->locked_run( (int) $hint['import_run_id'] );
			$table  = $wpdb->prefix . 'ideaxperts_ea_import_actions';
			$action = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND logical_key = %s AND dispatch_generation = %d FOR UPDATE", $action_id, $logical_key, $dispatch_generation ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! is_array( $action ) || ! in_array( (string) $action['status'], array( 'dispatching', 'dispatched' ), true ) ) {
				$this->rollback();
				$open = false;
				return '';
			}
			if ( ! $run || 'running' !== $run['status'] || (int) $run['id'] !== (int) $action['import_run_id'] || null !== $run['cancellation_authoritative_at'] || (int) $run['claim_generation'] !== (int) $action['claim_generation'] || (string) $run['source_scope'] !== (string) $action['source_scope'] || (string) $run['environment'] !== (string) $action['environment'] ) {
				$this->rollback();
				$open = false;
				return '';
			}
			$token   = $this->token();
			$now     = current_time( 'mysql', true );
			$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::ACTION_LEASE_SECONDS );
			$ok      = $wpdb->update(
				$table,
				array(
					'status'           => 'running',
					'execution_token'  => $token,
					'started_at'       => $now,
					'lease_expires_at' => $expires,
					'updated_at'       => $now,
				),
				array(
					'id'                  => $action_id,
					'logical_key'         => $logical_key,
					'dispatch_generation' => $dispatch_generation,
					'status'              => $action['status'],
				)
			);
			if ( false === $ok || 1 !== (int) $wpdb->rows_affected || ! $this->action_event( $action, 'action_running', 'running' ) || false === $wpdb->query( 'COMMIT' ) ) {
				$this->rollback();
				$open = false;
				return '';
			}
			$open = false;
			return $token;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	public function complete_action_execution( int $action_id, string $logical_key, string $token ): bool {
		return $this->transition_action(
			$action_id,
			array(
				'logical_key'     => $logical_key,
				'status'          => 'running',
				'execution_token' => $token,
			),
			array( 'status' => 'completed' ),
			'action_completed'
		);
	}

	public function settle_action_for_item( int $action_id, string $logical_key, string $execution_token ): bool {
		$action = $this->action( $action_id );
		$item   = $action ? $this->item( (int) $action['import_item_id'] ) : null;
		if ( ! $action || ! $item || ! in_array( (string) $item['status'], array( 'ready', 'stale_snapshot', 'manual_required', 'blocked', 'cancelled', 'applied', 'manual_recovery' ), true ) ) {
			return false;
		}
		if ( 'completed' === (string) $action['status'] || 'cancelled' === (string) $action['status'] ) {
			return true;
		}
		if ( 'running' !== (string) $action['status'] || ! hash_equals( (string) $action['execution_token'], $execution_token ) ) {
			return false;
		}
		return $this->complete_action_execution( $action_id, $logical_key, $execution_token );
	}

	/** @return list<array<string,mixed>> */
	public function cancel_requested_actions( int $limit = self::BATCH_SIZE ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_import_actions';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d", 'cancel_requested', max( 1, min( self::BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** Settle a cancellation only after the exact scheduler delivery is neutralized. */
	public function settle_cancel_requested_action( int $action_id, int $dispatch_generation, int $scheduler_id ): bool {
		global $wpdb;
		$hint = $this->action( $action_id );
		if ( ! $hint || ! $this->begin() ) {
			return false;
		}
		$open = true;
		try {
			$run    = $this->locked_run( (int) $hint['import_run_id'] );
			$action = $this->locked_action( $action_id );
			if ( ! $run || ! $action || ! in_array( (string) $run['status'], array( 'cancelling', 'cancelled' ), true ) || (int) $action['dispatch_generation'] !== $dispatch_generation || (int) ( $action['action_scheduler_id'] ?? 0 ) !== $scheduler_id ) {
				return $this->abort( $open );
			}
			if ( 'cancelled' === (string) $action['status'] ) {
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					return $this->abort( $open );
				}
				$open = false;
				return true;
			}
			$now = current_time( 'mysql', true );
			if ( 'cancel_requested' !== (string) $action['status'] || ( '' !== (string) ( $action['execution_token'] ?? '' ) && ! empty( $action['lease_expires_at'] ) && (string) $action['lease_expires_at'] > $now ) ) {
				return $this->abort( $open );
			}
			$ok = $wpdb->update(
				$wpdb->prefix . 'ideaxperts_ea_import_actions',
				array(
					'status'                    => 'cancelled',
					'dispatch_token'            => '',
					'dispatch_started_at'       => null,
					'dispatch_lease_expires_at' => null,
					'execution_token'           => '',
					'started_at'                => null,
					'lease_expires_at'          => null,
					'updated_at'                => $now,
				),
				array(
					'id'                  => $action_id,
					'status'              => 'cancel_requested',
					'dispatch_generation' => $dispatch_generation,
				)
			);
			if ( false === $ok || 1 !== (int) $wpdb->rows_affected || ! $this->action_event( $action, 'action_cancelled', 'cancelled' ) || false === $wpdb->query( 'COMMIT' ) ) {
				return $this->abort( $open );
			}
			$open = false;
			return true;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	/** A delivered stale callback can conclusively neutralize its own exact generation. */
	public function settle_cancelled_callback( int $action_id, string $logical_key, int $dispatch_generation ): bool {
		$action = $this->action( $action_id );
		if ( ! $action || (string) $action['logical_key'] !== $logical_key || (int) $action['dispatch_generation'] !== $dispatch_generation ) {
			return false;
		}
		return $this->settle_cancel_requested_action( $action_id, $dispatch_generation, (int) ( $action['action_scheduler_id'] ?? 0 ) );
	}

	public function fail_dispatch( int $action_id, string $token, string $failure_code = 'enqueue_failed' ): bool {
		$action = $this->action( $action_id );
		$now    = current_time( 'mysql', true );
		return $action && $this->transition_action(
			$action_id,
			array(
				'status'         => 'dispatching',
				'dispatch_token' => $token,
			),
			array(
				'status'                    => 'retry_wait',
				'attempts'                  => (int) $action['attempts'] + 1,
				'available_at'              => gmdate( 'Y-m-d H:i:s', strtotime( $now ) + 30 ),
				'failure_code'              => sanitize_key( $failure_code ),
				'dispatch_token'            => '',
				'dispatch_started_at'       => null,
				'dispatch_lease_expires_at' => null,
				'action_scheduler_id'       => null,
				'dispatched_at'             => null,
			),
			'action_retry_wait'
		);
	}

	/** @return list<array<string,mixed>> */
	public function stale_dispatches( int $limit = self::BATCH_SIZE ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_import_actions';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND dispatch_lease_expires_at IS NOT NULL AND dispatch_lease_expires_at <= %s ORDER BY dispatch_lease_expires_at ASC, id ASC LIMIT %d", 'dispatching', current_time( 'mysql', true ), max( 1, min( self::BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** @return list<array<string,mixed>> */
	public function stale_dispatched( int $limit = self::BATCH_SIZE ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_import_actions';
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) ) - self::ACTION_LEASE_SECONDS );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND dispatched_at IS NOT NULL AND dispatched_at <= %s ORDER BY dispatched_at ASC, id ASC LIMIT %d", 'dispatched', $cutoff, max( 1, min( self::BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	public function retain_dispatched( int $action_id, int $generation, int $scheduler_id ): bool {
		return $this->transition_action(
			$action_id,
			array(
				'status'              => 'dispatched',
				'dispatch_generation' => $generation,
				'action_scheduler_id' => $scheduler_id,
			),
			array( 'dispatched_at' => current_time( 'mysql', true ) ),
			'action_dispatch_retained'
		);
	}

	public function reopen_lost_dispatched( int $action_id, int $generation, int $scheduler_id, string $failure_code = 'scheduler_action_lost' ): bool {
		return $this->transition_action(
			$action_id,
			array(
				'status'              => 'dispatched',
				'dispatch_generation' => $generation,
				'action_scheduler_id' => $scheduler_id,
			),
			array(
				'status'              => 'retry_wait',
				'available_at'        => current_time( 'mysql', true ),
				'action_scheduler_id' => null,
				'dispatched_at'       => null,
				'failure_code'        => sanitize_key( $failure_code ),
			),
			'action_retry_wait'
		);
	}

	public function reclaim_expired_action_executions( int $limit = self::BATCH_SIZE ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_import_actions';
		$now   = current_time( 'mysql', true );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s ORDER BY lease_expires_at ASC, id ASC LIMIT %d", 'running', $now, max( 1, min( self::BATCH_SIZE, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$ok     = $this->transition_action(
				(int) $row['id'],
				array(
					'status'           => 'running',
					'execution_token'  => $row['execution_token'],
					'lease_expires_at' => $row['lease_expires_at'],
				),
				array(
					'status'              => 'retry_wait',
					'available_at'        => $now,
					'execution_token'     => '',
					'started_at'          => null,
					'lease_expires_at'    => null,
					'failure_code'        => 'execution_lease_expired',
					'action_scheduler_id' => null,
					'dispatched_at'       => null,
				),
				'action_retry_wait'
			);
			$count += $ok ? 1 : 0;
		}
		return $count;
	}

	public function reopen_stale_dispatch( int $action_id, string $token, string $expires ): bool {
		return $this->transition_action(
			$action_id,
			array(
				'status'                    => 'dispatching',
				'dispatch_token'            => $token,
				'dispatch_lease_expires_at' => $expires,
			),
			array(
				'status'                    => 'retry_wait',
				'available_at'              => current_time( 'mysql', true ),
				'dispatch_token'            => '',
				'dispatch_started_at'       => null,
				'dispatch_lease_expires_at' => null,
				'action_scheduler_id'       => null,
				'dispatched_at'             => null,
			),
			'action_retry_wait'
		);
	}

	/** @return array<string,mixed>|null */
	public function action( int $action_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_actions WHERE id = %d', $action_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string,mixed> $manifest Approval manifest.
	 * @param array<string,mixed> $item     Manifest item.
	 */
	private function insert_manifest_item( int $run_id, array $manifest, array $item, int $actor_id, string $now ): bool {
		global $wpdb;
		$vendor = is_array( $item['vendor'] ?? null ) ? $item['vendor'] : array();
		$target = is_array( $item['target'] ?? null ) ? $item['target'] : array();
		$action = (string) ( $item['action'] ?? '' );
		if ( ! in_array( $action, array( 'create', 'link', 'validate', 'skip' ), true ) ) {
			return false;
		}
		$operation = $this->token();
		return false !== $wpdb->insert(
			$wpdb->prefix . 'ideaxperts_ea_import_items',
			array(
				'import_run_id'           => $run_id,
				'dry_run_item_id'         => (int) ( $item['dry_run_item_id'] ?? 0 ),
				'entity_kind'             => (string) ( $item['entity_kind'] ?? 'option' ),
				'approved_action'         => $action,
				'group_key'               => (string) ( $item['group_key'] ?? '' ),
				'ea_product_id'           => (string) ( $vendor['ea_product_id'] ?? '' ),
				'ea_option_id'            => (string) ( $vendor['ea_option_id'] ?? '' ),
				'vendor_sku'              => '',
				'normalized_upc'          => (string) ( $vendor['normalized_upc'] ?? '' ),
				'expected_vendor_hash'    => (string) ( $item['expected_vendor_hash'] ?? '' ),
				'expected_local_hash'     => (string) ( $item['expected_local_hash'] ?? '' ),
				'expected_mapping_hash'   => (string) ( $item['expected_mapping_hash'] ?? '' ),
				'expected_live_hash'      => (string) ( $item['expected_live_hash'] ?? '' ),
				'target_wc_product_id'    => (int) ( $target['wc_product_id'] ?? 0 ),
				'target_wc_variation_id'  => (int) ( $target['wc_variation_id'] ?? 0 ),
				'source_scope'            => $manifest['source_scope'],
				'environment'             => $manifest['environment'],
				'approval_generation'     => (int) $manifest['approval_generation'],
				'status'                  => 'skip' === $action ? 'applied' : 'pending',
				'freshness_status'        => 'unvalidated',
				'live_freshness_hash'     => '',
				'live_freshness_token'    => '',
				'live_freshness_at'       => null,
				'attempt_count'           => 0,
				'retry_at'                => null,
				'failure_code'            => '',
				'execution_token'         => '',
				'lease_expires_at'        => null,
				'operation_uuid'          => $operation,
				'apply_started_at'        => null,
				'reconciliation_required' => 0,
				'approved_by'             => $actor_id,
				'approved_at'             => $now,
				'created_at'              => $now,
				'updated_at'              => $now,
			)
		);
	}

	/** @param array<string,mixed> $item */
	private function validate_manifest_item( array $item, string $environment ): bool {
		$vendor = is_array( $item['vendor'] ?? null ) ? $item['vendor'] : array();
		$target = is_array( $item['target'] ?? null ) ? $item['target'] : array();
		if ( (int) ( $item['dry_run_item_id'] ?? 0 ) < 1 || '' === (string) ( $vendor['ea_product_id'] ?? '' ) || '' === (string) ( $vendor['ea_option_id'] ?? '' ) ) {
			return false;
		}
		$policy_item                 = array_merge( $vendor, $target );
		$flags                       = $vendor['review_flags'] ?? array();
		$policy_item['review_flags'] = wp_json_encode( is_array( $flags ) ? $flags : array() );
		$automatic                   = ( new ImportPolicy() )->evaluate( $policy_item );
		$manual                      = ( new ImportPolicy() )->evaluate( $policy_item, true );
		$action                      = (string) ( $item['action'] ?? '' );
		$allowed                     = ( $automatic['eligible'] && $automatic['action'] === $action ) || ( ! $automatic['automatic'] && $manual['eligible'] && $manual['action'] === $action );
		$kind                        = (int) ( $target['wc_variation_id'] ?? 0 ) > 0 ? 'variation' : 'option';
		return $allowed
			&& (string) ( $item['entity_kind'] ?? '' ) === $kind
			&& hash_equals( hash( 'sha256', $environment . "\0" . (string) $vendor['ea_product_id'] ), (string) ( $item['group_key'] ?? '' ) )
			&& 1 === preg_match( '/\A[a-f0-9]{64}\z/', (string) ( $item['expected_live_hash'] ?? '' ) )
			&& hash_equals( ApprovalManifest::hash( $vendor ), (string) ( $item['expected_vendor_hash'] ?? '' ) )
			&& hash_equals( ApprovalManifest::hash( $target ), (string) ( $item['expected_local_hash'] ?? '' ) )
			&& hash_equals(
				ApprovalManifest::hash(
					array(
						'product' => (string) $vendor['ea_product_id'],
						'option'  => (string) $vendor['ea_option_id'],
						'target'  => $target,
					)
				),
				(string) ( $item['expected_mapping_hash'] ?? '' )
			);
	}

	/**
	 * @param array<string,mixed> $manifest Approval manifest.
	 * @param array<string,mixed> $item     Manifest item.
	 */
	private function insert_snapshot( array $manifest, array $item, string $now ): bool {
		global $wpdb;
		$vendor  = is_array( $item['vendor'] ?? null ) ? $item['vendor'] : array();
		$payload = wp_json_encode( $vendor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $payload ) || strlen( $payload ) > 65535 ) {
			return false;
		}
		$identity = self::vendor_identity_key( (string) $manifest['source_scope'], (string) ( $item['entity_kind'] ?? 'option' ), (string) ( $vendor['ea_product_id'] ?? '' ), (string) ( $vendor['ea_option_id'] ?? '' ) );
		return false !== $wpdb->insert(
			$wpdb->prefix . 'ideaxperts_ea_vendor_snapshots',
			array(
				'source_scope'          => $manifest['source_scope'],
				'environment'           => $manifest['environment'],
				'identity_key'          => $identity,
				'ea_product_id'         => (string) ( $vendor['ea_product_id'] ?? '' ),
				'ea_option_id'          => (string) ( $vendor['ea_option_id'] ?? '' ),
				'payload'               => $payload,
				'payload_hash'          => (string) ( $item['expected_vendor_hash'] ?? '' ),
				'normalization_version' => 'dry-run-v1',
				'api_contract_version'  => 'documented-2026-09-25',
				'fetched_at'            => $now,
				'created_at'            => $now,
			)
		);
	}

	/**
	 * @param array<string,mixed> $data   Bounded event data.
	 * @param array<string,mixed> $before State before transition.
	 */
	private function event( int $run_id, int $item_id, int $dry_run_id, string $type, int $actor, array $data = array(), array $before = array() ): bool {
		global $wpdb;
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_EVENT_BYTES ) {
			return false;
		}
		$item = $item_id > 0 ? $this->item( $item_id ) : null;
		return false !== $wpdb->insert(
			$wpdb->prefix . 'ideaxperts_ea_import_events',
			array(
				'import_run_id'        => $run_id,
				'import_item_id'       => $item_id,
				'dry_run_id'           => $dry_run_id,
				'ea_product_id'        => (string) ( $item['ea_product_id'] ?? '' ),
				'ea_option_id'         => (string) ( $item['ea_option_id'] ?? '' ),
				'wc_product_id'        => (int) ( $item['target_wc_product_id'] ?? 0 ),
				'wc_variation_id'      => (int) ( $item['target_wc_variation_id'] ?? 0 ),
				'event_type'           => sanitize_key( $type ),
				'actor_id'             => $actor,
				'before_hash'          => $before ? ApprovalManifest::hash( $before ) : '',
				'after_hash'           => $item ? ApprovalManifest::hash( $item ) : '',
				'operation_uuid'       => (string) ( $item['operation_uuid'] ?? '' ),
				'ownership_token_hash' => isset( $item['execution_token'] ) && '' !== $item['execution_token'] ? hash( 'sha256', (string) $item['execution_token'] ) : '',
				'attempt'              => (int) ( $item['attempt_count'] ?? 0 ),
				'failure_code'         => (string) ( $item['failure_code'] ?? '' ),
				'event_data'           => $json,
				'created_at'           => current_time( 'mysql', true ),
			)
		);
	}

	/** @param array<string,mixed> $before */
	private function action_event( array $before, string $event_type, string $after_status ): bool {
		$run = $this->run( (int) $before['import_run_id'] );
		return $run && $this->event(
			(int) $run['id'],
			(int) $before['import_item_id'],
			(int) $run['dry_run_id'],
			$event_type,
			0,
			array(
				'action_id'     => (int) $before['id'],
				'action_type'   => (string) $before['action_type'],
				'before_status' => (string) $before['status'],
				'after_status'  => $after_status,
			),
			$before
		);
	}

	/**
	 * @param array<string,mixed> $where Exact action ownership/state fence.
	 * @param array<string,mixed> $data  New action fields.
	 */
	private function transition_action( int $action_id, array $where, array $data, string $event_type ): bool {
		global $wpdb;
		$hint = $this->action( $action_id );
		if ( ! $hint || ! $this->begin() ) {
			return false;
		}
		$open = true;
		try {
			$this->locked_run( (int) $hint['import_run_id'] );
			$table  = $wpdb->prefix . 'ideaxperts_ea_import_actions';
			$action = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $action_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! is_array( $action ) ) {
				return $this->abort( $open );
			}
			foreach ( $where as $column => $expected ) {
				if ( (string) ( $action[ $column ] ?? '' ) !== (string) $expected ) {
					return $this->abort( $open );
				}
			}
			$data['updated_at'] = current_time( 'mysql', true );
			$match              = array_merge( array( 'id' => $action_id ), $where );
			$ok                 = $wpdb->update( $table, $data, $match );
			if ( false === $ok || 1 !== (int) $wpdb->rows_affected || ! $this->action_event( $action, $event_type, (string) ( $data['status'] ?? $action['status'] ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				return $this->abort( $open );
			}
			$open = false;
			return true;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	/**
	 * @param list<string>        $from  Allowed current states.
	 * @param array<string,mixed> $extra Additional state fields.
	 */
	private function transition_owned_item( int $item_id, string $token, array $from, string $to, array $extra ): bool {
		if ( '' === $token || ! in_array( $to, self::ITEM_STATES, true ) ) {
			return false;
		}
		global $wpdb;
		if ( ! $this->begin() ) {
			return false;
		}
		$open = true;
		try {
			$item    = $this->locked_item( $item_id );
			$current = (string) ( $item['status'] ?? '' );
			if ( ! $item || ! hash_equals( (string) $item['execution_token'], $token ) || ! in_array( $current, $from, true ) || ! in_array( $to, self::ITEM_TRANSITIONS[ $current ] ?? array(), true ) ) {
				return $this->abort( $open );
			}
			$data = array_merge(
				$extra,
				array(
					'status'     => $to,
					'updated_at' => current_time( 'mysql', true ),
				)
			);
			$ok   = $wpdb->update(
				$wpdb->prefix . 'ideaxperts_ea_import_items',
				$data,
				array(
					'id'              => $item_id,
					'status'          => $current,
					'execution_token' => $token,
				)
			);
			$run  = $this->run( (int) $item['import_run_id'] );
			if ( false === $ok || 1 !== (int) $wpdb->rows_affected || ! $run || ! $this->event( (int) $run['id'], $item_id, (int) $run['dry_run_id'], 'item_' . $to, 0, array(), $item ) || false === $wpdb->query( 'COMMIT' ) ) {
				return $this->abort( $open );
			}
			$open = false;
			return true;
		} finally {
			if ( $open ) {
				$this->rollback();
			}
		}
	}

	/** @return array<string,mixed>|null */
	private function locked_run( int $run_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_runs WHERE id = %d FOR UPDATE', $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	private function locked_item( int $item_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_items WHERE id = %d FOR UPDATE', $item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	private function locked_identity( int $identity_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_catalog_identities WHERE id = %d FOR UPDATE', $identity_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	private function locked_action( int $action_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ideaxperts_ea_import_actions WHERE id = %d FOR UPDATE', $action_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	public static function vendor_identity_key( string $scope, string $kind, string $product, string $option ): string {
		$json = wp_json_encode( array( $scope, $kind, $product, $option ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'Vendor identity could not be encoded.' );
		}
		return hash( 'sha256', $json );
	}

	/** @param array<string,mixed> $item */
	private function freshness_evidence_token( array $item, string $live_hash, string $freshness_at ): string {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
			return '';
		}
		$key     = (string) AUTH_KEY . "\0" . (string) SECURE_AUTH_KEY;
		$payload = wp_json_encode(
			array(
				(int) ( $item['id'] ?? 0 ),
				(int) ( $item['import_run_id'] ?? 0 ),
				(string) ( $item['source_scope'] ?? '' ),
				(string) ( $item['environment'] ?? '' ),
				(int) ( $item['approval_generation'] ?? 0 ),
				$live_hash,
				$freshness_at,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( ! is_string( $payload ) ) {
			return '';
		}
		return hash_hmac( 'sha256', $payload, $key );
	}

	private function begin(): bool {
		global $wpdb;
		return $this->session_usable && false !== $wpdb->query( 'START TRANSACTION' );
	}

	private function abort( bool &$open ): bool {
		$this->rollback();
		$open = false;
		return false;
	}

	private function abort_int( bool &$open ): int {
		$this->rollback();
		$open = false;
		return 0;
	}

	private function rollback(): bool {
		global $wpdb;
		$ok = false !== $wpdb->query( 'ROLLBACK' );
		if ( ! $ok ) {
			$this->session_usable = false;
		}
		return $ok;
	}

	private function token(): string {
		return bin2hex( random_bytes( 16 ) );
	}
}
