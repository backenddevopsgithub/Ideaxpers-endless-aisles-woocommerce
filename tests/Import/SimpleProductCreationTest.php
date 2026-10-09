<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Database\ImportRepository;
use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ImportManager;
use IdeaXperts\EndlessAisles\Import\ImportPolicy;
use IdeaXperts\EndlessAisles\Import\LiveCatalogStateProvider;
use IdeaXperts\EndlessAisles\Import\SimpleProductCreation;
use IdeaXperts\EndlessAisles\Import\SimpleProductProjection;
use IdeaXperts\EndlessAisles\Import\CreationPricingPolicyInterface;
use IdeaXperts\EndlessAisles\Import\ConfiguredCreationPricingPolicy;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use IdeaXperts\EndlessAisles\Tests\Support\FakeSimpleProductWriter;
use IdeaXperts\EndlessAisles\Tests\Support\FixedCatalogStateProvider;
use IdeaXperts\EndlessAisles\Tests\Support\FixturePricingPolicy;
use IdeaXperts\EndlessAisles\Tests\Support\ReadOnlyProduct;
use PHPUnit\Framework\TestCase;

final class SimpleProductCreationTest extends TestCase {
	private DryRunMemoryWpdb $db;
	private FixedCatalogStateProvider $state;
	private ImportRepository $imports;
	private FakeSimpleProductWriter $writer;
	private SimpleProductCreation $creation;
	private ImportManager $manager;
	private int $run;
	private array $action;
	private array $vendor;
	private ?CreationPricingPolicyInterface $pricing = null;
	private SimpleProductProjection $projection;
	private ?LiveCatalogStateProvider $live_state = null;

	protected function setUp(): void {
		$GLOBALS['ea_now'] = '2026-10-02 12:00:00';
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings'] = array( 'environment' => 'qa' );
		$GLOBALS['ea_action_queue'] = array();
		$GLOBALS['ea_enqueue_failure'] = false;
		$GLOBALS['ea_recovery_actions'] = array();
		$GLOBALS['ea_recovery_schedule_failure'] = false;
		$GLOBALS['ea_registered_hooks'] = array();
		$this->db = new DryRunMemoryWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->state = new FixedCatalogStateProvider();
		$this->imports = new ImportRepository( $this->state );
		$this->writer = new FakeSimpleProductWriter( $this->state );
		$this->vendor = array( 'id' => 1, 'classification' => 'new_product_candidate', 'review_flags' => '[]', 'ea_product_id' => 'p', 'ea_option_id' => 'o', 'normalized_upc' => '001234567890', 'wc_product_id' => 0, 'wc_variation_id' => 0, 'vendor_title' => '<b>Safe title</b>', 'vendor_option_description' => '<p onclick="bad()">Description</p><script>bad()</script>', 'retail_price' => '10', 'purchasable' => 1, 'discontinued' => 0 );
	}

	private function approve( string $environment = 'qa', bool $pricing = true, ?CreationPricingPolicyInterface $policy = null, bool $controlled = true ): void {
		$this->pricing = $pricing ? ( $policy ?? new FixturePricingPolicy() ) : null;
		$this->projection = new SimpleProductProjection( $this->pricing );
		$this->creation = new SimpleProductCreation( $this->imports, $this->writer, $this->projection );
		$this->manager = new ImportManager( $this->imports, $this->creation );
		$this->manager->register();
		do_action( 'init' );
		$this->vendor['environment'] = $environment;
		$this->vendor['source_scope'] = 'endless-aisles:' . $environment;
		$built = ( new ApprovalManifest( new ImportPolicy(), $this->live_state ?? $this->state, $this->projection ) )->build( array( 'id' => count( $this->db->tables['wp_ideaxperts_ea_import_runs'] ) + 1, 'status' => 'completed', 'environment' => $environment, 'source_scope' => 'endless-aisles:' . $environment, 'completed_at' => $GLOBALS['ea_now'] ), array( $this->vendor ), array(), 1, array( 'controlled_qa_creation' => $controlled, 'allow_sku_upc_match' => $GLOBALS['ea_test_options']['ideaxperts_ea_settings']['allow_sku_upc_match'] ?? 'no' ) );
		$this->run = $this->imports->create_from_manifest( $built['manifest'], $built['hash'], 7 );
		self::assertGreaterThan( 0, $this->run );
		self::assertTrue( $this->manager->queue( $this->run ) );
		$this->action = $this->imports->creation_action( (int) $this->imports->items( $this->run )[0]['id'] );
	}

	private function deliver_callback( ?array $action = null ): void {
		$action ??= $this->action;
		$this->manager->validate_item( $action['id'], $action['logical_key'], $action['dispatch_generation'] );
	}

	private function item(): array { return $this->imports->items( $this->run )[0]; }
	private function expire(): void { $GLOBALS['ea_now'] = '2026-10-02 13:00:00'; }
	private function mapping_count(): int { return count( $this->db->tables['wp_ideaxperts_ea_mappings'] ); }

	/** Real inspection and SQL, with only Woo persistence replaced by the external-store double. */
	private function use_live_catalog(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings'] = array( 'use_global_unique_id' => 'yes', 'upc_meta_keys' => array(), 'allow_sku_upc_match' => 'no' );
		$GLOBALS['ea_wc_products'] = array();
		$GLOBALS['ea_wc_product_map'] = array();
		$GLOBALS['ea_wc_pages'] = array();
		$GLOBALS['ea_wc_max_pages'] = 1;
		$this->live_state = new LiveCatalogStateProvider( new SettingsRepository() );
		$this->imports = new ImportRepository( $this->live_state );
		$this->writer->after_persistence = function (): void {
			$product = new ReadOnlyProduct( 501, 'simple', 'draft', 'Safe title', (string) $this->item()['normalized_upc'], '' );
			$GLOBALS['ea_wc_products'] = array( $product );
			$GLOBALS['ea_wc_product_map'][501] = $product;
		};
	}

	private function fail_live_mapping_read(): void {
		// This SELECT is inside the actual LiveCatalogStateProvider::inspect(), not correlation or settlement writes.
		$this->db->fail_read_contains = 'SELECT source_scope,environment,wc_product_id';
	}

	private function assert_creation_adopted_once(): void {
		self::assertSame( 1, $this->writer->saves );
		self::assertCount( 1, $this->writer->objects );
		self::assertSame( 501, $this->item()['target_wc_product_id'] );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( '', $this->item()['failure_code'] );
		self::assertSame( 'completed', $this->imports->action( $this->action['id'] )['status'] );
		$this->assert_transaction_closed();
	}

