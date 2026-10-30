<?php
namespace IdeaXperts\EndlessAisles\Tests\API;

use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\API\StockService;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;
use PHPUnit\Framework\TestCase;

final class StockServiceTest extends TestCase {
	private SettingsRepository $settings;
	/** @var list<int> */
	private array $sleeps = array();

	protected function setUp(): void {
		$GLOBALS['ea_test_options'] = array(
			'ideaxperts_ea_settings' => array( 'environment' => 'qa', 'log_level' => 'critical' ),
		);
		$this->settings = new SettingsRepository();
		$this->settings->replace_token( 'qa', 'secret' );
		$this->sleeps = array();
	}

	public function test_documented_object_body_maps_quantities_and_reports_missing_skus(): void {
		$client = new SequenceClient( array( array( 'data' => (object) array( '24752' => 149, '24751' => 0 ) ) ) );

		$result = $this->service( $client )->quantities( 'qa', array( '24752', '24751', '24750' ) );

		self::assertSame( array( '24752' => 149, '24751' => 0 ), $result['quantities'] );
		self::assertSame( array( '24750' ), $result['missing'] );
		self::assertSame( array(), $result['invalid'] );
		self::assertSame( array( array( 'GET', '/api/stock?sku=24752,24751,24750' ) ), $client->requests );
	}

	public function test_documented_empty_list_means_every_sku_is_missing(): void {
		$result = StockService::parse( array( 'data' => array() ), array( '1', '2' ) );

		self::assertSame( array(), $result['quantities'] );
		self::assertSame( array( '1', '2' ), $result['missing'] );
	}

	public function test_non_integer_or_negative_quantities_are_invalid_not_zero(): void {
		$result = StockService::parse( array( 'data' => (object) array( '1' => '5', '2' => -1, '3' => 2.5, '4' => 7 ) ), array( '1', '2', '3', '4' ) );

		self::assertSame( array( '4' => 7 ), $result['quantities'] );
		self::assertSame( array( '1', '2', '3' ), $result['invalid'] );
	}

	public function test_malformed_bodies_are_rejected(): void {
		foreach ( array( array(), array( 'data' => 'x' ), array( 'data' => array( 5, 6 ) ), array( 'data' => null ) ) as $body ) {
			try {
				StockService::parse( $body, array( '1' ) );
				self::fail( 'Malformed stock body was accepted.' );
			} catch ( ApiException $exception ) {
				self::assertStringContainsString( 'unexpected stock structure', $exception->getMessage() );
			}
		}
	}

	public function test_transient_failures_retry_then_succeed(): void {
		$client = new SequenceClient( array( new ApiException( 'Endless Aisles returned an unsuccessful response.', array(), 503 ), array( 'data' => (object) array( '9' => 3 ) ) ) );

		$result = $this->service( $client )->quantities( 'qa', array( '9' ) );

		self::assertSame( array( '9' => 3 ), $result['quantities'] );
		self::assertCount( 2, $client->requests );
		self::assertSame( array( 1 ), $this->sleeps );
	}

	public function test_authentication_failure_is_not_retried(): void {
		$client = new SequenceClient( array( new ApiException( 'Endless Aisles returned an unsuccessful response.', array(), 401 ) ) );

		$this->expectException( ApiException::class );
		try {
			$this->service( $client )->quantities( 'qa', array( '9' ) );
		} finally {
			self::assertCount( 1, $client->requests );
		}
	}

	public function test_guards_reject_before_any_request(): void {
		$cases = array(
			'environment mismatch'   => static fn( StockService $service ) => $service->quantities( 'production', array( '1' ) ),
			'empty batch'            => static fn( StockService $service ) => $service->quantities( 'qa', array() ),
			'oversized batch'        => static fn( StockService $service ) => $service->quantities( 'qa', array_map( 'strval', range( 1, StockService::BATCH_SIZE + 1 ) ) ),
			'unsafe sku'             => static fn( StockService $service ) => $service->quantities( 'qa', array( '1&sku=2' ) ),
		);
		foreach ( $cases as $label => $call ) {
			$client = new SequenceClient( array() );
			try {
				$call( $this->service( $client ) );
				self::fail( $label . ' was accepted.' );
			} catch ( ApiException ) {
				self::assertSame( array(), $client->requests, $label );
			}
		}
	}

	public function test_production_requires_safeguard_and_token(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['environment'] = 'production';
		$client = new SequenceClient( array() );
		try {
			$this->service( $client )->quantities( 'production', array( '1' ) );
			self::fail( 'Unconfirmed Production stock lookup was accepted.' );
		} catch ( ApiException ) {
			self::assertSame( array(), $client->requests );
		}

		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['production_confirmed'] = SettingsValidator::PRODUCTION_CONFIRMED;
		try {
			$this->service( $client )->quantities( 'production', array( '1' ) );
			self::fail( 'Production stock lookup without a token was accepted.' );
		} catch ( ApiException $exception ) {
			self::assertStringContainsString( 'credential', $exception->getMessage() );
			self::assertSame( array(), $client->requests );
		}
	}

	private function service( SequenceClient $client ): StockService {
		return new StockService(
			$this->settings,
			new BaseUrlResolver(),
			new DatabaseLogger(),
			static fn(): SequenceClient => $client,
			function ( int $seconds ): void {
				$this->sleeps[] = $seconds;
			}
		);
	}
}
