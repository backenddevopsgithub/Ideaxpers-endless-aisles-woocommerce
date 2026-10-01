<?php
namespace IdeaXperts\EndlessAisles\Tests\Database;

use IdeaXperts\EndlessAisles\Database\ImportRepository;
use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ImportPolicy;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use IdeaXperts\EndlessAisles\Tests\Support\FixedCatalogStateProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/SigningKeyConstants.php';

final class ImportRepositoryTest extends TestCase {
	private DryRunMemoryWpdb $wpdb;
	private ImportRepository $imports;
	private FixedCatalogStateProvider $catalog_state;

	protected function tearDown(): void {
		\IdeaXperts\EndlessAisles\Tests\Support\SigningKeyConstants::$values = null;
	}

	/** @dataProvider unusableSigningKeys */
	public function test_unusable_keys_reject_creation_verification_and_forged_permits( array $keys ): void {
		$context = $this->ready_item();
		\IdeaXperts\EndlessAisles\Tests\Support\SigningKeyConstants::$values = $keys;
		self::assertSame( 'wordpress_signing_keys_invalid', $this->imports->freshness_signing_configuration_error() );
		self::assertFalse( $this->permit( $context ) ); // Previously valid evidence must also fail verification.
		foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_items'] as &$item ) {
			$item['status'] = 'validating';
		}
		unset( $item );
		self::assertFalse( $this->imports->accept_freshness( $context['item_id'], $context['token'] ) );
		$item = $this->imports->item( $context['item_id'] );
		$payload = wp_json_encode( array( (int) $item['id'], (int) $item['import_run_id'], $item['source_scope'], $item['environment'], (int) $item['approval_generation'], $item['live_freshness_hash'], $item['live_freshness_at'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$predictable_key = (string) ( $keys['AUTH_KEY'] ?? '' ) . "\0" . (string) ( $keys['SECURE_AUTH_KEY'] ?? '' );
		foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_items'] as &$stored ) {
			$stored['status'] = 'ready';
			$stored['live_freshness_token'] = hash_hmac( 'sha256', $payload, $predictable_key );
		}
		unset( $stored );
		self::assertFalse( $this->permit( $context ) );
		self::assertSame( 'ready', $this->imports->item( $context['item_id'] )['status'] );
		self::assertSame( 'not_permitted', $this->imports->finalize_existing_link( $context['item_id'], $context['identity_id'], $context['token'], 1, $context['action_id'], $context['logical_key'], $context['dispatch_generation'], $context['action_execution_token'] ) );
	}

	public static function unusableSigningKeys(): array {
		$valid = array( 'AUTH_KEY' => AUTH_KEY, 'SECURE_AUTH_KEY' => SECURE_AUTH_KEY );
		$cases = array( 'both undefined' => array( array() ) );
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY' ) as $name ) {
			$missing = $valid;
			unset( $missing[ $name ] );
			$cases[ $name . ' undefined' ] = array( $missing );
			foreach ( array( 'empty' => '', 'whitespace' => " \t\r\n ", 'WordPress default' => 'put your unique phrase here', 'padded default' => '  PUT YOUR UNIQUE PHRASE HERE  put your unique phrase here ', 'short' => 'short-secret', 'single byte' => str_repeat( 'x', 64 ), 'repeated pattern' => str_repeat( 'abcdefghijklmnop', 4 ), 'non-string' => false, 'control bytes' => str_repeat( "\0", 32 ) . AUTH_KEY ) as $label => $value ) {
				$keys = $valid;
				$keys[ $name ] = $value;
				$cases[ $name . ' ' . $label ] = array( $keys );
			}
		}
		return $cases;
	}

