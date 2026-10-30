<?php
namespace IdeaXperts\EndlessAisles\Tests\Inventory;

use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\API\StockService;
use IdeaXperts\EndlessAisles\Inventory\InventoryStoreInterface;
use IdeaXperts\EndlessAisles\Inventory\InventorySync;
use IdeaXperts\EndlessAisles\Inventory\StockWriterInterface;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Tests\API\SequenceClient;
use PHPUnit\Framework\TestCase;

final class InventorySyncTest extends TestCase {
	private MemoryInventoryStore $store;
	private RecordingStockWriter $writer;
	private int $now = 1000;
	private int $continuations = 0;

	protected function setUp(): void {
		$GLOBALS['ea_test_options'] = array(
			'ideaxperts_ea_settings' => array( 'environment' => 'qa', 'log_level' => 'critical' ),
		);
		( new SettingsRepository() )->replace_token( 'qa', 'secret' );
		$this->store         = new MemoryInventoryStore();
		$this->writer        = new RecordingStockWriter();
		$this->now           = 1000;
		$this->continuations = 0;
	}

	public function test_vendor_quantities_are_applied_and_missing_skus_become_zero(): void {
		$this->store->mappings = array( $this->mapping( 1, '501' ), $this->mapping( 2, '502' ), $this->mapping( 3, '503' ) );
		$client                = new SequenceClient( array( array( 'data' => (object) array( '501' => 7, '502' => 0 ) ) ) );

		$status = $this->sync( $client )->run( 'qa' );

		self::assertSame( InventorySync::COMPLETED, $status );
		self::assertSame( array( '501' => 7, '502' => 0, '503' => 0 ), $this->writer->applied );
		self::assertSame( array( 1, 2, 3 ), $this->store->synced );
		self::assertSame( InventorySync::COMPLETED, $this->store->runs[1]['status'] );
		self::assertStringContainsString( 'not_found=1', $this->store->runs[1]['message'] );
		self::assertSame( 3, $this->store->runs[1]['counts']['success'] );
		self::assertNull( $this->store->cursor() );
		self::assertSame( array( 'qa' ), $this->store->successes );
		self::assertSame( '', $this->store->lock );
		self::assertSame( array( array( 'GET', '/api/stock?sku=501,502,503' ) ), $client->requests );
	}

	public function test_api_failure_fails_the_run_and_leaves_stock_untouched(): void {
		$this->store->mappings = array( $this->mapping( 1, '501' ) );
		$client                = new SequenceClient( array( new ApiException( 'Endless Aisles returned an unsuccessful response.', array(), 401 ) ) );

		$status = $this->sync( $client )->run( 'qa' );

		self::assertSame( InventorySync::FAILED, $status );
		self::assertSame( array(), $this->writer->applied );
		self::assertSame( InventorySync::FAILED, $this->store->runs[1]['status'] );
		self::assertNull( $this->store->cursor() );
		self::assertSame( array(), $this->store->successes );
		self::assertSame( '', $this->store->lock );
	}

	public function test_held_lock_prevents_overlapping_runs_and_requests(): void {
		$this->store->mappings = array( $this->mapping( 1, '501' ) );
		$this->store->lock     = 'other-worker';
		$client                = new SequenceClient( array() );

		self::assertSame( InventorySync::LOCKED, $this->sync( $client )->run( 'qa' ) );
		self::assertSame( array(), $client->requests );
		self::assertSame( array(), $this->store->runs );
		self::assertSame( 'other-worker', $this->store->lock );
	}

