<?php
namespace IdeaXperts\EndlessAisles\Logging;

defined( 'ABSPATH' ) || exit;

final class Redactor {
	private const MAX_TEXT_LENGTH  = 65536;
	private const MAX_TOKEN_LENGTH = 4096;
	private const SENSITIVE_KEYS   = array(
		'authorization',
		'x-ea-request-token',
		'credential',
		'api_key',
		'x-api-key',
		'api_token',
		'token',
		'access_token',
		'password',
		'secret',
		'client_secret',
		'cookie',
		'session',
		'payment',
		'card',
		'cvv',
		'customer',
		'billing',
		'shipping',
		'email',
		'phone',
		'address',
		'first_name',
		'last_name',
		'postcode',
		'postal_code',
		'ip_address',
	);

	public static function redact( mixed $value, string $key = '' ): mixed {
		if ( self::sensitive_key( $key ) ) {
			return '[REDACTED]';
		}
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $child_key => $child ) {
				$result[ $child_key ] = self::redact( $child, (string) $child_key );
			}
			return $result;
		}
		if ( is_object( $value ) || is_resource( $value ) ) {
			return '[REDACTED]';
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > self::MAX_TEXT_LENGTH && false !== stripos( $value, 'x-ea-request-token' ) ) {
				return '[REDACTED]';
			}
			$value      = preg_replace( '/(Bearer\s+)[^\s,]+/i', '$1[REDACTED]', $value ) ?? $value;
			$value      = preg_replace( '/("x-ea-request-token"\s*:\s*)"[^"\r\n]{0,' . self::MAX_TOKEN_LENGTH . '}"/i', '$1"[REDACTED]"', $value ) ?? $value;
			$value      = preg_replace( '/(\\\\+"x-ea-request-token\\\\+"\s*:\s*\\\\+")[^"\r\n]{0,' . self::MAX_TOKEN_LENGTH . '}\\\\+"/i', '$1[REDACTED]\\"', $value ) ?? $value;
			$value      = preg_replace( '/((?:x-ea-request-token|api[_-]?(?:key|token)|access[_-]?token|token|password|secret|session(?:id)?|cookie)\s*[=:]\s*)[^\s,;&]+/i', '$1[REDACTED]', $value ) ?? $value;
			$unredacted = preg_replace(
				array(
					'/x-ea-request-token\s*[=:]\s*\[REDACTED\]/i',
					'/"x-ea-request-token"\s*:\s*"\[REDACTED\]"/i',
					'/\\\\+"x-ea-request-token\\\\+"\s*:\s*\\\\+"\[REDACTED\]\\\\+"/i',
				),
				'',
				$value
			) ?? $value;
			if ( false !== stripos( $unredacted, 'x-ea-request-token' ) ) {
				return '[REDACTED]';
			}
			$value = preg_replace( '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[REDACTED]', $value ) ?? $value;
			$value = preg_replace( '/\b(?:\d[ -]*?){13,19}\b/', '[REDACTED]', $value ) ?? $value;
			$value = preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[REDACTED]', $value ) ?? $value;
		}
		return $value;
	}

	private static function sensitive_key( string $key ): bool {
		$key = strtolower( $key );
		foreach ( self::SENSITIVE_KEYS as $sensitive ) {
			if ( $key === $sensitive || str_contains( $key, $sensitive ) ) {
				return true;
			}
		}
		return false;
	}
}