	public function test_valid_signing_secrets_create_evidence_that_verifies(): void {
		self::assertSame( '', $this->imports->freshness_signing_configuration_error() );
		$context = $this->ready_item();
		$item = $this->imports->item( $context['item_id'] );
		$payload = wp_json_encode( array( (int) $item['id'], (int) $item['import_run_id'], $item['source_scope'], $item['environment'], (int) $item['approval_generation'], $item['live_freshness_hash'], $item['live_freshness_at'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		self::assertSame( 'ready', $item['status'] );
		self::assertSame( hash_hmac( 'sha256', $payload, AUTH_KEY . "\0" . SECURE_AUTH_KEY ), $item['live_freshness_token'] );
		self::assertTrue( $this->permit( $context ) );
	}

	protected function setUp(): void {
		$GLOBALS['ea_now']   = '2026-09-28 12:00:00'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Existing shared test clock.
		$this->wpdb          = new DryRunMemoryWpdb();
		$GLOBALS['wpdb']     = $this->wpdb;
		$this->catalog_state = new FixedCatalogStateProvider();
		$this->imports       = new ImportRepository( $this->catalog_state );
	}

	public function test_manifest_persistence_is_transactional_and_generation_unique(): void {
		$manifest = $this->manifest( 'production', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		self::assertGreaterThan( 0, $run_id );
		self::assertSame( ApprovalManifest::hash( $manifest ), $this->imports->run( $run_id )['manifest_hash'] );
		self::assertSame( 0, $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 ) );
		self::assertCount( 1, $this->wpdb->tables['wp_ideaxperts_ea_import_runs'] );
	}

	public function test_self_hashed_manifest_cannot_promote_a_server_blocked_action(): void {
		$manifest                       = $this->manifest( 'production', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$manifest['items'][0]['action'] = 'link';

		self::assertSame( 0, $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 ) );
		self::assertCount( 0, $this->wpdb->tables['wp_ideaxperts_ea_import_runs'] );
	}

	public function test_vendor_identity_and_store_upc_have_separate_atomic_uniqueness(): void {
		$manifest = $this->manifest( 'production', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ), $this->manifest_item( 2, 'p2', 'o2', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		self::assertTrue( $this->imports->start_run( $run_id ) );
		$items           = $this->imports->items( $run_id );
		$first_token     = $this->imports->claim_item( (int) $items[0]['id'] );
		$second_token    = $this->imports->claim_item( (int) $items[1]['id'] );
		$first_identity  = $this->imports->reserve_catalog_identity( (int) $items[0]['id'], $first_token );
		$second_identity = $this->imports->reserve_catalog_identity( (int) $items[1]['id'], $second_token );
		self::assertGreaterThan( 0, $first_identity );
		self::assertGreaterThan( 0, $second_identity );
		self::assertGreaterThan( 0, $this->imports->reserve_upc( $first_identity, (int) $items[0]['id'], $first_token ) );
		self::assertSame( 0, $this->imports->reserve_upc( $second_identity, (int) $items[1]['id'], $second_token ) );
	}

	public function test_competing_runs_cannot_reserve_the_same_scoped_vendor_identity(): void {
		$first_manifest  = $this->manifest( 'production', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$second_manifest = $this->manifest( 'production', array( $this->manifest_item( 2, 'p1', 'o1', '009876543210' ) ) );
		$first_run       = $this->imports->create_from_manifest( $first_manifest, ApprovalManifest::hash( $first_manifest ), 7 );
		$second_run      = $this->imports->create_from_manifest( $second_manifest, ApprovalManifest::hash( $second_manifest ), 7 );
		$this->imports->start_run( $first_run );
		$this->imports->start_run( $second_run );
		$first_item   = $this->imports->items( $first_run )[0];
		$second_item  = $this->imports->items( $second_run )[0];
		$first_token  = $this->imports->claim_item( (int) $first_item['id'] );
		$second_token = $this->imports->claim_item( (int) $second_item['id'] );

		self::assertGreaterThan( 0, $this->imports->reserve_catalog_identity( (int) $first_item['id'], $first_token ) );
		self::assertSame( 0, $this->imports->reserve_catalog_identity( (int) $second_item['id'], $second_token ) );
	}

	public function test_final_permit_rechecks_cancellation_freshness_and_current_owner(): void {
		$context = $this->ready_item();
		self::assertTrue( $this->imports->request_cancellation( $context['run_id'] ) );
		self::assertFalse( $this->permit( $context ) );
		self::assertSame( 'cancelled', $this->imports->item( $context['item_id'] )['status'] );
	}

	public function test_tampered_vendor_snapshot_cannot_be_accepted_as_fresh(): void {
		$manifest = $this->manifest( 'qa', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		$this->imports->start_run( $run_id );
		$item  = $this->imports->items( $run_id )[0];
		$token = $this->imports->claim_item( (int) $item['id'] );
		$this->imports->begin_validation( (int) $item['id'], $token );
		$this->wpdb->tables['wp_ideaxperts_ea_vendor_snapshots'][0]['payload'] = '{"ea_product_id":"tampered"}';

		self::assertFalse( $this->imports->accept_freshness( (int) $item['id'], $token ) );
		self::assertTrue( $this->imports->reject_stale( (int) $item['id'], $token ) );
		self::assertSame( 'stale_snapshot', $this->imports->item( (int) $item['id'] )['status'] );
	}

	public function test_final_permit_rejects_an_expired_lease_even_when_the_old_token_still_matches(): void {
		$context           = $this->ready_item();
		$GLOBALS['ea_now'] = '2026-09-28 13:00:00'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Existing shared test clock.

		self::assertFalse( $this->permit( $context ) );
		self::assertSame( 'ready', $this->imports->item( $context['item_id'] )['status'] );
	}

	public function test_applying_is_not_reclaimed_and_blocks_authoritative_cancellation(): void {
		$context = $this->ready_item();
		self::assertTrue( $this->permit( $context ) );
		$GLOBALS['ea_now'] = '2026-09-28 13:00:00'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Existing shared test clock.
		self::assertSame( 0, $this->imports->reclaim_expired_pre_apply() );
		self::assertTrue( $this->imports->request_cancellation( $context['run_id'] ) );
		self::assertFalse( $this->imports->finalize_cancellation( $context['run_id'] ) );
		self::assertTrue( $this->imports->move_applying_to_reconciling( $context['item_id'], $context['token'] ) );
		self::assertTrue( $this->imports->finish_reconciliation_without_write( $context['item_id'], $context['token'] ) );
		self::assertTrue( $this->imports->settle_cancel_requested_action( $context['action_id'], $context['dispatch_generation'], 99 ) );
		self::assertTrue( $this->imports->finalize_cancellation( $context['run_id'] ) );
		self::assertSame( 'cancelled', $this->imports->run( $context['run_id'] )['status'] );
	}

	public function test_cancellation_fences_a_validating_worker_and_becomes_authoritative(): void {
		$manifest = $this->manifest( 'qa', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		$this->imports->start_run( $run_id );
		$item  = $this->imports->items( $run_id )[0];
		$token = $this->imports->claim_item( (int) $item['id'] );
		self::assertTrue( $this->imports->begin_validation( (int) $item['id'], $token ) );

		self::assertTrue( $this->imports->request_cancellation( $run_id ) );
		self::assertFalse( $this->imports->accept_freshness( (int) $item['id'], $token ) );
		self::assertTrue( $this->imports->finalize_cancellation( $run_id ) );
		self::assertNotNull( $this->imports->run( $run_id )['cancellation_authoritative_at'] );
		self::assertSame( 'cancelled', $this->imports->item( (int) $item['id'] )['status'] );
	}

	public function test_expired_pre_apply_lease_is_reclaimed_and_old_token_is_fenced(): void {
		$manifest = $this->manifest( 'qa', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		$this->imports->start_run( $run_id );
		$item = $this->imports->items( $run_id )[0];
		$old  = $this->imports->claim_item( (int) $item['id'] );
		self::assertTrue( $this->imports->begin_validation( (int) $item['id'], $old ) );
		$GLOBALS['ea_now'] = '2026-09-28 13:00:00'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Existing shared test clock.
		self::assertSame( 1, $this->imports->reclaim_expired_pre_apply() );
		self::assertFalse( $this->imports->accept_freshness( (int) $item['id'], $old ) );
		self::assertNotSame( '', $this->imports->claim_item( (int) $item['id'] ) );
	}

	public function test_reclaimed_item_can_reacquire_its_own_durable_reservations_with_a_new_token(): void {
		$manifest = $this->manifest( 'production', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		$this->imports->start_run( $run_id );
		$item              = $this->imports->items( $run_id )[0];
		$old               = $this->imports->claim_item( (int) $item['id'] );
		$identity_id       = $this->imports->reserve_catalog_identity( (int) $item['id'], $old );
		$upc_id            = $this->imports->reserve_upc( $identity_id, (int) $item['id'], $old );
		$GLOBALS['ea_now'] = '2026-09-28 13:00:00'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Existing shared test clock.
		self::assertSame( 1, $this->imports->reclaim_expired_pre_apply() );
		$new = $this->imports->claim_item( (int) $item['id'] );

		self::assertNotSame( $old, $new );
		self::assertSame( $identity_id, $this->imports->reserve_catalog_identity( (int) $item['id'], $new ) );
		self::assertSame( $upc_id, $this->imports->reserve_upc( $identity_id, (int) $item['id'], $new ) );
	}

	public function test_qa_and_production_use_distinct_vendor_and_upc_namespaces(): void {
		$qa         = $this->manifest( 'qa', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$production = $this->manifest( 'production', array( $this->manifest_item( 2, 'p1', 'o1', '001234567890' ) ) );
		$qa_run     = $this->imports->create_from_manifest( $qa, ApprovalManifest::hash( $qa ), 7 );
		$prod_run   = $this->imports->create_from_manifest( $production, ApprovalManifest::hash( $production ), 7 );
		$this->imports->start_run( $qa_run );
		$this->imports->start_run( $prod_run );
		$qa_item       = $this->imports->items( $qa_run )[0];
		$prod_item     = $this->imports->items( $prod_run )[0];
		$qa_token      = $this->imports->claim_item( (int) $qa_item['id'] );
		$prod_token    = $this->imports->claim_item( (int) $prod_item['id'] );
		$qa_identity   = $this->imports->reserve_catalog_identity( (int) $qa_item['id'], $qa_token );
		$prod_identity = $this->imports->reserve_catalog_identity( (int) $prod_item['id'], $prod_token );

		self::assertNotSame( $qa_identity, $prod_identity );
		self::assertGreaterThan( 0, $this->imports->reserve_upc( $qa_identity, (int) $qa_item['id'], $qa_token ) );
		self::assertGreaterThan( 0, $this->imports->reserve_upc( $prod_identity, (int) $prod_item['id'], $prod_token ) );
		$namespaces = array_column( $this->wpdb->tables['wp_ideaxperts_ea_store_identifier_reservations'], 'namespace' );
		self::assertContains( 'preview:qa', $namespaces );
		self::assertContains( 'woocommerce_catalog', $namespaces );
	}

	public function test_invalid_run_transition_is_rejected(): void {
		$manifest = $this->manifest( 'qa', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );

		self::assertFalse( $this->imports->transition_run( $run_id, array( 'queued' ), 'completed' ) );
		self::assertSame( 'queued', $this->imports->run( $run_id )['status'] );
	}

	public function test_cancellation_rolls_back_when_a_child_fence_write_fails(): void {
		$manifest = $this->manifest( 'qa', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		$this->imports->start_run( $run_id );
		$this->wpdb->fail_query_contains = 'wp_ideaxperts_ea_import_items';

		self::assertFalse( $this->imports->request_cancellation( $run_id ) );
		self::assertSame( 'running', $this->imports->run( $run_id )['status'] );
		self::assertContains( 'ROLLBACK', $this->wpdb->queries );
	}

	public function test_dispatch_compare_and_set_and_old_owner_fencing(): void {
		$manifest = $this->manifest( 'qa', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		$this->imports->start_run( $run_id );
		$item      = $this->imports->items( $run_id )[0];
		$action_id = $this->imports->create_action( $run_id, (int) $item['id'], 1, 'validate', 'hook' );
		$first     = $this->imports->claim_dispatch( $action_id );
		self::assertNotSame( '', $first );
		self::assertSame( '', $this->imports->claim_dispatch( $action_id ) );
		$generation = (int) $this->imports->action( $action_id )['dispatch_generation'];
		self::assertFalse( $this->imports->record_dispatched( $action_id, 'stale-owner', 91, $generation ) );
		self::assertTrue( $this->imports->record_dispatched( $action_id, $first, 92, $generation ) );
	}

	public function test_expired_action_execution_is_reopened_and_old_execution_owner_is_fenced(): void {
		$manifest = $this->manifest( 'qa', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		$this->imports->start_run( $run_id );
		$item       = $this->imports->items( $run_id )[0];
		$action_id  = $this->imports->create_action( $run_id, (int) $item['id'], 1, 'validate', 'hook' );
		$dispatch   = $this->imports->claim_dispatch( $action_id );
		$generation = (int) $this->imports->action( $action_id )['dispatch_generation'];
		$this->imports->record_dispatched( $action_id, $dispatch, 92, $generation );
		$action            = $this->imports->action( $action_id );
		$execution         = $this->imports->claim_action_execution( $action_id, (string) $action['logical_key'], $generation );
		$GLOBALS['ea_now'] = '2026-09-28 13:00:00'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Existing shared test clock.

		self::assertSame( 1, $this->imports->reclaim_expired_action_executions() );
		self::assertFalse( $this->imports->complete_action_execution( $action_id, (string) $action['logical_key'], $execution ) );
		self::assertSame( 'retry_wait', $this->imports->action( $action_id )['status'] );
		self::assertNotSame( '', $this->imports->claim_dispatch( $action_id ) );
	}

	public function test_live_catalog_change_before_validation_rejects_freshness(): void {
		$manifest = $this->manifest( 'production', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		$this->imports->start_run( $run_id );
		$item  = $this->imports->items( $run_id )[0];
		$token = $this->imports->claim_item( (int) $item['id'] );
		$this->imports->begin_validation( (int) $item['id'], $token );
		$this->catalog_state->version = 'UPC or mapping changed';
		self::assertFalse( $this->imports->accept_freshness( (int) $item['id'], $token ) );
	}

	public function test_live_catalog_change_after_validation_blocks_final_permit_even_with_reservation(): void {
		$context = $this->ready_item();
		self::assertCount( 1, $this->wpdb->tables['wp_ideaxperts_ea_store_identifier_reservations'] );
		$this->catalog_state->version = 'occupied after validation';
		self::assertFalse( $this->permit( $context ) );
		self::assertSame( 'ready', $this->imports->item( $context['item_id'] )['status'] );
	}

	public function test_final_permit_rejects_wrong_execution_and_freshness_tokens(): void {
		$context = $this->ready_item();
		self::assertFalse( $this->permit( $context, array( 'token' => 'wrong-worker-token' ) ) );
		foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_items'] as &$stored ) {
			if ( (int) $stored['id'] === $context['item_id'] ) {
				$stored['live_freshness_token'] = str_repeat( '0', 64 );
			}
		}
		unset( $stored );
		self::assertFalse( $this->permit( $context ) );
	}

	/** @dataProvider freshnessContextMutations */
	public function test_final_permit_rejects_freshness_evidence_rebound_to_another_context( callable $mutate ): void {
		$context = $this->ready_item();
		foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_items'] as &$stored ) {
			if ( (int) $stored['id'] === $context['item_id'] ) {
				$mutate( $stored );
			}
		}
		unset( $stored );
		self::assertFalse( $this->permit( $context ) );
	}

	/** @return array<string,array{callable(array<string,mixed>&):void}> */
	public static function freshnessContextMutations(): array {
		return array(
			'wrong item'        => array(
				static function ( array &$item ): void {
										$item['id'] = (int) $item['id'] + 100; },
			),
			'wrong run'         => array(
				static function ( array &$item ): void {
										$item['import_run_id'] = (int) $item['import_run_id'] + 100; },
			),
			'wrong environment' => array(
				static function ( array &$item ): void {
					$item['environment']  = 'qa';
					$item['source_scope'] = 'endless-aisles:qa';
				},
			),
		);
	}

	public function test_environment_bound_freshness_rejects_qa_evidence_for_production(): void {
		$manifest = $this->manifest( 'qa', array( $this->manifest_item( 1, 'p1', 'o1', '001234567890' ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		$this->imports->start_run( $run_id );
		$item = $this->imports->items( $run_id )[0];
		foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_items'] as &$stored ) {
			if ( (int) $stored['id'] === (int) $item['id'] ) {
				$stored['environment']  = 'production';
				$stored['source_scope'] = 'endless-aisles:production';
			}
		}
		unset( $stored );
		$token = $this->imports->claim_item( (int) $item['id'] );
		$this->imports->begin_validation( (int) $item['id'], $token );
		self::assertFalse( $this->imports->accept_freshness( (int) $item['id'], $token ) );
	}

	public function test_vendor_identity_encoding_is_unambiguous_for_hostile_and_edge_ids(): void {
		self::assertNotSame(
			ImportRepository::vendor_identity_key( 'endless-aisles:qa', 'option', "a\0b", 'c' ),
			ImportRepository::vendor_identity_key( 'endless-aisles:qa', 'option', 'a', "b\0c" )
		);
		self::assertNotSame( ImportRepository::vendor_identity_key( 's', 'parent', '0', '' ), ImportRepository::vendor_identity_key( 's', 'variation', '0', '' ) );
		self::assertNotSame( ImportRepository::vendor_identity_key( 's', 'option', '0', '' ), ImportRepository::vendor_identity_key( 's', 'option', '', '0' ) );
		self::assertNotSame( ImportRepository::vendor_identity_key( 's', 'option', 'é', str_repeat( 'x', 191 ) ), ImportRepository::vendor_identity_key( 's', 'option', 'e', str_repeat( 'x', 191 ) ) );
	}

	public function test_final_permit_requires_current_action_generation_and_execution_owner(): void {
		$context = $this->ready_item();
		self::assertFalse( $this->permit( $context, array( 'action_id' => $context['action_id'] + 100 ) ) );
		self::assertFalse( $this->permit( $context, array( 'logical_key' => str_repeat( 'f', 64 ) ) ) );
		self::assertFalse( $this->permit( $context, array( 'dispatch_generation' => $context['dispatch_generation'] + 1 ) ) );
		self::assertFalse( $this->permit( $context, array( 'action_execution_token' => 'stale-execution' ) ) );
	}

	public function test_final_permit_rejects_expired_or_terminal_action_execution(): void {
		$context = $this->ready_item();
		foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] as &$stored ) {
			if ( (int) $stored['id'] === $context['action_id'] ) {
				$stored['lease_expires_at'] = '2026-09-28 11:59:59';
			}
		}
		unset( $stored );
		self::assertFalse( $this->permit( $context ) );

		foreach ( array( 'cancelled', 'completed' ) as $status ) {
			foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] as &$stored ) {
				if ( (int) $stored['id'] === $context['action_id'] ) {
					$stored['status']           = $status;
					$stored['lease_expires_at'] = '2026-09-28 13:00:00';
				}
			}
			unset( $stored );
			self::assertFalse( $this->permit( $context ) );
		}
	}

	public function test_final_permit_rejects_action_from_another_run_and_item(): void {
		$first  = $this->ready_item();
		$second = $this->ready_item( 'p2', '009876543210' );
		self::assertFalse(
			$this->permit(
				$first,
				array(
					'action_id'              => $second['action_id'],
					'logical_key'            => $second['logical_key'],
					'dispatch_generation'    => $second['dispatch_generation'],
					'action_execution_token' => $second['action_execution_token'],
				)
			)
		);
	}

	public function test_final_permit_rejects_action_for_another_item_in_same_run(): void {
		$context = $this->ready_item();
		foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] as &$stored ) {
			if ( (int) $stored['id'] === $context['action_id'] ) {
				$stored['import_item_id'] = $context['item_id'] + 1;
			}
		}
		unset( $stored );

		self::assertFalse( $this->permit( $context ) );
	}

	public function test_old_action_generation_cannot_permit_after_redispatch(): void {
		$GLOBALS['ea_now'] = '2026-09-28 12:01:00';
		$context           = $this->ready_item();
		foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] as &$stored_action ) {
			if ( (int) $stored_action['id'] === $context['action_id'] ) {
				$stored_action['lease_expires_at'] = '2026-09-28 12:05:00';
			}
		}
		unset( $stored_action );
		foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_items'] as &$stored_item ) {
			if ( (int) $stored_item['id'] === $context['item_id'] ) {
				$stored_item['lease_expires_at'] = '2026-09-28 12:06:00';
			}
		}
		unset( $stored_item );
		$old               = $context;
		$GLOBALS['ea_now'] = '2026-09-28 12:05:30';
		self::assertSame( 1, $this->imports->reclaim_expired_action_executions() );
		$owner   = $this->imports->claim_dispatch( $context['action_id'] );
		$current = $this->imports->action( $context['action_id'] );
		self::assertTrue( $this->imports->record_dispatched( $context['action_id'], $owner, 990, (int) $current['dispatch_generation'] ) );
		$current_execution = $this->imports->claim_action_execution( $context['action_id'], (string) $current['logical_key'], (int) $current['dispatch_generation'] );
		self::assertFalse( $this->permit( $old ) );
		self::assertTrue(
			$this->permit(
				$context,
				array(
					'dispatch_generation'   => (int) $current['dispatch_generation'],
					'action_execution_token' => $current_execution,
				)
			)
		);
	}

	/** @return array{run_id:int,item_id:int,identity_id:int,token:string,action_id:int,logical_key:string,dispatch_generation:int,action_execution_token:string} */
	private function ready_item( string $product = 'p1', string $upc = '001234567890' ): array {
		$manifest = $this->manifest( 'production', array( $this->manifest_item( 1, $product, 'o1', $upc ) ) );
		$run_id   = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		$this->imports->start_run( $run_id );
		$item       = $this->imports->items( $run_id )[0];
		$action_id  = $this->imports->create_action( $run_id, (int) $item['id'], 1, 'validate', 'permit-test' );
		$dispatch   = $this->imports->claim_dispatch( $action_id );
		$action     = $this->imports->action( $action_id );
		$generation = (int) $action['dispatch_generation'];
		$this->imports->record_dispatched( $action_id, $dispatch, 99, $generation );
		$execution = $this->imports->claim_action_execution( $action_id, (string) $action['logical_key'], $generation );
		$token = $this->imports->claim_item( (int) $item['id'] );
		$this->imports->begin_validation( (int) $item['id'], $token );
		$identity = $this->imports->reserve_catalog_identity( (int) $item['id'], $token );
		$this->imports->reserve_upc( $identity, (int) $item['id'], $token );
		$this->imports->accept_freshness( (int) $item['id'], $token );
		return array(
			'run_id'      => $run_id,
			'item_id'     => (int) $item['id'],
			'identity_id' => $identity,
			'token'       => $token,
			'action_id'   => $action_id,
			'logical_key' => (string) $action['logical_key'],
			'dispatch_generation'   => $generation,
			'action_execution_token' => $execution,
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $overrides
	 */
	private function permit( array $context, array $overrides = array() ): bool {
		$values = array_merge( $context, $overrides );
		return $this->imports->acquire_final_write_permit(
			(int) $values['item_id'],
			(int) $values['identity_id'],
			(string) $values['token'],
			1,
			(int) $values['action_id'],
			(string) $values['logical_key'],
			(int) $values['dispatch_generation'],
			(string) $values['action_execution_token']
		);
	}

	/** @param list<array<string,mixed>> $items @return array<string,mixed> */
	private function manifest( string $environment, array $items ): array {
		static $dry_run_id = 1000;
		++$dry_run_id;
		foreach ( $items as &$item ) {
			$vendor                     = is_array( $item['vendor'] ?? null ) ? $item['vendor'] : array();
			$item['group_key']          = hash( 'sha256', $environment . "\0" . (string) ( $vendor['ea_product_id'] ?? '' ) );
			$context                    = array_merge(
				$vendor,
				array(
					'target_wc_product_id'   => (int) ( $item['target']['wc_product_id'] ?? 0 ),
					'target_wc_variation_id' => (int) ( $item['target']['wc_variation_id'] ?? 0 ),
				)
			);
			$item['expected_live_hash'] = $this->catalog_state->fingerprint( $context, 'endless-aisles:' . $environment, $environment );
		}
		unset( $item );
		return array(
			'version'                => 1,
			'policy_version'         => ImportPolicy::VERSION,
			'dry_run_id'             => $dry_run_id,
			'dry_run_generation'     => 1,
			'environment'            => $environment,
			'source_scope'           => 'endless-aisles:' . $environment,
			'approval_generation'    => 1,
			'matching_settings_hash' => str_repeat( 'a', 64 ),
			'items'                  => $items,
		);
	}

	/** @return array<string,mixed> */
	private function manifest_item( int $id, string $product, string $option, string $upc ): array {
		$vendor = array(
			'ea_product_id'  => $product,
			'ea_option_id'   => $option,
			'normalized_upc' => $upc,
			'classification' => 'new_product_candidate',
			'review_flags'   => array(),
			'retail_price'   => '10',
			'purchasable'    => 1,
			'discontinued'   => 0,
		);
		$target = array(
			'wc_product_id'   => 0,
			'wc_variation_id' => 0,
		);
		return array(
			'dry_run_item_id'       => $id,
			'action'                => 'create',
			'entity_kind'           => 'option',
			'group_key'             => hash( 'sha256', 'production' . "\0" . $product ),
			'vendor'                => $vendor,
			'target'                => $target,
			'expected_vendor_hash'  => ApprovalManifest::hash( $vendor ),
			'expected_local_hash'   => ApprovalManifest::hash( $target ),
			'expected_mapping_hash' => ApprovalManifest::hash(
				array(
					'product' => $product,
					'option'  => $option,
					'target'  => $target,
				)
			),
		);
	}
}
