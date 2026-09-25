<?php
namespace IdeaXperts\EndlessAisles\Tests\API;

use IdeaXperts\EndlessAisles\API\ApiClient;
use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class ApiClientTest extends TestCase {
	private const LIMIT = 1024;
	private const TOKEN = 'never-log-this-token';

	protected function setUp(): void {
		$GLOBALS['ea_test_options']    = array( 'ideaxperts_ea_settings' => array( 'log_level' => 'critical' ) );
		$GLOBALS['ea_remote_requests'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_normal_response_below_limit_is_decoded_and_limit_is_sent(): void {
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'Content-Length' => '11' ),
			'body'     => '{"ok":true}',
		);

		self::assertSame( array( 'ok' => true ), $this->client()->request( 'GET', '/api/products' ) );
		self::assertSame( self::LIMIT, $GLOBALS['ea_remote_requests'][0]['args']['limit_response_size'] );
	}

	public function test_nested_json_object_and_array_shapes_are_preserved(): void {
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"object":{},"list":[],"records":[{"id":1}]}',
		);

		$response = $this->client()->request( 'GET', '/api/products' );

		self::assertInstanceOf( \stdClass::class, $response['object'] );
		self::assertSame( array(), $response['list'] );
		self::assertInstanceOf( \stdClass::class, $response['records'][0] );
	}

	#[DataProvider( 'nonObjectResponses' )]
	public function test_response_envelope_must_be_a_json_object( string $body ): void {
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => $body,
		);
		$this->expectException( ApiException::class );

		$this->client()->request( 'GET', '/api/products' );
	}

	/** @return array<string,array{string}> */
	public static function nonObjectResponses(): array {
		return array(
			'empty body' => array( '' ),
			'array'      => array( '[]' ),
			'null'       => array( 'null' ),
			'string'     => array( '"text"' ),
			'integer'    => array( '123' ),
			'boolean'    => array( 'false' ),
		);
	}

	public function test_content_length_exceeding_limit_is_rejected(): void {
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-length' => (string) ( self::LIMIT + 1 ) ),
			'body'     => '{}',
		);

		$this->assertOversizedResponseIsGeneric();
	}

	public function test_downloaded_body_reaching_limit_is_rejected_before_json_decoding(): void {
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => self::TOKEN . str_repeat( 'x', self::LIMIT - strlen( self::TOKEN ) ),
		);

		$this->assertOversizedResponseIsGeneric();
	}

	public function test_transport_details_and_credentials_never_reach_logs(): void {
		$internal_message = 'cURL error 28: operation timed out with ' . self::TOKEN;
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['log_level'] = 'debug';
		$GLOBALS['wpdb']               = new class() {
			public string $prefix = 'wp_';
			/** @var list<array<string,mixed>> */
			public array $rows = array();
			public function insert( string $table, array $data, array $formats ): bool {
				$this->rows[] = $data;
				return true;
			}
			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}
			public function query( string $query ): int {
				return 0;
			}
		};
		$GLOBALS['ea_remote_response'] = new WP_Error( 'http_request_failed', $internal_message );

		try {
			$this->client()->request( 'GET', '/api/products' );
			self::fail( 'Expected an API exception.' );
		} catch ( ApiException $exception ) {
			$logged = serialize( $GLOBALS['wpdb']->rows );
			self::assertSame( 'Endless Aisles request timed out.', $exception->getMessage() );
			self::assertStringNotContainsString( $internal_message, $logged );
			self::assertStringNotContainsString( self::TOKEN, $logged );
		}
	}

	#[DataProvider( 'transportErrors' )]
	public function test_transport_errors_are_classified_without_leaking_details( string $code, string $internal_message, string $expected_message ): void {
		$GLOBALS['ea_remote_response'] = new WP_Error( $code, $internal_message );

		try {
			$this->client()->request( 'GET', '/api/products' );
			self::fail( 'Expected an API exception.' );
		} catch ( ApiException $exception ) {
			self::assertSame( $expected_message, $exception->getMessage() );
			self::assertStringNotContainsString( $internal_message, $exception->getMessage() );
			self::assertStringNotContainsString( self::TOKEN, serialize( $exception->context() ) );
		}
	}

	/** @return array<string,array{string,string,string}> */
	public static function transportErrors(): array {
		return array(
			'explicit timeout code' => array( 'http_request_timeout', 'private timeout details', 'Endless Aisles request timed out.' ),
			'curl error 28'         => array( 'http_request_failed', 'cURL error 28: Connection timed out after 15001 milliseconds ' . self::TOKEN, 'Endless Aisles request timed out.' ),
			'operation timed out'   => array( 'http_request_failed', 'The operation timed out while reading ' . self::TOKEN, 'Endless Aisles request timed out.' ),
			'unrelated failure'     => array( 'http_request_failed', 'Could not resolve host containing ' . self::TOKEN, 'Endless Aisles request failed.' ),
		);
	}

	private function client(): ApiClient {
		return new ApiClient( 'https://app-qa.endlessaisles.io', array( 'X-EA-REQUEST-TOKEN' => self::TOKEN ), new DatabaseLogger(), 15, self::LIMIT );
	}

	private function assertOversizedResponseIsGeneric(): void {
		try {
			$this->client()->request( 'GET', '/api/products' );
			self::fail( 'Expected an oversized-response exception.' );
		} catch ( ApiException $exception ) {
			self::assertSame( 'Endless Aisles response exceeded the configured size limit.', $exception->getMessage() );
			self::assertSame( 'response_too_large', $exception->context()['reason'] );
			$output = $exception->getMessage() . serialize( $exception->context() );
			self::assertStringNotContainsString( self::TOKEN, $output );
		}
	}
}
