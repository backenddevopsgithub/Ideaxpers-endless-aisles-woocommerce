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

	public function test_mappings_require_exact_product_and_option_identity(): void {
		$this->wpdb->insert(
			'wp_ideaxperts_ea_mappings',
			array(
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
			)
		);
		$this->runs->item(
			$run_id,
			array(
				'ea_product_id'  => '11',
				'ea_option_id'   => '22',
				'normalized_upc' => '001234567890',
				'classification' => 'new_product_candidate',
				'review_flags'   => array(),
			)
		);

		self::assertTrue( $this->runs->vendor_upc_used_by_other_option( $run_id, '001234567890', '11', '22' ) );
		$this->runs->add_review_flag_for_upc( $run_id, '001234567890', 'duplicate_vendor_upc' );

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
			)
		);
		$this->runs->store_identifier(
			$run_id,
			array(
				'wc_product_id'         => 8,
				'wc_variation_id'       => 0,
				'identifier_type'       => 'upc',
				'identifier_source'     => 'meta:upc',
				'normalized_identifier' => '001',
			)
		);
		$this->runs->store_identifier(
			$run_id,
			array(
				'wc_product_id'         => 8,
				'wc_variation_id'       => 9,
				'identifier_type'       => 'upc',
				'identifier_source'     => 'global_unique_id',
				'normalized_identifier' => '002',
			)
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
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 11, 'global_unique_id', '001234567891' ) );
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 11, 'meta:upc', '001234567891' ) );

		$matches = $this->runs->identifier_matches( $run_id, '001234567891', 'upc' );
		self::assertCount( 1, $matches );
		self::assertSame( 5, (int) $matches[0]['wc_product_id'] );
		self::assertSame( 11, (int) $matches[0]['wc_variation_id'] );
	}

	public function test_distinct_variations_sharing_a_upc_remain_duplicate_store_identities(): void {
		$run_id = $this->active_run();
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 11, 'global_unique_id', '001234567891' ) );
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 12, 'global_unique_id', '001234567891' ) );

		self::assertCount( 2, $this->runs->identifier_matches( $run_id, '001234567891', 'upc' ) );
	}

	public function test_parent_product_and_variation_are_distinct_identities(): void {
		$run_id = $this->active_run();
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 0, 'global_unique_id', '001234567890' ) );
		$this->runs->store_identifier( $run_id, $this->identifier( 5, 11, 'global_unique_id', '001234567890' ) );

		self::assertCount( 2, $this->runs->identifier_matches( $run_id, '001234567890', 'upc' ) );
	}

	public function test_cancelled_run_rejects_later_child_writes(): void {
		$run_id = $this->active_run();
		self::assertTrue( $this->runs->heartbeat( $run_id ) );
		$this->runs->transition( $run_id, DryRunRepository::ACTIVE_STATUSES, array( 'status' => 'cancelled', 'completed_at' => current_time( 'mysql', true ) ) );

		self::assertFalse( $this->runs->store_identifier( $run_id, $this->identifier( 1, 0, 'global_unique_id', '001' ) ) );
		self::assertFalse( $this->runs->item( $run_id, array( 'ea_product_id' => '1', 'ea_option_id' => '1' ) ) );
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
				)
			)
		);
		$this->runs->add_review_flag_for_upc( $run_id, '001234567890', 'not-a-flag' );
		$this->runs->add_review_flag_for_upc( $run_id, '001234567890', 'discontinued' );
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
		$this->runs->item( $old_completed, array( 'ea_product_id' => '1', 'ea_option_id' => '1' ) );
		$this->runs->transition( $old_completed, array( 'pending' ), array( 'status' => 'completed', 'completed_at' => '2026-01-01 00:00:00' ) );
		$latest = $this->runs->create( 1, 'qa' );
		$this->runs->transition( $latest, array( 'pending' ), array( 'status' => 'completed', 'completed_at' => '2026-01-02 00:00:00' ) );
		$failed = $this->runs->create( 1, 'qa' );
		$this->runs->transition( $failed, array( 'pending' ), array( 'status' => 'failed', 'completed_at' => '2026-04-01 00:00:00' ) );
		$cancelled = $this->runs->create( 1, 'qa' );
		$this->runs->transition( $cancelled, array( 'pending' ), array( 'status' => 'cancelled', 'completed_at' => '2026-07-01 00:00:00' ) );
		$old_cancelled = $this->runs->create( 1, 'qa' );
		$this->runs->transition( $old_cancelled, array( 'pending' ), array( 'status' => 'cancelled', 'completed_at' => '2026-01-01 00:00:00' ) );
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
			$this->runs->transition( $id, array( 'pending' ), array( 'status' => 'completed', 'completed_at' => '2026-01-01 00:00:00', 'environment' => 'qa' ) );
		}
		$latest = $this->runs->create( 1, 'qa' );
		$this->runs->transition( $latest, array( 'pending' ), array( 'status' => 'completed', 'completed_at' => '2026-09-01 00:00:00' ) );

		self::assertSame( DryRunRepository::PURGE_BATCH_SIZE, $this->runs->purge_expired() );
		self::assertTrue( $this->runs->has_purge_remaining() );
		self::assertNotNull( $this->runs->run( $latest ) );
	}

	public function test_stale_active_runs_are_failed_and_the_lock_is_released(): void {
		$run_id = $this->runs->claim_new( 4 );
		$this->runs->transition( $run_id, array( 'pending' ), array( 'status' => 'scanning_store', 'last_heartbeat_at' => '2026-09-24 05:59:59' ) );

		$this->runs->recover_stale_active();

		self::assertSame( 'failed', $this->runs->run( $run_id )['status'] );
		self::assertSame( '', $this->lock_value() );
	}

	public function test_claim_new_is_exclusive(): void {
		self::assertSame( 1, $this->runs->claim_new( 1 ) );
		self::assertSame( 0, $this->runs->claim_new( 2 ) );
	}

	private function active_run(): int {
		$run_id = $this->runs->claim_new( 1 );
		$this->runs->transition( $run_id, array( 'pending' ), array( 'status' => 'scanning_store' ) );
		return $run_id;
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
