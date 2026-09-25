<?php
namespace IdeaXperts\EndlessAisles\Tests\Catalog;

use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\API\CatalogService;
use IdeaXperts\EndlessAisles\Catalog\DryRunManager;
use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;
use IdeaXperts\EndlessAisles\Catalog\StoreCatalogScanner;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Tests\API\SequenceClient;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use IdeaXperts\EndlessAisles\Tests\Support\ReadOnlyProduct;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DryRunManagerTest extends TestCase {
	private DryRunMemoryWpdb $wpdb;
	private DryRunRepository $runs;
	private SettingsRepository $settings;

	protected function setUp(): void {
		$this->wpdb                 = new DryRunMemoryWpdb();
		$GLOBALS['wpdb']            = $this->wpdb;
		$GLOBALS['ea_action_queue'] = array();
		$GLOBALS['ea_now']          = '2026-09-24 12:00:00';
		$GLOBALS['ea_wc_products']  = array();
		$GLOBALS['ea_wc_writes']    = array();
		$GLOBALS['ea_wc_max_pages'] = 1;
		$GLOBALS['ea_test_options'] = array(
			'ideaxperts_ea_settings'            => array(
				'enabled'             => 'yes',
				'environment'         => 'qa',
				'log_level'           => 'critical',
				'allow_sku_upc_match'  => 'no',
				'use_global_unique_id' => 'yes',
				'upc_meta_keys'        => array(),
			),
		);
		$this->settings = new SettingsRepository();
		$this->settings->replace_token( 'qa', 'secret' );
		$GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] = array( 'status' => 'connected' );
		$this->runs     = new DryRunRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_start_claims_one_active_run_and_schedules_store_page_one(): void {
		$run_id = $this->manager()->start( 9 );
		self::assertSame( 1, $run_id );
		self::assertSame( 'scanning_store', $this->runs->run( $run_id )['status'] );
		self::assertSame(
			array( DryRunManager::STORE_HOOK, array( 1, 1 ), DryRunManager::GROUP ),
			array(
				$GLOBALS['ea_action_queue'][0]['hook'],
				$GLOBALS['ea_action_queue'][0]['args'],
				$GLOBALS['ea_action_queue'][0]['group'],
			)
		);
		$this->expectException( RuntimeException::class );
		$this->manager()->start( 10 );
	}

	public function test_cancel_is_terminal_and_drops_only_that_run_from_action_scheduler(): void {
		$run_id = $this->manager()->start( 1 );
		$GLOBALS['ea_action_queue'][] = array(
			'hook'  => DryRunManager::CATALOG_HOOK,
			'args'  => array( 99, 3 ),
			'group' => DryRunManager::GROUP,
		);
		$this->manager()->cancel( $run_id );
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		self::assertSame( 99, $GLOBALS['ea_action_queue'][0]['args'][0] );
		$this->manager()->store_batch( $run_id, 1 );
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
	}

	public function test_store_batch_recomputes_counts_and_does_not_resurrect_a_cancelled_run(): void {
		$run_id = $this->manager()->start( 1 );
		$product = new ReadOnlyProduct( 5, 'simple', 'publish', 'Widget', '001234567890', 'SKU-1' );
		$GLOBALS['ea_wc_products'] = array( $product );
		$this->manager()->store_batch( $run_id, 1 );
		$first = $this->runs->run( $run_id );
		self::assertSame( 1, (int) $first['products_inspected'] );
		$this->runs->transition( $run_id, array( 'fetching_catalog' ), array( 'status' => 'scanning_store', 'current_store_page' => 0 ) );
		$this->manager()->store_batch( $run_id, 1 );
		self::assertSame( 1, (int) $this->runs->run( $run_id )['products_inspected'] );
		$this->manager()->cancel( $run_id );
		$this->manager()->store_batch( $run_id, 2 );
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
	}

	public function test_catalog_page_keeps_already_linked_when_a_later_vendor_upc_duplicates(): void {
		$run_id = $this->runs->claim_new( 1 );
		$this->runs->transition( $run_id, array( 'pending' ), array( 'status' => 'fetching_catalog', 'resume_cursor' => 'catalog:1' ) );
		$this->wpdb->insert(
			'wp_ideaxperts_ea_mappings',
			array(
				'ea_product_id'   => 'product-1',
				'ea_option_id'    => 'option-1',
				'mapping_status'  => 'active',
				'wc_product_id'   => 7,
				'wc_variation_id' => 8,
			)
		);
		$client = new SequenceClient(
			array(
				array(
					'current_page'  => 1,
					'per_page'      => 10,
					'next_page_url' => '/api/products?page=2&per_page=10',
					'data'          => array(
						(object) array(
							'id'    => 'product-1',
							'title' => 'One',
							'sizes' => array(
								(object) array(
									'id'             => 'option-1',
									'upc'            => '001234567890',
									'price'          => 10,
									'wholesale'      => 5,
									'purchasability' => true,
								),
							),
						),
					),
				),
				array(
					'current_page'  => 2,
					'per_page'      => 10,
					'next_page_url' => null,
					'data'          => array(
						(object) array(
							'id'    => 'product-2',
							'title' => 'Two',
							'sizes' => array(
								(object) array(
									'id'             => 'option-2',
									'upc'            => '001234567890',
									'price'          => 11,
									'wholesale'      => 6,
									'purchasability' => true,
								),
							),
						),
					),
				),
			)
		);
		$manager = $this->manager( $client );
		$manager->catalog_page( $run_id, 1 );
		$manager->catalog_page( $run_id, 2 );
		$items = $this->runs->items( $run_id );
		self::assertSame( 'already_linked', $items[0]['classification'] );
		self::assertSame( 'new_product_candidate', $items[1]['classification'] );
		self::assertStringContainsString( 'duplicate_vendor_upc', (string) $items[0]['review_flags'] );
		self::assertSame( 'completed', $this->runs->run( $run_id )['status'] );
		self::assertSame( array( array( 'GET', '/api/products?page=1&per_page=10' ), array( 'GET', '/api/products?page=2&per_page=10' ) ), $client->requests );
	}

	public function test_cancellation_between_heartbeat_and_child_write_blocks_identifiers_and_items(): void {
		$run_id = $this->manager()->start( 1 );
		self::assertTrue( $this->runs->heartbeat( $run_id ) );
		$this->manager()->cancel( $run_id );
		self::assertFalse(
			$this->runs->store_identifier(
				$run_id,
				array(
					'wc_product_id'         => 1,
					'identifier_type'       => 'upc',
					'identifier_source'     => 'global_unique_id',
					'normalized_identifier' => '001',
				)
			)
		);
		self::assertFalse( $this->runs->item( $run_id, array( 'ea_product_id' => '1', 'ea_option_id' => '1' ) ) );
		$queue_before = count( $GLOBALS['ea_action_queue'] );
		$this->manager()->store_batch( $run_id, 1 );
		$this->manager()->catalog_page( $run_id, 1 );
		self::assertSame( $queue_before, count( $GLOBALS['ea_action_queue'] ) );
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
	}

	public function test_stale_recovery_fails_the_run_unschedules_owned_jobs_and_blocks_later_writes(): void {
		$run_id = $this->manager()->start( 1 );
		$this->runs->transition( $run_id, array( 'scanning_store' ), array( 'last_heartbeat_at' => '2026-09-24 05:59:59' ) );
		$GLOBALS['ea_action_queue'][] = array(
			'id'     => 88,
			'status' => 'pending',
			'hook'   => DryRunManager::STORE_HOOK,
			'args'   => array( $run_id, 2 ),
			'group'  => DryRunManager::GROUP,
		);
		$GLOBALS['ea_action_queue'][] = array(
			'id'     => 89,
			'status' => 'pending',
			'hook'   => DryRunManager::STORE_HOOK,
			'args'   => array( 99, 1 ),
			'group'  => DryRunManager::GROUP,
		);
		self::assertSame( $run_id, $this->manager()->recover_stale() );
		self::assertSame( 'failed', $this->runs->run( $run_id )['status'] );
		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		self::assertSame( 99, $GLOBALS['ea_action_queue'][0]['args'][0] );
		self::assertFalse( $this->runs->item( $run_id, array( 'ea_product_id' => '1', 'ea_option_id' => '1' ) ) );
		$before = count( $GLOBALS['ea_action_queue'] );
		$this->manager()->store_batch( $run_id, 2 );
		self::assertSame( $before, count( $GLOBALS['ea_action_queue'] ) );
	}

	public function test_deactivation_fails_active_runs_and_unschedules_jobs_with_arguments(): void {
		$run_id    = $this->manager()->start( 1 );
		$completed = $this->runs->create( 2, 'qa' );
		$this->runs->transition( $completed, array( 'pending' ), array( 'status' => 'completed', 'completed_at' => current_time( 'mysql', true ) ) );
		$GLOBALS['ea_action_queue'][] = array(
			'id'     => 70,
			'status' => 'pending',
			'hook'   => DryRunManager::CATALOG_HOOK,
			'args'   => array( $run_id, 4 ),
			'group'  => DryRunManager::GROUP,
		);
		$this->manager()->handle_deactivation();
		self::assertSame( 'failed', $this->runs->run( $run_id )['status'] );
		self::assertSame( 'completed', $this->runs->run( $completed )['status'] );
		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
		self::assertGreaterThan( 0, $this->runs->claim_new( 3 ) );
	}

	public function test_purge_schedules_a_unique_continuation_when_expired_rows_remain(): void {
		for ( $index = 0; $index < 27; ++$index ) {
			$id = $this->runs->create( 1, 'qa' );
			$this->runs->transition( $id, array( 'pending' ), array( 'status' => 'completed', 'completed_at' => '2026-01-01 00:00:00' ) );
		}
		$latest = $this->runs->create( 1, 'qa' );
		$this->runs->transition( $latest, array( 'pending' ), array( 'status' => 'completed', 'completed_at' => '2026-09-01 00:00:00' ) );
		$GLOBALS['ea_action_queue'] = array();
		self::assertSame( DryRunRepository::PURGE_BATCH_SIZE, $this->manager()->purge_expired() );
		self::assertSame( DryRunManager::PURGE_HOOK, $GLOBALS['ea_action_queue'][0]['hook'] );
		self::assertSame( array(), $GLOBALS['ea_action_queue'][0]['args'] );
	}

	private function manager( ?SequenceClient $client = null ): DryRunManager {
		$client  = $client ?? new SequenceClient( array() );
		$catalog = new CatalogService( $this->settings, new BaseUrlResolver(), new DatabaseLogger(), static fn(): SequenceClient => $client );
		return new DryRunManager(
			$this->settings,
			$this->runs,
			new StoreCatalogScanner( $this->settings, $this->runs ),
			$catalog,
			new DatabaseLogger(),
			new MatchClassifier()
		);
	}
}
