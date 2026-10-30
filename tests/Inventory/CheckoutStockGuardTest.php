<?php
namespace IdeaXperts\EndlessAisles\Tests\Inventory;

use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\API\StockService;
use IdeaXperts\EndlessAisles\Inventory\CheckoutStockGuard;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Tests\API\SequenceClient;
use IdeaXperts\EndlessAisles\Tests\Support\MemoryInventoryStore;
use IdeaXperts\EndlessAisles\Tests\Support\RecordingStockWriter;
use PHPUnit\Framework\TestCase;

final class CheckoutStockGuardTest extends TestCase {
	private MemoryInventoryStore $store;
	private RecordingStockWriter $writer;

	protected function setUp(): void {
		$GLOBALS['ea_test_options'] = array(
			'ideaxperts_ea_settings' => array(
				'enabled'     => 'yes',
				'environment' => 'qa',
				'log_level'   => 'critical',
			),
		);
		( new SettingsRepository() )->replace_token( 'qa', 'secret' );
		$this->store           = new MemoryInventoryStore();
		$this->store->mappings = array(
			array( 'id' => 1, 'wc_product_id' => 701, 'ea_product_id' => 'p1', 'ea_option_id' => '501' ),
			array( 'id' => 2, 'wc_product_id' => 702, 'ea_product_id' => 'p2', 'ea_option_id' => '502' ),
		);
		$this->writer          = new RecordingStockWriter();
		unset( $GLOBALS['ea_wc'] );
	}

	public function test_carts_without_mapped_products_never_call_the_vendor(): void {
		$client = new SequenceClient( array() );

		self::assertSame( array(), $this->guard( $client )->problems( array( $this->line( 999, 5 ) ) ) );
		self::assertSame( array(), $client->requests );
	}

	public function test_sufficient_live_stock_allows_checkout_and_refreshes_local_stock(): void {
		$client = new SequenceClient( array( array( 'data' => (object) array( '501' => 4 ) ) ) );

		self::assertSame( array(), $this->guard( $client )->problems( array( $this->line( 701, 4 ), $this->line( 999, 1 ) ) ) );
		self::assertSame( array( '501' => 4 ), $this->writer->applied );
		self::assertSame( array( array( 'GET', '/api/stock?sku=501' ) ), $client->requests );
	}

	public function test_stock_that_disappeared_after_the_last_sync_blocks_checkout(): void {
		$client = new SequenceClient( array( array( 'data' => (object) array( '501' => 2, '502' => 0 ) ) ) );

		$problems = $this->guard( $client )->problems( array( $this->line( 701, 3, 'Salmon Dog Food' ), $this->line( 702, 1, 'Duck Treats' ) ) );

		self::assertCount( 2, $problems );
		self::assertStringContainsString( 'Only 2 of "Salmon Dog Food"', $problems[0] );
		self::assertStringContainsString( '"Duck Treats" is no longer available', $problems[1] );
	}

	public function test_sku_omitted_by_vendor_is_unavailable(): void {
		$client = new SequenceClient( array( array( 'data' => array() ) ) );

		$problems = $this->guard( $client )->problems( array( $this->line( 701, 1, 'Salmon Dog Food' ) ) );

		self::assertCount( 1, $problems );
		self::assertStringContainsString( 'no longer available', $problems[0] );
	}

	public function test_repeated_lines_for_one_product_are_checked_against_their_total(): void {
		$client = new SequenceClient( array( array( 'data' => (object) array( '501' => 3 ) ) ) );

		self::assertCount( 1, $this->guard( $client )->problems( array( $this->line( 701, 2 ), $this->line( 701, 2 ) ) ) );
	}

	public function test_vendor_failure_blocks_by_default_and_allows_only_when_configured(): void {
		$failure = static fn(): SequenceClient => new SequenceClient( array( new ApiException( 'Endless Aisles returned an unsuccessful response.', array(), 401 ) ) );

		$problems = $this->guard( $failure() )->problems( array( $this->line( 701, 1 ) ) );
		self::assertCount( 1, $problems );
		self::assertStringContainsString( 'could not confirm stock', $problems[0] );
		self::assertSame( array(), $this->writer->applied );

		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['checkout_unverified'] = 'allow';
		self::assertSame( array(), $this->guard( $failure() )->problems( array( $this->line( 701, 1 ) ) ) );
	}