	/** @dataProvider pendingCancellation */
	public function test_creator_live_mapping_select_failure_is_recovered_without_another_save( bool $cancel ): void {
		$this->use_live_catalog();
		$this->approve();
		$operation = $this->item()['operation_uuid'];
		$publish = $this->writer->after_persistence;
		$this->writer->after_persistence = function () use ( $publish, $cancel ): void {
			$publish();
			self::assertSame( 1, $this->writer->saves );
			if ( $cancel ) { self::assertTrue( $this->imports->request_cancellation( $this->run ) ); }
			$this->fail_live_mapping_read();
		};
		$this->deliver_callback();
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( 'reconciling', $this->item()['status'] );
		self::assertSame( 'creation_inspection_read_failed', $this->item()['failure_code'] );
		self::assertSame( 0, $this->item()['attempt_count'] );
		self::assertSame( '2026-10-02 12:01:00', $this->item()['lease_expires_at'] );
		self::assertSame( $cancel ? 'cancel_requested' : 'running', $this->imports->action( $this->action['id'] )['status'] );
		self::assertSame( 'reserved', $this->db->tables['wp_ideaxperts_ea_catalog_identities'][0]['reservation_status'] );
		self::assertSame( 'reserved', $this->db->tables['wp_ideaxperts_ea_store_identifier_reservations'][0]['reservation_status'] );
		self::assertSame( $operation, $this->item()['operation_uuid'] );
		$this->assert_transaction_closed();
		$events = array_values( array_filter( $this->db->tables['wp_ideaxperts_ea_import_events'], static fn( array $event ): bool => 'creation_recovery_deferred' === $event['event_type'] ) );
		self::assertCount( 1, $events );
		self::assertSame( 'creation_inspection_read_failed', $events[0]['failure_code'] );
		$this->db->fail_read_contains = '';
		$this->scheduled_recovery();
		self::assertSame( 0, $this->mapping_count() ); // Backoff has not elapsed.
		if ( $cancel ) { self::assertFalse( $this->imports->finalize_cancellation( $this->run ) ); }
		$GLOBALS['ea_now'] = $this->item()['lease_expires_at'];
		$this->scheduled_recovery();
		$this->scheduled_recovery();
		$this->deliver_callback();
		$this->assert_creation_adopted_once();
		if ( $cancel ) { self::assertSame( 'cancelled', $this->imports->run( $this->run )['status'] ); }
	}

	public static function pendingCancellation(): array { return array( array( false ), array( true ) ); }

	public function test_scheduled_live_mapping_select_failure_defers_then_adopts_original_draft(): void {
		$this->use_live_catalog();
		$this->approve();
		self::assertTrue( $this->permit( $this->ready() ) );
		$this->writer->create_draft( $this->item(), $this->projection->build( $this->imports->creation_snapshot( (int) $this->item()['id'] ) ) );
		$this->expire();
		$this->fail_live_mapping_read();
		$this->scheduled_recovery();
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( 'reconciling', $this->item()['status'] );
		self::assertSame( 'creation_inspection_read_failed', $this->item()['failure_code'] );
		self::assertSame( 1, $this->item()['attempt_count'] );
		self::assertSame( '2026-10-02 13:01:00', $this->item()['lease_expires_at'] );
		self::assertSame( 'running', $this->imports->action( $this->action['id'] )['status'] );
		$this->assert_transaction_closed();
		$this->db->fail_read_contains = '';
		$this->scheduled_recovery();
		self::assertSame( 1, $this->writer->lookups );
		$GLOBALS['ea_now'] = $this->item()['lease_expires_at'];
		$this->scheduled_recovery();
		$this->scheduled_recovery();
		$this->assert_creation_adopted_once();
	}

