<?php
namespace IdeaXperts\EndlessAisles\Tests\Database;

use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use PHPUnit\Framework\TestCase;

final class DryRunRepositoryTest extends TestCase {
	private DryRunMemoryWpdb $wpdb;
	private DryRunRepository $runs;

	protected function setUp(): void {
		$this->wpdb                 = new DryRunMemoryWpdb();
		$GLOBALS['wpdb']            = $this->wpdb;
		$GLOBALS['ea_test_options'] = array();
		$GLOBALS['ea_now']          = '2026-09-24 12:00:00';
		$this->runs                 = new DryRunRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

    public function test_discovery_summary_counts_owners_once_and_excludes_other_runs_and_skus(): void {
        $rows = array( array( 1, 0, '123', 'upc' ), array( 1, 0, '123', 'upc' ), array( 1, 0, '456', 'upc' ), array( 2, 0, '123', 'upc' ), array( 2, 3, '789', 'upc' ), array( 4, 0, '111', 'sku' ) );
        foreach ( $rows as $row ) {
            $this->wpdb->insert( 'wp_ideaxperts_ea_store_identifiers', array( 'run_id' => 1, 'wc_product_id' => $row[0], 'wc_variation_id' => $row[1], 'normalized_identifier' => $row[2], 'identifier_type' => $row[3] ) );
        }
        $this->wpdb->insert( 'wp_ideaxperts_ea_store_identifiers', array( 'run_id' => 2, 'wc_product_id' => 5, 'wc_variation_id' => 0, 'normalized_identifier' => '123', 'identifier_type' => 'upc' ) );
        self::assertSame( array( 'upc_records' => 5, 'owners_with_upc' => 3, 'duplicate_upcs' => 1, 'conflicting_owners' => 1 ), $this->runs->discovery_summary( 1 ) );
        $this->wpdb->fail_read_contains = 'identifier_type';
        self::assertNull( $this->runs->discovery_summary( 1 ) );
    }

    public function test_map_summary_agrees_with_price_inspector_for_numeric_strings(): void {
        $this->wpdb->tables['wp_ideaxperts_ea_dry_run_items'] = array(
            array( 'id' => 1, 'run_id' => 1, 'classification' => 'manual_review', 'review_flags' => '["suspicious_price"]', 'retail_price' => '1e1', 'map_price' => '20' ),
            array( 'id' => 2, 'run_id' => 1, 'classification' => 'manual_review', 'review_flags' => '["suspicious_price"]', 'retail_price' => ' +9.5 ', 'map_price' => '10' ),
        );
        foreach ( $this->wpdb->tables['wp_ideaxperts_ea_dry_run_items'] as $row ) {
            self::assertContains( 'retail_below_map', \IdeaXperts\EndlessAisles\Catalog\PriceInspector::warnings( array( 'price' => $row['retail_price'], 'minimum_advertised_price' => $row['map_price'] ) ) );
        }
        self::assertSame( 2, $this->runs->review_summary( 1 )['retail_below_map'] );
    }
    public function test_review_summary_counts_distinct_items_without_summing_overlapping_flags(): void {
        $this->wpdb->tables['wp_ideaxperts_ea_dry_run_items'] = array(
            array( 'run_id' => 1, 'classification' => 'manual_review', 'review_flags' => '["suspicious_price","duplicate_vendor_upc"]', 'retail_price' => '9.50', 'map_price' => '10' ),
            array( 'run_id' => 1, 'classification' => 'new_product_candidate', 'review_flags' => '["discontinued"]', 'retail_price' => 'bad', 'map_price' => '20' ),
            array( 'run_id' => 1, 'classification' => 'exact_upc_match', 'review_flags' => '[]', 'retail_price' => '30', 'map_price' => '20' ),
            array( 'run_id' => 2, 'classification' => 'manual_review', 'review_flags' => '[]', 'retail_price' => '1', 'map_price' => '2' ),
        );
        self::assertSame( array( 'review_required' => 2, 'retail_below_map' => 1 ), $this->runs->review_summary( 1 ) );
        $this->wpdb->fail_read_contains = 'AS review_required';
        self::assertNull( $this->runs->review_summary( 1 ) );
    }
	public function test_mappings_require_exact_product_and_option_identity(): void {
		$this->wpdb->insert(
			'wp_ideaxperts_ea_mappings',
			array(
				'source_scope'    => 'endless-aisles:qa',
				'ea_product_id'   => '10',
				'ea_option_id'    => '20',
				'mapping_status'  => 'active',
				'wc_product_id'   => 1,
				'wc_variation_id' => 0,
			)
		);
		$this->wpdb->insert(
			'wp_ideaxperts_ea_mappings',
			array(
				'source_scope'    => 'endless-aisles:qa',
				'ea_product_id'   => '10',
				'ea_option_id'    => '21',
				'mapping_status'  => 'active',
				'wc_product_id'   => 2,
				'wc_variation_id' => 0,
			)
		);

		$matches = $this->runs->mappings( '10', '21' );

		self::assertCount( 1, $matches );
		self::assertSame( '21', $matches[0]['ea_option_id'] );
		self::assertSame( array(), $this->runs->mappings( '10', '99' ) );
	}

	public function test_mapping_lookup_never_adopts_a_different_environment(): void {
		$this->wpdb->insert(
			'wp_ideaxperts_ea_mappings',
			array(
				'source_scope'    => 'endless-aisles:production',
				'ea_product_id'   => '10',
				'ea_option_id'    => '20',
				'mapping_status'  => 'active',
				'wc_product_id'   => 1,
				'wc_variation_id' => 0,
			)
		);

		self::assertSame( array(), $this->runs->mappings( '10', '20', 'endless-aisles:qa' ) );
		self::assertCount( 1, $this->runs->mappings( '10', '20', 'endless-aisles:production' ) );
	}

	public function test_vendor_duplicate_flags_do_not_change_classification(): void {
		$run_id = $this->active_run();
		$this->runs->item(
			$run_id,
			array(
				'ea_product_id'  => '10',
				'ea_option_id'   => '20',
				'normalized_upc' => '001234567890',
				'classification' => 'already_linked',
				'review_flags'   => array(),
			),
			$this->runs->claim_token( $run_id )
		);
		$this->runs->item(
			$run_id,
			array(
				'ea_product_id'  => '11',
				'ea_option_id'   => '22',
				'normalized_upc' => '001234567890',
				'classification' => 'new_product_candidate',
				'review_flags'   => array(),
			),
			$this->runs->claim_token( $run_id )
		);

		self::assertTrue( $this->runs->vendor_upc_used_by_other_option( $run_id, '001234567890', '11', '22' ) );
		$this->runs->add_review_flag_for_upc( $run_id, '001234567890', 'duplicate_vendor_upc', $this->runs->claim_token( $run_id ) );

		$items = $this->runs->items( $run_id );
		self::assertSame( 'already_linked', $items[0]['classification'] );
		self::assertSame( 'new_product_candidate', $items[1]['classification'] );
		self::assertSame( array( 'duplicate_vendor_upc' => 2 ), $this->runs->flag_counts( $run_id ) );
	}

	public function test_store_counts_are_recomputed_from_unique_identities(): void {
		$run_id = $this->active_run();
		$this->runs->store_identifier(
			$run_id,
			array(
				'wc_product_id'         => 8,
				'wc_variation_id'       => 0,
				'identifier_type'       => 'upc',
				'identifier_source'     => 'global_unique_id',
				'normalized_identifier' => '001',
			),
			$this->runs->claim_token( $run_id )
		);
		$this->runs->store_identifier(
			$run_id,
			array(
				'wc_product_id'         => 8,
				'wc_variation_id'       => 0,
				'identifier_type'       => 'upc',
				'identifier_source'     => 'meta:upc',
				'normalized_identifier' => '001',
			),
			$this->runs->claim_token( $run_id )
		);
		$this->runs->store_identifier(
			$run_id,
			array(
				'wc_product_id'         => 8,
				'wc_variation_id'       => 9,
				'identifier_type'       => 'upc',
				'identifier_source'     => 'global_unique_id',
				'normalized_identifier' => '002',
			),
			$this->runs->claim_token( $run_id )
		);

		self::assertSame(
			array(
				'products'   => 1,
				'variations' => 1,
			),
			$this->runs->store_record_counts( $run_id )
		);
	}

	public function test_repeated_sources_for_one_woocommerce_identity_are_one_store_match(): void {
		$run_id = $this->active_run();
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 11, 'global_unique_id', '001234567891' ), $this->runs->claim_token( $run_id ) );
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 11, 'meta:upc', '001234567891' ), $this->runs->claim_token( $run_id ) );

		$matches = $this->runs->identifier_matches( $run_id, '001234567891', 'upc' );
		self::assertCount( 1, $matches );
		self::assertSame( 5, (int) $matches[0]['wc_product_id'] );
		self::assertSame( 11, (int) $matches[0]['wc_variation_id'] );
	}

	public function test_distinct_variations_sharing_a_upc_remain_duplicate_store_identities(): void {
		$run_id = $this->active_run();
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 11, 'global_unique_id', '001234567891' ), $this->runs->claim_token( $run_id ) );
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 12, 'global_unique_id', '001234567891' ), $this->runs->claim_token( $run_id ) );

		self::assertCount( 2, $this->runs->identifier_matches( $run_id, '001234567891', 'upc' ) );
	}

	public function test_parent_product_and_variation_are_distinct_identities(): void {
		$run_id = $this->active_run();
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 0, 'global_unique_id', '001234567890' ), $this->runs->claim_token( $run_id ) );
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 11, 'global_unique_id', '001234567890' ), $this->runs->claim_token( $run_id ) );

		self::assertCount( 2, $this->runs->identifier_matches( $run_id, '001234567890', 'upc' ) );
	}

	public function test_cancelled_run_rejects_later_child_writes(): void {
		$run_id = $this->active_run();
		self::assertTrue( $this->runs->heartbeat( $run_id, $this->runs->claim_token( $run_id ) ) );
		$this->runs->transition(
			$run_id,
			DryRunRepository::ACTIVE_STATUSES,
			array(
				'status'       => 'cancelled',
				'completed_at' => current_time( 'mysql', true ),
			),
			$this->runs->claim_token( $run_id )
		);

		self::assertFalse( $this->runs->store_identifier( $run_id, $this->identifier( 1, 0, 'global_unique_id', '001' ) ) );
		self::assertFalse(
			$this->runs->item(
				$run_id,
				array(
					'ea_product_id' => '1',
					'ea_option_id'  => '1',
				)
			)
		);
		$this->runs->add_review_flag_for_upc( $run_id, '001', 'duplicate_vendor_upc' );
		self::assertSame( array(), $this->runs->items( $run_id ) );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_store_identifiers'] );
	}

	public function test_older_lock_owner_cannot_release_a_newer_claim(): void {
		$first = $this->runs->claim_new( 1 );
		$old   = $this->lock_value();
		self::assertNotSame( '', $old );
		$newer = (string) wp_json_encode(
			array(
				'token'      => 'newer-owner-token',
				'run_id'     => 99,
				'claimed_at' => current_time( 'mysql', true ),
			)
		);
		$this->wpdb->tables['wp_options'][0]['option_value'] = $newer;
		$this->runs->release_lock( $first );
		self::assertSame( $newer, $this->lock_value() );
		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM wp_options WHERE option_name = %s AND option_value = %s', DryRunRepository::LOCK_OPTION, $old ) );
		self::assertSame( $newer, $this->lock_value() );
	}

	public function test_review_flags_are_allowlisted_deduplicated_and_counted(): void {
		$run_id = $this->active_run();
		self::assertTrue(
			$this->runs->item(
				$run_id,
				array(
					'ea_product_id'  => '1',
					'ea_option_id'   => '1',
					'normalized_upc' => '001234567890',
					'review_flags'   => array( 'duplicate_vendor_upc', 'duplicate_vendor_upc', 'not-a-flag', 12, true, null, array( 'x' ), 'suspicious_price' ),
				),
				$this->runs->claim_token( $run_id )
			)
		);
		$this->runs->add_review_flag_for_upc( $run_id, '001234567890', 'not-a-flag', $this->runs->claim_token( $run_id ) );
		$this->runs->add_review_flag_for_upc( $run_id, '001234567890', 'discontinued', $this->runs->claim_token( $run_id ) );
		$items = $this->runs->items( $run_id );
		self::assertSame( array( 'duplicate_vendor_upc', 'suspicious_price', 'discontinued' ), json_decode( (string) $items[0]['review_flags'], true ) );
		self::assertSame(
			array(
				'duplicate_vendor_upc' => 1,
				'suspicious_price'     => 1,
				'discontinued'         => 1,
			),
			$this->runs->flag_counts( $run_id )
		);
		self::assertContains( 'duplicate_vendor_upc', MatchClassifier::REVIEW_FLAGS );
	}

	public function test_purge_keeps_active_latest_completed_and_recent_cancelled_runs(): void {
		$old_completed = $this->runs->create( 1, 'qa' );
		$this->runs->item(
			$old_completed,
			array(
				'ea_product_id' => '1',
				'ea_option_id'  => '1',
			)
		);
		$this->runs->transition(
			$old_completed,
			array( 'pending' ),
			array(
				'status'       => 'completed',
				'completed_at' => '2026-01-01 00:00:00',
			)
		);
		$latest = $this->runs->create( 1, 'qa' );
		$this->runs->transition(
			$latest,
			array( 'pending' ),
			array(
				'status'       => 'completed',
				'completed_at' => '2026-01-02 00:00:00',
			)
		);
		$failed = $this->runs->create( 1, 'qa' );
		$this->runs->transition(
			$failed,
			array( 'pending' ),
			array(
				'status'       => 'failed',
				'completed_at' => '2026-04-01 00:00:00',
			)
		);
		$cancelled = $this->runs->create( 1, 'qa' );
		$this->runs->transition(
			$cancelled,
			array( 'pending' ),
			array(
				'status'       => 'cancelled',
				'completed_at' => '2026-07-01 00:00:00',
			)
		);
		$old_cancelled = $this->runs->create( 1, 'qa' );
		$this->runs->transition(
			$old_cancelled,
			array( 'pending' ),
			array(
				'status'       => 'cancelled',
				'completed_at' => '2026-01-01 00:00:00',
			)
		);
		$active = $this->runs->create( 1, 'qa' );

		$deleted = $this->runs->purge_expired();

		self::assertSame( 2, $deleted );
		self::assertNotNull( $this->runs->run( $latest ) );
		self::assertNotNull( $this->runs->run( $failed ) );
		self::assertNotNull( $this->runs->run( $cancelled ) );
		self::assertNotNull( $this->runs->run( $active ) );
		self::assertNull( $this->runs->run( $old_completed ) );
		self::assertNull( $this->runs->run( $old_cancelled ) );
		self::assertSame( array(), $this->runs->items( $old_completed ) );
	}

	public function test_purge_deletes_a_bounded_batch_and_reports_remaining_work(): void {
		for ( $index = 0; $index < 27; ++$index ) {
			$id = $this->runs->create( 1, 'qa' );
			$this->runs->transition(
				$id,
				array( 'pending' ),
				array(
					'status'       => 'completed',
					'completed_at' => '2026-01-01 00:00:00',
					'environment'  => 'qa',
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

		self::assertSame( DryRunRepository::PURGE_BATCH_SIZE, $this->runs->purge_expired() );
		self::assertTrue( $this->runs->has_purge_remaining() );
		self::assertNotNull( $this->runs->run( $latest ) );
	}

	public function test_stale_active_runs_are_failed_and_the_lock_is_released(): void {
		$run_id = $this->runs->claim_new( 4 );
		$this->runs->transition(
			$run_id,
			array( 'pending' ),
			array(
				'status'            => 'scanning_store',
				'last_heartbeat_at' => '2026-09-24 05:59:59',
			),
			$this->runs->claim_token( $run_id )
		);

		$this->runs->recover_stale_active();

		self::assertSame( 'recovering', $this->runs->run( $run_id )['status'] );
		self::assertNotSame( '', $this->lock_value() );
	}

	public function test_claim_new_is_exclusive(): void {
		self::assertSame( 1, $this->runs->claim_new( 1 ) );
		self::assertSame( 0, $this->runs->claim_new( 2 ) );
	}

	public function test_store_page_is_rolled_back_when_a_child_write_fails(): void {
		$run_id                         = $this->active_run();
		$token                          = $this->runs->claim_token( $run_id );
		$this->wpdb->fail_replace_after = 1;

		$ok = $this->runs->persist_store_page(
			$run_id,
			$token,
			array(
				$this->identifier( 1, 0, 'global_unique_id', '001' ),
				$this->identifier( 2, 0, 'global_unique_id', '002' ),
			),
			array( 'current_store_page' => 1 )
		);

		self::assertFalse( $ok );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_store_identifiers'] );
		self::assertSame( 0, (int) $this->runs->run( $run_id )['current_store_page'] );
		self::assertContains( 'ROLLBACK', $this->wpdb->queries );
	}

	public function test_commit_failure_and_exception_both_roll_back(): void {
		$run_id                     = $this->active_run();
		$token                      = $this->runs->claim_token( $run_id );
		$this->wpdb->fail_operation = 'commit';
		self::assertFalse( $this->runs->persist_store_page( $run_id, $token, array( $this->identifier( 1, 0, 'global_unique_id', '001' ) ), array( 'current_store_page' => 1 ) ) );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_store_identifiers'] );

		$this->wpdb->fail_operation = '';
		self::assertFalse(
			$this->runs->with_locked_run(
				$run_id,
				$token,
				DryRunRepository::ACTIVE_STATUSES,
				static function (): bool {
					throw new \RuntimeException( 'test-only' );
				}
			)
		);
		self::assertSame( 2, count( array_keys( $this->wpdb->queries, 'ROLLBACK', true ) ) );
	}

	public function test_old_claim_cannot_write_or_release_after_resume(): void {
		$run_id = $this->active_run();
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
		$new = $this->runs->claim_existing_token( $run_id );

		self::assertNotSame( '', $new );
		self::assertNotSame( $old, $new );
		self::assertFalse( $this->runs->store_identifier( $run_id, $this->identifier( 1, 0, 'global_unique_id', '001' ), $old ) );
		$this->runs->release_lock( $run_id, $old );
		self::assertSame( $new, $this->runs->claim_token( $run_id ) );
		self::assertSame( 0, $this->runs->claim_new( 2 ) );
	}

	public function test_purge_queries_are_sql_bounded(): void {
		$this->runs->has_purge_remaining();
		$this->runs->purge_expired();
		$selects = array_filter( $this->wpdb->queries, static fn( string $query ): bool => str_contains( $query, 'COALESCE(r.completed_at,r.updated_at)' ) );
		self::assertNotEmpty( $selects );
		self::assertTrue( (bool) array_filter( $selects, static fn( string $query ): bool => str_contains( $query, 'LIMIT 1' ) ) );
		self::assertTrue( (bool) array_filter( $selects, static fn( string $query ): bool => str_contains( $query, 'LIMIT ' . DryRunRepository::PURGE_BATCH_SIZE ) ) );
	}

	public function test_deactivation_during_placeholder_claim_removes_only_that_claim(): void {
		$this->wpdb->after_lock_insert = static function (): void {
			$GLOBALS['ea_test_options'][ DryRunRepository::DEACTIVATING_OPTION ] = '2026-09-24 12:00:00';
		};

		self::assertSame( 0, $this->runs->claim_new( 1 ) );
		self::assertSame( '', $this->lock_value() );
		self::assertSame( array(), $this->wpdb->tables['wp_ideaxperts_ea_dry_runs'] );
	}

	public function test_rollback_failure_poisoned_session_blocks_later_writes_and_lock_release(): void {
		$run_id                     = $this->active_run();
		$token                      = $this->runs->claim_token( $run_id );
		$generation                 = $this->runs->claim_generation( $run_id );
		$this->wpdb->fail_operation = 'rollback';

		self::assertFalse( $this->runs->with_locked_run( $run_id, $token, array( 'scanning_store' ), static fn(): bool => false, $generation ) );
		self::assertFalse( $this->runs->session_usable() );
		self::assertFalse( $this->runs->transition( $run_id, array( 'scanning_store' ), array( 'status' => 'failed' ), $token, $generation ) );
		$this->runs->release_lock( $run_id, $token, $generation );
		self::assertNotSame( '', $this->lock_value() );
	}

	public function test_aggregate_read_errors_are_explicit(): void {
		$this->wpdb->fail_read_contains = 'GROUP BY classification';
		self::assertNull( $this->runs->classification_counts( 1 ) );

		$this->wpdb->fail_read_contains = 'SELECT review_flags';
		self::assertNull( $this->runs->flag_counts( 1 ) );
	}

	public function test_only_one_callback_claims_an_intent_and_late_scheduler_recording_preserves_running(): void {
		$context = $this->store_intent();
		$first   = $this->runs->claim_intent_execution( ...$context['claim'] );
		$second  = $this->runs->claim_intent_execution( ...$context['claim'] );

		self::assertNotSame( '', $first );
		self::assertSame( '', $second );
		self::assertTrue( $this->runs->mark_intent_dispatched( $context['intent_id'], $context['intent_token'], $context['dispatch_token'], 91 ) );
		$intent = $this->runs->action_intent( $context['intent_id'], $context['intent_token'] );
		self::assertSame( 'running', $intent['status'] );
		self::assertSame( 91, (int) $intent['action_scheduler_id'] );
	}

	public function test_late_scheduler_recording_preserves_completed_intent(): void {
		$context   = $this->store_intent();
		$execution = $this->runs->claim_intent_execution( ...$context['claim'] );
		self::assertTrue( $this->runs->persist_store_page_execution( $context['run_id'], $context['claim_token'], $context['generation'], $context['intent_id'], $context['intent_token'], $execution, 'store-hook', 1, array(), array( 'current_store_page' => 1 ), null ) );

		self::assertTrue( $this->runs->mark_intent_dispatched( $context['intent_id'], $context['intent_token'], $context['dispatch_token'], 92 ) );
		$intent = $this->runs->action_intent( $context['intent_id'], $context['intent_token'] );
		self::assertSame( 'completed', $intent['status'] );
		self::assertSame( 92, (int) $intent['action_scheduler_id'] );
	}

	public function test_expired_lease_is_reclaimed_and_old_execution_token_cannot_persist(): void {
		$context           = $this->store_intent();
		$old               = $this->runs->claim_intent_execution( ...$context['claim'] );
		$GLOBALS['ea_now'] = '2026-09-24 12:31:00';
		self::assertSame( 1, $this->runs->reclaim_expired_executions() );
		self::assertNotSame( '', $this->runs->mark_intent_dispatching( $context['intent_id'], $context['intent_token'] ) );
		$new = $this->runs->claim_intent_execution( ...$context['claim'] );

		self::assertNotSame( '', $new );
		self::assertNotSame( $old, $new );
		self::assertFalse( $this->runs->persist_store_page_execution( $context['run_id'], $context['claim_token'], $context['generation'], $context['intent_id'], $context['intent_token'], $old, 'store-hook', 1, array(), array( 'current_store_page' => 1 ), null ) );
		self::assertTrue( $this->runs->persist_store_page_execution( $context['run_id'], $context['claim_token'], $context['generation'], $context['intent_id'], $context['intent_token'], $new, 'store-hook', 1, array(), array( 'current_store_page' => 1 ), null ) );
	}

	public function test_cancellation_and_cursor_mismatch_prevent_execution_persistence(): void {
		$context   = $this->store_intent();
		$execution = $this->runs->claim_intent_execution( ...$context['claim'] );
		self::assertNotSame( '', $execution );
		self::assertNotNull( $this->runs->request_cancellation( $context['run_id'], $context['generation'] ) );
		self::assertFalse( $this->runs->persist_store_page_execution( $context['run_id'], $context['claim_token'], $context['generation'], $context['intent_id'], $context['intent_token'], $execution, 'store-hook', 1, array(), array( 'current_store_page' => 1 ), null ) );

		$this->setUp();
		$context = $this->store_intent();
		$this->runs->transition( $context['run_id'], array( 'scanning_store' ), array( 'current_store_page' => 1 ), $context['claim_token'], $context['generation'] );
		self::assertSame( '', $this->runs->claim_intent_execution( ...$context['claim'] ) );
	}

	public function test_dispatch_compare_and_set_has_exactly_one_owner(): void {
		$run_id       = $this->active_run();
		$claim_token  = $this->runs->claim_token( $run_id );
		$generation   = $this->runs->claim_generation( $run_id );
		$intent_id    = $this->runs->create_action_intent( $run_id, $claim_token, $generation, 'store', 'store-hook', 1 );
		$intent_token = (string) $this->runs->action_intent( $intent_id )['intent_token'];
		$first_view   = $this->runs->dispatchable_intents();
		$second_view  = $this->runs->dispatchable_intents();

		self::assertCount( 1, $first_view );
		self::assertCount( 1, $second_view );
		$owner = $this->runs->mark_intent_dispatching( $intent_id, $intent_token );
		self::assertNotSame( '', $owner );
		self::assertSame( '', $this->runs->mark_intent_dispatching( $intent_id, $intent_token ) );
		self::assertSame( 'dispatching', $this->runs->action_intent( $intent_id )['status'] );
		self::assertSame( $owner, $this->runs->action_intent( $intent_id )['dispatch_token'] );
	}

	public function test_stale_dispatch_is_reclaimed_once_and_old_owner_is_fenced(): void {
		$run_id            = $this->active_run();
		$claim_token       = $this->runs->claim_token( $run_id );
		$generation        = $this->runs->claim_generation( $run_id );
		$intent_id         = $this->runs->create_action_intent( $run_id, $claim_token, $generation, 'store', 'store-hook', 1 );
		$intent_token      = (string) $this->runs->action_intent( $intent_id )['intent_token'];
		$old_owner         = $this->runs->mark_intent_dispatching( $intent_id, $intent_token );
		$GLOBALS['ea_now'] = '2026-09-24 12:06:00';
		$stale             = $this->runs->stale_dispatching_intents();

		self::assertCount( 1, $stale );
		self::assertTrue( $this->runs->release_expired_dispatch( $intent_id, $intent_token, $old_owner, (string) $stale[0]['dispatch_lease_expires_at'] ) );
		self::assertFalse( $this->runs->release_expired_dispatch( $intent_id, $intent_token, $old_owner, (string) $stale[0]['dispatch_lease_expires_at'] ) );
		$new_owner = $this->runs->mark_intent_dispatching( $intent_id, $intent_token );
		self::assertNotSame( '', $new_owner );
		self::assertNotSame( $old_owner, $new_owner );
		self::assertFalse( $this->runs->mark_intent_dispatched( $intent_id, $intent_token, $old_owner, 91 ) );
		self::assertFalse( $this->runs->mark_intent_dispatch_failed( $intent_id, $intent_token, $old_owner ) );
		self::assertTrue( $this->runs->mark_intent_dispatched( $intent_id, $intent_token, $new_owner, 92 ) );
	}

	public function test_expired_execution_selection_filters_before_limit_and_honours_boundary(): void {
		$table = 'wp_ideaxperts_ea_dry_run_actions';
		for ( $id = 1; $id <= 25; ++$id ) {
			$this->wpdb->tables[ $table ][] = $this->execution_lease_row( $id, '2026-09-24 13:00:00' );
		}
		$this->wpdb->tables[ $table ][] = $this->execution_lease_row( 26, '2026-09-24 12:00:00' );

		self::assertSame( 1, $this->runs->reclaim_expired_executions() );
		self::assertSame( 'failed', $this->wpdb->tables[ $table ][25]['status'] );
		for ( $index = 0; $index < 25; ++$index ) {
			self::assertSame( 'running', $this->wpdb->tables[ $table ][ $index ]['status'] );
		}
	}

	public function test_expired_execution_selection_is_bounded_and_ordered(): void {
		$table = 'wp_ideaxperts_ea_dry_run_actions';
		for ( $id = 1; $id <= 27; ++$id ) {
			$minute                         = str_pad( (string) ( 28 - $id ), 2, '0', STR_PAD_LEFT );
			$this->wpdb->tables[ $table ][] = $this->execution_lease_row( $id, '2026-09-24 11:' . $minute . ':00' );
		}

		self::assertSame( 25, $this->runs->reclaim_expired_executions() );
		self::assertSame( 'running', $this->wpdb->tables[ $table ][0]['status'] );
		self::assertSame( 'running', $this->wpdb->tables[ $table ][1]['status'] );
		self::assertSame( 'failed', $this->wpdb->tables[ $table ][2]['status'] );
	}

	public function test_activation_reconciles_only_an_abandoned_placeholder(): void {
		$GLOBALS['ea_test_options'][ DryRunRepository::DEACTIVATING_OPTION ] = '2026-09-24 12:00:00';
		$placeholder                        = wp_json_encode(
			array(
				'token'      => 'old-placeholder-token',
				'run_id'     => 0,
				'generation' => 1,
				'claimed_at' => '2026-09-24 12:00:00',
			)
		);
		$this->wpdb->tables['wp_options'][] = array(
			'id'           => 1,
			'option_name'  => DryRunRepository::LOCK_OPTION,
			'option_value' => $placeholder,
			'autoload'     => 'no',
		);

		self::assertTrue( $this->runs->reconcile_activation_placeholder() );
		self::assertSame( '', $this->lock_value() );
		self::assertFalse( get_option( DryRunRepository::DEACTIVATING_OPTION, false ) );
	}

	public function test_activation_does_not_remove_a_valid_newer_claim(): void {
		$run_id = $this->runs->claim_new( 1 );
		$lock   = $this->lock_value();
		$GLOBALS['ea_test_options'][ DryRunRepository::DEACTIVATING_OPTION ] = '2026-09-24 12:00:00';

		self::assertTrue( $this->runs->reconcile_activation_placeholder() );
		self::assertSame( $lock, $this->lock_value() );
		self::assertSame( $run_id, $this->runs->lock_run_id() );
	}

	private function active_run(): int {
		$run_id = $this->runs->claim_new( 1 );
		$this->runs->transition( $run_id, array( 'pending' ), array( 'status' => 'scanning_store' ), $this->runs->claim_token( $run_id ) );
		return $run_id;
	}

	/** @return array{run_id:int,claim_token:string,generation:int,intent_id:int,intent_token:string,dispatch_token:string,claim:array<int,mixed>} */
	private function store_intent(): array {
		$run_id         = $this->active_run();
		$claim_token    = $this->runs->claim_token( $run_id );
		$generation     = $this->runs->claim_generation( $run_id );
		$intent_id      = $this->runs->create_action_intent( $run_id, $claim_token, $generation, 'store', 'store-hook', 1 );
		$intent         = $this->runs->action_intent( $intent_id );
		$intent_token   = (string) $intent['intent_token'];
		$dispatch_token = $this->runs->mark_intent_dispatching( $intent_id, $intent_token );
		return array(
			'run_id'         => $run_id,
			'claim_token'    => $claim_token,
			'generation'     => $generation,
			'intent_id'      => $intent_id,
			'intent_token'   => $intent_token,
			'dispatch_token' => $dispatch_token,
			'claim'          => array( $intent_id, $intent_token, $run_id, $claim_token, $generation, 'store', 'store-hook', 1, 'scanning_store', 'current_store_page' ),
		);
	}

	/** @return array<string,mixed> */
	private function execution_lease_row( int $id, string $expires ): array {
		return array(
			'id'               => $id,
			'intent_token'     => 'intent-' . $id,
			'status'           => 'running',
			'execution_token'  => 'execution-' . $id,
			'lease_expires_at' => $expires,
			'available_at'     => '2026-09-24 11:00:00',
			'updated_at'       => '2026-09-24 11:00:00',
		);
	}

	/** @return array<string,mixed> */
	private function identifier( int $product_id, int $variation_id, string $source, string $upc ): array {
		return array(
			'wc_product_id'         => $product_id,
			'wc_variation_id'       => $variation_id,
			'identifier_type'       => 'upc',
			'identifier_source'     => $source,
			'normalized_identifier' => $upc,
		);
	}

	private function lock_value(): string {
		foreach ( $this->wpdb->tables['wp_options'] as $row ) {
			if ( DryRunRepository::LOCK_OPTION === (string) ( $row['option_name'] ?? '' ) ) {
				return (string) ( $row['option_value'] ?? '' );
			}
		}
		return '';
	}
}
