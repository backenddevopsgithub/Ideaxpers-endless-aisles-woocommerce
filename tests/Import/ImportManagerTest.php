<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Database\ImportRepository;
use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ImportManager;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use IdeaXperts\EndlessAisles\Tests\Support\FixedCatalogStateProvider;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test fixture globals are shared by bootstrap fakes.
final class ImportManagerTest extends TestCase {
	private DryRunMemoryWpdb $wpdb;
	private ImportRepository $imports;
	private ImportManager $manager;
	private FixedCatalogStateProvider $catalog_state;

	protected function setUp(): void {
		$GLOBALS['ea_now']             = '2026-09-28 12:00:00'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Shared test clock.
		$GLOBALS['ea_action_queue']    = array(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Action Scheduler test fake.
		$GLOBALS['ea_enqueue_failure'] = false; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Action Scheduler test fake.
		$GLOBALS['ea_wc_writes']       = array(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce write sentinel.
		$this->wpdb                    = new DryRunMemoryWpdb();
		$GLOBALS['wpdb']               = $this->wpdb;
		$this->catalog_state           = new FixedCatalogStateProvider();
		$this->imports                 = new ImportRepository( $this->catalog_state );
		$this->manager                 = new ImportManager( $this->imports );
	}

	public function test_validation_callback_reserves_identities_stops_at_ready_and_is_idempotent(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];

		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );

		$item = $this->imports->items( $run_id )[0];
		self::assertSame( 'ready', $item['status'] );
		self::assertSame( 1, (int) $item['attempt_count'] );
		self::assertCount( 1, $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'] );
		self::assertCount( 1, $this->wpdb->tables['wp_ideaxperts_ea_store_identifier_reservations'] );
		self::assertSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
	}

	public function test_stale_dispatch_adopts_an_exact_existing_scheduler_action(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->imports->start_run( $run_id ) );
		$item                         = $this->imports->items( $run_id )[0];
		$action_id                    = $this->imports->create_action( $run_id, (int) $item['id'], 1, 'validate', ImportManager::HOOK );
		$owner                        = $this->imports->claim_dispatch( $action_id );
		$action                       = $this->imports->action( $action_id );
		$GLOBALS['ea_action_queue'][] = array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Action Scheduler test fake.
			'id'     => 777,
			'status' => 'pending',
			'hook'   => ImportManager::HOOK,
			'args'   => array( $action_id, $action['logical_key'], $action['dispatch_generation'] ),
			'group'  => ImportManager::GROUP,
		);
		$GLOBALS['ea_now']            = '2026-09-28 13:00:00'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Shared test clock.

		$this->manager->reconcile();

