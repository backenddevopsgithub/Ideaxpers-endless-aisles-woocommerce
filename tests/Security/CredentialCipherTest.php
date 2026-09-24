<?php
namespace IdeaXperts\EndlessAisles\Tests\Security;

use IdeaXperts\EndlessAisles\Security\CredentialCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CredentialCipherTest extends TestCase {
	public function test_round_trip_uses_nondeterministic_authenticated_ciphertext(): void {
		$cipher = new CredentialCipher();
		$one    = $cipher->encrypt( 'top-secret-token' );
		$two    = $cipher->encrypt( 'top-secret-token' );
		self::assertNotSame( $one, $two );
		self::assertStringNotContainsString( 'top-secret-token', $one );
		self::assertSame( 'top-secret-token', $cipher->decrypt( $one ) );
	}

	public function test_tampering_fails_closed(): void {
		$this->expectException( RuntimeException::class );
		$cipher  = new CredentialCipher();
		$payload = $cipher->encrypt( 'secret' );
		$cipher->decrypt( substr( $payload, 0, -2 ) . 'xx' );
	}

	public function test_incomplete_or_placeholder_secret_material_is_rejected(): void {
		self::assertFalse( ( new CredentialCipher( array( str_repeat( 'a', 32 ) ) ) )->available() );
		self::assertFalse( ( new CredentialCipher( array( str_repeat( 'a', 32 ), str_repeat( 'b', 32 ), str_repeat( 'c', 32 ), 'put your unique phrase here please' ) ) )->available() );
	}
}
