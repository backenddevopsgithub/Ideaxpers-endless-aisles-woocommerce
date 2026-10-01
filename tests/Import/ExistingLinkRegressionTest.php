<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Database\ImportRepository;
use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ImportManager;
use IdeaXperts\EndlessAisles\Import\ImportPolicy;
use IdeaXperts\EndlessAisles\Import\LiveCatalogStateProvider;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use IdeaXperts\EndlessAisles\Tests\Support\ReadOnlyProduct;
use PHPUnit\Framework\TestCase;

/** Exercise approval, real read-only inspection, durable recovery and finalization together. */
final class ExistingLinkRegressionTest extends TestCase {
	public static array $boundary_sizes = array();
	private DryRunMemoryWpdb $wpdb;
	private LiveCatalogStateProvider $provider;
	private ImportRepository $imports;
	private ImportManager $manager;

	protected function setUp(): void {
		$GLOBALS['ea_now'] = '2026-09-28 12:00:00';
		$GLOBALS['ea_action_queue'] = array();
		$GLOBALS['ea_enqueue_failure'] = false;
		$GLOBALS['ea_wc_writes'] = array();
		$GLOBALS['ea_wc_reads'] = array();
		$GLOBALS['ea_wc_product_map'] = array();
		$GLOBALS['ea_wc_products'] = array();
		$GLOBALS['ea_wc_pages'] = array();
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings'] = array( 'allow_sku_upc_match' => 'yes', 'use_global_unique_id' => 'yes', 'upc_meta_keys' => array() );
		$this->wpdb = new DryRunMemoryWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->provider = new LiveCatalogStateProvider( new SettingsRepository() );
		$this->imports = new ImportRepository( $this->provider );
		$this->manager = new ImportManager( $this->imports );
	}

	/** @return array<string,mixed> */
	private function selection( bool $variation, string $sku ): array {
		$target = new ReadOnlyProduct( $variation ? 51 : 50, $variation ? 'variation' : 'simple', 'publish', 'Target', '', $sku, array(), array(), $variation ? 50 : 0 );
		$GLOBALS['ea_wc_product_map'][ $variation ? 51 : 50 ] = $target;
		$GLOBALS['ea_wc_products'] = array( $target );
		if ( $variation ) {
			$GLOBALS['ea_wc_product_map'][50] = new ReadOnlyProduct( 50, 'variable', 'publish', 'Parent', '', '' );
		}
		return array(
			'id' => 1, 'classification' => 'exact_sku_match', 'review_flags' => '[]',
			'ea_product_id' => 'p', 'ea_option_id' => 'o', 'normalized_upc' => '001234567890',
			'wc_product_id' => 50, 'wc_variation_id' => $variation ? 51 : 0,
			'retail_price' => '10', 'purchasable' => 1, 'discontinued' => 0,
		);
	}

	/** @param array<string,mixed> $selection */
	private function approve( array $selection, string $environment = 'production' ): int {
		$builder = new ApprovalManifest( new ImportPolicy(), $this->provider );
		$built = $builder->build( array( 'id' => count( $this->wpdb->tables['wp_ideaxperts_ea_import_runs'] ) + 1, 'status' => 'completed', 'environment' => $environment ), array( $selection ), array( 1 => true ), 1, array() );
		$run = $this->imports->create_from_manifest( $built['manifest'], $built['hash'], 7 );
		self::assertGreaterThan( 0, $run );
		self::assertTrue( $this->manager->queue( $run ) );
		return $run;
	}

