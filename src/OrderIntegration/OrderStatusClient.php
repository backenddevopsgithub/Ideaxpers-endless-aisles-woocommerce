<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

use Closure;
use IdeaXperts\EndlessAisles\API\ApiClient;
use IdeaXperts\EndlessAisles\API\ApiClientInterface;
use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\API\RequestRetry;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;

defined( 'ABSPATH' ) || exit;

/** Read-only `GET /api/orders/{id}`; idempotent, so the shared bounded GET retry applies. */
final class OrderStatusClient {
	private readonly Closure $client_factory;
	private readonly Closure $sleeper;

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly BaseUrlResolver $base_urls,
		DatabaseLogger $logger,
		?Closure $client_factory = null,
		?Closure $sleeper = null
	) {
		$this->client_factory = $client_factory ?? static fn( string $url, array $headers ): ApiClientInterface => new ApiClient( $url, $headers, $logger );
		$this->sleeper        = $sleeper ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
	}

	/** @throws ApiException On any failure, including 404 (code 404). */
	public function fetch( string $environment, string $ea_order_id ): VendorOrderStatus {
		if ( ! in_array( $environment, array( 'qa', 'production' ), true ) || $environment !== $this->settings->get( 'environment', 'qa' ) ) {
			throw new ApiException( 'Order status environment does not match the active configuration.' );
		}
		$confirmed = SettingsValidator::PRODUCTION_CONFIRMED === $this->settings->get( 'production_confirmed', 'no' );
		if ( 'production' === $environment && ! $confirmed ) {
			throw new ApiException( 'Production order status requires the existing Production safeguard.' );
		}
		$token = $this->settings->token( $environment );
		if ( '' === $token ) {
			throw new ApiException( 'Order status credential is not configured.' );
		}
		if ( 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/', $ea_order_id ) ) {
			throw new ApiException( 'Vendor order identifier is invalid.' );
		}
		$client = ( $this->client_factory )( $this->base_urls->resolve( $environment, $confirmed ), array( 'X-EA-REQUEST-TOKEN' => $token ) );
		return self::parse( RequestRetry::get( $client, '/api/orders/' . $ea_order_id, $this->sleeper ) );
	}

	/** @param array<string,mixed> $response */
	public static function parse( array $response ): VendorOrderStatus {
		$data = self::fields( $response['data'] ?? null );
		if ( null === $data || ! is_string( $data['status'] ?? null ) || '' === trim( $data['status'] ) ) {
			throw new ApiException( 'Endless Aisles returned an unexpected order structure.' );
		}
		$lines = array();
		foreach ( is_array( $data['line_items'] ?? null ) ? $data['line_items'] : array() as $raw ) {
			$line = self::fields( $raw );
			if ( null === $line || ! empty( $line['is_shipping'] ) ) {
				continue;
			}
			$quantity = $line['quantity'] ?? null;
			$shipped  = $line['quantity_shipped'] ?? 0;
			if ( ! is_int( $quantity ) || $quantity < 0 || ( null !== $shipped && ( ! is_int( $shipped ) || $shipped < 0 ) ) ) {
				throw new ApiException( 'Endless Aisles returned an unexpected order line.' );
			}
			$lines[] = array(
				'id'       => is_scalar( $line['id'] ?? null ) ? (string) $line['id'] : '',
				'quantity' => $quantity,
				'shipped'  => (int) $shipped,
			);
		}
		$shipments = array();
		foreach ( is_array( $data['shipments'] ?? null ) ? $data['shipments'] : array() as $raw ) {
			$shipment = self::fields( $raw );
			$number   = is_scalar( $shipment['number'] ?? null ) ? trim( sanitize_text_field( (string) $shipment['number'] ) ) : '';
			if ( '' === $number || strlen( $number ) > 64 ) {
				continue;
			}
			$url         = is_string( $shipment['url'] ?? null ) ? trim( $shipment['url'] ) : '';
			$shipments[] = array(
				'number' => $number,
				'url'    => 1 === preg_match( '#\Ahttps?://[^\s<>"]{1,500}\z#i', $url ) ? $url : '',
			);
		}
		return new VendorOrderStatus(
			strtolower( trim( $data['status'] ) ),
			$lines,
			$shipments,
			is_string( $data['last_updated'] ?? null ) ? substr( sanitize_text_field( $data['last_updated'] ), 0, 32 ) : ''
		);
	}

	/** @return array<string,mixed>|null */
	private static function fields( mixed $value ): ?array {
		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}
		return is_array( $value ) ? $value : null;
	}
}
