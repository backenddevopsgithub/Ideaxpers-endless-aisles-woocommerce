<?php
namespace IdeaXperts\EndlessAisles\Tests\API;

use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\API\ConnectionTester;
use IdeaXperts\EndlessAisles\API\EndpointRegistry;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class ConnectionTesterTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ea_test_options']    = array(
			'ideaxperts_ea_settings' => array(
				'environment'          => 'qa',
				'production_confirmed' => 'no',
				'log_level'            => 'critical',
			),
		);
		$GLOBALS['ea_remote_requests'] = array();
	}

	public function test_connection_is_authenticated_read_only_and_never_calls_orders(): void {
		( new SettingsRepository() )->replace_token( 'qa', 'qa-secret' );
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"current_page":1,"data":[],"per_page":1,"next_page_url":null}',
		);

		$result  = $this->tester()->test();
		$request = $GLOBALS['ea_remote_requests'][0];

		self::assertTrue( $result->successful );
		self::assertSame( 'GET', $request['args']['method'] );
		self::assertSame( 'qa-secret', $request['args']['headers']['X-EA-REQUEST-TOKEN'] );
		self::assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
		self::assertSame( 'https://app-qa.endlessaisles.io/api/products?page=1&per_page=1', $request['url'] );
		self::assertStringNotContainsString( '/orders', $request['url'] );
		self::assertArrayNotHasKey( 'body', $request['args'] );
	}

	public function test_production_requires_confirmation_before_any_request(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['environment'] = 'production';
		( new SettingsRepository() )->replace_token( 'production', 'production-secret' );

		$result = $this->tester()->test();

		self::assertSame( 'production_confirmation_required', $result->status );
		self::assertSame( array(), $GLOBALS['ea_remote_requests'] );
	}

	public function test_confirmed_production_uses_only_the_production_credential_and_url(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['environment']          = 'production';
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['production_confirmed'] = SettingsValidator::PRODUCTION_CONFIRMED;
		$settings = new SettingsRepository();
		$settings->replace_token( 'qa', 'qa-secret' );
		$settings->replace_token( 'production', 'production-secret' );
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"current_page":1,"data":[],"per_page":1}',
		);

		self::assertTrue( $this->tester()->test()->successful );
		self::assertSame( 'https://app.endlessaisles.io/api/products?page=1&per_page=1', $GLOBALS['ea_remote_requests'][0]['url'] );
		self::assertSame( 'production-secret', $GLOBALS['ea_remote_requests'][0]['args']['headers']['X-EA-REQUEST-TOKEN'] );
	}

	public function test_timeout_is_reported_without_leaking_token(): void {
		( new SettingsRepository() )->replace_token( 'qa', 'timeout-secret' );
		$GLOBALS['ea_remote_response'] = new WP_Error( 'http_request_timeout' );

		$result = $this->tester()->test();

		self::assertSame( 'timeout', $result->status );
		self::assertStringNotContainsString( 'timeout-secret', $result->message );
	}

	public function test_malformed_json_is_rejected(): void {
		( new SettingsRepository() )->replace_token( 'qa', 'qa-secret' );
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{not-json',
		);

		self::assertSame( 'request_failed', $this->tester()->test()->status );
	}

	public function test_unexpected_product_list_structure_is_rejected(): void {
		( new SettingsRepository() )->replace_token( 'qa', 'qa-secret' );
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"data":{"not":"a-list"}}',
		);

		self::assertSame( 'unexpected_response', $this->tester()->test()->status );
	}

	public function test_production_unexpected_envelope_does_not_change_qa_connection_status(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['environment']          = 'production';
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['production_confirmed'] = SettingsValidator::PRODUCTION_CONFIRMED;
		$GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status']             = array(
			'status'    => 'connected',
			'tested_at' => '2026-09-24 11:00:00',
		);
		( new SettingsRepository() )->replace_token( 'production', 'production-secret' );
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"data":{"not":"a-list"}}',
		);

		self::assertSame( 'unexpected_response', $this->tester()->test()->status );
		self::assertSame( 'connected', $GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status']['status'] );
	}

	public function test_production_success_timeout_and_malformed_responses_never_alter_qa_readiness(): void {
		$qa_status = array(
			'status'    => 'connected',
			'tested_at' => '2026-09-24 11:00:00',
		);
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['environment']          = 'production';
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['production_confirmed'] = SettingsValidator::PRODUCTION_CONFIRMED;
		( new SettingsRepository() )->replace_token( 'production', 'production-secret' );

		$GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] = $qa_status;
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"current_page":1,"data":[],"per_page":1}',
		);
		self::assertTrue( $this->tester()->test()->successful );
		self::assertSame( $qa_status, $GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] );

		$GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] = $qa_status;
		$GLOBALS['ea_remote_response'] = new WP_Error( 'http_request_timeout' );
		self::assertSame( 'timeout', $this->tester()->test()->status );
		self::assertSame( $qa_status, $GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] );

		$GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] = $qa_status;
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{not-json',
		);
		self::assertSame( 'request_failed', $this->tester()->test()->status );
		self::assertSame( $qa_status, $GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] );
	}

	public function test_non_object_response_envelope_is_rejected_generically(): void {
		( new SettingsRepository() )->replace_token( 'qa', 'qa-secret' );
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '[]',
		);

		$result = $this->tester()->test();

		self::assertSame( 'request_failed', $result->status );
		self::assertSame( 'The connection test failed.', $result->message );
	}

	#[DataProvider( 'validEnvelopes' )]
	public function test_valid_zero_or_one_record_envelope_succeeds( string $body ): void {
		( new SettingsRepository() )->replace_token( 'qa', 'qa-secret' );
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => $body,
		);

		self::assertTrue( $this->tester()->test()->successful );
	}

	/** @return array<string,array{string}> */
	public static function validEnvelopes(): array {
		return array(
			'zero records' => array( '{"current_page":1,"data":[],"per_page":1}' ),
			'one record'   => array( '{"current_page":1,"data":[{"unvalidated":"product"}],"per_page":1}' ),
		);
	}

	#[DataProvider( 'malformedEnvelopes' )]
	public function test_malformed_connection_envelopes_are_rejected_without_exposure( string $body ): void {
		( new SettingsRepository() )->replace_token( 'qa', 'qa-secret' );
		$GLOBALS['ea_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => $body,
		);

		$result = $this->tester()->test();

		self::assertSame( 'unexpected_response', $result->status );
		self::assertSame( 'Endless Aisles returned an unexpected product-list structure.', $result->message );
		self::assertStringNotContainsString( 'private-response-value', $result->message );
	}

	/** @return array<string,array{string}> */
	public static function malformedEnvelopes(): array {
		return array(
			'missing current page'  => array( '{"data":[],"per_page":1}' ),
			'missing data'          => array( '{"current_page":1,"per_page":1}' ),
			'missing per page'      => array( '{"current_page":1,"data":[]}' ),
			'current page string'   => array( '{"current_page":"1","data":[],"per_page":1}' ),
			'current page float'    => array( '{"current_page":1.0,"data":[],"per_page":1}' ),
			'current page boolean'  => array( '{"current_page":true,"data":[],"per_page":1}' ),
			'current page zero'     => array( '{"current_page":0,"data":[],"per_page":1}' ),
			'current page negative' => array( '{"current_page":-1,"data":[],"per_page":1}' ),
			'current page mismatch' => array( '{"current_page":2,"data":[],"per_page":1}' ),
			'per page string'       => array( '{"current_page":1,"data":[],"per_page":"1"}' ),
			'per page float'        => array( '{"current_page":1,"data":[],"per_page":1.0}' ),
			'per page boolean'      => array( '{"current_page":1,"data":[],"per_page":true}' ),
			'per page zero'         => array( '{"current_page":1,"data":[],"per_page":0}' ),
			'per page negative'     => array( '{"current_page":1,"data":[],"per_page":-1}' ),
			'per page mismatch'     => array( '{"current_page":1,"data":[],"per_page":2}' ),
			'data object'           => array( '{"current_page":1,"data":{},"per_page":1}' ),
			'associative data'      => array( '{"current_page":1,"data":{"secret":"private-response-value"},"per_page":1}' ),
			'null item'             => array( '{"current_page":1,"data":[null],"per_page":1}' ),
			'string item'           => array( '{"current_page":1,"data":["private-response-value"],"per_page":1}' ),
			'integer item'          => array( '{"current_page":1,"data":[123],"per_page":1}' ),
			'boolean item'          => array( '{"current_page":1,"data":[false],"per_page":1}' ),
			'array item'            => array( '{"current_page":1,"data":[[]],"per_page":1}' ),
			'more than one record'  => array( '{"current_page":1,"data":[{},{}],"per_page":1}' ),
			'data is string'        => array( '{"current_page":1,"data":"private-response-value","per_page":1}' ),
		);
	}

	private function tester(): ConnectionTester {
		return new ConnectionTester( new EndpointRegistry(), new BaseUrlResolver(), new SettingsRepository(), new DatabaseLogger() );
	}
}