	/** Stop the worker exactly after freshness acceptance, without finalization.
	 * @return array{int,int,string,array<string,mixed>,string}
	 */
	private function ready( int $run ): array {
		$actions = array_values( array_filter( $this->wpdb->tables['wp_ideaxperts_ea_import_actions'], static fn( array $row ): bool => (int) $row['import_run_id'] === $run ) );
		$action = $actions[0];
		$execution = $this->imports->claim_action_execution( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertNotSame( '', $execution );
		$item = $this->imports->items( $run )[0];
		$id = (int) $item['id'];
		$token = $this->imports->claim_item( $id );
		self::assertNotSame( '', $token );
		self::assertTrue( $this->imports->begin_validation( $id, $token ) );
		$identity = $this->imports->reserve_catalog_identity( $id, $token );
		self::assertGreaterThan( 0, $identity );
		self::assertGreaterThan( 0, $this->imports->reserve_upc( $identity, $id, $token ) );
		self::assertTrue( $this->imports->accept_freshness( $id, $token ) );
		self::assertSame( 'ready', $this->imports->item( $id )['status'] );
		return array( $id, $identity, $token, $action, $execution );
	}

	/** @param array{int,int,string,array<string,mixed>,string} $ready */
	private function finalize( array $ready ): string {
		list( $id, $identity, $token, $action, $execution ) = $ready;
		return $this->imports->finalize_existing_link( $id, $identity, $token, 1, (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'], $execution );
	}

	private function deliver_callback( int $run = 0 ): void {
		$actions = array_values( array_filter( $this->wpdb->tables['wp_ideaxperts_ea_import_actions'], static fn( array $row ): bool => 0 === $run || (int) $row['import_run_id'] === $run ) );
		$action = $actions[0];
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
	}

	/** @dataProvider differentProductionIdentities */
	public function test_independent_qa_reuses_settled_reservations_and_preserves_history( bool $different ): void {
		$selection = $this->selection( false, '001234567890' );
		$a = $this->approve( $selection, 'qa' );
		$old = $this->ready( $a );
		self::assertSame( 'applied', $this->finalize( $old ) );
		$history = $this->imports->items( $a );
		$events = $this->wpdb->tables['wp_ideaxperts_ea_import_events'];
		$selection['retail_price'] = '11';
		$selection['ea_product_id'] = $different ? 'identity-b' : 'p';
		$b = $this->approve( $selection, 'qa' );
		self::assertSame( 'applied', $this->finalize( $this->ready( $b ) ) );
		$this->assert_preview( $b );
		self::assertSame( 'not_permitted', $this->finalize( $old ) );
		self::assertSame( $history, $this->imports->items( $a ) );
		self::assertSame( $events, array_slice( $this->wpdb->tables['wp_ideaxperts_ea_import_events'], 0, count( $events ) ) );
		self::assertSame( 1, (int) $this->imports->run( $a )['applied_count'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
	}

	/** @dataProvider differentProductionIdentities */
	public function test_active_qa_owner_defers_competitor_until_settlement( bool $different ): void {
		$selection = $this->selection( false, '001234567890' );
		$a = $this->approve( $selection, 'qa' );
		$ready = $this->ready( $a );
		$selection['retail_price'] = '11';
		$selection['ea_product_id'] = $different ? 'identity-b' : 'p';
		$b = $this->approve( $selection, 'qa' );
		$this->deliver_callback( $b );
		self::assertSame( 'retry_wait', $this->imports->items( $b )[0]['status'] );
		self::assertSame( 'preview_reservation_busy', $this->imports->items( $b )[0]['failure_code'] );
		self::assertSame( $ready[0], (int) $this->wpdb->tables['wp_ideaxperts_ea_store_identifier_reservations'][0]['owning_import_item_id'] );
		self::assertSame( 'reserved', $this->wpdb->tables['wp_ideaxperts_ea_store_identifier_reservations'][0]['reservation_status'] );
		self::assertSame( 'applied', $this->finalize( $ready ) );
		$GLOBALS['ea_now'] = '2026-09-28 12:00:31';
		$this->manager->reconcile();
		$this->deliver_callback( $b );
		$this->deliver_callback( $b );
		$this->assert_preview( $b );
	}

	public function test_cancelled_qa_reservations_are_reusable_only_after_authoritative_settlement(): void {
		$selection = $this->selection( false, '001234567890' );
		$a = $this->approve( $selection, 'qa' );
		$ready = $this->ready( $a );
		self::assertTrue( $this->imports->request_cancellation( $a ) );
		self::assertFalse( $this->imports->finalize_cancellation( $a ) );
		self::assertSame( 'reserved', $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0]['reservation_status'] );
		$selection['retail_price'] = '11';
		$b = $this->approve( $selection, 'qa' );
		$this->deliver_callback( $b );
		self::assertSame( 'retry_wait', $this->imports->items( $b )[0]['status'] );
		$GLOBALS['ea_now'] = '2026-09-28 12:05:01';
		$this->manager->reconcile();
		self::assertSame( 'cancelled', $this->imports->run( $a )['status'] );
		self::assertSame( 'released', $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0]['reservation_status'] );
		self::assertSame( 'not_permitted', $this->finalize( $ready ) );
		$this->deliver_callback( $b );
		$this->assert_preview( $b );
	}

	/** @dataProvider previewTerminalStates */
	public function test_preview_release_keeps_ambiguous_recovery_and_production_owners( string $status, bool $release ): void {
		$run = $this->approve( $this->selection( false, '001234567890' ), 'qa' );
		$this->ready( $run );
		$item = $this->imports->items( $run )[0];
		$item['status'] = $status;
		$item['reconciliation_required'] = in_array( $status, array( 'manual_recovery', 'reconciling', 'applying' ), true ) ? 1 : 0;
		$method = new \ReflectionMethod( ImportRepository::class, 'release_preview_reservations' );
		self::assertTrue( $method->invoke( $this->imports, array( $item ) ) );
		self::assertSame( $release ? 'released' : 'reserved', $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0]['reservation_status'] );
		self::assertSame( $release ? 'released' : 'reserved', $this->wpdb->tables['wp_ideaxperts_ea_store_identifier_reservations'][0]['reservation_status'] );
		$before = $this->wpdb->tables;
		$item['environment'] = 'production';
		$item['source_scope'] = 'endless-aisles:production';
		$item['status'] = 'applied';
		$item['reconciliation_required'] = 0;
		self::assertTrue( $method->invoke( $this->imports, array( $item ) ) );
		self::assertSame( $before, $this->wpdb->tables );
	}

	public static function previewTerminalStates(): array {
		return array( array( 'applied', true ), array( 'stale_snapshot', true ), array( 'manual_required', true ), array( 'blocked', true ), array( 'manual_recovery', false ), array( 'applying', false ), array( 'reconciling', false ), array( 'ready', false ), array( 'cancelled', false ) );
	}

	/** @dataProvider boundaryTargets */
	public function test_boundary_identifiers_persist_created_adopted_and_qa_audits( bool $variation, bool $adopt ): void {
		$selection = $this->selection( false, '001234567890' );
		$selection['ea_product_id'] = str_repeat( '😀', 191 );
		$selection['ea_option_id'] = str_repeat( '😀', 191 );
		$selection['wc_product_id'] = $variation ? PHP_INT_MAX - 1 : PHP_INT_MAX;
		$selection['wc_variation_id'] = $variation ? PHP_INT_MAX : 0;
		$target = new ReadOnlyProduct( PHP_INT_MAX, $variation ? 'variation' : 'simple', 'publish', 'Boundary', '', '001234567890', array(), array(), $variation ? PHP_INT_MAX - 1 : 0 );
		$GLOBALS['ea_wc_products'] = array( $target );
		$GLOBALS['ea_wc_product_map'][PHP_INT_MAX] = $target;
		if ( $variation ) {
			$GLOBALS['ea_wc_product_map'][PHP_INT_MAX - 1] = new ReadOnlyProduct( PHP_INT_MAX - 1, 'variable', 'publish', 'Parent', '', '' );
		}
		if ( $adopt ) {
			$this->wpdb->tables['wp_ideaxperts_ea_mappings'][] = array( 'id' => 90, 'source_scope' => 'endless-aisles:production', 'environment' => 'production', 'ea_product_id' => $selection['ea_product_id'], 'ea_option_id' => $selection['ea_option_id'], 'wc_product_id' => $selection['wc_product_id'], 'wc_variation_id' => $selection['wc_variation_id'], 'normalized_upc' => $selection['normalized_upc'], 'mapping_status' => 'active' );
		}
		$a = $this->approve( $selection );
		self::assertSame( 'applied', $this->finalize( $this->ready( $a ) ) );
		$qa = $this->approve( $selection, 'qa' );
		self::assertSame( 'applied', $this->finalize( $this->ready( $qa ) ) );
		$this->assert_preview( $qa );
		$success = array_values( array_filter( $this->wpdb->tables['wp_ideaxperts_ea_import_events'], static fn( array $row ): bool => in_array( $row['event_type'], array( 'existing_link_applied', 'preview_link_succeeded' ), true ) ) );
		self::assertCount( 2, $success );
		self::assertSame( ! $adopt, json_decode( $success[0]['event_data'], true )['mapping_created'] );
		self::assertSame( $adopt, json_decode( $success[0]['event_data'], true )['mapping_adopted'] );
		foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_events'] as $event ) {
			self::assertLessThanOrEqual( 4000, strlen( $event['event_data'] ) );
			self::assertIsArray( json_decode( $event['event_data'], true, 512, JSON_THROW_ON_ERROR ) );
		}
		foreach ( $success as $event ) {
			self::$boundary_sizes[ $event['event_type'] . ( $adopt ? '_adopted' : '_created' ) . ( $variation ? '_variation' : '_simple' ) ] = strlen( $event['event_data'] );
			self::assertSame( $selection['ea_product_id'], $event['ea_product_id'] );
			self::assertSame( $selection['ea_option_id'], $event['ea_option_id'] );
			self::assertSame( $this->imports->items( (int) $event['import_run_id'] )[0]['operation_uuid'], $event['operation_uuid'] );
		}
	}

	public static function boundaryTargets(): array {
		return array( array( false, false ), array( true, false ), array( false, true ), array( true, true ) );
	}

	/** @dataProvider releaseFailureTables */
	public function test_release_failure_rolls_back_success_and_remains_recoverable( string $table ): void {
		$qa = $this->approve( $this->selection( false, '001234567890' ), 'qa' );
		$ready = $this->ready( $qa );
		$before = $this->wpdb->tables;
		$this->wpdb->fail_release_table_contains = $table;
		self::assertSame( 'retry', $this->finalize( $ready ) );
		self::assertSame( $before, $this->wpdb->tables );
		self::assertSame( 'applied', $this->finalize( $ready ) );
		$this->assert_preview( $qa );
	}

	public static function releaseFailureTables(): array {
		return array( array( 'catalog_identities' ), array( 'store_identifier_reservations' ) );
	}

	/** @dataProvider qaTerminalIssues */
	public function test_conclusive_qa_finalizer_outcomes_release_both_reservations( bool $conflict ): void {
		$run = $this->approve( $this->selection( false, '001234567890' ), 'qa' );
		$ready = $this->ready( $run );
		if ( $conflict ) {
			$this->wpdb->tables['wp_ideaxperts_ea_mappings'][] = array( 'id' => 90, 'source_scope' => 'endless-aisles:production', 'environment' => 'production', 'ea_product_id' => 'other', 'ea_option_id' => 'other', 'wc_product_id' => 50, 'wc_variation_id' => 0, 'normalized_upc' => '001234567890', 'mapping_status' => 'active' );
		} else {
			$GLOBALS['ea_wc_product_map'][50] = new ReadOnlyProduct( 50, 'simple', 'publish', 'Target', '', '009876543210' );
		}
		self::assertSame( $conflict ? 'manual_required' : 'stale_snapshot', $this->finalize( $ready ) );
		self::assertSame( 'released', $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0]['reservation_status'] );
		self::assertSame( 'released', $this->wpdb->tables['wp_ideaxperts_ea_store_identifier_reservations'][0]['reservation_status'] );
		self::assertSame( 'not_permitted', $this->finalize( $ready ) );
		self::assertSame( 0, (int) $this->imports->run( $run )['applied_count'] );
		self::assertSame( 'completed', $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['status'] );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
	}

