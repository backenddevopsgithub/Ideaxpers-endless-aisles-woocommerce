<?php
namespace IdeaXperts\EndlessAisles\API;

use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use JsonException;

defined( 'ABSPATH' ) || exit;

final class ApiClient implements ApiClientInterface {
	/** Five MiB accommodates documented catalog pages while bounding memory use. */
	public const DEFAULT_RESPONSE_SIZE_LIMIT = 5 * 1024 * 1024;
	private const MAX_RESPONSE_SIZE_LIMIT    = 20 * 1024 * 1024;

	/**
	 * @param array<string,string> $authentication_headers Documented authentication headers.
	 */
	public function __construct(
		private readonly string $base_url,
		private readonly array $authentication_headers,
		private readonly DatabaseLogger $logger,
		private readonly int $timeout = 15,
		private readonly int $response_size_limit = self::DEFAULT_RESPONSE_SIZE_LIMIT
	) {}

	/**
	 * @param array<string,mixed>|null $body Request body.
	 * @return array<string,mixed>
	 */
	public function request( string $method, string $path, ?array $body = null ): array {
		if ( '' === $this->base_url || '' === $path || array() === $this->authentication_headers ) {
			throw new ApiException( 'API client is not configured.' );
		}
		$size_limit = min( self::MAX_RESPONSE_SIZE_LIMIT, max( 1024, $this->response_size_limit ) );
		$url        = $this->base_url . '/' . ltrim( $path, '/' );
		$args       = array(
			'method'              => strtoupper( $method ),
			'timeout'             => min( 60, max( 1, $this->timeout ) ),
			'redirection'         => 0,
			'limit_response_size' => $size_limit,
			'headers'             => array_merge(
				array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				$this->authentication_headers
			),
		);
		if ( null !== $body ) {
			try {
				$args['body'] = wp_json_encode( $body, JSON_THROW_ON_ERROR );
			} catch ( JsonException $exception ) {
				throw new ApiException( 'API request data could not be encoded.', array(), 0, $exception );
			}
		}
		$this->logger->log(
			'debug',
			'Endless Aisles API request.',
			array(
				'method' => strtoupper( $method ),
			)
		);
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$error_code = $response->get_error_code();
			$error_text = strtolower( $response->get_error_message() );
			$is_timeout = str_contains( strtolower( $error_code ), 'timeout' ) || ( 'http_request_failed' === $error_code && 1 === preg_match( '/(?:curl error 28\b|operation (?:timed out|timeout)\b|\btimed out\b)/', $error_text ) );
			$message    = $is_timeout ? 'Endless Aisles request timed out.' : 'Endless Aisles request failed.';
			throw new ApiException( $message, array( 'error_code' => $error_code ) );
		}
		$status         = wp_remote_retrieve_response_code( $response );
		$raw            = wp_remote_retrieve_body( $response );
		$content_length = trim( (string) wp_remote_retrieve_header( $response, 'content-length' ) );
		$retry_after    = trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
		if ( ( '' !== $content_length && ctype_digit( $content_length ) && (int) $content_length > $size_limit ) || strlen( $raw ) >= $size_limit ) {
			throw new ApiException(
				'Endless Aisles response exceeded the configured size limit.',
				array(
					'reason'      => 'response_too_large',
					'limit_bytes' => $size_limit,
					'status'      => $status,
				)
			);
		}
		if ( $status < 200 || $status >= 300 ) {
			$context = array( 'status' => $status );
			if ( ctype_digit( $retry_after ) ) {
				$context['retry_after'] = (int) $retry_after;
			}
			throw new ApiException( 'Endless Aisles returned an unsuccessful response.', $context, $status );
		}
		try {
			$data = json_decode( $raw, false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			throw new ApiException( 'Endless Aisles returned invalid JSON.', array( 'status' => $status ), 0, $exception );
		}
		if ( ! is_object( $data ) ) {
			throw new ApiException( 'Endless Aisles returned an invalid JSON object.', array( 'status' => $status ) );
		}
		return get_object_vars( $data );
	}
}