	public function test_time_budget_saves_cursor_and_continuation_resumes_without_reprocessing(): void {
		$first  = array();
		$second = array();
		for ( $i = 1; $i <= StockService::BATCH_SIZE; ++$i ) {
			$this->store->mappings[] = $this->mapping( $i, (string) ( 1000 + $i ) );
			$first[ (string) ( 1000 + $i ) ] = 1;
		}
		$this->store->mappings[] = $this->mapping( 51, '2051' );
		$second['2051']          = 4;
		$this->writer->on_apply  = function (): void {
			$this->now += InventorySync::TIME_BUDGET;
		};
		$client                  = new SequenceClient( array( array( 'data' => (object) $first ), array( 'data' => (object) $second ) ) );
		$sync                    = $this->sync( $client );

		self::assertSame( InventorySync::CONTINUED, $sync->run( 'qa' ) );
		self::assertSame( 1, $this->continuations );
		self::assertSame( array( 'run_id' => 1, 'after_id' => 50 ), array_slice( (array) $this->store->cursor(), 0, 2 ) );
		self::assertSame( 'running', $this->store->runs[1]['status'] );
		self::assertSame( '', $this->store->lock );

		self::assertSame( InventorySync::COMPLETED, $sync->run( 'qa' ) );
		self::assertCount( 51, $this->writer->applied );
		self::assertSame( 4, $this->writer->applied['2051'] );
		self::assertCount( 1, $this->store->runs );
		self::assertSame( 51, $this->store->runs[1]['counts']['processed'] );
		self::assertNull( $this->store->cursor() );
	}

	public function test_unowned_or_missing_products_are_failures_and_never_marked_synced(): void {
		$this->store->mappings          = array( $this->mapping( 1, '501' ), $this->mapping( 2, '502' ), $this->mapping( 3, '503' ) );
		$this->writer->outcomes['501']  = StockWriterInterface::NOT_OWNED;
		$this->writer->outcomes['502']  = StockWriterInterface::MISSING;
		$this->writer->outcomes['503']  = StockWriterInterface::STALE;
		$client                         = new SequenceClient( array( array( 'data' => (object) array( '501' => 1, '502' => 1, '503' => 1 ) ) ) );

		self::assertSame( InventorySync::PARTIAL, $this->sync( $client )->run( 'qa' ) );
		self::assertSame( array(), $this->store->synced );
		self::assertSame( 2, $this->store->runs[1]['counts']['failure'] );
		self::assertSame( 1, $this->store->runs[1]['counts']['success'] );
	}

	public function test_invalid_identifiers_are_not_sent_and_writer_exceptions_do_not_stop_the_batch(): void {
		$this->store->mappings         = array( $this->mapping( 1, 'bad id' ), $this->mapping( 2, '502' ), $this->mapping( 3, '503' ) );
		$this->writer->outcomes['502'] = 'throw';
		$client                        = new SequenceClient( array( array( 'data' => (object) array( '502' => 1, '503' => '9' ) ) ) );

		self::assertSame( InventorySync::PARTIAL, $this->sync( $client )->run( 'qa' ) );
		self::assertSame( array( array( 'GET', '/api/stock?sku=502,503' ) ), $client->requests );
		self::assertSame( array(), $this->writer->applied );
		self::assertSame( 3, $this->store->runs[1]['counts']['failure'] );
		self::assertStringContainsString( 'invalid=2', $this->store->runs[1]['message'] );
	}

	public function test_no_mappings_completes_without_vendor_request(): void {
		$client = new SequenceClient( array() );

		self::assertSame( InventorySync::COMPLETED, $this->sync( $client )->run( 'qa' ) );
		self::assertSame( array(), $client->requests );
		self::assertSame( array( 'qa' ), $this->store->successes );
	}

	public function test_lost_lock_stops_and_retains_cursor(): void {
		for ( $i = 1; $i <= StockService::BATCH_SIZE + 1; ++$i ) {
			$this->store->mappings[] = $this->mapping( $i, (string) $i );
		}
		$this->store->refresh_fails = true;
		$client                     = new SequenceClient( array( array( 'data' => array() ) ) );

		self::assertSame( InventorySync::LOCK_LOST, $this->sync( $client )->run( 'qa' ) );
		self::assertSame( 50, $this->store->cursor()['after_id'] ?? null );
		self::assertCount( 1, $client->requests );
	}