	public static function qaTerminalIssues(): array {
		return array( array( false ), array( true ) );
	}

	/** @dataProvider previewLinks */
	public function test_qa_preview_then_production_link( string $classification, bool $variation ): void {
		$selection = $this->selection( $variation, '001234567890' );
		$selection['classification'] = $classification;
		if ( 'exact_upc_match' === $classification ) {
			$target = new ReadOnlyProduct( $variation ? 51 : 50, $variation ? 'variation' : 'simple', 'publish', 'Target', '001234567890', '', array(), array(), $variation ? 50 : 0 );
			$GLOBALS['ea_wc_product_map'][ $variation ? 51 : 50 ] = $target;
			$GLOBALS['ea_wc_products'] = array( $target );
		}
		$qa = $this->approve( $selection, 'qa' );
		$ready = $this->ready( $qa );
		self::assertSame( 'applied', $this->finalize( $ready ) );
		self::assertSame( 'not_permitted', $this->finalize( $ready ) );
		$this->assert_preview( $qa );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		$dry_runs = new \IdeaXperts\EndlessAisles\Database\DryRunRepository();
		self::assertSame( array(), $dry_runs->mappings( 'p', 'o', 'endless-aisles:production' ) );
		self::assertSame( array(), $dry_runs->mappings( 'p', 'o', 'endless-aisles:qa' ) );
		$classifier = new \IdeaXperts\EndlessAisles\Catalog\MatchClassifier();
		$option = array( 'ea_product_id' => 'p', 'id' => 'o', 'upc' => '001234567890', 'retail_price' => '10' );
		$matches = array( array( 'wc_product_id' => 50, 'wc_variation_id' => $variation ? 51 : 0 ) );
		foreach ( array( 'qa', 'production' ) as $environment ) {
			$match = $classifier->classify( $option, $dry_runs->mappings( 'p', 'o', 'endless-aisles:' . $environment ), 'exact_upc_match' === $classification ? $matches : array(), 'exact_sku_match' === $classification ? $matches : array(), true, false );
			self::assertSame( $classification, $match['classification'] );
		}
		self::assertFalse( $this->imports->acquire_final_write_permit( $ready[0], $ready[1], $ready[2], 1, (int) $ready[3]['id'], (string) $ready[3]['logical_key'], (int) $ready[3]['dispatch_generation'], $ready[4] ) );
		$production = $this->approve( $selection );
		$prod_ready = $this->ready( $production );
		$wrong_identity = $prod_ready;
		$wrong_identity[1] = $ready[1];
		self::assertSame( 'not_permitted', $this->finalize( $wrong_identity ) );
		self::assertSame( 'applied', $this->finalize( $prod_ready ) );
		self::assertSame( 'not_permitted', $this->finalize( $prod_ready ) );
		self::assertCount( 1, $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( 'production', $this->wpdb->tables['wp_ideaxperts_ea_mappings'][0]['environment'] );
		self::assertSame( hash( 'sha256', '50:' . ( $variation ? '51' : '0' ) ), $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][1]['wc_identity_key'] );
		self::assertSame( 1, (int) $this->imports->run( $production )['applied_count'] );
		self::assertCount( 1, $dry_runs->mappings( 'p', 'o', 'endless-aisles:production' ) );
		$match = $classifier->classify( $option, $dry_runs->mappings( 'p', 'o', 'endless-aisles:production' ), array(), array(), true, false );
		self::assertSame( 'already_linked', $match['classification'] );
		$this->assert_preview( $qa );
	}

