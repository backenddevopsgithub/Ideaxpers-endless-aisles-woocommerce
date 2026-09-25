<?php
namespace IdeaXperts\EndlessAisles\Tests\Logging;

use IdeaXperts\EndlessAisles\Logging\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase {
	public function test_nested_secrets_and_authorization_headers_are_redacted(): void {
		$value = Redactor::redact(
			array(
				'headers' => array(
					'Authorization'      => 'Bearer abc123',
					'X-EA-REQUEST-TOKEN' => 'ea-secret',
					'Cookie'             => 'session=private',
				),
				'nested'  => array(
					'api_token'      => 'secret',
					'customer_email' => 'person@example.com',
				),
				'message' => 'token=abc123 api_key=xyz789 sessionid=session-value',
				'object'  => (object) array( 'password' => 'private' ),
			)
		);
		self::assertSame( '[REDACTED]', $value['headers']['Authorization'] );
		self::assertSame( '[REDACTED]', $value['headers']['X-EA-REQUEST-TOKEN'] );
		self::assertSame( '[REDACTED]', $value['headers']['Cookie'] );
		self::assertSame( '[REDACTED]', $value['nested']['api_token'] );
		self::assertSame( '[REDACTED]', $value['nested']['customer_email'] );
		self::assertSame( 'token=[REDACTED] api_key=[REDACTED] sessionid=[REDACTED]', $value['message'] );
		self::assertSame( '[REDACTED]', $value['object'] );
	}

	public function test_documented_header_is_redacted_inside_text(): void {
		self::assertSame( 'X-EA-REQUEST-TOKEN: [REDACTED]', Redactor::redact( 'X-EA-REQUEST-TOKEN: ea-secret' ) );
	}

	public function test_json_formatted_headers_are_fully_redacted(): void {
		$token  = 'json-token-must-disappear';
		$inputs = array(
			'{"X-EA-REQUEST-TOKEN":"' . $token . '"}',
			'{ "X-EA-REQUEST-TOKEN" : "' . $token . '" }',
			'{"x-ea-request-token":"' . $token . '"}',
			'prefix {"headers":{"X-Ea-Request-Token":"' . $token . '"}} suffix',
			'X-EA-REQUEST-TOKEN=' . $token,
			'X-EA-REQUEST-TOKEN: ' . $token,
		);

		foreach ( $inputs as $input ) {
			$redacted = (string) Redactor::redact( $input );
			self::assertStringNotContainsString( $token, $redacted );
			self::assertStringContainsString( '[REDACTED]', $redacted );
		}
	}

	public function test_escaped_json_headers_are_fully_redacted_recursively(): void {
		$token   = 'ea-secret-value';
		$escaped = '{\\"X-EA-REQUEST-TOKEN\\" : \\"' . $token . '\\"}';
		$inputs  = array(
			$escaped,
			'{\\"x-ea-request-token\\":\\"' . $token . '\\"}',
			array( 'metadata' => $escaped ),
			array( 'outer' => array( 'serialized' => $escaped ) ),
		);

		foreach ( $inputs as $input ) {
			$redacted = Redactor::redact( $input );
			self::assertStringNotContainsString( $token, serialize( $redacted ) );
			self::assertStringContainsString( '[REDACTED]', serialize( $redacted ) );
		}
	}

	public function test_objects_and_resources_remain_rejected(): void {
		$resource = fopen( 'php://memory', 'rb' );
		self::assertIsResource( $resource );
		self::assertSame( '[REDACTED]', Redactor::redact( (object) array( 'token' => 'secret' ) ) );
		self::assertSame( '[REDACTED]', Redactor::redact( $resource ) );
		fclose( $resource );
	}
}