		self::assertNotSame( '', $owner );
		self::assertSame( 'dispatched', $this->imports->action( $action_id )['status'] );
		self::assertSame( 777, (int) $this->imports->action( $action_id )['action_scheduler_id'] );
		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
	}

	public function test_stale_dispatch_is_atomically_reopened_only_when_exact_action_is_absent(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->imports->start_run( $run_id ) );
		$item      = $this->imports->items( $run_id )[0];
		$action_id = $this->imports->create_action( $run_id, (int) $item['id'], 1, 'validate', ImportManager::HOOK );
		self::assertNotSame( '', $this->imports->claim_dispatch( $action_id ) );
		$GLOBALS['ea_now']             = '2026-09-28 13:00:00'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Shared test clock.
		$GLOBALS['ea_enqueue_failure'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Action Scheduler test fake.

		$this->manager->reconcile();

		$action = $this->imports->action( $action_id );
		self::assertSame( 'retry_wait', $action['status'] );
		self::assertSame( 'enqueue_failed', $action['failure_code'] );
		self::assertSame( '', $action['dispatch_token'] );
	}

	public function test_import_foundation_contains_no_catalog_write_calls(): void {
		$root    = dirname( __DIR__, 2 );
		$sources = array(
			$root . '/src/Import/ApprovalManifest.php',
			$root . '/src/Import/ImportManager.php',
			$root . '/src/Import/ImportPolicy.php',
			$root . '/src/Database/ImportRepository.php',
			$root . '/src/Admin/CatalogImportAdmin.php',
		);
		$code    = '';
		foreach ( $sources as $source ) {
			$contents = file_get_contents( $source );
			self::assertIsString( $contents );
			$code .= $contents;
		}

		self::assertDoesNotMatchRegularExpression( '/(?:wp_insert_post|wp_update_post|update_post_meta|delete_post_meta|media_handle_sideload|wc_update_product_stock|set_(?:regular_)?price|->save)\s*\(/', $code );
	}

	public function test_queue_uses_keyset_batches_beyond_the_display_limit(): void {
		$run_id = $this->create_run( 'qa', 101 );

		self::assertTrue( $this->manager->queue( $run_id ) );
		self::assertCount( 101, $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] );
	}

	public function test_partial_action_provisioning_is_idempotently_resumed_before_run_starts(): void {
		$run_id                                      = $this->create_run( 'qa', 3 );
		$this->wpdb->fail_import_action_insert_after = 1;
		self::assertFalse( $this->manager->queue( $run_id ) );
		self::assertSame( 'queued', $this->imports->run( $run_id )['status'] );
		self::assertCount( 1, $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] );

		$this->wpdb->fail_import_action_insert_after = -1;
		self::assertTrue( $this->manager->queue( $run_id ) );
		self::assertSame( 'running', $this->imports->run( $run_id )['status'] );
		self::assertCount( 3, $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] );
		self::assertCount( 3, array_unique( array_column( $this->wpdb->tables['wp_ideaxperts_ea_import_actions'], 'logical_key' ) ) );
	}

	public function test_action_audit_failure_rolls_back_that_action_and_provisioning_resumes(): void {
		$run_id                                     = $this->create_run( 'qa', 3 );
		$this->wpdb->fail_import_event_insert_after = $this->wpdb->import_event_insert_calls + 1;

		self::assertFalse( $this->manager->queue( $run_id ) );
		self::assertSame( 'queued', $this->imports->run( $run_id )['status'] );
		self::assertCount( 1, $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] );
		self::assertCount( 1, array_filter( $this->wpdb->tables['wp_ideaxperts_ea_import_events'], static fn( array $event ): bool => 'action_created' === $event['event_type'] ) );

		$this->wpdb->fail_import_event_insert_after = -1;
		self::assertTrue( $this->manager->queue( $run_id ) );
		self::assertSame( 'running', $this->imports->run( $run_id )['status'] );
		self::assertCount( 3, $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] );
		self::assertCount( 3, array_filter( $this->wpdb->tables['wp_ideaxperts_ea_import_events'], static fn( array $event ): bool => 'action_created' === $event['event_type'] ) );
	}

	public function test_failure_before_final_action_is_resumable_without_duplicate_outbox_rows(): void {
		$run_id                                      = $this->create_run( 'qa', 4 );
		$this->wpdb->fail_import_action_insert_after = 3;

		self::assertFalse( $this->manager->queue( $run_id ) );
		self::assertCount( 3, $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] );
		$this->wpdb->fail_import_action_insert_after = -1;
		self::assertTrue( $this->manager->queue( $run_id ) );
		self::assertCount( 4, $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] );
		self::assertCount( 4, array_unique( array_column( $this->wpdb->tables['wp_ideaxperts_ea_import_actions'], 'logical_key' ) ) );
	}

	public function test_run_transition_failure_after_full_provisioning_is_resumable(): void {
		$run_id                     = $this->create_run( 'qa', 3 );
		$this->wpdb->fail_operation = 'update';
		self::assertFalse( $this->manager->queue( $run_id ) );
		self::assertSame( 'queued', $this->imports->run( $run_id )['status'] );
		self::assertCount( 3, $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] );
		$this->wpdb->fail_operation = '';
		self::assertTrue( $this->manager->queue( $run_id ) );
		self::assertCount( 3, $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] );
	}

	public function test_stale_freshness_terminalizes_both_item_and_action(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action                       = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		$this->catalog_state->version = 'changed';
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertSame( 'stale_snapshot', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
	}

	public function test_reservation_conflict_blocks_item_and_completes_action(): void {
		$first_run = $this->create_run();
		self::assertTrue( $this->manager->queue( $first_run ) );
		$first = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		$this->manager->validate_item( (int) $first['id'], (string) $first['logical_key'], (int) $first['dispatch_generation'] );

		$second_run = $this->create_run( 'production', 1, 'other-product-' );
		self::assertTrue( $this->manager->queue( $second_run ) );
		$second = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][1]['id'] );
		$this->manager->validate_item( (int) $second['id'], (string) $second['logical_key'], (int) $second['dispatch_generation'] );

		self::assertSame( 'blocked', $this->imports->items( $second_run )[0]['status'] );
		self::assertSame( 'completed', $this->imports->action( (int) $second['id'] )['status'] );
	}

	public function test_cancelled_item_rejects_late_callback_without_reopening_action(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		self::assertTrue( $this->imports->request_cancellation( $run_id ) );

		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );

		self::assertSame( 'cancelled', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( 'cancel_requested', $this->imports->action( (int) $action['id'] )['status'] );
	}

	public function test_action_close_failure_after_ready_is_reconciled_by_duplicate_callback(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action                             = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		$this->wpdb->fail_import_event_type = 'action_completed';

		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertSame( 'ready', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( 'running', $this->imports->action( (int) $action['id'] )['status'] );

		$GLOBALS['ea_now'] = '2026-09-28 13:00:00';
		$this->manager->reconcile();
		$current = $this->imports->action( (int) $action['id'] );
		$this->manager->validate_item( (int) $current['id'], (string) $current['logical_key'], (int) $current['dispatch_generation'] );

		self::assertSame( 'ready', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
	}

	public function test_lost_dispatched_action_is_reopened_once(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action                     = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
		$GLOBALS['ea_action_queue'] = array();
		$GLOBALS['ea_now']          = '2026-09-28 13:00:00';
		$this->manager->reconcile();
		self::assertSame( 'retry_wait', $this->imports->action( (int) $action['id'] )['status'] );
		$this->manager->reconcile();
		self::assertCount( 1, $GLOBALS['ea_action_queue'] );
		self::assertSame( 2, (int) $this->imports->action( (int) $action['id'] )['dispatch_generation'] );
	}

	/** @dataProvider retainedSchedulerStates */
	public function test_stale_dispatched_action_is_retained_when_exact_scheduler_action_is_live( string $scheduler_state ): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action                                  = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		$GLOBALS['ea_action_queue'][0]['status'] = $scheduler_state;
		$GLOBALS['ea_now']                       = '2026-09-28 13:00:00';

		$this->manager->reconcile();

		$current = $this->imports->action( (int) $action['id'] );
		self::assertSame( 'dispatched', $current['status'] );
		self::assertSame( (int) $action['dispatch_generation'], (int) $current['dispatch_generation'] );
		self::assertSame( (int) $action['action_scheduler_id'], (int) $current['action_scheduler_id'] );
	}

	/** @return array<string,array{string}> */
	public static function retainedSchedulerStates(): array {
		return array(
			'pending'     => array( 'pending' ),
			'in progress' => array( 'in-progress' ),
		);
	}

	/** @dataProvider lostSchedulerStates */
	public function test_failed_or_cancelled_scheduler_action_is_reopened( string $scheduler_state ): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action                                  = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		$GLOBALS['ea_action_queue'][0]['status'] = $scheduler_state;
		$GLOBALS['ea_now']                       = '2026-09-28 13:00:00';

		$this->manager->reconcile();

		self::assertSame( 'retry_wait', $this->imports->action( (int) $action['id'] )['status'] );
	}

	/** @return array<string,array{string}> */
	public static function lostSchedulerStates(): array {
		return array(
			'failed'    => array( 'failed' ),
			'cancelled' => array( 'canceled' ),
		);
	}

	public function test_completed_scheduler_action_without_durable_claim_is_reopened_with_distinct_reason(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action                                  = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		$GLOBALS['ea_action_queue'][0]['status'] = 'complete';
		$GLOBALS['ea_now']                       = '2026-09-28 13:00:00';

		$this->manager->reconcile();

		$current = $this->imports->action( (int) $action['id'] );
		self::assertSame( 'retry_wait', $current['status'] );
		self::assertSame( 'scheduler_completed_without_claim', $current['failure_code'] );
	}

	public function test_stale_scheduler_id_pointing_to_wrong_action_is_reopened(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action                              = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		$GLOBALS['ea_action_queue'][0]['id'] = 999;
		$GLOBALS['ea_now']                   = '2026-09-28 13:00:00';

		$this->manager->reconcile();

		self::assertSame( 'retry_wait', $this->imports->action( (int) $action['id'] )['status'] );
	}

	public function test_old_dispatch_generation_is_rejected_after_redispatch(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action           = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
		$first_generation = (int) $action['dispatch_generation'];
		$execution        = $this->imports->claim_action_execution( (int) $action['id'], (string) $action['logical_key'], $first_generation );
		self::assertNotSame( '', $execution );
		$GLOBALS['ea_now'] = '2026-09-28 13:00:00';
		self::assertSame( 1, $this->imports->reclaim_expired_action_executions() );
		$owner   = $this->imports->claim_dispatch( (int) $action['id'] );
		$current = $this->imports->action( (int) $action['id'] );
		self::assertTrue( $this->imports->record_dispatched( (int) $action['id'], $owner, 999, (int) $current['dispatch_generation'] ) );
		self::assertSame( '', $this->imports->claim_action_execution( (int) $action['id'], (string) $action['logical_key'], $first_generation ) );
		self::assertNotSame( '', $this->imports->claim_action_execution( (int) $action['id'], (string) $action['logical_key'], (int) $current['dispatch_generation'] ) );
	}

	private function create_run( string $environment = 'production', int $count = 1, string $product_prefix = 'p' ): int {
		static $dry_run_id = 2000;
		++$dry_run_id;
		$target = array(
			'wc_product_id'   => 0,
			'wc_variation_id' => 0,
		);
		$items  = array();
		for ( $index = 1; $index <= $count; ++$index ) {
			$vendor      = array(
				'ea_product_id'  => $product_prefix . $index,
				'ea_option_id'   => 'o' . $index,
				'normalized_upc' => str_pad( (string) $index, 12, '0', STR_PAD_LEFT ),
				'classification' => 'new_product_candidate',
				'review_flags'   => array(),
				'retail_price'   => '10',
				'purchasable'    => 1,
				'discontinued'   => 0,
			);
				$items[] = array(
					'dry_run_item_id'       => $index,
					'action'                => 'create',
					'entity_kind'           => 'option',
					'group_key'             => hash( 'sha256', $environment . "\0" . $vendor['ea_product_id'] ),
					'vendor'                => $vendor,
					'target'                => $target,
					'expected_vendor_hash'  => ApprovalManifest::hash( $vendor ),
					'expected_local_hash'   => ApprovalManifest::hash( $target ),
					'expected_mapping_hash' => ApprovalManifest::hash(
						array(
							'product' => $vendor['ea_product_id'],
							'option'  => $vendor['ea_option_id'],
							'target'  => $target,
						)
					),
					'expected_live_hash'    => $this->catalog_state->fingerprint(
						array_merge(
							$vendor,
							array(
								'target_wc_product_id'   => 0,
								'target_wc_variation_id' => 0,
							)
						),
						'endless-aisles:' . $environment,
						$environment
					),
				);
		}
		$manifest = array(
			'version'                => 1,
			'policy_version'         => '3a-v1',
			'dry_run_id'             => $dry_run_id,
			'dry_run_generation'     => 1,
			'environment'            => $environment,
			'source_scope'           => 'endless-aisles:' . $environment,
			'approval_generation'    => 1,
			'matching_settings_hash' => str_repeat( 'a', 64 ),
			'items'                  => $items,
		);
		return $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
	}
}
