<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

use Closure;
use IdeaXperts\EndlessAisles\API\ApiClient;
use IdeaXperts\EndlessAisles\API\ApiClientInterface;
use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Logging\Redactor;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Exactly one `PUT /api/orders` per call — never a retry loop. Retrying is the
 * worker's decision, made only with the same custom_order_id and payload.
 */
final class OrderSubmissionClient {
	public const TIMEOUT = 30;
	private readonly Closure $client_factory;

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly BaseUrlResolver $base_urls,
		DatabaseLogger $logger,
		?Closure $client_factory = null
	) {
		$this->client_factory = $client_factory ?? static fn( string $url, array $headers ): ApiClientInterface => new ApiClient( $url, $headers, $logger, self::TIMEOUT );
	}

	public function submit( string $environment, OrderPayload $payload ): SubmissionOutcome {
		if ( ! in_array( $environment, array( 'qa', 'production' ), true ) || $environment !== $this->settings->get( 'environment', 'qa' ) ) {
			return new SubmissionOutcome( SubmissionOutcome::NOT_CREATED, 'environment_changed' );
		}
		$confirmed = SettingsValidator::PRODUCTION_CONFIRMED === $this->settings->get( 'production_confirmed', 'no' );
		if ( 'production' === $environment && ( ! $confirmed || SettingsValidator::PRODUCTION_ORDERS_CONFIRMED !== $this->settings->get( 'production_orders_confirmed', 'no' ) ) ) {
			return new SubmissionOutcome( SubmissionOutcome::NOT_CREATED, 'environment_not_permitted' );
		}
		$token = $this->settings->token( $environment );
		if ( '' === $token ) {
			return new SubmissionOutcome( SubmissionOutcome::NOT_CREATED, 'credential_missing' );
		}
		$client = ( $this->client_factory )( $this->base_urls->resolve( $environment, $confirmed ), array( 'X-EA-REQUEST-TOKEN' => $token ) );
		try {
			$response = $client->request( 'PUT', '/api/orders', $payload->payload );
		} catch ( ApiException $exception ) {
			return self::classify_failure( $exception );
		}
		return self::classify_success( $response );
	}

	/** @param array<string,mixed> $response */
	public static function classify_success( array $response ): SubmissionOutcome {
		$data = $response['data'] ?? null;
		if ( is_object( $data ) ) {
			$data = get_object_vars( $data );
		}
		$id = is_array( $data ) ? ( $data['id'] ?? null ) : null;
		if ( ( is_int( $id ) && $id > 0 ) || ( is_string( $id ) && 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/', $id ) ) ) {
			$status = is_array( $data ) && is_string( $data['status'] ?? null ) ? sanitize_key( strtolower( $data['status'] ) ) : '';
			return new SubmissionOutcome( SubmissionOutcome::ACCEPTED, '', (string) $id, $status );
		}
		// A 2xx without a usable ID may still mean the vendor created the order.
		return new SubmissionOutcome( SubmissionOutcome::UNKNOWN, 'vendor_success_malformed' );
	}

	public static function classify_failure( ApiException $exception ): SubmissionOutcome {
		$status = $exception->getCode();
		$detail = self::detail( $exception->body() );
		if ( in_array( $status, array( 400, 409, 422 ), true ) ) {
			// The exact duplicate error format is unconfirmed (design Q1); any mention of
			// our reference is treated as "the vendor already has it", never as a rejection.
			if ( str_contains( strtolower( $exception->body() ), 'custom_order_id' ) ) {
				return new SubmissionOutcome( SubmissionOutcome::DUPLICATE, 'vendor_duplicate_reference', '', '', $detail );
			}
			return new SubmissionOutcome( SubmissionOutcome::REJECTED, 'vendor_validation_failed', '', '', $detail );
		}
		if ( in_array( $status, array( 401, 403, 429 ), true ) ) {
			return new SubmissionOutcome( SubmissionOutcome::NOT_CREATED, 401 === $status || 403 === $status ? 'vendor_auth_failed' : 'vendor_rate_limited', '', '', $detail );
		}
		$code = $status >= 500 ? 'vendor_server_error' : ( str_contains( strtolower( $exception->getMessage() ), 'timed out' ) ? 'vendor_timeout' : 'vendor_transport_failed' );
		return new SubmissionOutcome( SubmissionOutcome::UNKNOWN, $code, '', '', $detail );
	}

	private static function detail( string $body ): string {
		$decoded = json_decode( $body, true );
		$text    = is_array( $decoded ) && isset( $decoded['error'] ) && is_scalar( $decoded['error'] ) ? (string) $decoded['error'] : $body;
		return substr( (string) Redactor::redact( sanitize_text_field( $text ) ), 0, 500 );
	}
}