	public function test_disabled_integration_cannot_verify_and_never_calls_the_vendor(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['enabled'] = 'no';
		$client = new SequenceClient( array() );

		self::assertCount( 1, $this->guard( $client )->problems( array( $this->line( 701, 1 ) ) ) );
		self::assertSame( array(), $client->requests );
	}

	public function test_malformed_vendor_quantity_is_unverified_not_available(): void {
		$client = new SequenceClient( array( array( 'data' => (object) array( '501' => '9' ) ) ) );

		$problems = $this->guard( $client )->problems( array( $this->line( 701, 1, 'Salmon Dog Food' ) ) );

		self::assertCount( 1, $problems );
		self::assertStringContainsString( 'could not confirm stock for "Salmon Dog Food"', $problems[0] );
		self::assertSame( array(), $this->writer->applied );
	}

	public function test_failed_local_refresh_never_unblocks_a_shortage(): void {
		$this->writer->outcomes['501'] = 'throw';
		$client                        = new SequenceClient( array( array( 'data' => (object) array( '501' => 0 ) ) ) );

		self::assertCount( 1, $this->guard( $client )->problems( array( $this->line( 701, 1 ) ) ) );
	}

	public function test_variation_lines_are_outside_the_simple_product_mapping_contract(): void {
		$client = new SequenceClient( array() );
		$line   = $this->line( 701, 1 );
		$line['variation_id'] = 55;

		self::assertSame( array(), $this->guard( $client )->problems( array( $line ) ) );
		self::assertSame( array(), $client->requests );
	}

	public function test_classic_checkout_adds_errors_from_the_live_cart(): void {
		$GLOBALS['ea_wc'] = (object) array(
			'cart' => new class() {
				/** @return array<string,array<string,mixed>> */
				public function get_cart(): array {
					return array(
						'k' => array(
							'product_id'   => 701,
							'variation_id' => 0,
							'quantity'     => 2,
							'data'         => new class() {
								public function get_name(): string {
									return 'Salmon Dog Food';
								}
							},
						),
					);
				}
			},
		);
		$errors = new RecordingErrors();

		$this->guard( new SequenceClient( array( array( 'data' => (object) array( '501' => 1 ) ) ) ) )->validate_classic_checkout( array(), $errors );

		self::assertSame( CheckoutStockGuard::ERROR_CODE, $errors->codes[0] ?? '' );
		self::assertStringContainsString( 'Only 1 of "Salmon Dog Food"', $errors->messages[0] ?? '' );
	}

	public function test_block_checkout_throws_before_payment_and_passes_when_stock_suffices(): void {
		$order = new class() {
			/** @return list<object> */
			public function get_items(): array {
				return array(
					new class() {
						public function get_product_id(): int {
							return 701;
						}
						public function get_variation_id(): int {
							return 0;
						}
						public function get_quantity(): int {
							return 2;
						}
						public function get_name(): string {
							return 'Salmon Dog Food';
						}
					},
				);
			}
		};

		$this->guard( new SequenceClient( array( array( 'data' => (object) array( '501' => 2 ) ) ) ) )->validate_store_api_checkout( $order );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Only 1 of "Salmon Dog Food"' );
		$this->guard( new SequenceClient( array( array( 'data' => (object) array( '501' => 1 ) ) ) ) )->validate_store_api_checkout( $order );
	}

	/** @return array{product_id:int,variation_id:int,quantity:int,name:string} */
	private function line( int $product_id, int $quantity, string $name = 'Item' ): array {
		return array(
			'product_id'   => $product_id,
			'variation_id' => 0,
			'quantity'     => $quantity,
			'name'         => $name,
		);
	}

	private function guard( SequenceClient $client ): CheckoutStockGuard {
		$settings = new SettingsRepository();
		$stock    = new StockService( $settings, new BaseUrlResolver(), new DatabaseLogger(), static fn(): SequenceClient => $client, static function (): void {} );
		return new CheckoutStockGuard( $settings, $stock, $this->store, $this->writer, new DatabaseLogger() );
	}
}

final class RecordingErrors extends \WP_Error {
	/** @var list<string> */
	public array $codes = array();
	/** @var list<string> */
	public array $messages = array();

	public function __construct() {
		parent::__construct( '' );
	}

	public function add( string $code, string $message ): void {
		$this->codes[]    = $code;
		$this->messages[] = $message;
	}
}
