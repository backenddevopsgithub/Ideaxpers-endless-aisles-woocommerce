<?php
namespace IdeaXperts\EndlessAisles\Security;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class CredentialCipher {
	private const PREFIX           = 'v1:';
	private const REQUIRED_SECRETS = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' );

	/** @param list<string>|null $secret_material Explicit test material; production reads WordPress constants. */
	public function __construct( private readonly ?array $secret_material = null ) {}

	public function available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && '' !== $this->key_material();
	}

	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! $this->available() ) {
			throw new RuntimeException( 'Credential encryption is unavailable.' );
		}
		try {
			$iv = random_bytes( 12 );
		} catch ( Throwable $exception ) {
			throw new RuntimeException( 'Credential encryption is unavailable.', 0, $exception );
		}
		$tag       = '';
		$encrypted = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, 'ideaxperts-endless-aisles' );
		if ( false === $encrypted || 16 !== strlen( $tag ) ) {
			throw new RuntimeException( 'Credential encryption failed.' );
		}
		return self::PREFIX . base64_encode( $iv . $tag . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function decrypt( string $payload ): string {
		if ( '' === $payload ) {
			return '';
		}
		if ( ! $this->available() || ! str_starts_with( $payload, self::PREFIX ) ) {
			throw new RuntimeException( 'Credential decryption is unavailable.' );
		}
		$decoded = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded || strlen( $decoded ) < 29 ) {
			throw new RuntimeException( 'Credential payload is invalid.' );
		}
		$plain = openssl_decrypt( substr( $decoded, 28 ), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr( $decoded, 0, 12 ), substr( $decoded, 12, 16 ), 'ideaxperts-endless-aisles' );
		if ( false === $plain ) {
			throw new RuntimeException( 'Credential decryption failed.' );
		}
		return $plain;
	}

	private function key(): string {
		return hash_hkdf( 'sha256', $this->key_material(), 32, 'ideaxperts-endless-aisles-credentials-v1' );
	}

	private function key_material(): string {
		if ( null !== $this->secret_material ) {
			$parts = $this->secret_material;
			if ( count( self::REQUIRED_SECRETS ) !== count( $parts ) ) {
				return '';
			}
		} else {
			$parts = array();
			foreach ( self::REQUIRED_SECRETS as $constant ) {
				if ( ! defined( $constant ) ) {
					return '';
				}
				$parts[] = constant( $constant );
			}
		}
		foreach ( $parts as $value ) {
			if ( ! is_string( $value ) || strlen( $value ) < 32 || str_contains( strtolower( $value ), 'put your unique phrase here' ) ) {
				return '';
			}
		}
		return implode( '|', $parts );
	}
}