	/** @return array{id:int,wc_product_id:int,ea_product_id:string,ea_option_id:string} */
	private function mapping( int $id, string $option ): array {
		return array(
			'id'            => $id,
			'wc_product_id' => 600 + $id,
			'ea_product_id' => 'p' . $id,
			'ea_option_id'  => $option,
		);
	}

	private function sync( SequenceClient $client ): InventorySync {
		$stock = new StockService( new SettingsRepository(), new BaseUrlResolver(), new DatabaseLogger(), static fn(): SequenceClient => $client, static function (): void {} );
		return new InventorySync(
			$stock,
			$this->store,
			$this->writer,
			new DatabaseLogger(),
			fn(): int => $this->now,
			function (): bool {
				++$this->continuations;
				return true;
			}
		);
	}
}

final class RecordingStockWriter implements StockWriterInterface {
	/** @var array<string,int> */
	public array $applied = array();
	/** @var array<string,string> */
	public array $outcomes = array();
	public ?\Closure $on_apply = null;

	public function apply( array $mapping, string $environment, int $quantity, string $observed_at ): string {
		$outcome = $this->outcomes[ $mapping['ea_option_id'] ] ?? self::UPDATED;
		if ( 'throw' === $outcome ) {
			throw new \RuntimeException( 'save failed' );
		}
		if ( self::UPDATED === $outcome ) {
			$this->applied[ $mapping['ea_option_id'] ] = $quantity;
		}
		if ( $this->on_apply ) {
			( $this->on_apply )();
		}
		return $outcome;
	}
}

final class MemoryInventoryStore implements InventoryStoreInterface {
	public string $lock = '';
	public bool $refresh_fails = false;
	/** @var array{run_id:int,after_id:int,counts:array<string,int>}|null */
	public ?array $saved_cursor = null;
	/** @var list<array{id:int,wc_product_id:int,ea_product_id:string,ea_option_id:string}> */
	public array $mappings = array();
	/** @var list<int> */
	public array $synced = array();
	/** @var array<int,array{status:string,counts:array<string,int>,message:string}> */
	public array $runs = array();
	/** @var list<string> */
	public array $successes = array();

	public function acquire_lock( int $ttl_seconds ): string {
		if ( '' !== $this->lock ) {
			return '';
		}
		$this->lock = 'token';
		return 'token';
	}

	public function refresh_lock( string $token, int $ttl_seconds ): bool {
		return ! $this->refresh_fails && $token === $this->lock;
	}

	public function release_lock( string $token ): void {
		if ( $token === $this->lock ) {
			$this->lock = '';
		}
	}

	public function cursor(): ?array {
		return $this->saved_cursor;
	}

	public function save_cursor( int $run_id, int $after_id, array $counts ): void {
		$this->saved_cursor = array(
			'run_id'   => $run_id,
			'after_id' => $after_id,
			'counts'   => $counts,
		);
	}

	public function clear_cursor(): void {
		$this->saved_cursor = null;
	}

	public function active_mappings( string $scope, string $environment, int $after_id, int $limit ): array {
		$rows = array_values( array_filter( $this->mappings, static fn( array $row ): bool => $row['id'] > $after_id ) );
		return array_slice( $rows, 0, $limit );
	}

	public function mark_synced( int $mapping_id ): void {
		$this->synced[] = $mapping_id;
	}

	public function start_run(): int {
		$id                = count( $this->runs ) + 1;
		$this->runs[ $id ] = array(
			'status'  => 'running',
			'counts'  => array(),
			'message' => '',
		);
		return $id;
	}

	public function update_run( int $run_id, string $status, array $counts, string $message ): void {
		$this->runs[ $run_id ] = compact( 'status', 'counts', 'message' );
	}

	public function record_success( string $environment ): void {
		$this->successes[] = $environment;
	}
}
