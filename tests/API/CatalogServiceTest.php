<?php
namespace IdeaXperts\EndlessAisles\Tests\API;

use IdeaXperts\EndlessAisles\API\ApiClientInterface;
use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\API\CatalogService;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class CatalogServiceTest extends TestCase {
	private SettingsRepository $settings;

	protected function setUp(): void {
		$GLOBALS['ea_test_options'] = array(
			'ideaxperts_ea_settings' => array( 'environment' => 'qa', 'log_level' => 'critical' ),
		);
		$this->settings = new SettingsRepository();
		$this->settings->replace_token( 'qa', 'secret' );
	}

	public function test_documented_products_and_options_remain_grouped(): void {
		$client = new SequenceClient( array( $this->pageResponse() ) );
		$service = $this->service( $client );
		$page = $service->page( 1 );

		self::assertSame( 1, $page['current_page'] );
		self::assertNull( $page['next_page'] );
		self::assertSame( 'product-1', $page['products'][0]['id'] );
		self::assertCount( 2, $page['products'][0]['sizes'] );
		self::assertSame( '001234567890', $page['products'][0]['sizes'][0]['upc'] );
		self::assertSame( array( array( 'GET', '/api/products?page=1&per_page=10' ) ), $client->requests );
	}

	public function test_numeric_vendor_upc_is_preserved_as_a_json_number(): void {
		$client = new SequenceClient(
			array(
				array(
					'current_page'  => 1,
					'per_page'      => 10,
					'next_page_url' => null,
					'data'          => array(
						(object) array(
							'id'    => 'product-1',
							'title' => 'Display only',
							'sizes' => array(
								(object) array(
									'id'  => 'option-1',
									'upc' => 123456789012,
								),
							),
						),
					),
				),
			)
		);
		$page = $this->service( $client )->page( 1 );
		self::assertSame( 123456789012, $page['products'][0]['sizes'][0]['upc'] );
	}

	public function test_retryable_failures_use_bounded_retry_after(): void {
		$client = new SequenceClient(
			array(
				new ApiException( 'retry', array( 'retry_after' => 999 ), 429 ),
				new ApiException( 'server', array(), 503 ),
				$this->pageResponse(),
			)
		);
		$sleeps = array();
		$this->service( $client, static function ( int $seconds ) use ( &$sleeps ): void { $sleeps[] = $seconds; } )->page( 1 );
		self::assertSame( array( CatalogService::MAX_RETRY_AFTER, 2 ), $sleeps );
		self::assertCount( 3, $client->requests );
	}

	public function test_authentication_failure_is_not_retried(): void {
		$client = new SequenceClient( array( new ApiException( 'auth', array(), 401 ) ) );
		try {
			$this->service( $client )->page( 1 );
			self::fail( 'Expected authentication failure.' );
		} catch ( ApiException $exception ) {
			self::assertSame( 401, $exception->getCode() );
			self::assertCount( 1, $client->requests );
		}
	}

	public function test_production_is_rejected_before_any_request(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['environment'] = 'production';
		$client = new SequenceClient( array() );
		$this->expectException( ApiException::class );
		try {
			$this->service( $client )->page( 1 );
		} finally {
			self::assertSame( array(), $client->requests );
		}
	}

	private function service( SequenceClient $client, ?\Closure $sleeper = null ): CatalogService {
		return new CatalogService( $this->settings, new BaseUrlResolver(), new DatabaseLogger(), static fn(): ApiClientInterface => $client, $sleeper );
	}

	/** @return array<string,mixed> */
	private function pageResponse(): array {
		return array(
			'current_page' => 1,
			'per_page' => 10,
			'next_page_url' => null,
			'data' => array(
				(object) array(
					'id' => 'product-1',
					'title' => 'Display only',
					'sizes' => array(
						(object) array( 'id' => 'option-1', 'upc' => '001234567890', 'price' => 10, 'wholesale' => 5 ),
						(object) array( 'id' => 'option-2', 'upc' => '001234567891', 'price' => 11, 'wholesale' => 6 ),
					),
				),
			),
		);
	}
}
