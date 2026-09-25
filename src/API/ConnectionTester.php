<?php
namespace IdeaXperts\EndlessAisles\API;

use Closure;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;

defined( 'ABSPATH' ) || exit;

final class ConnectionTester {
	private readonly Closure $client_factory;

	public function __construct(
		private readonly EndpointRegistry $endpoints,
		private readonly BaseUrlResolver $base_urls,
		private readonly SettingsRepository $settings,
		private readonly DatabaseLogger $logger,
		?Closure $client_factory = null
	) {
		$this->client_factory = $client_factory ?? static fn( string $base_url, array $headers ): ApiClientInterface => new ApiClient( $base_url, $headers, $logger );
	}

	public function test(): ConnectionTestResult {
		$environment = (string) $this->settings->get( 'environment', 'qa' );
		$environment = 'production' === $environment ? 'production' : 'qa';
		$confirmed   = SettingsValidator::PRODUCTION_CONFIRMED === $this->settings->get( 'production_confirmed', 'no' );
		if ( 'production' === $environment && ! $confirmed ) {
			return new ConnectionTestResult( false, 'production_confirmation_required', __( 'Production requests are blocked until the production safeguard is explicitly confirmed.', 'ideaxperts-endless-aisles' ) );
		}
		$token = $this->settings->token( $environment );
		if ( '' === $token ) {
			return new ConnectionTestResult( false, 'not_configured', __( 'The selected environment credential is not configured. No request was sent.', 'ideaxperts-endless-aisles' ) );
		}
		$base_url = $this->base_urls->resolve( $environment, $confirmed );
		try {
			/** @var ApiClientInterface $client */
			$client         = ( $this->client_factory )( $base_url, array( 'X-EA-REQUEST-TOKEN' => $token ) );
			$response       = $client->request( 'GET', $this->endpoints->connection_test_path() );
			$valid_envelope = array_key_exists( 'current_page', $response )
				&& array_key_exists( 'data', $response )
				&& array_key_exists( 'per_page', $response )
				&& 1 === $response['current_page']
				&& 1 === $response['per_page']
				&& is_array( $response['data'] )
				&& array_is_list( $response['data'] )
				&& ( array() === $response['data'] || ( 1 === count( $response['data'] ) && is_object( $response['data'][0] ) ) );
			if ( ! $valid_envelope ) {
				$this->store_qa_status( $environment, 'unexpected_response' );
				return new ConnectionTestResult( false, 'unexpected_response', __( 'Endless Aisles returned an unexpected product-list structure.', 'ideaxperts-endless-aisles' ) );
			}
			$this->store_qa_status( $environment, 'connected' );
			return new ConnectionTestResult( true, 'connected', __( 'Authenticated read-only connection succeeded.', 'ideaxperts-endless-aisles' ) );
		} catch ( ApiException $exception ) {
			$this->store_qa_status( $environment, 'failed' );
			$this->logger->log( 'warning', 'Endless Aisles connection test failed.', $exception->context() );
			$status  = str_contains( strtolower( $exception->getMessage() ), 'timed out' ) ? 'timeout' : 'request_failed';
			$message = 'timeout' === $status ? __( 'The connection test timed out.', 'ideaxperts-endless-aisles' ) : __( 'The connection test failed.', 'ideaxperts-endless-aisles' );
			return new ConnectionTestResult( false, $status, $message );
		}
	}

	private function store_qa_status( string $environment, string $status ): void {
		if ( 'qa' !== $environment ) {
			return;
		}
		update_option(
			'ideaxperts_ea_qa_connection_status',
			array(
				'status'    => $status,
				'tested_at' => current_time( 'mysql', true ),
			),
			false
		);
	}
}