	public function test_repeated_live_mapping_select_failures_use_existing_backoff_and_exhaustion(): void {
		$this->use_live_catalog();
		$this->approve();
		self::assertTrue( $this->permit( $this->ready() ) );
		$this->writer->create_draft( $this->item(), $this->projection->build( $this->imports->creation_snapshot( (int) $this->item()['id'] ) ) );
		$operation = $this->item()['operation_uuid'];
		$this->expire();
		$this->fail_live_mapping_read();
		foreach ( array( 60, 120, 240, 300, 300 ) as $index => $delay ) {
			$now = strtotime( $GLOBALS['ea_now'] );
			$this->scheduled_recovery();
			self::assertSame( $index + 1, $this->item()['attempt_count'] );
			self::assertSame( 'reconciling', $this->item()['status'] );
			self::assertSame( 'creation_inspection_read_failed', $this->item()['failure_code'] );
			self::assertSame( $now + $delay, strtotime( $this->item()['lease_expires_at'] ) );
			self::assertSame( 0, $this->mapping_count() );
			self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
			$this->assert_transaction_closed();
			$this->scheduled_recovery();
			self::assertSame( $index + 1, $this->writer->lookups );
			$GLOBALS['ea_now'] = $this->item()['lease_expires_at'];
		}
		$this->scheduled_recovery();
		$this->db->fail_read_contains = '';
		$this->scheduled_recovery();
		$this->deliver_callback();
		self::assertSame( 6, $this->item()['attempt_count'] );
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 'recovery_attempts_exhausted', $this->item()['failure_code'] );
		self::assertSame( 5, $this->writer->lookups );
		self::assertSame( 1, $this->writer->saves );
		self::assertCount( 1, $this->writer->objects );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( $operation, $this->item()['operation_uuid'] );
		self::assertSame( $operation, $this->writer->objects[501]['item']['operation_uuid'] );
		self::assertSame( 'reserved', $this->db->tables['wp_ideaxperts_ea_catalog_identities'][0]['reservation_status'] );
		self::assertSame( 'reserved', $this->db->tables['wp_ideaxperts_ea_store_identifier_reservations'][0]['reservation_status'] );
		self::assertSame( 'completed', $this->imports->action( $this->action['id'] )['status'] );
		$this->assert_transaction_closed();
	}

	public function test_live_upc_conflict_is_still_semantic_manual_recovery(): void {
		$this->use_live_catalog();
		$this->approve();
		$publish = $this->writer->after_persistence;
		$this->writer->after_persistence = function () use ( $publish ): void {
			$publish();
			$GLOBALS['ea_wc_products'][] = new ReadOnlyProduct( 999, 'simple', 'publish', 'Other', (string) $this->item()['normalized_upc'], '' );
		};
		$this->deliver_callback();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 'post_save_upc_conflict', $this->item()['failure_code'] );
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
		$this->assert_transaction_closed();
	}

	public function test_transient_inspection_retry_cannot_restore_expired_or_replaced_ownership(): void {
		$this->use_live_catalog();
		$this->approve();
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		$this->writer->create_draft( $this->item(), $this->projection->build( $this->imports->creation_snapshot( (int) $this->item()['id'] ) ) );
		$operation = $this->item()['operation_uuid'];
		$id = (int) $this->item()['id'];
		$this->expire();
		$owner_b = $this->imports->claim_creation_recovery( $id );
		self::assertNotSame( '', $owner_b );
		$this->fail_live_mapping_read();
		self::assertSame( 'inspection_retry', $this->imports->finalize_simple_creation( $id, $this->action['id'], $operation, 501, '', array(), $owner_b ) );
		$this->imports->defer_creation_recovery( $id, $owner_b, 'creation_inspection_read_failed' );
		self::assertSame( 'reconciling', $this->item()['status'] );
		self::assertTrue( $this->imports->request_cancellation( $this->run ) );
		$GLOBALS['ea_now'] = $this->item()['lease_expires_at'];
		$before = $this->db->tables;
		$this->imports->defer_creation_recovery( $id, $owner_b, 'creation_inspection_read_failed' );
		self::assertSame( $before, $this->db->tables ); // An expired token cannot renew itself before takeover either.
		$owner_c = $this->imports->claim_creation_recovery( $id );
		self::assertNotSame( '', $owner_c );
		self::assertNotSame( $owner_b, $owner_c );
		$before = $this->db->tables;
		foreach ( array( '', $ready[1], $owner_b ) as $stale ) {
			self::assertSame( 'not_permitted', $this->imports->finalize_simple_creation( $id, $this->action['id'], $operation, 501, '', array(), $stale ) );
			$this->imports->defer_creation_recovery( $id, $stale, 'creation_inspection_read_failed' );
			self::assertSame( $before, $this->db->tables );
			$this->assert_transaction_closed();
		}
		$this->db->fail_read_contains = '';
		( new \ReflectionMethod( SimpleProductCreation::class, 'recover' ) )->invoke( $this->creation, $this->item(), $this->action, 0, $owner_c, true );
		$this->scheduled_recovery();
		$this->assert_creation_adopted_once();
		self::assertSame( 'cancelled', $this->imports->run( $this->run )['status'] );
	}

	private function ready(): array {
		$id = (int) $this->item()['id'];
		$execution = $this->imports->claim_action_execution( $this->action['id'], $this->action['logical_key'], $this->action['dispatch_generation'] );
		$token = $this->imports->claim_item( $id );
		self::assertTrue( $this->imports->begin_validation( $id, $token ) );
		$identity = $this->imports->reserve_catalog_identity( $id, $token );
		self::assertGreaterThan( 0, $this->imports->reserve_upc( $identity, $id, $token ) );
		self::assertTrue( $this->imports->accept_freshness( $id, $token ) );
		return array( $identity, $token, $execution );
	}

	private function permit( array $ready ): bool {
		$vendor = $this->imports->creation_snapshot( (int) $this->item()['id'] );
		$binding = $this->projection->binding( $vendor, (string) $this->item()['environment'], $this->projection->build( $vendor ) );
		return $this->imports->acquire_final_write_permit( (int) $this->item()['id'], $ready[0], $ready[1], 1, $this->action['id'], $this->action['logical_key'], $this->action['dispatch_generation'], $ready[2], $binding, $this->projection );
	}

	public function test_production_single_draft_mapping_and_response_loss_replay(): void {
		$this->approve();
		$this->deliver_callback();
		$this->deliver_callback();
		$this->expire();
		$this->manager->reconcile();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( 'vendor_created', $this->db->tables['wp_ideaxperts_ea_catalog_identities'][0]['ownership_mode'] );
		self::assertSame( 'draft', $this->writer->objects[501]['status'] );
		self::assertSame( 'Safe title', $this->writer->objects[501]['projection']['title'] );
		self::assertSame( '<p>Description</p>', $this->writer->objects[501]['projection']['description'] );
	}

	public function test_missing_production_policy_blocks_without_external_intent(): void {
		$this->approve( 'qa', false );
		$this->deliver_callback();
		self::assertSame( 'blocked', $this->item()['status'] );
		self::assertSame( 'pricing_policy_missing', $this->item()['failure_code'] );
		self::assertSame( 0, $this->writer->saves );
		self::assertSame( array(), $this->db->tables['wp_ideaxperts_ea_catalog_identities'] );
		self::assertSame( array(), $this->db->tables['wp_ideaxperts_ea_store_identifier_reservations'] );
		$this->approve( 'qa', true );
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 1, $this->writer->saves );
	}


	public function test_exception_after_persistence_adopts_exact_draft_without_second_save(): void {
		$this->approve();
		$this->writer->mode = 'ambiguous';
		$this->deliver_callback();
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 501, $this->item()['target_wc_product_id'] );
		self::assertSame( 1, $this->mapping_count() );
	}

	/** @dataProvider finalizationFailures */
	public function test_rollback_after_external_save_recovers_mapping_once( string $failure ): void {
		$this->approve();
		$this->writer->after_persistence = function () use ( $failure ): void {
			if ( 'mapping' === $failure ) { $this->db->fail_insert_table_contains = 'mappings'; }
			elseif ( 'audit' === $failure ) { $this->db->fail_import_event_type = 'authoritative_create_finalized'; }
			elseif ( 'commit' === $failure ) { $this->db->fail_query_contains = 'COMMIT'; }
			else { $this->db->fail_update_table_contains = $failure; }
		};
		$this->deliver_callback();
		self::assertSame( 'applying', $this->item()['status'] );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( 1, count( $this->writer->objects ) );
		$this->db->fail_query_contains = '';
		$this->expire();
		$this->manager->reconcile();
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->imports->run( $this->run )['applied_count'] );
	}

	public static function finalizationFailures(): array {
		return array_map( static fn( string $failure ): array => array( $failure ), array( 'catalog_identities', 'mapping', 'import_items', 'import_actions', 'import_runs', 'audit', 'commit' ) );
	}

	/** @dataProvider badCorrelation */
	public function test_bad_or_missing_correlation_never_recreates( string $mutation ): void {
		$this->approve();
		$this->writer->after_persistence = function () use ( $mutation ): void {
			if ( 'multiple' === $mutation ) { $this->writer->objects[502] = $this->writer->objects[501]; }
			elseif ( 'missing' === $mutation ) { $this->writer->objects = array(); }
			elseif ( in_array( $mutation, array( 'type', 'status' ), true ) ) { $this->writer->objects[501][ $mutation ] = 'wrong'; }
			else { $this->writer->objects[501]['item'][ $mutation ] = 'wrong'; }
		};
		$this->deliver_callback();
		$this->expire();
		$this->manager->reconcile();
		$this->deliver_callback();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 0, $this->mapping_count() );
	}

	public static function badCorrelation(): array {
		return array_map( static fn( string $key ): array => array( $key ), array( 'multiple', 'missing', 'environment', 'source_scope', 'ea_product_id', 'ea_option_id', 'normalized_upc', 'id', 'type', 'status' ) );
	}

	public function test_duplicate_worker_during_save_and_stale_generation_cannot_create(): void {
		$this->approve();
		$this->writer->after_persistence = function (): void {
			$this->deliver_callback();
			$this->manager->validate_item( $this->action['id'], $this->action['logical_key'], $this->action['dispatch_generation'] - 1 );
			self::assertSame( 1, $this->writer->saves );
		};
		$this->deliver_callback();
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 'applied', $this->item()['status'] );
	}

	/** @dataProvider environments */
	public function test_cancellation_before_permit_prevents_write( string $environment ): void {
		$this->approve( $environment );
		self::assertTrue( $this->imports->request_cancellation( $this->run ) );
		$this->deliver_callback();
		self::assertSame( 'cancelled', $this->item()['status'] );
		self::assertSame( 0, $this->writer->saves );
	}

	public static function environments(): array { return array( array( 'qa' ), array( 'qa' ) ); }

	public function test_cancellation_after_save_adopts_draft_before_settling_run(): void {
		$this->approve();
		$this->writer->mode = 'ambiguous';
		$this->writer->after_persistence = function (): void { self::assertTrue( $this->imports->request_cancellation( $this->run ) ); };
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 'cancelling', $this->imports->run( $this->run )['status'] );
		self::assertTrue( $this->imports->finalize_cancellation( $this->run ) );
		self::assertSame( 1, $this->writer->saves );
	}

	public function test_external_upc_race_keeps_draft_and_requires_manual_recovery(): void {
		$this->approve();
		$this->writer->after_persistence = function (): void { $this->state->upc_owners[] = '999:0'; };
		$this->deliver_callback();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 'post_save_upc_conflict', $this->item()['failure_code'] );
		self::assertSame( 501, $this->item()['target_wc_product_id'] );
		self::assertCount( 1, $this->writer->objects );
		self::assertSame( 0, $this->mapping_count() );
	}

	public function test_returned_id_mismatch_is_not_adopted(): void {
		$this->approve();
		$this->writer->mode = 'mismatch';
		$this->deliver_callback();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 'correlation_returned_id_mismatch', $this->item()['failure_code'] );
	}

	public function test_before_persistence_exception_is_conservatively_manual(): void {
		$this->approve();
		$this->writer->mode = 'before';
		$this->deliver_callback();
		$this->expire();
		$this->manager->reconcile();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( array(), $this->writer->objects );
	}

	public function test_crash_before_permit_is_safely_retryable_with_same_operation(): void {
		$this->approve();
		$this->ready();
		$operation = $this->item()['operation_uuid'];
		$this->expire();
		$this->manager->reconcile();
		$this->deliver_callback( $this->imports->creation_action( (int) $this->item()['id'] ) );
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( $operation, $this->item()['operation_uuid'] );
		self::assertSame( 1, $this->writer->saves );
	}

	public function test_crash_after_committed_permit_without_object_never_authorizes_recreate(): void {
		$this->approve();
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		$this->expire();
		$this->manager->reconcile();
		$this->deliver_callback();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 0, $this->writer->saves );
	}

	public function test_crash_after_persisted_draft_before_finalizer_recovers_exact_object_once(): void {
		$this->approve();
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		$item = $this->item();
		self::assertSame( 501, $this->writer->create_draft( $item, $this->projection->build( $this->imports->creation_snapshot( (int) $item['id'] ) ) ) );
		// Simulate worker death: no coordinator/finalizer runs in this execution.
		$this->deliver_callback();
		self::assertSame( 'applying', $this->item()['status'] );
		$this->expire();
		$this->manager->reconcile();
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 501, $this->item()['target_wc_product_id'] );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->writer->saves );
	}

	public function test_cancellation_after_permit_before_save_fences_worker_and_stays_unresolved(): void {
		$this->approve();
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		self::assertTrue( $this->imports->request_cancellation( $this->run ) );
		self::assertFalse( $this->imports->creation_can_start( (int) $this->item()['id'], $this->action['id'], $ready[1], $ready[2], $this->action['dispatch_generation'] ) );
		$this->expire();
		$this->manager->reconcile();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 'cancelling', $this->imports->run( $this->run )['status'] );
		self::assertSame( 0, $this->writer->saves );
	}

	public function test_mapping_appearing_before_final_permit_prevents_save(): void {
		$this->approve();
		$ready = $this->ready();
		$this->state->mappings = array( array( 'wc_product_id' => 999 ) );
		self::assertFalse( $this->permit( $ready ) );
		self::assertSame( 0, $this->writer->saves );
	}

	/** @dataProvider mappingConflicts */
	public function test_mapping_or_target_owner_conflict_after_save_never_binds_wrong_object( bool $identity ): void {
		$this->approve();
		$this->writer->after_persistence = function () use ( $identity ): void {
			$item = $this->item();
			if ( $identity ) {
				$row = $this->db->tables['wp_ideaxperts_ea_catalog_identities'][0];
				$row['id'] = 999;
				$row['owning_import_item_id'] = 999;
				$row['wc_identity_key'] = hash( 'sha256', '501:0' );
				$this->db->tables['wp_ideaxperts_ea_catalog_identities'][] = $row;
			} else {
				$this->db->tables['wp_ideaxperts_ea_mappings'][] = array( 'id' => 999, 'source_scope' => 'endless-aisles:qa', 'environment' => 'qa', 'wc_product_id' => 999, 'wc_variation_id' => 0, 'ea_product_id' => $item['ea_product_id'], 'ea_option_id' => $item['ea_option_id'], 'normalized_upc' => $item['normalized_upc'], 'mapping_status' => 'active' );
			}
		};
		$this->deliver_callback();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( $identity ? 'wc_identity_conflict' : 'mapping_conflict', $this->item()['failure_code'] );
		self::assertSame( 1, $this->writer->saves );
	}

	public static function mappingConflicts(): array { return array( array( false ), array( true ) ); }

	public function test_late_save_after_recovery_settles_missing_remains_manual(): void {
		$this->approve();
		$this->writer->before_persistence = function (): void {
			$this->expire();
			$this->manager->reconcile();
			self::assertSame( 'manual_recovery', $this->item()['status'] );
			self::assertSame( 'correlation_missing', $this->item()['failure_code'] );
		};
		$this->deliver_callback();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 'correlation_missing', $this->item()['failure_code'] );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( '', $this->item()['execution_token'] );
		self::assertSame( 1, $this->writer->saves );
	}

	/** @dataProvider creatorTakeovers */
	public function test_original_creator_is_fenced_after_recovery_takeover( string $ordering, bool $ambiguous ): void {
		$this->approve( 'qa', true, new FixturePricingPolicy( '20.00' ) );
		$this->writer->mode = $ambiguous ? 'ambiguous' : 'success';
		$token = '';
		$owned = array();
		$takeover = function () use ( $ordering, &$token, &$owned ): void {
			$this->assert_transaction_closed();
			self::assertSame( 'applying', $this->item()['status'] );
			self::assertSame( '20.00', $this->writer->objects[501]['projection']['regular_price'] );
			$this->pricing->price = '25.00';
			if ( 'cancel_before' === $ordering ) { self::assertTrue( $this->imports->request_cancellation( $this->run ) ); }
			$this->expire();
			$token = $this->imports->claim_creation_recovery( (int) $this->item()['id'] );
			self::assertNotSame( '', $token );
			self::assertSame( 'reconciling', $this->item()['status'] );
			if ( 'cancel_after' === $ordering ) { self::assertTrue( $this->imports->request_cancellation( $this->run ) ); }
			$owned = $this->db->tables;
		};
		if ( 'lookup' === $ordering ) { $this->writer->before_lookup = $takeover; }
		else { $this->writer->after_persistence = $takeover; }
		// Resume the actual validate_item -> apply -> recover creator callback.
		$this->deliver_callback();
		self::assertSame( $owned, $this->db->tables );
		self::assertSame( $token, $this->item()['execution_token'] );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
		$this->assert_transaction_closed();
		$this->writer->before_lookup = null;
		$this->writer->after_persistence = null;
		// Continue the legitimate recovery owner through the production correlation path.
		( new \ReflectionMethod( SimpleProductCreation::class, 'recover' ) )->invoke( $this->creation, $this->item(), $this->action, 0, $token, true );
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 501, $this->item()['target_wc_product_id'] );
		self::assertSame( 'completed', $this->imports->action( $this->action['id'] )['status'] );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( 1, $this->writer->saves );
		self::assertCount( 1, $this->writer->objects );
		self::assertSame( '20.00', $this->writer->objects[501]['projection']['regular_price'] );
		$this->scheduled_recovery();
		$this->deliver_callback();
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( 1, $this->writer->saves );
		$this->assert_transaction_closed();
	}

	public static function creatorTakeovers(): array {
		return array( array( 'save', false ), array( 'save', true ), array( 'lookup', false ), array( 'cancel_before', false ), array( 'cancel_after', true ) );
	}

	private function assert_transaction_closed(): void {
		self::assertNull( ( new \ReflectionProperty( DryRunMemoryWpdb::class, 'transaction_snapshot' ) )->getValue( $this->db ) );
	}

	public function test_creator_finishes_before_recovery_takeover(): void {
		$this->approve();
		$this->writer->after_persistence = function (): void {
			$this->assert_transaction_closed();
			self::assertSame( '', $this->imports->claim_creation_recovery( (int) $this->item()['id'] ) );
		};
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		$this->expire();
		self::assertSame( '', $this->imports->claim_creation_recovery( (int) $this->item()['id'] ) );
		$this->scheduled_recovery();
		self::assertSame( 'completed', $this->imports->action( $this->action['id'] )['status'] );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( 1, $this->writer->saves );
	}

	/** @dataProvider invalidCreatorOwners */
	public function test_repository_rejects_missing_expired_or_wrong_creator_owner( string $invalid ): void {
		$this->approve();
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		$this->writer->create_draft( $this->item(), $this->projection->build( $this->imports->creation_snapshot( (int) $this->item()['id'] ) ) );
		if ( 'expired' === $invalid ) { $this->expire(); }
		$token = 'empty' === $invalid ? '' : ( 'wrong' === $invalid ? 'wrong' : $ready[1] );
		$before = $this->db->tables;
		self::assertSame( 'not_permitted', $this->imports->finalize_simple_creation( (int) $this->item()['id'], $this->action['id'], $this->item()['operation_uuid'], 501, '', array(), $token ) );
		self::assertSame( $before, $this->db->tables );
		$this->assert_transaction_closed();
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
	}

	public static function invalidCreatorOwners(): array { return array( array( 'empty' ), array( 'expired' ), array( 'wrong' ) ); }

	public function test_finalizer_exception_after_mapping_insert_rolls_back_and_closes_transaction(): void {
		$this->approve();
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		$this->writer->create_draft( $this->item(), $this->projection->build( $this->imports->creation_snapshot( (int) $this->item()['id'] ) ) );
		$before = $this->db->tables;
		$this->db->throw_update_table_contains = 'import_actions';
		try {
			$this->imports->finalize_simple_creation( (int) $this->item()['id'], $this->action['id'], $this->item()['operation_uuid'], 501, '', array(), $ready[1] );
			self::fail( 'Expected injected exception' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'Injected update exception.', $error->getMessage() );
		}
		self::assertSame( $before, $this->db->tables );
		$this->assert_transaction_closed();
		$this->expire();
		$this->scheduled_recovery();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( 1, $this->writer->saves );
	}

	public function test_cancellation_after_applied_preserves_the_one_product_and_mapping(): void {
		$this->approve();
		$this->deliver_callback();
		self::assertTrue( $this->imports->request_cancellation( $this->run ) );
		self::assertTrue( $this->imports->finalize_cancellation( $this->run ) );
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->writer->saves );
	}

	public function test_final_permit_audit_failure_before_external_write_retries_safely(): void {
		$this->approve();
		$this->db->fail_import_event_type = 'create_started';
		$this->deliver_callback();
		self::assertSame( 'ready', $this->item()['status'] );
		self::assertSame( 0, $this->writer->saves );
		$this->expire();
		$this->manager->reconcile();
		$this->deliver_callback( $this->imports->creation_action( (int) $this->item()['id'] ) );
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 1, $this->writer->saves );
	}

	private function replace_policy( ?CreationPricingPolicyInterface $pricing ): void {
		$this->pricing = $pricing;
		$this->projection = new SimpleProductProjection( $pricing );
		$this->creation = new SimpleProductCreation( $this->imports, $this->writer, $this->projection );
		$this->manager = new ImportManager( $this->imports, $this->creation );
		$GLOBALS['ea_registered_hooks'] = array();
		$this->manager->register();
	}

	/** Invoke the registered scheduled hook, without an admin request or reconcile() call. */
	private function scheduled_recovery(): void {
		self::assertCount( 1, $GLOBALS['ea_recovery_actions'] );
		$action = $GLOBALS['ea_recovery_actions'][0];
		self::assertSame( ImportManager::GROUP, $action['group'] );
		self::assertSame( 60, $action['interval'] );
		do_action( $action['hook'], ...$action['args'] );
	}

	public function test_missing_policy_added_after_approval_requires_explicit_reapproval(): void {
		$this->approve( 'qa', false );
		$before = $this->imports->run( $this->run );
		$this->replace_policy( new FixturePricingPolicy( '20.00' ) );
		$this->deliver_callback();
		self::assertSame( 'blocked', $this->item()['status'] );
		self::assertSame( 'approval_projection_changed', $this->item()['failure_code'] );
		self::assertSame( 0, $this->writer->saves );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( $before['manifest_hash'], $this->imports->run( $this->run )['manifest_hash'] );
		self::assertSame( $before['approval_generation'], $this->imports->run( $this->run )['approval_generation'] );
		self::assertSame( array(), $this->db->tables['wp_ideaxperts_ea_catalog_identities'] );
		$this->approve( 'qa', true, $this->pricing );
		self::assertNotSame( $before['manifest_hash'], $this->imports->run( $this->run )['manifest_hash'] );
		self::assertSame( '20.00', $this->imports->approved_creation_binding( (int) $this->item()['id'] )['regular_price'] );
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( '20.00', $this->writer->objects[501]['projection']['regular_price'] );
		self::assertSame( 1, $this->writer->saves );
	}

	/** @dataProvider pricingChanges */
	public function test_pricing_identity_version_config_removal_and_price_changes_require_reapproval( string $change ): void {
		$this->approve( 'qa', true, new FixturePricingPolicy( '20.00' ) );
		if ( 'removed' === $change ) { $this->replace_policy( null ); }
		else { $this->pricing->$change = 'price' === $change ? '25.00' : 'changed'; }
		$this->deliver_callback();
		self::assertSame( 'approval_projection_changed', $this->item()['failure_code'] );
		self::assertSame( 'blocked', $this->item()['status'] );
		self::assertSame( 0, $this->writer->saves );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
	}

	public static function pricingChanges(): array {
		return array( array( 'price' ), array( 'version' ), array( 'id' ), array( 'config' ), array( 'removed' ) );
	}

	public function test_configured_source_price_drift_blocks_then_fresh_approval_creates_once(): void {
		$GLOBALS['ea_test_options']['woocommerce_currency'] = 'USD';
		$GLOBALS['ea_test_options']['woocommerce_price_num_decimals'] = 2;
		$this->vendor['created_at'] = $GLOBALS['ea_now'];
		$this->vendor['retail_price'] = '20';
		$policy = new ConfiguredCreationPricingPolicy( ConfiguredCreationPricingPolicyTest::config() );
		$this->approve( 'qa', true, $policy );
		self::assertSame( '20.00', $this->imports->approved_creation_binding( (int) $this->item()['id'] )['regular_price'] );
		$row = &$this->db->tables['wp_ideaxperts_ea_vendor_snapshots'][0];
		$original = $row;
		$payload = json_decode( $row['payload'], true );
		$payload['retail_price'] = '25';
		$row['payload'] = wp_json_encode( $payload );
		$row['payload_hash'] = ApprovalManifest::hash( $payload );
		$this->db->tables['wp_ideaxperts_ea_import_items'][0]['expected_vendor_hash'] = $row['payload_hash'];
		$this->deliver_callback();
		self::assertSame( 'approval_projection_changed', $this->item()['failure_code'] );
		self::assertSame( 0, $this->writer->saves );
		self::assertSame( 0, $this->mapping_count() );
		$row = $original;
		$this->db->tables['wp_ideaxperts_ea_import_items'][0]['expected_vendor_hash'] = $original['payload_hash'];
		$this->vendor['retail_price'] = '25';
		$this->approve( 'qa', true, $policy );
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( '25.00', $this->writer->objects[501]['projection']['regular_price'] );
		self::assertSame( 1, $this->writer->saves );
	}

	public function test_configured_policy_configuration_drift_requires_reapproval(): void {
		$GLOBALS['ea_test_options']['woocommerce_currency'] = 'USD';
		$GLOBALS['ea_test_options']['woocommerce_price_num_decimals'] = 2;
		$this->vendor['created_at'] = $GLOBALS['ea_now'];
		$config = ConfiguredCreationPricingPolicyTest::config();
		$this->approve( 'qa', true, new ConfiguredCreationPricingPolicy( $config ) );
		$config['approval_reference'] = 'different-approved-decision';
		$this->replace_policy( new ConfiguredCreationPricingPolicy( $config ) );
		$this->deliver_callback();
		self::assertSame( 'approval_projection_changed', $this->item()['failure_code'] );
		self::assertSame( 0, $this->writer->saves );
		self::assertSame( 0, $this->mapping_count() );
	}

	/** @dataProvider changedFields */
	public function test_snapshot_field_drift_cannot_refresh_original_approval( string $field, string $value ): void {
		$this->approve();
		$row = &$this->db->tables['wp_ideaxperts_ea_vendor_snapshots'][0];
		$payload = json_decode( $row['payload'], true );
		$payload[ $field ] = $value;
		$row['payload'] = wp_json_encode( $payload );
		$row['payload_hash'] = ApprovalManifest::hash( $payload );
		$this->db->tables['wp_ideaxperts_ea_import_items'][0]['expected_vendor_hash'] = $row['payload_hash'];
		$this->deliver_callback();
		self::assertSame( 'approval_projection_changed', $this->item()['failure_code'] );
		self::assertSame( 0, $this->writer->saves );
		self::assertSame( 0, $this->mapping_count() );
	}

	public static function changedFields(): array {
		return array( array( 'vendor_title', 'Changed title' ), array( 'vendor_option_description', '<p>Changed description</p>' ), array( 'normalized_upc', '009876543210' ) );
	}

	public function test_final_permit_rebuilds_policy_even_after_successful_preflight(): void {
		$this->approve();
		$ready = $this->ready();
		$old = $this->imports->approved_creation_binding( (int) $this->item()['id'] );
		$this->pricing->price = '25.00';
		self::assertFalse( $this->imports->acquire_final_write_permit( (int) $this->item()['id'], $ready[0], $ready[1], 1, $this->action['id'], $this->action['logical_key'], $this->action['dispatch_generation'], $ready[2], $old, $this->projection ) );
		self::assertSame( 'ready', $this->item()['status'] );
		self::assertSame( 0, $this->writer->saves );
	}

	public function test_scheduled_worker_recovers_dead_creator_using_original_price_after_policy_change(): void {
		$this->approve( 'qa', true, new FixturePricingPolicy( '20.00' ) );
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		$vendor = $this->imports->creation_snapshot( (int) $this->item()['id'] );
		$this->writer->create_draft( $this->item(), $this->projection->build( $vendor ) );
		// Creator terminates: no coordinator/finalizer is invoked.
		$this->replace_policy( new FixturePricingPolicy( '25.00', 'changed', 'v2' ) );
		$this->expire();
		$this->writer->before_lookup = function () use ( $ready ): void {
			$this->writer->before_lookup = null;
			$before = $this->db->tables;
			self::assertSame( 'reconciling', $this->item()['status'] );
			// Resume the original creator's post-save path during scheduled recovery.
			( new \ReflectionMethod( SimpleProductCreation::class, 'recover' ) )->invoke( $this->creation, $this->item(), $this->action, 501, $ready[1] );
			self::assertSame( $before, $this->db->tables );
			self::assertSame( 0, $this->mapping_count() );
			self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
			$this->assert_transaction_closed();
		};
		$this->scheduled_recovery();
		$this->scheduled_recovery();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 'completed', $this->imports->action( $this->action['id'] )['status'] );
		self::assertSame( '20.00', $this->writer->objects[501]['projection']['regular_price'] );
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->imports->run( $this->run )['applied_count'] );
	}

	public function test_stale_recovery_after_cancellation_cannot_settle_or_clean_up_current_owner(): void {
		$this->approve();
		self::assertTrue( $this->permit( $this->ready() ) );
		$this->writer->create_draft( $this->item(), $this->projection->build( $this->imports->creation_snapshot( (int) $this->item()['id'] ) ) );
		$this->expire();
		$first = $this->imports->claim_creation_recovery( (int) $this->item()['id'] );
		self::assertNotSame( '', $first );
		self::assertTrue( $this->imports->request_cancellation( $this->run ) );
		$GLOBALS['ea_now'] = $this->item()['lease_expires_at'];
		$next = $this->imports->claim_creation_recovery( (int) $this->item()['id'] );
		self::assertNotSame( '', $next );
		self::assertNotSame( $first, $next );
		$before = $this->db->tables;
		self::assertSame( 'not_permitted', $this->imports->finalize_simple_creation( (int) $this->item()['id'], $this->action['id'], $this->item()['operation_uuid'], 501, '', array(), $first ) );
		$this->imports->defer_creation_recovery( (int) $this->item()['id'], $first );
		self::assertFalse( $this->imports->move_applying_to_reconciling( (int) $this->item()['id'], $first ) );
		self::assertSame( $before, $this->db->tables );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( 0, $this->imports->run( $this->run )['applied_count'] );
		$this->assert_transaction_closed();
		self::assertSame( 'applied', $this->imports->finalize_simple_creation( (int) $this->item()['id'], $this->action['id'], $this->item()['operation_uuid'], 501, '', array(), $next ) );
		self::assertTrue( $this->imports->finalize_cancellation( $this->run ) );
		self::assertSame( 'cancelled', $this->imports->run( $this->run )['status'] );
		self::assertSame( 'completed', $this->imports->action( $this->action['id'] )['status'] );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->imports->run( $this->run )['applied_count'] );
		self::assertSame( 1, $this->writer->saves );
	}

	public function test_scheduled_recovery_retries_finalization_rollback_without_another_save(): void {
		$this->approve();
		$this->writer->after_persistence = function (): void { $this->db->fail_insert_table_contains = 'mappings'; };
		$this->deliver_callback();
		self::assertSame( 'applying', $this->item()['status'] );
		$this->expire();
		$this->scheduled_recovery();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->writer->saves );
	}

	/** @dataProvider scheduledConflicts */
	public function test_scheduled_missing_multiple_invalid_and_upc_conflict_are_manual( string $mode ): void {
		$this->approve();
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		if ( 'missing' !== $mode ) { $this->writer->create_draft( $this->item(), $this->projection->build( $this->imports->creation_snapshot( (int) $this->item()['id'] ) ) ); }
		if ( 'multiple' === $mode ) { $this->writer->objects[502] = $this->writer->objects[501]; }
		if ( 'invalid' === $mode ) { $this->writer->objects[501]['status'] = 'publish'; }
		if ( 'upc' === $mode ) { $this->state->upc_owners[] = '999:0'; }
		$before = $this->writer->saves;
		$this->expire();
		$this->scheduled_recovery();
		$this->scheduled_recovery();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( $before, $this->writer->saves );
	}

	public static function scheduledConflicts(): array { return array( array( 'missing' ), array( 'multiple' ), array( 'invalid' ), array( 'upc' ) ); }

	public function test_live_creator_and_duplicate_recovery_claims_are_fenced(): void {
		$this->approve();
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		self::assertSame( '', $this->imports->claim_creation_recovery( (int) $this->item()['id'] ) );
		$this->scheduled_recovery();
		self::assertSame( 0, $this->writer->lookups );
		$this->writer->create_draft( $this->item(), $this->projection->build( $this->imports->creation_snapshot( (int) $this->item()['id'] ) ) );
		$this->expire();
		$token = $this->imports->claim_creation_recovery( (int) $this->item()['id'] );
		self::assertNotSame( '', $token );
		self::assertSame( '', $this->imports->claim_creation_recovery( (int) $this->item()['id'] ) );
		$this->scheduled_recovery();
		self::assertSame( 0, $this->writer->lookups );
		$GLOBALS['ea_now'] = '2026-10-02 13:06:00';
		$next = $this->imports->claim_creation_recovery( (int) $this->item()['id'] );
		self::assertNotSame( $token, $next );
		self::assertSame( 'not_permitted', $this->imports->finalize_simple_creation( (int) $this->item()['id'], $this->action['id'], $this->item()['operation_uuid'], 501, '', array(), $token ) );
		self::assertSame( 'applied', $this->imports->finalize_simple_creation( (int) $this->item()['id'], $this->action['id'], $this->item()['operation_uuid'], 501, '', array(), $next ) );
		self::assertSame( 1, $this->writer->saves );
	}

	public function test_temporary_lookup_failure_backs_off_and_exhausts_to_manual(): void {
		$this->approve();
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		$this->writer->lookup_failure = true;
		$this->expire();
		foreach ( array( 60, 120, 240, 300, 300 ) as $attempt => $delay ) {
			$now = strtotime( $GLOBALS['ea_now'] );
			$this->scheduled_recovery();
			self::assertSame( $attempt + 1, $this->item()['attempt_count'] );
			self::assertSame( 'reconciling', $this->item()['status'] );
			self::assertSame( $now + $delay, strtotime( $this->item()['lease_expires_at'] ) );
			$this->scheduled_recovery();
			self::assertSame( $attempt + 1, $this->writer->lookups );
			$GLOBALS['ea_now'] = $this->item()['lease_expires_at'];
		}
		$this->scheduled_recovery();
		$this->scheduled_recovery();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 'recovery_attempts_exhausted', $this->item()['failure_code'] );
		self::assertSame( 5, $this->writer->lookups );
		self::assertSame( 0, $this->writer->saves );
	}

	public function test_scheduled_recovery_continues_during_cancellation(): void {
		$this->approve();
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		$this->writer->create_draft( $this->item(), $this->projection->build( $this->imports->creation_snapshot( (int) $this->item()['id'] ) ) );
		self::assertTrue( $this->imports->request_cancellation( $this->run ) );
		$this->expire();
		$this->scheduled_recovery();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 'cancelled', $this->imports->run( $this->run )['status'] );
		self::assertSame( 1, $this->writer->saves );
	}

	public function test_recovery_trigger_is_unique_repaired_and_required_before_save(): void {
		$this->approve();
		do_action( 'init' );
		self::assertCount( 1, $GLOBALS['ea_recovery_actions'] );
		$GLOBALS['ea_recovery_actions'] = array();
		$GLOBALS['ea_recovery_schedule_failure'] = true;
		$this->deliver_callback();
		self::assertSame( 'creation_recovery_unavailable', $this->item()['failure_code'] );
		self::assertSame( 0, $this->writer->saves );
		$GLOBALS['ea_recovery_schedule_failure'] = false;
		do_action( 'init' );
		self::assertCount( 1, $GLOBALS['ea_recovery_actions'] );
	}

	public function test_scheduled_finalizer_failure_defers_only_reconciliation(): void {
		$this->approve();
		$ready = $this->ready();
		self::assertTrue( $this->permit( $ready ) );
		$this->writer->create_draft( $this->item(), $this->projection->build( $this->imports->creation_snapshot( (int) $this->item()['id'] ) ) );
		$this->expire();
		$this->db->fail_insert_table_contains = 'mappings';
		$this->scheduled_recovery();
		self::assertSame( 'reconciling', $this->item()['status'] );
		self::assertSame( 0, $this->mapping_count() );
		self::assertSame( '2026-10-02 13:01:00', $this->item()['lease_expires_at'] );
		$this->scheduled_recovery();
		self::assertSame( 1, $this->writer->lookups );
		$GLOBALS['ea_now'] = '2026-10-02 13:01:00';
		$this->scheduled_recovery();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 1, $this->mapping_count() );
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 1, $this->imports->run( $this->run )['applied_count'] );
	}

	public function test_scheduled_batch_is_bounded_and_recurring_trigger_handles_remainder(): void {
		for ( $index = 0; $index < ImportRepository::BATCH_SIZE + 1; ++$index ) {
			$this->vendor['ea_product_id'] = 'p' . $index;
			$this->vendor['normalized_upc'] = str_pad( (string) $index, 12, '0', STR_PAD_LEFT );
			$this->approve();
			self::assertTrue( $this->permit( $this->ready() ) );
		}
		$this->replace_policy( $this->pricing );
		$this->expire();
		$this->scheduled_recovery();
		self::assertSame( ImportRepository::BATCH_SIZE, $this->writer->lookups );
		$statuses = array_count_values( array_column( $this->db->tables['wp_ideaxperts_ea_import_items'], 'status' ) );
		self::assertSame( 1, $statuses['applying'] );
		self::assertSame( ImportRepository::BATCH_SIZE, $statuses['manual_recovery'] );
		$this->scheduled_recovery();
		self::assertSame( ImportRepository::BATCH_SIZE + 1, $this->writer->lookups );
		self::assertSame( 0, $this->writer->saves );
	}

	public function test_projection_change_after_reservations_releases_only_unstarted_claims_for_reapproval(): void {
		$this->approve();
		$ready = $this->ready();
		$this->pricing->price = '25.00';
		$this->creation->apply( $this->item(), $ready[0], $ready[1], $this->action, $ready[2] );
		self::assertSame( 'approval_projection_changed', $this->item()['failure_code'] );
		self::assertSame( array(), $this->db->tables['wp_ideaxperts_ea_catalog_identities'] );
		self::assertSame( array(), $this->db->tables['wp_ideaxperts_ea_store_identifier_reservations'] );
		self::assertSame( 0, $this->writer->saves );
		$this->approve( 'qa', true, $this->pricing );
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( '25.00', $this->writer->objects[501]['projection']['regular_price'] );
		self::assertSame( 1, $this->writer->saves );
	}
	public function test_qa_preview_is_reusable_and_cannot_save_or_claim_global_ownership(): void {
		$this->approve( 'qa', false, null, false );
		$this->deliver_callback();
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 0, $this->writer->saves );
		self::assertSame( 0, $this->mapping_count() );
		self::assertNull( $this->db->tables['wp_ideaxperts_ea_catalog_identities'][0]['wc_identity_key'] );
		self::assertSame( 'released', $this->db->tables['wp_ideaxperts_ea_store_identifier_reservations'][0]['reservation_status'] );
		$this->approve( 'qa', false, null, false );
		$this->deliver_callback();
		self::assertSame( 'applied', $this->item()['status'] );
		self::assertSame( 0, $this->writer->saves );
	}
	public function test_created_qa_option_is_linked_on_second_comparison_and_cannot_be_created_again(): void {
		$this->approve();
		$this->deliver_callback();
		$this->assert_creation_adopted_once();
		$mapping = $this->db->tables['wp_ideaxperts_ea_mappings'][0];
		self::assertSame( 'qa', $mapping['environment'] );
		self::assertSame( 'endless-aisles:qa', $mapping['source_scope'] );
		$classifier = new \IdeaXperts\EndlessAisles\Catalog\MatchClassifier();
		$option = array( 'ea_product_id' => 'p', 'id' => 'o', 'upc' => $this->vendor['normalized_upc'], 'retail_price' => '10', 'purchasability' => true );
		self::assertSame( 'new_product_candidate', $classifier->classify( $option, array(), array(), array(), false, false )['classification'] );
		self::assertSame( 'already_linked', $classifier->classify( $option, array( $mapping ), array(), array(), false, false )['classification'] );
		$this->state->mappings = array( $mapping );
		try {
			$this->approve();
			self::fail( 'An already linked option cannot receive a second creation approval.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'New creation requires current mapping and UPC absence.', $error->getMessage() );
		}
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 1, $this->mapping_count() );
	}
	/** @dataProvider scopeChanges */
	public function test_qa_source_expiry_or_production_settings_fence_final_permit( bool $expired ): void {
		$this->approve();
		$ready = $this->ready();
		if ( $expired ) {
			// Keep leases live to isolate the dry-run freshness check.
			foreach ( $this->db->tables['wp_ideaxperts_ea_import_runs'] as &$run ) {
				$manifest = json_decode( $run['approval_manifest'], true );
				$manifest['source_completed_at'] = '2026-09-30 12:00:00';
				$run['approval_manifest'] = wp_json_encode( $manifest );
				$run['manifest_hash'] = ApprovalManifest::hash( $manifest );
			}
			unset( $run );
		} else {
			$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['environment'] = 'production';
		}
		self::assertFalse( $this->permit( $ready ) );
		self::assertSame( 0, $this->writer->saves );
	}
	public static function scopeChanges(): array { return array( array( true ), array( false ) ); }
	public function test_recovery_rejects_changed_original_price_without_recreating(): void {
		$this->approve();
		self::assertTrue( $this->permit( $this->ready() ) );
		$vendor = $this->imports->creation_snapshot( (int) $this->item()['id'] );
		$this->writer->create_draft( $this->item(), $this->projection->build( $vendor ) );
		$this->writer->objects[501]['projection']['regular_price'] = '999.00';
		$this->expire();
		$this->manager->reconcile();
		self::assertSame( 'manual_recovery', $this->item()['status'] );
		self::assertSame( 1, $this->writer->saves );
		self::assertSame( 0, $this->mapping_count() );
	}

	public function test_matching_configuration_change_requires_reapproval_before_save(): void {
		$this->approve();
		$ready = $this->ready();
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['allow_sku_upc_match'] = 'yes';
		self::assertFalse( $this->permit( $ready ) );
		self::assertSame( 0, $this->writer->saves );
	}

}
