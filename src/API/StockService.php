<?php
namespace IdeaXperts\EndlessAisles\API;

use Closure;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only `GET /api/stock?sku=...` lookups keyed by Endless Aisles option IDs.
 *
 * Documented contract: `{"data":{"<sku>":<qty>}}`; unknown SKUs are omitted and an
 * all-unknown request returns `{"data":[]}`; non-entitled SKUs always report 0.
 */
final class StockService {
	public const BATCH_SIZE   = 50;
	private const SKU_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';
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

	/**
	 * @param list<string> $skus At most BATCH_SIZE option IDs.
	 * @return array{quantities:array<string,int>,missing:list<string>,invalid:list<string>}
	 */
	public function quantities( string $environment, array $skus ): array {
		if ( ! in_array( $environment, array( 'qa', 'production' ), true ) || $environment !== $this->settings->get( 'environment', 'qa' ) ) {
			throw new ApiException( 'Stock lookup environment does not match the active configuration.' );
		}
		$confirmed = SettingsValidator::PRODUCTION_CONFIRMED === $this->settings->get( 'production_confirmed', 'no' );
		if ( 'production' === $environment && ! $confirmed ) {
			throw new ApiException( 'Production stock lookup requires the existing Production safeguard.' );
		}
		$token = $this->settings->token( $environment );
		if ( '' === $token ) {
			throw new ApiException( 'Stock lookup credential is not configured.' );
		}
		$skus = array_values( array_unique( array_map( 'strval', $skus ) ) );
		if ( array() === $skus || count( $skus ) > self::BATCH_SIZE ) {
			throw new ApiException( 'Stock lookup batch size is invalid.' );
		}
		foreach ( $skus as $sku ) {
			if ( ! self::valid_sku( $sku ) ) {
				throw new ApiException( 'Stock lookup received an invalid option identifier.' );
			}
		}
		$client   = ( $this->client_factory )( $this->base_urls->resolve( $environment, $confirmed ), array( 'X-EA-REQUEST-TOKEN' => $token ) );
		$response = RequestRetry::get( $client, '/api/stock?sku=' . implode( ',', $skus ), $this->sleeper );
		return self::parse( $response, $skus );
	}

	public static function valid_sku( string $sku ): bool {
		return 1 === preg_match( self::SKU_PATTERN, $sku );
	}

	/**
	 * @param array<string,mixed> $response
	 * @param list<string>        $skus
	 * @return array{quantities:array<string,int>,missing:list<string>,invalid:list<string>}
	 */
	public static function parse( array $response, array $skus ): array {
		if ( ! array_key_exists( 'data', $response ) ) {
			throw new ApiException( 'Endless Aisles returned an unexpected stock structure.' );
		}
		$data = $response['data'];
		if ( is_object( $data ) ) {
			$data = get_object_vars( $data );
		}
		// An empty JSON list is the documented "nothing found" body; any other list is malformed.
		if ( ! is_array( $data ) || ( array() !== $data && array_is_list( $data ) ) ) {
			throw new ApiException( 'Endless Aisles returned an unexpected stock structure.' );
		}
		$quantities = array();
		$missing    = array();
		$invalid    = array();
		foreach ( $skus as $sku ) {
			if ( ! array_key_exists( $sku, $data ) ) {
				$missing[] = $sku;
				continue;
			}
			$quantity = $data[ $sku ];
			if ( ! is_int( $quantity ) || $quantity < 0 ) {
				$invalid[] = $sku;
				continue;
			}
			$quantities[ $sku ] = $quantity;
		}
		return compact( 'quantities', 'missing', 'invalid' );
	}
}
