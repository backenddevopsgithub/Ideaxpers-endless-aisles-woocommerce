<?php
namespace IdeaXperts\EndlessAisles\API;

use Closure;

defined( 'ABSPATH' ) || exit;

/** Bounded retry for idempotent GET requests: 429, 5xx and transport failures only. */
final class RequestRetry {
	public const MAX_ATTEMPTS    = 3;
	public const MAX_RETRY_AFTER = 30;

	/** @return array<string,mixed> */
	public static function get( ApiClientInterface $client, string $path, Closure $sleeper ): array {
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt ) {
			try {
				return $client->request( 'GET', $path );
			} catch ( ApiException $exception ) {
				$status    = $exception->getCode();
				$retryable = 429 === $status || $status >= 500 || str_contains( strtolower( $exception->getMessage() ), 'timed out' ) || str_contains( strtolower( $exception->getMessage() ), 'request failed' );
				if ( ! $retryable || $attempt >= self::MAX_ATTEMPTS ) {
					throw $exception;
				}
				$retry_after = (int) ( $exception->context()['retry_after'] ?? $attempt );
				$sleeper( min( self::MAX_RETRY_AFTER, max( 1, $retry_after ) ) );
			}
		}
		throw new ApiException( 'Endless Aisles retry limit exceeded.' );
	}
}
