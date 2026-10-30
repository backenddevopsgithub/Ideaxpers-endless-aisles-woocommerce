<?php
namespace IdeaXperts\EndlessAisles\Tests\Inventory;

use IdeaXperts\EndlessAisles\Inventory\InventoryRepository;
use PHPUnit\Framework\TestCase;

final class InventoryRepositoryTest extends TestCase {
	private LockWpdb $wpdb;

	protected function setUp(): void {
		$GLOBALS['ea_test_options']       = array();
		$GLOBALS['ea_add_option_failure'] = false;
		$this->wpdb                       = new LockWpdb();
		$GLOBALS['wpdb']                  = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_lock_is_exclusive_until_released(): void {
		$repository = new InventoryRepository();

		$token = $repository->acquire_lock( 120 );
		self::assertNotSame( '', $token );
		self::assertSame( '', $repository->acquire_lock( 120 ) );

		$repository->release_lock( 'not-the-owner' );
		self::assertSame( '', $repository->acquire_lock( 120 ) );

		$repository->release_lock( $token );
		self::assertNotSame( '', $repository->acquire_lock( 120 ) );
	}

	public function test_expired_lock_is_taken_over_and_old_owner_cannot_refresh_or_release(): void {
		$repository                                                  = new InventoryRepository();
		$GLOBALS['ea_test_options'][ InventoryRepository::LOCK_OPTION ] = 'stale-owner|' . ( time() - 1 );

		$token = $repository->acquire_lock( 120 );

		self::assertNotSame( '', $token );
		self::assertFalse( $repository->refresh_lock( 'stale-owner', 120 ) );
		$repository->release_lock( 'stale-owner' );
		self::assertStringStartsWith( $token . '|', (string) $GLOBALS['ea_test_options'][ InventoryRepository::LOCK_OPTION ] );
	}

	public function test_same_second_refresh_is_not_mistaken_for_lost_ownership(): void {
		$repository = new InventoryRepository();
		$token      = $repository->acquire_lock( 120 );

		// The rewritten value is identical within one second, so MySQL would report zero changed rows.
		self::assertTrue( $repository->refresh_lock( $token, 120 ) );
		self::assertTrue( $repository->refresh_lock( $token, 120 ) );
	}

	public function test_cursor_round_trips_and_rejects_corrupt_values(): void {
		$repository = new InventoryRepository();
		$repository->save_cursor( 3, 50, array( 'processed' => 50 ) );

		self::assertSame( array( 'run_id' => 3, 'after_id' => 50, 'counts' => array( 'processed' => 50 ) ), $repository->cursor() );

		$GLOBALS['ea_test_options'][ InventoryRepository::CURSOR_OPTION ] = array( 'run_id' => '3', 'after_id' => 50, 'counts' => array() );
		self::assertNull( $repository->cursor() );

		$repository->clear_cursor();
		self::assertNull( $repository->cursor() );
	}

	public function test_mapping_query_is_scoped_to_active_simple_mappings_after_cursor(): void {
		( new InventoryRepository() )->active_mappings( 'endless-aisles:qa', 'qa', 50, 50 );

		$query = $this->wpdb->last_results_query;
		self::assertStringContainsString( 'source_scope = %s AND environment = %s AND mapping_status = %s AND wc_variation_id = 0', $query );
		self::assertStringContainsString( 'id > %d ORDER BY id ASC LIMIT %d', $query );
		self::assertSame( array( 'endless-aisles:qa', 'qa', 'active', 50, 50 ), $this->wpdb->last_results_args );
	}
}

/** Options-table fake: get_var/UPDATE/DELETE operate on the same store add_option() writes. */
final class LockWpdb {
	public string $prefix     = 'wp_';
	public string $options    = 'wp_options';
	public int $rows_affected = 0;
	public int $insert_id     = 0;
	public string $last_results_query = '';
	/** @var list<mixed> */
	public array $last_results_args = array();
	/** @var array<string,array{string,list<mixed>}> */
	private array $prepared = array();

	public function prepare( string $query, mixed ...$args ): string {
		$key                    = 'prepared:' . count( $this->prepared );
		$this->prepared[ $key ] = array( $query, $args );
		return $key;
	}

	public function get_var( string $key ): mixed {
		$args = $this->prepared[ $key ][1];
		return $GLOBALS['ea_test_options'][ $args[0] ] ?? null;
	}

	public function query( string $key ): int {
		list( $query, $args ) = $this->prepared[ $key ];
		$this->rows_affected   = 0;
		if ( str_starts_with( $query, 'UPDATE' ) ) {
			list( $replacement, $name, $expected ) = $args;
			if ( ( $GLOBALS['ea_test_options'][ $name ] ?? null ) === $expected && $expected !== $replacement ) {
				$GLOBALS['ea_test_options'][ $name ] = $replacement;
				$this->rows_affected                 = 1;
			}
		} elseif ( str_starts_with( $query, 'DELETE' ) ) {
			list( $name, $expected ) = $args;
			if ( ( $GLOBALS['ea_test_options'][ $name ] ?? null ) === $expected ) {
				unset( $GLOBALS['ea_test_options'][ $name ] );
				$this->rows_affected = 1;
			}
		}
		return $this->rows_affected;
	}

	/** @return list<array<string,mixed>> */
	public function get_results( string $key, mixed $output = null ): array {
		list( $this->last_results_query, $this->last_results_args ) = $this->prepared[ $key ];
		return array();
	}
}