	/** @return list<array{string,bool}> */
	public static function previewLinks(): array {
		return array( array( 'exact_upc_match', false ), array( 'exact_sku_match', false ), array( 'exact_upc_match', true ), array( 'exact_sku_match', true ) );
	}

	private function assert_preview( int $run ): void {
		$item = $this->imports->items( $run )[0];
		self::assertSame( 'applied', $item['status'] );
		self::assertSame( 1, (int) $this->imports->run( $run )['applied_count'] );
		$identities = array_values( array_filter( $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'], static fn( array $row ): bool => (int) $row['owning_import_item_id'] === (int) $item['id'] ) );
		self::assertCount( 1, $identities );
		self::assertSame( 'preview_only', $identities[0]['ownership_mode'] );
		self::assertSame( 'released', $identities[0]['reservation_status'] );
		self::assertNull( $identities[0]['wc_identity_key'] );
		self::assertNull( $identities[0]['wc_product_id'] );
		self::assertNull( $identities[0]['wc_variation_id'] );
		$events = array_values( array_filter( $this->wpdb->tables['wp_ideaxperts_ea_import_events'], static fn( array $row ): bool => (int) $row['import_run_id'] === $run && 'preview_link_succeeded' === $row['event_type'] ) );
		self::assertCount( 1, $events );
		$audit = json_decode( (string) $events[0]['event_data'], true );
		self::assertSame( 'qa', $audit['environment'] );
		self::assertSame( 'endless-aisles:qa', $audit['source_scope'] );
		self::assertTrue( $audit['preview_only'] );
		self::assertFalse( $audit['mapping_created'] );
		self::assertArrayNotHasKey( 'final_mapping', $audit );
		self::assertSame( (int) $item['target_wc_product_id'], $audit['preview_target']['wc_product_id'] );
		self::assertSame( $item['operation_uuid'], $events[0]['operation_uuid'] );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
	}

	public function test_production_first_qa_observes_mapping_without_mutating_ownership(): void {
		$selection = $this->selection( false, '001234567890' );
		$production = $this->approve( $selection );
		self::assertSame( 'applied', $this->finalize( $this->ready( $production ) ) );
		$mappings = $this->wpdb->tables['wp_ideaxperts_ea_mappings'];
		$identity = $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0];
		$upc = $this->wpdb->tables['wp_ideaxperts_ea_store_identifier_reservations'][0];
		$qa = $this->approve( $selection, 'qa' );
		self::assertSame( 'applied', $this->finalize( $this->ready( $qa ) ) );
		$this->assert_preview( $qa );
		self::assertSame( $mappings, $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( $identity, $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0] );
		self::assertSame( $upc, $this->wpdb->tables['wp_ideaxperts_ea_store_identifier_reservations'][0] );
	}

	/** @dataProvider differentProductionIdentities */
	public function test_preview_does_not_bind_production_identity_or_target( bool $different_identity ): void {
		$selection = $this->selection( false, '001234567890' );
		$qa = $this->approve( $selection, 'qa' );
		self::assertSame( 'applied', $this->finalize( $this->ready( $qa ) ) );
		if ( $different_identity ) {
			$selection['ea_product_id'] = 'identity-b';
		} else {
			$selection['wc_product_id'] = 60;
			$target = new ReadOnlyProduct( 60, 'simple', 'publish', 'New target', '', '001234567890' );
			$GLOBALS['ea_wc_products'] = array( $target );
			$GLOBALS['ea_wc_product_map'][60] = $target;
			$GLOBALS['ea_wc_product_map'][50] = new ReadOnlyProduct( 50, 'simple', 'publish', 'Old target', '', '' );
		}
		$production = $this->approve( $selection );
		self::assertSame( 'applied', $this->finalize( $this->ready( $production ) ) );
		self::assertCount( 1, $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( $selection['wc_product_id'], $this->wpdb->tables['wp_ideaxperts_ea_mappings'][0]['wc_product_id'] );
		self::assertSame( $selection['ea_product_id'], $this->wpdb->tables['wp_ideaxperts_ea_mappings'][0]['ea_product_id'] );
		$this->assert_preview( $qa );
	}

	/** @return list<array{bool}> */
	public static function differentProductionIdentities(): array {
		return array( array( true ), array( false ) );
	}

	public function test_qa_cancellation_before_finalization_cannot_claim_ownership(): void {
		$qa = $this->approve( $this->selection( false, '001234567890' ), 'qa' );
		$ready = $this->ready( $qa );
		self::assertTrue( $this->imports->request_cancellation( $qa ) );
		self::assertSame( 'not_permitted', $this->finalize( $ready ) );
		$GLOBALS['ea_now'] = '2026-09-28 12:05:01';
		$this->manager->reconcile();
		self::assertSame( 'cancelled', $this->imports->items( $qa )[0]['status'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertNull( $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0]['wc_identity_key'] );
		self::assertSame( 0, (int) $this->imports->run( $qa )['applied_count'] );
	}

	/** @dataProvider environmentTampering */
	public function test_finalization_rejects_environment_switches( string $environment, string $table ): void {
		$run = $this->approve( $this->selection( false, '001234567890' ), $environment );
		$ready = $this->ready( $run );
		$other = 'qa' === $environment ? 'production' : 'qa';
		$this->wpdb->tables[ 'wp_ideaxperts_ea_' . $table ][0]['environment'] = $other;
		$this->wpdb->tables[ 'wp_ideaxperts_ea_' . $table ][0]['source_scope'] = 'endless-aisles:' . $other;
		self::assertSame( 'not_permitted', $this->finalize( $ready ) );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertNull( $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0]['wc_identity_key'] );
	}

	/** @return list<array{string,string}> */
	public static function environmentTampering(): array {
		$cases = array();
		foreach ( array( 'qa', 'production' ) as $environment ) {
			foreach ( array( 'import_runs', 'import_items', 'import_actions', 'catalog_identities' ) as $table ) {
				$cases[] = array( $environment, $table );
			}
		}
		return $cases;
	}

	/** @dataProvider previewFailureStages */
	public function test_qa_finalization_failure_rolls_back_and_retry_stays_preview_only( string $stage ): void {
		$qa = $this->approve( $this->selection( false, '001234567890' ), 'qa' );
		$ready = $this->ready( $qa );
		$before = $this->wpdb->tables;
		if ( 'event' === $stage ) {
			$this->wpdb->fail_import_event_type = 'preview_link_succeeded';
		} elseif ( 'commit' === $stage ) {
			$this->wpdb->fail_query_contains = 'COMMIT';
		} else {
			$this->wpdb->fail_update_table_contains = $stage;
		}
		self::assertSame( 'retry', $this->finalize( $ready ) );
		self::assertSame( $before, $this->wpdb->tables );
		$this->wpdb->fail_query_contains = '';
		self::assertSame( 'applied', $this->finalize( $ready ) );
		self::assertSame( 'not_permitted', $this->finalize( $ready ) );
		$this->assert_preview( $qa );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
	}

	/** @return list<array{string}> */
	public static function previewFailureStages(): array {
		return array( array( 'catalog_identities' ), array( 'import_items' ), array( 'import_actions' ), array( 'import_runs' ), array( 'event' ), array( 'commit' ) );
	}

	/** @dataProvider authoritativeConflictEnvironments */
	public function test_real_production_mapping_conflict_blocks_without_mutation( string $environment ): void {
		$selection = $this->selection( false, '001234567890' );
		$production = $this->approve( $selection );
		self::assertSame( 'applied', $this->finalize( $this->ready( $production ) ) );
		$mappings = $this->wpdb->tables['wp_ideaxperts_ea_mappings'];
		$owner = $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0];
		$selection['ea_product_id'] = 'different-vendor';
		$run = $this->approve( $selection, $environment );
		if ( 'qa' === $environment ) {
			self::assertSame( 'manual_required', $this->finalize( $this->ready( $run ) ) );
			self::assertSame( 'mapping_conflict', $this->imports->items( $run )[0]['failure_code'] );
		} else {
			$item = $this->imports->items( $run )[0];
			$token = $this->imports->claim_item( (int) $item['id'] );
			self::assertTrue( $this->imports->begin_validation( (int) $item['id'], $token ) );
			$identity = $this->imports->reserve_catalog_identity( (int) $item['id'], $token );
			self::assertGreaterThan( 0, $identity );
			// Production ownership rejects the different identity even before finalization.
			self::assertSame( 0, $this->imports->reserve_upc( $identity, (int) $item['id'], $token ) );
			self::assertSame( 0, (int) $this->imports->run( $run )['applied_count'] );
		}
		self::assertSame( $mappings, $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( $owner, $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0] );
		self::assertNull( $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][1]['wc_identity_key'] );
	}

