<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Database\ImportRepository;
use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ImportManager;
use IdeaXperts\EndlessAisles\Import\ImportPolicy;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use IdeaXperts\EndlessAisles\Tests\Support\FixedCatalogStateProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/SigningKeyConstants.php';

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test fixture globals are shared by bootstrap fakes.
final class ImportManagerTest extends TestCase {
	private DryRunMemoryWpdb $wpdb;
	private ImportRepository $imports;
	private ImportManager $manager;
	private FixedCatalogStateProvider $catalog_state;

	public function test_invalid_signing_configuration_reports_bounded_error_without_secret_output(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
		$invalid = str_repeat( 'sensitive-fixture', 4 );
		\IdeaXperts\EndlessAisles\Tests\Support\SigningKeyConstants::$values = array( 'AUTH_KEY' => $invalid, 'SECURE_AUTH_KEY' => SECURE_AUTH_KEY );
		ob_start();
		try {
			$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
			\IdeaXperts\EndlessAisles\Tests\Support\SigningKeyConstants::$values = null;
		}
		$item = $this->imports->items( $run_id )[0];
		self::assertSame( 'blocked', $item['status'] );
		self::assertSame( 'wordpress_signing_keys_invalid', $item['failure_code'] );
		self::assertSame( '', $item['live_freshness_token'] );
		self::assertSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
		self::assertSame( '', $output );
		$observable = $output . wp_json_encode( array( $this->wpdb->tables, $this->wpdb->queries, $this->wpdb->last_error ) );
		foreach ( array( $invalid, AUTH_KEY, SECURE_AUTH_KEY ) as $secret ) {
			self::assertStringNotContainsString( $secret, $observable );
		}
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
	}

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

