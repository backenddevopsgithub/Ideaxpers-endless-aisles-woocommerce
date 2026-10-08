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

	public function test_same_second_store_heartbeat_persists_first_page_and_dispatches_next_action(): void {
		$GLOBALS['ea_now'] = '2026-10-08 14:43:34';
		$GLOBALS['ea_wc_max_pages'] = 7;
		$GLOBALS['ea_wc_total'] = 313;
		$GLOBALS['ea_wc_pages'] = array();
		for ( $i = 1; $i <= 50; ++$i ) {
			$GLOBALS['ea_wc_products'][] = new ReadOnlyProduct( $i, 'simple', 'publish', 'Widget', sprintf( '%012d', $i ), '' );
		}
		try {
			$manager = $this->manager();
			$id = $manager->start( 9 );
			$before = $this->runs->run( $id );
			self::assertSame( 'scanning_store', $before['status'] );
			self::assertSame( $GLOBALS['ea_now'], $before['last_heartbeat_at'] );
			self::assertSame( $GLOBALS['ea_now'], $before['updated_at'] );
			$this->run_next_action( $manager );
			self::assertSame( array( 0 ), $this->wpdb->heartbeat_update_results );
			self::assertSame( 'wc_get_products', $GLOBALS['ea_wc_reads'][0][0] );
			$run = $this->runs->run( $id );
			self::assertSame( 50, (int) $run['store_products_scanned'] );
			self::assertSame( 313, (int) $run['store_total_products'] );
			self::assertSame( 1, (int) $run['current_store_page'] );
			self::assertCount( 50, $this->wpdb->tables['wp_ideaxperts_ea_store_identifiers'] );
			$intents = $this->wpdb->tables['wp_ideaxperts_ea_dry_run_actions'];
			self::assertCount( 2, $intents );
			self::assertNotEmpty( $intents[0]['execution_token'] );
			self::assertSame( 'completed', $intents[0]['status'] );
			self::assertSame( 2, (int) $intents[1]['page_number'] );
			self::assertCount( 1, $GLOBALS['ea_action_queue'] );
			self::assertSame( DryRunManager::STORE_HOOK, $GLOBALS['ea_action_queue'][0]['hook'] );
			self::assertSame( 2, $GLOBALS['ea_action_queue'][0]['args'][6] );
		} finally {
			unset( $GLOBALS['ea_wc_total'] );
		}
	}

	protected function setUp(): void {
		$this->wpdb                       = new DryRunMemoryWpdb();
		$GLOBALS['wpdb']                  = $this->wpdb;
		$GLOBALS['ea_action_queue']       = array();
		$GLOBALS['ea_now']                = '2026-09-24 12:00:00';
		$GLOBALS['ea_wc_products']        = array();
		$GLOBALS['ea_wc_reads']           = array();
		$GLOBALS['ea_wc_writes']          = array();
		$GLOBALS['ea_wc_max_pages']       = 1;
		$GLOBALS['ea_enqueue_failure']    = false;
		$GLOBALS['ea_after_enqueue']      = null;
		$GLOBALS['ea_unschedule_failure'] = false;
		$GLOBALS['ea_add_option_failure'] = false;
		$GLOBALS['ea_test_options']       = array(
			'ideaxperts_ea_settings' => array(
				'enabled'              => 'yes',
				'environment'          => 'qa',
				'log_level'            => 'critical',
				'allow_sku_upc_match'  => 'no',
				'use_global_unique_id' => 'yes',
				'upc_meta_keys'        => array(),
			),
		);
		$this->settings                   = new SettingsRepository();
		$this->settings->replace_token( 'qa', 'secret' );
		$GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] = array( 'status' => 'connected' );
		$this->runs = new DryRunRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_start_claims_one_active_run_and_schedules_store_page_one(): void {
		$run_id = $this->manager()->start( 9 );
		self::assertSame( 1, $run_id );
		self::assertSame( 'scanning_store', $this->runs->run( $run_id )['status'] );
		self::assertSame( DryRunManager::STORE_HOOK, $GLOBALS['ea_action_queue'][0]['hook'] );
		self::assertSame( $run_id, $GLOBALS['ea_action_queue'][0]['args'][2] );
		self::assertSame( $this->runs->claim_token( $run_id ), $GLOBALS['ea_action_queue'][0]['args'][3] );
		self::assertSame( $this->runs->claim_generation( $run_id ), $GLOBALS['ea_action_queue'][0]['args'][4] );
		self::assertSame( DryRunManager::GROUP, $GLOBALS['ea_action_queue'][0]['group'] );
		$this->expectException( RuntimeException::class );
		$this->manager()->start( 10 );
	}

	public function test_callback_can_claim_while_dispatching_and_late_id_recording_preserves_completion(): void {
		$manager                     = $this->manager();
		$GLOBALS['ea_after_enqueue'] = static function ( array $action ) use ( $manager ): void {
			$GLOBALS['ea_after_enqueue'] = null;
			array_pop( $GLOBALS['ea_action_queue'] );
			$manager->store_batch( ...$action['args'] );
		};

		$run_id = $manager->start( 1 );
		$first  = $this->wpdb->tables['wp_ideaxperts_ea_dry_run_actions'][0];
		self::assertSame( 'completed', $first['status'] );
		self::assertGreaterThan( 0, (int) $first['action_scheduler_id'] );
		self::assertSame( 'fetching_catalog', $this->runs->run( $run_id )['status'] );
		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		self::assertSame( DryRunManager::CATALOG_HOOK, $GLOBALS['ea_action_queue'][0]['hook'] );
	}

	public function test_duplicate_callback_performs_no_second_catalog_request(): void {
		$run_id = $this->runs->claim_new( 1 );
		$token  = $this->runs->claim_token( $run_id );
		$gen    = $this->runs->claim_generation( $run_id );
		$this->runs->transition(
			$run_id,
			array( 'pending' ),
			array(
				'status'        => 'fetching_catalog',
				'resume_cursor' => 'catalog:1',
			),
			$token,
			$gen
		);
		$this->runs->create_action_intent( $run_id, $token, $gen, 'catalog', DryRunManager::CATALOG_HOOK, 1 );
		$client  = new SequenceClient(
			array(
				array(
					'current_page'  => 1,
					'per_page'      => 10,
					'next_page_url' => null,
					'data'          => array(),
				),
			)
		);
		$manager = $this->manager( $client );
		$manager->reconcile();
		$action = array_shift( $GLOBALS['ea_action_queue'] );
		$manager->catalog_page( ...$action['args'] );
		$manager->catalog_page( ...$action['args'] );

		self::assertCount( 1, $client->requests );
		self::assertSame( 'completed', $this->runs->run( $run_id )['status'] );
	}

	public function test_duplicate_store_callback_performs_no_second_scan(): void {
		$manager = $this->manager();
		$manager->start( 1 );
		$action = array_shift( $GLOBALS['ea_action_queue'] );
		$manager->store_batch( ...$action['args'] );
		$reads = count( $GLOBALS['ea_wc_reads'] );
		$manager->store_batch( ...$action['args'] );

		self::assertGreaterThan( 0, $reads );
		self::assertSame( $reads, count( $GLOBALS['ea_wc_reads'] ) );
	}

	public function test_cancel_is_terminal_and_drops_only_that_run_from_action_scheduler(): void {
		$run_id                       = $this->manager()->start( 1 );
		$GLOBALS['ea_action_queue'][] = array(
			'hook'  => DryRunManager::CATALOG_HOOK,
			'args'  => array( 99, 3 ),
			'group' => DryRunManager::GROUP,
		);
		$this->manager()->cancel( $run_id, $this->runs->claim_generation( $run_id ) );
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		self::assertSame( 99, $GLOBALS['ea_action_queue'][0]['args'][0] );
		$this->manager()->store_batch( $run_id, 1, $this->runs->claim_token( $run_id ) );
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
	}

	public function test_store_batch_recomputes_counts_and_does_not_resurrect_a_cancelled_run(): void {
		$run_id                    = $this->manager()->start( 1 );
		$product                   = new ReadOnlyProduct( 5, 'simple', 'publish', 'Widget', '001234567890', 'SKU-1' );
		$GLOBALS['ea_wc_products'] = array( $product );
		$this->run_next_action( $this->manager() );
		$first = $this->runs->run( $run_id );
		self::assertSame( 1, (int) $first['products_inspected'] );
		$this->runs->transition(
			$run_id,
			array( 'fetching_catalog' ),
			array(
				'status'             => 'scanning_store',
				'current_store_page' => 0,
			),
			$this->runs->claim_token( $run_id )
		);
		$this->manager()->store_batch( $run_id, 1, $this->runs->claim_token( $run_id ) );
		self::assertSame( 1, (int) $this->runs->run( $run_id )['products_inspected'] );
		$this->manager()->cancel( $run_id, $this->runs->claim_generation( $run_id ) );
		$this->manager()->store_batch( $run_id, 2, $this->runs->claim_token( $run_id ) );
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
	}

	public function test_catalog_page_keeps_already_linked_when_a_later_vendor_upc_duplicates(): void {
		$run_id = $this->runs->claim_new( 1 );
		$this->runs->transition(
			$run_id,
			array( 'pending' ),
			array(
				'status'        => 'fetching_catalog',
				'resume_cursor' => 'catalog:1',
			),
			$this->runs->claim_token( $run_id )
		);
		$this->wpdb->insert(
			'wp_ideaxperts_ea_mappings',
			array(
				'source_scope'    => 'endless-aisles:qa',
				'ea_product_id'   => 'product-1',
				'ea_option_id'    => 'option-1',
				'mapping_status'  => 'active',
				'wc_product_id'   => 7,
				'wc_variation_id' => 8,
			)
		);
		$client  = new SequenceClient(
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
		$this->runs->create_action_intent( $run_id, $this->runs->claim_token( $run_id ), $this->runs->claim_generation( $run_id ), 'catalog', DryRunManager::CATALOG_HOOK, 1 );
		$manager->reconcile();
		$this->run_next_action( $manager );
		$this->run_next_action( $manager );
		$items = $this->runs->items( $run_id );
		self::assertSame( 'already_linked', $items[0]['classification'] );
		self::assertSame( 'new_product_candidate', $items[1]['classification'] );
		self::assertStringContainsString( 'duplicate_vendor_upc', (string) $items[0]['review_flags'] );
		self::assertSame( 'completed', $this->runs->run( $run_id )['status'] );
		self::assertSame( array( array( 'GET', '/api/products?page=1&per_page=10' ), array( 'GET', '/api/products?page=2&per_page=10' ) ), $client->requests );
	}

	public function test_cancellation_between_heartbeat_and_child_write_blocks_identifiers_and_items(): void {
		$run_id = $this->manager()->start( 1 );
		self::assertTrue( $this->runs->heartbeat( $run_id, $this->runs->claim_token( $run_id ) ) );
		$this->manager()->cancel( $run_id, $this->runs->claim_generation( $run_id ) );
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
		self::assertFalse(
			$this->runs->item(
				$run_id,
				array(
					'ea_product_id' => '1',
					'ea_option_id'  => '1',
				)
			)
		);
		$queue_before = count( $GLOBALS['ea_action_queue'] );
		$this->manager()->store_batch( $run_id, 1 );
		$this->manager()->catalog_page( $run_id, 1 );
		self::assertSame( $queue_before, count( $GLOBALS['ea_action_queue'] ) );
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
	}

	public function test_stale_recovery_fails_the_run_unschedules_owned_jobs_and_blocks_later_writes(): void {
		$run_id = $this->manager()->start( 1 );
		$this->runs->transition( $run_id, array( 'scanning_store' ), array( 'last_heartbeat_at' => '2026-09-24 05:59:59' ), $this->runs->claim_token( $run_id ) );
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
		self::assertCount( 2, $GLOBALS['ea_action_queue'] );
		self::assertSame( 88, $GLOBALS['ea_action_queue'][0]['id'] );
		self::assertSame( 99, $GLOBALS['ea_action_queue'][1]['args'][0] );
		self::assertFalse(
			$this->runs->item(
				$run_id,
				array(
					'ea_product_id' => '1',
					'ea_option_id'  => '1',
				)
			)
		);
		$before = count( $GLOBALS['ea_action_queue'] );
		$this->manager()->store_batch( $run_id, 2 );
		self::assertSame( $before, count( $GLOBALS['ea_action_queue'] ) );
	}

	public function test_deactivation_fails_active_runs_and_unschedules_jobs_with_arguments(): void {
		$run_id    = $this->manager()->start( 1 );
		$completed = $this->runs->create( 2, 'qa' );
		$this->runs->transition(
			$completed,
			array( 'pending' ),
			array(
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql', true ),
			)
		);
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
		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		self::assertSame( 70, $GLOBALS['ea_action_queue'][0]['id'] );
		delete_option( DryRunRepository::DEACTIVATING_OPTION );
		self::assertGreaterThan( 0, $this->runs->claim_new( 3 ) );
	}

	public function test_purge_schedules_a_unique_continuation_when_expired_rows_remain(): void {
		for ( $index = 0; $index < 27; ++$index ) {
			$id = $this->runs->create( 1, 'qa' );
			$this->runs->transition(
				$id,
				array( 'pending' ),
				array(
					'status'       => 'completed',
					'completed_at' => '2026-01-01 00:00:00',
				)
			);
		}
		$latest = $this->runs->create( 1, 'qa' );
		$this->runs->transition(
			$latest,
			array( 'pending' ),
			array(
				'status'       => 'completed',
				'completed_at' => '2026-09-01 00:00:00',
			)
		);
		$GLOBALS['ea_action_queue'] = array();
		self::assertSame( DryRunRepository::PURGE_BATCH_SIZE, $this->manager()->purge_expired() );
		self::assertSame( DryRunManager::PURGE_HOOK, $GLOBALS['ea_action_queue'][0]['hook'] );
		self::assertSame( 0, $GLOBALS['ea_action_queue'][0]['args'][2] );
		self::assertSame( DryRunManager::PURGE_HOOK, $GLOBALS['ea_action_queue'][0]['args'][5] );
	}

	public function test_purge_retry_adopts_the_same_durable_continuation_after_completion_failure(): void {
		for ( $index = 0; $index < 81; ++$index ) {
			$id = $this->runs->create( 1, 'qa' );
			$this->runs->transition(
				$id,
				array( 'pending' ),
				array(
					'status'       => 'completed',
					'completed_at' => '2026-01-01 00:00:00',
				)
			);
		}
		$latest = $this->runs->create( 1, 'qa' );
		$this->runs->transition(
			$latest,
			array( 'pending' ),
			array(
				'status'       => 'completed',
				'completed_at' => '2026-09-01 00:00:00',
			)
		);
		$manager = $this->manager();
		$manager->purge_expired();
		$action                          = array_shift( $GLOBALS['ea_action_queue'] );
		$this->wpdb->fail_query_contains = "SET status = 'completed'";
		$manager->continue_purge( ...$action['args'] );

		self::assertCount( 2, $this->wpdb->tables['wp_ideaxperts_ea_dry_run_actions'] );
		$this->wpdb->fail_query_contains = '';
		$GLOBALS['ea_now']               = '2026-09-24 12:31:00';
		$manager->continue_purge( ...$action['args'] );

		self::assertCount( 2, $this->wpdb->tables['wp_ideaxperts_ea_dry_run_actions'] );
		self::assertSame( 2, (int) $this->wpdb->tables['wp_ideaxperts_ea_dry_run_actions'][1]['claim_generation'] );
		self::assertSame( DryRunManager::PURGE_HOOK, $GLOBALS['ea_action_queue'][0]['hook'] );
	}

	public function test_old_format_and_old_token_callbacks_fail_closed(): void {
		$run_id = $this->manager()->start( 1 );
		$old    = $this->runs->claim_token( $run_id );
		$this->runs->transition(
			$run_id,
			DryRunRepository::ACTIVE_STATUSES,
			array(
				'status'        => 'failed',
				'resume_cursor' => 'store:1',
			),
			$old
		);
		$this->runs->release_lock( $run_id, $old );
		$this->manager()->resume( $run_id );
		$new    = $this->runs->claim_token( $run_id );
		$before = count( $GLOBALS['ea_action_queue'] );

		$this->manager()->store_batch( $run_id, 1 );
		$this->manager()->store_batch( $run_id, 1, $old );

		self::assertNotSame( $old, $new );
		self::assertSame( $before, count( $GLOBALS['ea_action_queue'] ) );
		self::assertSame( 0, (int) $this->runs->run( $run_id )['current_store_page'] );
	}

	public function test_page_write_failure_rolls_back_and_does_not_schedule_follow_up(): void {
		$run_id                     = $this->manager()->start( 1 );
		$token                      = $this->runs->claim_token( $run_id );
		$GLOBALS['ea_wc_products']  = array( new ReadOnlyProduct( 5, 'simple', 'publish', 'Widget', '001234567890', '' ) );
		$GLOBALS['ea_wc_max_pages'] = 2;
		$this->wpdb->fail_operation = 'replace';

		$this->run_next_action( $this->manager() );

		self::assertSame( 'failed', $this->runs->run( $run_id )['status'] );
		self::assertSame( 0, (int) $this->runs->run( $run_id )['current_store_page'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_store_identifiers'] );
		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
	}

	public function test_failed_action_scheduler_insert_leaves_initial_intent_retryable(): void {
		$GLOBALS['ea_enqueue_failure'] = true;
		$run_id                        = $this->manager()->start( 1 );
		self::assertSame( 'scanning_store', $this->runs->run( $run_id )['status'] );
		self::assertSame( 'failed', $this->wpdb->tables['wp_ideaxperts_ea_dry_run_actions'][0]['status'] );
		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
	}

	public function test_catalog_page_failure_rolls_back_every_item_and_cursor(): void {
		$run_id = $this->runs->claim_new( 1 );
		$token  = $this->runs->claim_token( $run_id );
		$this->runs->transition(
			$run_id,
			array( 'pending' ),
			array(
				'status'        => 'fetching_catalog',
				'resume_cursor' => 'catalog:1',
			),
			$token
		);
		$client                         = new SequenceClient(
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
									'id'    => 'option-1',
									'upc'   => '001',
									'price' => 10,
								),
								(object) array(
									'id'    => 'option-2',
									'upc'   => '002',
									'price' => 11,
								),
							),
						),
					),
				),
			)
		);
		$this->wpdb->fail_replace_after = 1;

		$manager = $this->manager( $client );
		$this->runs->create_action_intent( $run_id, $token, $this->runs->claim_generation( $run_id ), 'catalog', DryRunManager::CATALOG_HOOK, 1 );
		$manager->reconcile();
		$this->run_next_action( $manager );

		self::assertSame( array(), $this->runs->items( $run_id ) );
		self::assertSame( 0, (int) $this->runs->run( $run_id )['current_api_page'] );
		self::assertSame( 'failed', $this->runs->run( $run_id )['status'] );
		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
	}

	public function test_reconciliation_adopts_externally_committed_action_after_id_recording_failure(): void {
		$this->wpdb->fail_query_contains = 'action_scheduler_id';
		$manager                         = $this->manager();
		$run_id                          = $manager->start( 1 );

		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		self::assertSame( 'failed', $this->wpdb->tables['wp_ideaxperts_ea_dry_run_actions'][0]['status'] );

		$this->wpdb->fail_query_contains = '';
		$GLOBALS['ea_now']               = '2026-09-24 12:01:00';
		$manager->reconcile();

		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		self::assertSame( 'dispatched', $this->wpdb->tables['wp_ideaxperts_ea_dry_run_actions'][0]['status'] );
		self::assertSame( $GLOBALS['ea_action_queue'][0]['id'], (int) $this->wpdb->tables['wp_ideaxperts_ea_dry_run_actions'][0]['action_scheduler_id'] );
		self::assertSame( 'scanning_store', $this->runs->run( $run_id )['status'] );
	}

	public function test_two_reconcilers_with_the_same_pending_snapshot_enqueue_once(): void {
		$run_id     = $this->runs->claim_new( 1 );
		$token      = $this->runs->claim_token( $run_id );
		$generation = $this->runs->claim_generation( $run_id );
		$this->runs->transition( $run_id, array( 'pending' ), array( 'status' => 'scanning_store' ), $token, $generation );
		$intent_id = $this->runs->create_action_intent( $run_id, $token, $generation, 'store', DryRunManager::STORE_HOOK, 1 );
		$intent    = $this->runs->action_intent( $intent_id );
		$manager   = $this->manager();
		$method    = new \ReflectionMethod( $manager, 'dispatch_intent' );

		$method->invoke( $manager, $intent );
		$method->invoke( $manager, $intent );

		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		self::assertSame( 'dispatched', $this->runs->action_intent( $intent_id )['status'] );
	}

	public function test_stale_dispatch_without_external_action_is_reclaimed_and_enqueued_once(): void {
		$context           = $this->dispatching_intent();
		$GLOBALS['ea_now'] = '2026-09-24 12:06:00';

		$this->manager()->reconcile();

		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		self::assertSame( 'dispatched', $this->runs->action_intent( $context['intent_id'] )['status'] );
		self::assertNotSame( $context['dispatch_token'], (string) $this->runs->action_intent( $context['intent_id'] )['dispatch_token'] );
	}

	public function test_stale_dispatch_with_external_action_is_adopted_without_enqueue(): void {
		$context                      = $this->dispatching_intent();
		$GLOBALS['ea_action_queue'][] = array(
			'id'     => 777,
			'hook'   => DryRunManager::STORE_HOOK,
			'args'   => $context['args'],
			'group'  => DryRunManager::GROUP,
			'status' => 'pending',
		);
		$GLOBALS['ea_now']            = '2026-09-24 12:06:00';

		$this->manager()->reconcile();

		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		self::assertSame( 777, (int) $this->runs->action_intent( $context['intent_id'] )['action_scheduler_id'] );
		self::assertSame( 'dispatched', $this->runs->action_intent( $context['intent_id'] )['status'] );
	}

	public function test_cancellation_during_external_dispatch_removes_the_committed_action(): void {
		$manager                     = $this->manager();
		$GLOBALS['ea_after_enqueue'] = static function ( array $action ) use ( $manager ): void {
			$GLOBALS['ea_after_enqueue'] = null;
			$manager->cancel( (int) $action['args'][2], (int) $action['args'][4] );
		};

		$run_id = $manager->start( 1 );

		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
		self::assertSame( 'cancelled', $this->wpdb->tables['wp_ideaxperts_ea_dry_run_actions'][0]['status'] );
		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
	}

	public function test_cancellation_after_dispatch_before_id_recording_removes_the_exact_action(): void {
		$this->wpdb->fail_query_contains = 'action_scheduler_id';
		$manager                         = $this->manager();
		$run_id                          = $manager->start( 1 );
		$generation                      = $this->runs->claim_generation( $run_id );

		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		$this->wpdb->fail_query_contains = '';
		self::assertTrue( $manager->cancel( $run_id, $generation ) );
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
	}

	public function test_cancellation_before_dispatch_cancels_pending_intent(): void {
		$GLOBALS['ea_enqueue_failure'] = true;
		$manager                       = $this->manager();
		$run_id                        = $manager->start( 1 );
		$generation                    = $this->runs->claim_generation( $run_id );

		self::assertTrue( $manager->cancel( $run_id, $generation ) );
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
		self::assertSame( 'cancelled', $this->wpdb->tables['wp_ideaxperts_ea_dry_run_actions'][0]['status'] );
		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
	}

	public function test_cancellation_remains_retryable_when_unscheduling_fails(): void {
		$manager                          = $this->manager();
		$run_id                           = $manager->start( 1 );
		$generation                       = $this->runs->claim_generation( $run_id );
		$GLOBALS['ea_unschedule_failure'] = true;

		self::assertFalse( $manager->cancel( $run_id, $generation ) );
		self::assertSame( 'cancelling', $this->runs->run( $run_id )['status'] );
		self::assertCount( 1, $GLOBALS['ea_action_queue'] );

		$GLOBALS['ea_unschedule_failure'] = false;
		$manager->reconcile();
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
	}

	public function test_cancellation_of_more_than_one_hundred_actions_is_bounded_and_resumes(): void {
		$manager    = $this->manager();
		$run_id     = $manager->start( 1 );
		$generation = $this->runs->claim_generation( $run_id );
		$template   = $GLOBALS['ea_action_queue'][0];
		for ( $index = 1; $index < 125; ++$index ) {
			$copy                         = $template;
			$copy['id']                   = 1000 + $index;
			$GLOBALS['ea_action_queue'][] = $copy;
		}

		self::assertFalse( $manager->cancel( $run_id, $generation ) );
		self::assertSame( 100, count( $GLOBALS['ea_action_queue'] ) );
		for ( $request = 0; $request < 4; ++$request ) {
			$manager->reconcile();
		}

		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
		self::assertSame( 'cancelled', $this->runs->run( $run_id )['status'] );
	}

	public function test_stale_cancellation_generation_cannot_cancel_resumed_claim(): void {
		$manager = $this->manager();
		$run_id  = $manager->start( 1 );
		$token   = $this->runs->claim_token( $run_id );
		$old     = $this->runs->claim_generation( $run_id );
		$this->runs->transition(
			$run_id,
			array( 'scanning_store' ),
			array(
				'status'        => 'failed',
				'resume_cursor' => 'store:1',
			),
			$token,
			$old
		);
		$this->runs->release_lock( $run_id, $token, $old );
		$manager->resume( $run_id );

		self::assertFalse( $manager->cancel( $run_id, $old ) );
		self::assertSame( $old + 1, $this->runs->claim_generation( $run_id ) );
		self::assertSame( 'scanning_store', $this->runs->run( $run_id )['status'] );
	}

	/** @dataProvider aggregateFailures */
	public function test_each_catalog_aggregate_failure_rolls_back_page( string $query_fragment ): void {
		$run_id     = $this->runs->claim_new( 1 );
		$token      = $this->runs->claim_token( $run_id );
		$generation = $this->runs->claim_generation( $run_id );
		$this->runs->transition(
			$run_id,
			array( 'pending' ),
			array(
				'status'        => 'fetching_catalog',
				'resume_cursor' => 'catalog:1',
			),
			$token,
			$generation
		);
		$this->runs->create_action_intent( $run_id, $token, $generation, 'catalog', DryRunManager::CATALOG_HOOK, 1 );
		$client  = new SequenceClient(
			array(
				array(
					'current_page'  => 1,
					'per_page'      => 10,
					'next_page_url' => null,
					'data'          => array(
						(object) array(
							'id'    => 'p',
							'title' => 'P',
							'sizes' => array(
								(object) array(
									'id'  => 'o',
									'upc' => '001',
								),
							),
						),
					),
				),
			)
		);
		$manager = $this->manager( $client );
		$manager->reconcile();
		$this->wpdb->fail_read_contains = $query_fragment;
		$this->run_next_action( $manager );

		self::assertSame( 0, (int) $this->runs->run( $run_id )['current_api_page'] );
		self::assertSame( array(), $this->runs->items( $run_id ) );
		self::assertSame( 'failed', $this->runs->run( $run_id )['status'] );
	}

	/** @return array<string,array{string}> */
	public static function aggregateFailures(): array {
		return array(
			'duplicate vendor UPC' => array( 'SELECT COUNT(*)' ),
			'classification count' => array( 'GROUP BY classification' ),
			'flag count'           => array( 'SELECT review_flags' ),
			'identifier matches'   => array( 'normalized_identifier = ' ),
			'vendor mappings'      => array( 'ea_product_id = ' ),
		);
	}

	public function test_store_mapping_read_failure_rolls_back_catalog_page(): void {
		$run_id = $this->runs->claim_new( 1 );
		$token  = $this->runs->claim_token( $run_id );
		$gen    = $this->runs->claim_generation( $run_id );
		$this->runs->transition(
			$run_id,
			array( 'pending' ),
			array(
				'status'        => 'fetching_catalog',
				'resume_cursor' => 'catalog:1',
			),
			$token,
			$gen
		);
		$this->runs->store_identifier(
			$run_id,
			array(
				'wc_product_id'         => 7,
				'wc_variation_id'       => 0,
				'identifier_type'       => 'upc',
				'identifier_source'     => 'global_unique_id',
				'normalized_identifier' => '001',
			),
			$token
		);
		$this->runs->create_action_intent( $run_id, $token, $gen, 'catalog', DryRunManager::CATALOG_HOOK, 1 );
		$client  = new SequenceClient(
			array(
				array(
					'current_page'  => 1,
					'per_page'      => 10,
					'next_page_url' => null,
					'data'          => array(
						(object) array(
							'id'    => 'p',
							'sizes' => array(
								(object) array(
									'id'  => 'o',
									'upc' => '001',
								),
							),
						),
					),
				),
			)
		);
		$manager = $this->manager( $client );
		$manager->reconcile();
		$this->wpdb->fail_read_contains = 'wc_product_id = 7';
		$this->run_next_action( $manager );

		self::assertSame( array(), $this->runs->items( $run_id ) );
		self::assertSame( 0, (int) $this->runs->run( $run_id )['current_api_page'] );
		self::assertSame( 'failed', $this->runs->run( $run_id )['status'] );
	}

	public function test_failure_after_an_earlier_item_classification_keeps_entire_page_uncommitted(): void {
		$run_id = $this->runs->claim_new( 1 );
		$token  = $this->runs->claim_token( $run_id );
		$gen    = $this->runs->claim_generation( $run_id );
		$this->runs->transition(
			$run_id,
			array( 'pending' ),
			array(
				'status'        => 'fetching_catalog',
				'resume_cursor' => 'catalog:1',
			),
			$token,
			$gen
		);
		$this->runs->create_action_intent( $run_id, $token, $gen, 'catalog', DryRunManager::CATALOG_HOOK, 1 );
		$client  = new SequenceClient(
			array(
				array(
					'current_page'  => 1,
					'per_page'      => 10,
					'next_page_url' => null,
					'data'          => array(
						(object) array(
							'id'    => 'p',
							'sizes' => array(
								(object) array(
									'id'  => 'one',
									'upc' => '001',
								),
								(object) array(
									'id'  => 'two',
									'upc' => '002',
								),
							),
						),
					),
				),
			)
		);
		$manager = $this->manager( $client );
		$manager->reconcile();
		$this->wpdb->fail_read_contains = 'normalized_identifier = ';
		$this->wpdb->fail_read_after    = 2;
		$this->run_next_action( $manager );

		self::assertSame( array(), $this->runs->items( $run_id ) );
		self::assertSame( 0, (int) $this->runs->run( $run_id )['current_api_page'] );
		self::assertSame( 'failed', $this->runs->run( $run_id )['status'] );
	}

	public function test_production_preview_fetch_is_read_only_and_scoped(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['environment'] = 'production';
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['production_confirmed'] = \IdeaXperts\EndlessAisles\Settings\SettingsValidator::PRODUCTION_CONFIRMED;
		$this->settings->replace_token( 'production', 'production-only-secret' );
		$GLOBALS['ea_test_options']['ideaxperts_ea_production_connection_status'] = array( 'status' => 'connected' );
		$client = new SequenceClient( array( array( 'current_page' => 1, 'per_page' => 10, 'next_page_url' => null, 'data' => array( (object) array( 'id' => 'p', 'title' => 'Title', 'sizes' => array( (object) array( 'id' => 'o', 'upc' => '001234567890', 'price' => '20', 'wholesale' => '10', 'purchasability' => true, 'discontinued' => false ) ) ) ) ) ) );
		$manager = $this->manager( $client );
		$run = $manager->start_production_preview( 7 );
		$this->run_next_action( $manager );
		$this->run_next_action( $manager );
		self::assertSame( 'completed', $this->runs->run( $run )['status'] );
		self::assertSame( 'production', $this->runs->run( $run )['environment'] );
		self::assertSame( 'endless-aisles:production', $this->runs->items( $run )[0]['source_scope'] );
		self::assertSame( '20', $this->runs->items( $run )[0]['retail_price'] );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_import_runs'] );
	}

	public function test_production_preview_requires_production_configuration(): void {
		$this->expectException( RuntimeException::class );
		$this->manager()->start_production_preview( 7 );
	}

	public function test_production_preview_cancellation_prevents_vendor_fetch(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['environment'] = 'production';
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['production_confirmed'] = \IdeaXperts\EndlessAisles\Settings\SettingsValidator::PRODUCTION_CONFIRMED;
		$this->settings->replace_token( 'production', 'production-only-secret' );
		$GLOBALS['ea_test_options']['ideaxperts_ea_production_connection_status'] = array( 'status' => 'connected' );
		$client = new SequenceClient( array() );
		$manager = $this->manager( $client );
		$run = $manager->start_production_preview( 7 );
		self::assertTrue( $manager->cancel( $run, $this->runs->claim_generation( $run ) ) );
		self::assertSame( 'cancelled', $this->runs->run( $run )['status'] );
		self::assertSame( array(), $client->requests );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
	}

    public function test_catalog_progress_commits_with_page_and_repeated_callback_cannot_inflate_it(): void {
        $responses = array();
        for ( $page = 1; $page <= 2; ++$page ) {
            $responses[] = array( 'current_page' => $page, 'per_page' => 10, 'total' => 2, 'last_page' => 2, 'next_page_url' => 1 === $page ? '/api/products?page=2&per_page=10' : null, 'data' => array( (object) array( 'id' => 'p' . $page, 'sizes' => array( (object) array( 'id' => 'o' . $page, 'upc' => '00123456789' . $page ) ) ) ) );
        }
        $manager = $this->manager( new SequenceClient( $responses ) );
        $id = $manager->start( 9 );
        $this->run_next_action( $manager );
        $action = array_shift( $GLOBALS['ea_action_queue'] );
        $manager->catalog_page( ...$action['args'] );
        $run = $this->runs->run( $id );
        self::assertSame( 1, (int) $run['catalog_products_processed'] );
        self::assertSame( 2, (int) $run['catalog_total_products'] );
        self::assertSame( 2, (int) $run['catalog_total_pages'] );
        self::assertSame( 'fetching_catalog', $run['status'] );
        $manager->catalog_page( ...$action['args'] );
        self::assertSame( 1, (int) $this->runs->run( $id )['catalog_products_processed'] );
        self::assertCount( 1, $GLOBALS['ea_action_queue'] );
        $this->run_next_action( $manager );
        $run = $this->runs->run( $id );
        self::assertSame( 2, (int) $run['catalog_products_processed'] );
        self::assertSame( 'completed', $run['status'] );
        self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
    }

    public function test_page_failure_does_not_commit_observability_counters(): void {
        $client = new SequenceClient( array( array( 'current_page' => 1, 'per_page' => 10, 'total' => 1, 'last_page' => 1, 'next_page_url' => null, 'data' => array( (object) array( 'id' => 'p', 'sizes' => array( (object) array( 'id' => 'o', 'upc' => '123' ) ) ) ) ) ) );
        $manager = $this->manager( $client );
        $id = $manager->start( 9 );
        $this->run_next_action( $manager );
        $this->wpdb->fail_operation = 'replace';
        $this->run_next_action( $manager );
        $run = $this->runs->run( $id );
        self::assertSame( 0, (int) $run['catalog_products_processed'] );
        self::assertSame( 0, (int) $run['current_api_page'] );
        self::assertArrayNotHasKey( 'catalog_total_products', $run );
        self::assertSame( array(), $this->runs->items( $id ) );
        self::assertSame( 'failed', $run['status'] );
    }

    public function test_local_scan_tracks_missing_identifier_objects_and_replay_is_idempotent(): void {
        $GLOBALS['ea_wc_products'] = array( new ReadOnlyProduct( 1, 'simple', 'publish', 'Empty', '', '' ) );
        $manager = $this->manager();
        $id = $manager->start_local_discovery( 9 );
        $action = array_shift( $GLOBALS['ea_action_queue'] );
        $manager->store_batch( ...$action['args'] );
        $manager->store_batch( ...$action['args'] );
        $run = $this->runs->run( $id );
        self::assertSame( 1, (int) $run['store_products_scanned'] );
        self::assertSame( 0, (int) $run['products_inspected'] );
        self::assertSame( 1, (int) $run['store_total_pages'] );
        self::assertSame( 'completed', $run['status'] );
        self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
    }
    public function test_cancellation_preserves_committed_progress_and_fences_next_callback(): void {
        $response = array( 'current_page' => 1, 'per_page' => 10, 'total' => 2, 'last_page' => 2, 'next_page_url' => '/api/products?page=2&per_page=10', 'data' => array( (object) array( 'id' => 'p', 'sizes' => array( (object) array( 'id' => 'o', 'upc' => '001234567890' ) ) ) ) );
        $client = new SequenceClient( array( $response ) );
        $manager = $this->manager( $client );
        $id = $manager->start( 9 );
        $this->run_next_action( $manager );
        $this->run_next_action( $manager );
        $next = $GLOBALS['ea_action_queue'][0];
        self::assertTrue( $manager->cancel( $id, $this->runs->claim_generation( $id ) ) );
        $before = $this->runs->run( $id );
        $manager->catalog_page( ...$next['args'] );
        self::assertSame( $before, $this->runs->run( $id ) );
        self::assertSame( 'cancelled', $before['status'] );
        self::assertSame( 1, (int) $before['catalog_products_processed'] );
        self::assertSame( 1, (int) $before['current_api_page'] );
        self::assertCount( 1, $client->requests );
        self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
    }

    public function test_progress_update_failure_rolls_back_item_writes_and_page_cursor(): void {
        $response = array( 'current_page' => 1, 'per_page' => 10, 'total' => 1, 'last_page' => 1, 'next_page_url' => null, 'data' => array( (object) array( 'id' => 'p', 'sizes' => array( (object) array( 'id' => 'o', 'upc' => '001234567890' ) ) ) ) );
        $manager = $this->manager( new SequenceClient( array( $response ) ) );
        $id = $manager->start( 9 );
        $this->run_next_action( $manager );
        $this->wpdb->fail_update_table_contains = 'ideaxperts_ea_dry_runs';
        $this->run_next_action( $manager );
        $run = $this->runs->run( $id );
        self::assertSame( 0, (int) $run['catalog_products_processed'] );
        self::assertSame( 0, (int) $run['current_api_page'] );
        self::assertSame( array(), $this->runs->items( $id ) );
        self::assertSame( 'failed', $run['status'] );
        self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
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

	/** @return array{intent_id:int,dispatch_token:string,args:list<mixed>} */
	private function dispatching_intent(): array {
		$run_id      = $this->runs->claim_new( 1 );
		$claim_token = $this->runs->claim_token( $run_id );
		$generation  = $this->runs->claim_generation( $run_id );
		$this->runs->transition( $run_id, array( 'pending' ), array( 'status' => 'scanning_store' ), $claim_token, $generation );
		$intent_id    = $this->runs->create_action_intent( $run_id, $claim_token, $generation, 'store', DryRunManager::STORE_HOOK, 1 );
		$intent       = $this->runs->action_intent( $intent_id );
		$intent_token = (string) $intent['intent_token'];
		return array(
			'intent_id'      => $intent_id,
			'dispatch_token' => $this->runs->mark_intent_dispatching( $intent_id, $intent_token ),
			'args'           => array( $intent_id, $intent_token, $run_id, $claim_token, $generation, DryRunManager::STORE_HOOK, 1 ),
		);
	}

	private function run_next_action( DryRunManager $manager ): void {
		$action = array_shift( $GLOBALS['ea_action_queue'] );
		self::assertIsArray( $action );
		if ( DryRunManager::STORE_HOOK === $action['hook'] ) {
			$manager->store_batch( ...$action['args'] );
		} elseif ( DryRunManager::CATALOG_HOOK === $action['hook'] ) {
			$manager->catalog_page( ...$action['args'] );
		} else {
			$manager->continue_purge( ...$action['args'] );
		}
	}
}