	/** @return list<array{string}> */
	public static function authoritativeConflictEnvironments(): array {
		return array( array( 'qa' ), array( 'production' ) );
	}

	/** @dataProvider normalizedSkus */
	public function test_formatted_approved_sku_links_unchanged_and_preserves_raw_evidence( string $sku, bool $variation ): void {
		$run = $this->approve( $this->selection( $variation, $sku ) );
		$item = $this->imports->items( $run )[0];
		$state = $this->provider->inspect( $item, 'endless-aisles:production', 'production' );
		self::assertSame( $sku, $state['target']['sku'] );
		self::assertSame( '001234567890', $state['target']['normalized_sku'] );
		self::assertSame( '001234567890', $item['vendor_sku'] );
		$this->deliver_callback();
		$this->deliver_callback();
		self::assertSame( 'applied', $this->imports->items( $run )[0]['status'] );
		self::assertSame( 1, (int) $this->imports->run( $run )['applied_count'] );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
	}

	/** @return array<string,array{string,bool}> */
	public static function normalizedSkus(): array {
		$cases = array();
		foreach ( array( '001234567890', '001-234-567-890', ' 001 234 567 890 ', "001\t234-567 890" ) as $sku ) {
			foreach ( array( false, true ) as $variation ) {
				$cases[ $sku . ( $variation ? ' variation' : ' simple' ) ] = array( $sku, $variation );
			}
		}
		return $cases;
	}