	/** @dataProvider existingLinkCases */
	public function test_explicit_existing_match_links_without_merchant_mutation( string $classification, bool $variation ): void {
		$run_id = $this->create_link_run( $classification, $variation );
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];

		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );

		$item = $this->imports->items( $run_id )[0];
		self::assertSame( 'applied', $item['status'] );
		self::assertSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
		self::assertCount( 1, $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( $variation ? 51 : 0, (int) $this->wpdb->tables['wp_ideaxperts_ea_mappings'][0]['wc_variation_id'] );
		self::assertSame( 'linked', $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0]['reservation_status'] );
		self::assertSame( 'linked_existing', $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0]['ownership_mode'] );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
		self::assertCount( 1, array_filter( $this->wpdb->tables['wp_ideaxperts_ea_import_events'], static fn( array $event ): bool => 'existing_link_applied' === $event['event_type'] ) );
	}

	/** @return array<string,array{string,bool}> */
	public static function existingLinkCases(): array {
		return array(
			'exact UPC simple'    => array( 'exact_upc_match', false ),
			'exact SKU simple'    => array( 'exact_sku_match', false ),
			'exact UPC variation' => array( 'exact_upc_match', true ),
			'exact SKU variation' => array( 'exact_sku_match', true ),
		);
	}

	/** @dataProvider staleLinkMutations */
	public function test_changed_existing_target_settles_stale_without_mapping( callable $mutate ): void {
		$run_id = $this->create_link_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$mutate( $this->catalog_state );
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];

		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );

		self::assertSame( 'stale_snapshot', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
	}

	/** @return array<string,array{callable(FixedCatalogStateProvider):void}> */
	public static function staleLinkMutations(): array {
		return array(
			'target deleted'      => array(
				static function ( FixedCatalogStateProvider $state ): void {
										$state->target = null; },
			),
			'UPC changed'         => array(
				static function ( FixedCatalogStateProvider $state ): void {
										$state->target['upcs'] = array( '009999999999' ); },
			),
			'type changed'        => array(
				static function ( FixedCatalogStateProvider $state ): void {
										$state->target['product_type'] = 'variable'; },
			),
			'duplicate UPC owner' => array(
				static function ( FixedCatalogStateProvider $state ): void {
										$state->upc_owners[] = '99:0'; },
			),
		);
	}

	public function test_sku_change_duplicate_or_upc_mismatch_blocks_link(): void {
		foreach ( array( 'sku', 'duplicate', 'upc' ) as $case ) {
			$this->setUp();
			$run_id = $this->create_link_run( 'exact_sku_match' );
			self::assertTrue( $this->manager->queue( $run_id ) );
			if ( 'sku' === $case ) {
				$this->catalog_state->target['sku'] = 'CHANGED';
			} elseif ( 'duplicate' === $case ) {
				$this->catalog_state->sku_owners[] = '99:0';
			} else {
				$this->catalog_state->target['upcs'] = array( '009999999999' );
			}
			$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
			$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
			self::assertSame( 'stale_snapshot', $this->imports->items( $run_id )[0]['status'] );
			self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		}
	}

	public function test_variation_parent_change_blocks_link(): void {
		$run_id = $this->create_link_run( 'exact_upc_match', true );
		self::assertTrue( $this->manager->queue( $run_id ) );
		$this->catalog_state->target['parent_product_id'] = 99;
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];

		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );

		self::assertSame( 'stale_snapshot', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
	}

	/** @dataProvider atomicLinkFailures */
	public function test_link_transaction_failure_leaves_no_partial_mapping( string $failure ): void {
		$run_id = $this->create_link_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		if ( 'mapping' === $failure ) {
			$this->wpdb->fail_insert_table_contains = 'ideaxperts_ea_mappings';
		} elseif ( 'audit' === $failure ) {
			$this->wpdb->fail_import_event_type = 'existing_link_applied';
		} else {
			$this->catalog_state->on_inspect = function () use ( $failure ): void {
				$this->wpdb->fail_update_table_contains = 'item' === $failure ? 'import_items' : 'import_actions';
			};
		}
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];

		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );

		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( 'ready', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( 'running', $this->imports->action( (int) $action['id'] )['status'] );
		self::assertSame( 'reserved', $this->wpdb->tables['wp_ideaxperts_ea_catalog_identities'][0]['reservation_status'] );
	}

	/** @return array<string,array{string}> */
	public static function atomicLinkFailures(): array {
		return array(
			'mapping insert'    => array( 'mapping' ),
			'audit insert'      => array( 'audit' ),
			'item transition'   => array( 'item' ),
			'action completion' => array( 'action' ),
		);
	}

	public function test_mapping_conflict_is_manual_and_exact_preexisting_mapping_is_adopted(): void {
		$run_id = $this->create_link_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$item  = $this->imports->items( $run_id )[0];
		$exact = $this->mapping_row( $item );
		$this->wpdb->tables['wp_ideaxperts_ea_mappings'][] = array_merge( $exact, array( 'id' => 900 ) );
		$this->catalog_state->mappings                     = array( $exact );
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];

		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertSame( 'applied', $this->imports->items( $run_id )[0]['status'] );
		self::assertCount( 1, $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );

		$this->setUp();
		$run_id = $this->create_link_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$item = $this->imports->items( $run_id )[0];
		$this->wpdb->tables['wp_ideaxperts_ea_mappings'][] = array_merge(
			$this->mapping_row( $item ),
			array(
				'id'            => 901,
				'ea_product_id' => 'other',
			)
		);
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertSame( 'manual_required', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
	}

	/** @dataProvider conflictingMappingMutations */
	public function test_ea_target_scope_and_option_mapping_conflicts_fail_closed( callable $mutate ): void {
		$run_id = $this->create_link_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$item = $this->imports->items( $run_id )[0];
		$row  = $this->mapping_row( $item );
		$mutate( $row );
		$this->wpdb->tables['wp_ideaxperts_ea_mappings'][] = array_merge( $row, array( 'id' => 950 ) );
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];

		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );

		self::assertSame( 'manual_required', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
	}

	/** @return array<string,array{callable(array<string,mixed>&):void}> */
	public static function conflictingMappingMutations(): array {
		return array(
			'EA identity elsewhere' => array(
				static function ( array &$row ): void {
										$row['wc_product_id'] = 88; },
			),
			'Woo target other EA'   => array(
				static function ( array &$row ): void {
										$row['ea_product_id'] = 'other'; },
			),
			'wrong source scope'    => array(
				static function ( array &$row ): void {
										$row['source_scope'] = 'endless-aisles:qa'; },
			),
			'conflicting option'    => array(
				static function ( array &$row ): void {
										$row['ea_option_id'] = 'other-option'; },
			),
		);
	}

	public function test_mapping_deleted_or_redirected_after_approval_requires_manual_review(): void {
		foreach ( array( 'deleted', 'redirected' ) as $case ) {
			$this->setUp();
			$run_id = $this->create_link_run( 'exact_upc_match', false, true );
			self::assertTrue( $this->manager->queue( $run_id ) );
			if ( 'deleted' === $case ) {
				$this->wpdb->tables['wp_ideaxperts_ea_mappings'] = array();
				$this->catalog_state->mappings                   = array();
			} else {
				$this->wpdb->tables['wp_ideaxperts_ea_mappings'][0]['wc_product_id'] = 88;
				$this->catalog_state->mappings[0]['wc_product_id']                   = 88;
			}
			$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
			$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
			self::assertSame( 'manual_required', $this->imports->items( $run_id )[0]['status'] );
		}
	}

	public function test_cancellation_before_and_after_link_is_authoritative_and_idempotent(): void {
		$run_id = $this->create_link_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
		self::assertTrue( $this->imports->request_cancellation( $run_id ) );
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );

		$this->setUp();
		$run_id = $this->create_link_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertTrue( $this->imports->request_cancellation( $run_id ) );
		self::assertTrue( $this->imports->finalize_cancellation( $run_id ) );
		self::assertCount( 1, $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( 'applied', $this->imports->items( $run_id )[0]['status'] );
	}

	public function test_stale_link_callback_generation_cannot_create_mapping(): void {
		$run_id = $this->create_link_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];

		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] - 1 );

		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( 'pending', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( 'dispatched', $this->imports->action( (int) $action['id'] )['status'] );
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

		self::assertDoesNotMatchRegularExpression( '/(?:wp_insert_post|wp_update_post|wp_insert_attachment|wp_set_object_terms|update_post_meta|delete_post_meta|media_handle_sideload|wc_update_product_stock|wc_create_product|set_(?:regular_|sale_)?price|set_stock|set_status|set_category_ids|set_image_id|set_gallery_image_ids|->save)\s*\(/', $code );
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
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );

		self::assertSame( 'cancelled', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( 'cancelled', $this->imports->action( (int) $action['id'] )['status'] );
		self::assertTrue( $this->imports->finalize_cancellation( $run_id ) );
	}

	public function test_pending_cancel_is_unscheduled_settled_and_run_finalized_idempotently(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		self::assertTrue( $this->imports->request_cancellation( $run_id ) );
		self::assertFalse( $this->imports->finalize_cancellation( $run_id ) );

		$this->manager->reconcile();
		$this->manager->reconcile();

		self::assertSame( 'cancelled', $this->imports->action( (int) $action['id'] )['status'] );
		self::assertSame( 'cancelled', $this->imports->run( $run_id )['status'] );
		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
	}

	public function test_cancel_reconciles_ambiguous_dispatch_without_recorded_scheduler_id(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		foreach ( $this->wpdb->tables['wp_ideaxperts_ea_import_actions'] as &$stored ) {
			if ( (int) $stored['id'] === (int) $action['id'] ) {
				$stored['action_scheduler_id'] = null;
			}
		}
		unset( $stored );
		self::assertTrue( $this->imports->request_cancellation( $run_id ) );

		$this->manager->reconcile();

		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
		self::assertSame( 'cancelled', $this->imports->action( (int) $action['id'] )['status'] );
		self::assertSame( 'cancelled', $this->imports->run( $run_id )['status'] );
	}

	public function test_two_cancellation_reconcilers_settle_the_same_action_idempotently(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action                     = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		$GLOBALS['ea_action_queue'] = array();
		self::assertTrue( $this->imports->request_cancellation( $run_id ) );

		self::assertTrue( $this->imports->settle_cancel_requested_action( (int) $action['id'], (int) $action['dispatch_generation'], (int) $action['action_scheduler_id'] ) );
		self::assertTrue( $this->imports->settle_cancel_requested_action( (int) $action['id'], (int) $action['dispatch_generation'], (int) $action['action_scheduler_id'] ) );
		self::assertTrue( $this->imports->finalize_cancellation( $run_id ) );
		self::assertSame( 'cancelled', $this->imports->run( $run_id )['status'] );
	}

	/** @dataProvider terminalCancellationSchedulerStates */
	public function test_missing_failed_cancelled_or_completed_delivery_settles_cancellation( string $state ): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		if ( '' === $state ) {
			$GLOBALS['ea_action_queue'] = array();
		} else {
			$GLOBALS['ea_action_queue'][0]['status'] = $state;
		}
		self::assertTrue( $this->imports->request_cancellation( $run_id ) );
		$this->manager->reconcile();
		self::assertSame( 'cancelled', $this->imports->action( (int) $action['id'] )['status'] );
		self::assertSame( 'cancelled', $this->imports->run( $run_id )['status'] );
	}

	/** @return array<string,array{string}> */
	public static function terminalCancellationSchedulerStates(): array {
		return array(
			'missing'   => array( '' ),
			'failed'    => array( 'failed' ),
			'cancelled' => array( 'canceled' ),
			'completed' => array( 'complete' ),
		);
	}

	public function test_in_progress_or_active_execution_blocks_cancellation_until_resolved(): void {
		$run_id = $this->create_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action                                  = $this->imports->action( (int) $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['id'] );
		$GLOBALS['ea_action_queue'][0]['status'] = 'in-progress';
		$execution                               = $this->imports->claim_action_execution( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertNotSame( '', $execution );
		self::assertTrue( $this->imports->request_cancellation( $run_id ) );
		$this->manager->reconcile();
		self::assertSame( 'cancel_requested', $this->imports->action( (int) $action['id'] )['status'] );
		self::assertSame( 'cancelling', $this->imports->run( $run_id )['status'] );

		$GLOBALS['ea_now']                       = '2026-09-28 13:00:00';
		$GLOBALS['ea_action_queue'][0]['status'] = 'complete';
		$this->manager->reconcile();
		self::assertSame( 'cancelled', $this->imports->action( (int) $action['id'] )['status'] );
		self::assertSame( 'cancelled', $this->imports->run( $run_id )['status'] );
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

	public function test_bugbot_locked_mapping_deletion_must_not_be_recreated(): void {
		$run_id = $this->create_link_run( 'exact_upc_match', false, true );
		self::assertTrue( $this->manager->queue( $run_id ) );
		$this->wpdb->before_mapping_lock = function (): void {
			$this->wpdb->tables['wp_ideaxperts_ea_mappings'] = array();
		};
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertSame( 'manual_required', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
	}

	/** @dataProvider lockedMappingRaces */
	public function test_locked_mapping_current_read_overrules_inspection( bool $expected_mapping, string $mutation, string $expected_status ): void {
		$run = $this->create_link_run( 'exact_upc_match', false, $expected_mapping );
		self::assertTrue( $this->manager->queue( $run ) );
		$item = $this->imports->items( $run )[0];
		$this->wpdb->before_mapping_lock = function () use ( $item, $mutation ): void {
			$row = array_merge( $this->mapping_row( $item ), array( 'id' => 999 ) );
			if ( 'redirected' === $mutation ) {
				$row['wc_product_id'] = 99;
			} elseif ( 'option' === $mutation ) {
				$row['ea_option_id'] = 'different';
			} elseif ( 'identifier' === $mutation ) {
				$row['normalized_upc'] = '009999999999';
			} elseif ( 'inactive' === $mutation ) {
				$row['mapping_status'] = 'inactive';
			}
			// Snapshot provider still returns its approved view; only the locking read changes.
			$this->wpdb->tables['wp_ideaxperts_ea_mappings'] = 'deleted' === $mutation ? array() : array( $row );
		};
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertSame( $expected_status, $this->imports->items( $run )[0]['status'] );
		self::assertCount( 'deleted' === $mutation ? 0 : 1, $this->wpdb->tables['wp_ideaxperts_ea_mappings'] );
		self::assertSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
		if ( 'redirected' === $mutation ) {
			self::assertSame( 99, $this->wpdb->tables['wp_ideaxperts_ea_mappings'][0]['wc_product_id'] );
		}
	}

	/** @return list<array{bool,string,string}> */
	public static function lockedMappingRaces(): array {
		return array(
			array( true, 'deleted', 'manual_required' ),
			array( true, 'redirected', 'manual_required' ),
			array( true, 'option', 'manual_required' ),
			array( true, 'unchanged', 'applied' ),
			array( false, 'exact_insert', 'applied' ),
			array( false, 'redirected', 'manual_required' ),
			array( false, 'option', 'manual_required' ),
			array( false, 'identifier', 'manual_required' ),
			array( false, 'inactive', 'manual_required' ),
		);
	}

	/** @dataProvider linkOutcomeMatrix */
	public function test_link_action_completion_requires_authoritative_terminal_outcome( string $status, bool $terminal ): void {
		$run = $this->create_link_run();
		self::assertTrue( $this->manager->queue( $run ) );
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
		$token = $this->imports->claim_action_execution( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		$this->wpdb->tables['wp_ideaxperts_ea_import_items'][0]['status'] = $status;
		if ( ! $terminal ) {
			self::assertFalse( $this->imports->complete_action_execution( (int) $action['id'], (string) $action['logical_key'], $token ) );
		}
		$this->imports->settle_action_for_item( (int) $action['id'], (string) $action['logical_key'], $token );
		self::assertSame( $terminal, 'completed' === $this->imports->action( (int) $action['id'] )['status'] );
	}

	/** @return list<array{string,bool}> */
	public static function linkOutcomeMatrix(): array {
		return array(
			array( 'pending', false ), array( 'leased', false ), array( 'validating', false ),
			array( 'ready', false ), array( 'applying', false ), array( 'reconciling', false ),
			array( 'applied', true ), array( 'stale_snapshot', true ), array( 'manual_required', true ),
			array( 'blocked', true ), array( 'retry_wait', false ), array( 'cancelled', true ), array( 'manual_recovery', true ),
		);
	}

	public function test_bugbot_ready_crash_action_expires_first(): void {
		$run_id = $this->create_link_run();
		self::assertTrue( $this->manager->queue( $run_id ) );
		$action = $this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0];
		$this->imports->claim_action_execution( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		$item = $this->imports->items( $run_id )[0];
		$token = $this->imports->claim_item( (int) $item['id'] );
		self::assertTrue( $this->imports->begin_validation( (int) $item['id'], $token ) );
		$identity = $this->imports->reserve_catalog_identity( (int) $item['id'], $token );
		self::assertGreaterThan( 0, $this->imports->reserve_upc( $identity, (int) $item['id'], $token ) );
		self::assertTrue( $this->imports->accept_freshness( (int) $item['id'], $token ) );
		$this->wpdb->tables['wp_ideaxperts_ea_import_actions'][0]['lease_expires_at'] = '2026-09-28 12:00:01';
		$this->wpdb->tables['wp_ideaxperts_ea_import_items'][0]['lease_expires_at'] = '2026-09-28 12:05:00';
		$GLOBALS['ea_now'] = '2026-09-28 12:00:02';
		$this->manager->reconcile();
		$action = $this->imports->action( (int) $action['id'] );
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertNotSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
		$GLOBALS['ea_now'] = '2026-09-28 13:00:00';
		$this->manager->reconcile();
		$action = $this->imports->action( (int) $action['id'] );
		$this->manager->validate_item( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
		self::assertSame( 'applied', $this->imports->items( $run_id )[0]['status'] );
		self::assertSame( 'completed', $this->imports->action( (int) $action['id'] )['status'] );
	}

	private function create_link_run( string $classification = 'exact_upc_match', bool $variation = false, bool $approved_mapping = false ): int {
		static $dry_run_id = 4000;
		++$dry_run_id;
		$upc                             = '001234567890';
		$product                         = 50;
		$variation_id                    = $variation ? 51 : 0;
		$target_key                      = $product . ':' . $variation_id;
		$target_state                    = array(
			'wc_product_id'     => $product,
			'wc_variation_id'   => $variation_id,
			'product_type'      => $variation ? 'variation' : 'simple',
			'product_status'    => 'publish',
			'parent_product_id' => $variation ? $product : 0,
			'sku'               => 'exact_sku_match' === $classification ? $upc : 'SKU-50',
			'upcs'              => 'exact_upc_match' === $classification ? array( $upc ) : array(),
		);
		$this->catalog_state->target     = $target_state;
		if ( $variation ) {
			$target_state['parent'] = array( 'id' => $product, 'type' => 'variable', 'status' => 'publish' );
			$this->catalog_state->target = $target_state;
		}
		$this->catalog_state->upc_owners = 'exact_upc_match' === $classification ? array( $target_key ) : array();
		$this->catalog_state->sku_owners = 'exact_sku_match' === $classification ? array( $target_key ) : array();
		$approved_mapping_row            = array(
			'source_scope'    => 'endless-aisles:production',
			'environment'     => 'production',
			'wc_product_id'   => $product,
			'wc_variation_id' => $variation_id,
			'ea_product_id'   => 'p-link',
			'ea_option_id'    => 'o-link',
			'normalized_upc'  => $upc,
			'mapping_status'  => 'active',
		);
		$this->catalog_state->mappings   = $approved_mapping ? array( $approved_mapping_row ) : array();
		$vendor                          = array(
			'ea_product_id'  => 'p-link',
			'ea_option_id'   => 'o-link',
			'normalized_upc' => $upc,
			'classification' => $classification,
			'vendor_sku'     => 'exact_sku_match' === $classification ? $upc : '',
			'review_flags'   => array(),
			'retail_price'   => '10',
			'purchasable'    => 1,
			'discontinued'   => 0,
		);
		$target                          = array(
			'wc_product_id'     => $product,
			'wc_variation_id'   => $variation_id,
			'approved_state'    => $target_state,
			'approved_mappings' => $this->catalog_state->mappings,
		);
		$context                         = array_merge(
			$vendor,
			array(
				'target_wc_product_id'   => $product,
				'target_wc_variation_id' => $variation_id,
			)
		);
		$item                            = array(
			'dry_run_item_id'       => 1,
			'action'                => 'link',
			'entity_kind'           => $variation ? 'variation' : 'option',
			'group_key'             => hash( 'sha256', "production\0p-link" ),
			'vendor'                => $vendor,
			'target'                => $target,
			'expected_vendor_hash'  => ApprovalManifest::hash( $vendor ),
			'expected_local_hash'   => ApprovalManifest::hash( $target ),
			'expected_mapping_hash' => ApprovalManifest::hash(
				array(
					'product' => 'p-link',
					'option'  => 'o-link',
					'target'  => $target,
				)
			),
			'expected_live_hash'    => $this->catalog_state->fingerprint( $context, 'endless-aisles:production', 'production' ),
		);
		$manifest                        = array(
			'version'                => 1,
			'policy_version'         => ImportPolicy::VERSION,
			'dry_run_id'             => $dry_run_id,
			'dry_run_generation'     => 1,
			'environment'            => 'production',
			'source_scope'           => 'endless-aisles:production',
			'approval_generation'    => 1,
			'matching_settings_hash' => str_repeat( 'a', 64 ),
			'items'                  => array( $item ),
		);
		$run_id                          = $this->imports->create_from_manifest( $manifest, ApprovalManifest::hash( $manifest ), 7 );
		if ( $approved_mapping && $run_id > 0 ) {
			$item = $this->imports->items( $run_id )[0];
			$this->wpdb->tables['wp_ideaxperts_ea_mappings'][] = array_merge( $this->mapping_row( $item ), array( 'id' => 990 ) );
		}
		return $run_id;
	}

	/** @param array<string,mixed> $item @return array<string,mixed> */
	private function mapping_row( array $item ): array {
		return array(
			'source_scope'    => $item['source_scope'],
			'environment'     => $item['environment'],
			'wc_product_id'   => $item['target_wc_product_id'],
			'wc_variation_id' => $item['target_wc_variation_id'],
			'ea_product_id'   => $item['ea_product_id'],
			'ea_option_id'    => $item['ea_option_id'],
			'upc'             => $item['normalized_upc'],
			'normalized_upc'  => $item['normalized_upc'],
			'mapping_status'  => 'active',
			'last_synced_at'  => null,
			'created_at'      => $GLOBALS['ea_now'],
			'updated_at'      => $GLOBALS['ea_now'],
		);
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
			'policy_version'         => ImportPolicy::VERSION,
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