	/** @dataProvider duplicateSkus */
	public function test_bugbot_duplicate_introduced_after_ready_blocks_finalization( bool $target_variation, bool $owner_variation, string $duplicate ): void {
		$run = $this->approve( $this->selection( $target_variation, '001234567890' ) );
		$ready = $this->ready( $run );
		$owner = new ReadOnlyProduct( 88, $owner_variation ? 'variation' : 'simple', 'publish', 'Duplicate', '', $duplicate, array(), array(), $owner_variation ? 80 : 0 );
		$GLOBALS['ea_wc_pages'] = array( 1 => $GLOBALS['ea_wc_products'], 2 => array( $owner ) );
		self::assertSame( 'stale_snapshot', $this->finalize( $ready ) );
		self::assertSame( 'stale_snapshot', $this->imports->items( $run )[0]['status'] );
		self::assertSame( 'completed', $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['status'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
	}

	/** @return list<array{bool,bool,string}> */
	public static function duplicateSkus(): array {
		$cases = array();
		foreach ( array( false, true ) as $target ) {
			foreach ( array( false, true ) as $owner ) {
				foreach ( array( '001234567890', '001-234-567-890', ' 001 234 567 890 ' ) as $sku ) {
					$cases[] = array( $target, $owner, $sku );
				}
			}
		}
		return $cases;
	}

	public function test_sku_changes_to_different_normalized_identifier_after_ready(): void {
		$run = $this->approve( $this->selection( false, '001-234-567-890' ) );
		$ready = $this->ready( $run );
		$this->selection( false, '009-999-999-999' );
		self::assertSame( 'stale_snapshot', $this->finalize( $ready ) );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
	}

	public function test_leading_zeroes_are_significant_and_parent_reads_do_not_load_siblings(): void {
		$run = $this->approve( $this->selection( true, '001234567890' ) );
		$ready = $this->ready( $run );
		$GLOBALS['ea_wc_products'][] = new ReadOnlyProduct( 99, 'simple', 'publish', 'Different identifier', '', '1234567890' );
		$GLOBALS['ea_wc_reads'] = array();
		self::assertSame( 'applied', $this->finalize( $ready ) );
		$direct = array_values( array_filter( $GLOBALS['ea_wc_reads'], static fn( array $read ): bool => 'wc_get_product' === $read[0] ) );
		self::assertSame( array( array( 'wc_get_product', 51 ), array( 'wc_get_product', 50 ) ), $direct );
	}

	public function test_finalization_lock_acquisitions_follow_canonical_order(): void {
		$run = $this->approve( $this->selection( false, '001234567890' ) );
		$ready = $this->ready( $run );
		$this->wpdb->queries = array();
		self::assertSame( 'applied', $this->finalize( $ready ) );
		$locks = array_values( array_filter( $this->wpdb->queries, static fn( string $sql ): bool => str_contains( $sql, 'FOR UPDATE' ) ) );
		$expected = array( 'import_runs', 'import_actions', 'import_items', 'catalog_identities', 'catalog_identities', 'store_identifier_reservations', 'mappings' );
		self::assertCount( count( $expected ), $locks );
		foreach ( $expected as $index => $table ) {
			self::assertStringContainsString( 'ideaxperts_ea_' . $table, $locks[ $index ] );
		}
	}

	/** @dataProvider invalidParents */
	public function test_bugbot_parent_invalidated_after_ready_cannot_link( string $mutation ): void {
		$run = $this->approve( $this->selection( true, '001234567890' ) );
		$ready = $this->ready( $run );
		$this->mutate_parent( $mutation );
		self::assertSame( 'stale_snapshot', $this->finalize( $ready ) );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
	}

	/** @dataProvider invalidParents */
	public function test_invalid_parent_cannot_be_approved( string $mutation ): void {
		$selection = $this->selection( true, '001234567890' );
		$this->mutate_parent( $mutation );
		$this->expectException( \RuntimeException::class );
		$this->approve( $selection );
	}

	/** @return list<array{string}> */
	public static function invalidParents(): array {
		return array( array( 'deleted' ), array( 'missing' ), array( 'trash' ), array( 'simple' ), array( 'grouped' ), array( 'changed_id' ), array( 'wrong_object' ) );
	}

	private function mutate_parent( string $mutation ): void {
		if ( in_array( $mutation, array( 'deleted', 'missing' ), true ) ) {
			unset( $GLOBALS['ea_wc_product_map'][50] );
		} elseif ( 'changed_id' === $mutation ) {
			$GLOBALS['ea_wc_product_map'][51] = new ReadOnlyProduct( 51, 'variation', 'publish', 'Target', '', '001234567890', array(), array(), 99 );
		} else {
			$GLOBALS['ea_wc_product_map'][50] = new ReadOnlyProduct( 'wrong_object' === $mutation ? 99 : 50, in_array( $mutation, array( 'simple', 'grouped' ), true ) ? $mutation : 'variable', 'trash' === $mutation ? 'trash' : 'publish', 'Parent', '', '' );
		}
	}

	/** @dataProvider leaseOrders */
	public function test_ready_crash_recovers_with_fenced_duplicate_callbacks( string $order, string $environment ): void {
		$run = $this->approve( $this->selection( false, '001234567890' ), $environment );
		$ready = $this->ready( $run );
		$this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['lease_expires_at'] = 'item_first' === $order ? '2026-09-28 12:05:00' : '2026-09-28 12:00:01';
		$this->wpdb->tables['wp_ideaxperts_ea_import_items'][0]['lease_expires_at'] = 'action_first' === $order ? '2026-09-28 12:05:00' : '2026-09-28 12:00:01';
		$GLOBALS['ea_now'] = '2026-09-28 12:00:02';
		$this->manager->reconcile();
		if ( 'qa' === $environment ) {
			self::assertSame( 'reserved', $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0]['reservation_status'] );
			self::assertSame( 'reserved', $this->wpdb->tables['wp_ideaxperts_ea_store_identifier_reservations'][0]['reservation_status'] );
		}
		$this->deliver_callback();
		$this->deliver_callback();
		if ( 'both' !== $order ) {
			self::assertNotSame( 'completed', $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['status'] );
			self::assertSame( 'action_first' === $order ? 'ready' : 'retry_wait', $this->imports->items( $run )[0]['status'] );
		}
		if ( 'action_first' === $order ) {
			self::assertSame( 'retry_wait', $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['status'] );
			self::assertSame( '2026-09-28 12:05:00', $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['available_at'] );
			$this->manager->reconcile();
			self::assertSame( 'retry_wait', $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['status'] );
		}
		$GLOBALS['ea_now'] = '2026-09-28 12:05:01';
		$this->manager->reconcile();
		$old = $ready[3];
		$this->manager->validate_item( (int) $old['id'], (string) $old['logical_key'], (int) $old['dispatch_generation'] );
		self::assertSame( 'not_permitted', $this->finalize( $ready ) );
		$this->deliver_callback();
		$this->deliver_callback();
		self::assertSame( 'applied', $this->imports->items( $run )[0]['status'] );
		self::assertSame( 'completed', $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['status'] );
		self::assertCount( 'qa' === $environment ? 0 : 1, $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( 1, (int) $this->imports->run( $run )['applied_count'] );
		if ( 'qa' === $environment ) {
			$this->assert_preview( $run );
		}
	}

	/** @return list<array{string,string}> */
	public static function leaseOrders(): array {
		return array( array( 'action_first', 'production' ), array( 'item_first', 'production' ), array( 'both', 'production' ), array( 'action_first', 'qa' ), array( 'item_first', 'qa' ), array( 'both', 'qa' ) );
	}

	/** @dataProvider interruptedRecovery */
	public function test_cancellation_or_mapping_drift_while_waiting_fails_closed( bool $cancel ): void {
		$run = $this->approve( $this->selection( false, '001234567890' ) );
		$this->ready( $run );
		$this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['lease_expires_at'] = '2026-09-28 12:00:01';
		$GLOBALS['ea_now'] = '2026-09-28 12:00:02';
		$this->manager->reconcile();
		$this->deliver_callback();
		self::assertSame( 'retry_wait', $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['status'] );
		if ( $cancel ) {
			self::assertTrue( $this->imports->request_cancellation( $run ) );
		} else {
			$this->wpdb->tables['wp_ideaxperts_ea_mappings'][] = array( 'id' => 90, 'source_scope' => 'endless-aisles:production', 'environment' => 'production', 'ea_product_id' => 'other', 'ea_option_id' => 'other', 'wc_product_id' => 50, 'wc_variation_id' => 0, 'normalized_upc' => '001234567890', 'mapping_status' => 'active' );
		}
		$GLOBALS['ea_now'] = '2026-09-28 12:05:01';
		$this->manager->reconcile();
		$this->deliver_callback();
		self::assertSame( $cancel ? 'cancelled' : 'stale_snapshot', $this->imports->items( $run )[0]['status'] );
		self::assertSame( $cancel ? 'cancelled' : 'completed', $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['status'] );
		self::assertSame( 0, (int) $this->imports->run( $run )['applied_count'] );
	}

	/** @return list<array{bool}> */
	public static function interruptedRecovery(): array {
		return array( array( true ), array( false ) );
	}
}
