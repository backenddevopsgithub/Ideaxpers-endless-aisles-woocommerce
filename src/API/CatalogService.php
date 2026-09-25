<?php
namespace IdeaXperts\EndlessAisles\API;

use Closure;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\ProductMapping\ProductContract;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class CatalogService {
	public const PER_PAGE        = 10;
	public const MAX_ATTEMPTS    = 3;
	public const MAX_RETRY_AFTER = 30;
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

	/** @return array{products:list<array<string,mixed>>,next_page:int|null,current_page:int} */
	public function page( int $page ): array {
		if ( 'qa' !== $this->settings->get( 'environment', 'qa' ) ) {
			throw new ApiException( 'Catalog dry runs are restricted to QA.' );
		}
		$token = $this->settings->token( 'qa' );
		if ( '' === $token ) {
			throw new ApiException( 'QA credential is not configured.' );
		}
		$paginator = new ProductPaginator();
		$path      = $paginator->first_path( $page, self::PER_PAGE );
		$client    = ( $this->client_factory )( $this->base_urls->resolve( 'qa' ), array( 'X-EA-REQUEST-TOKEN' => $token ) );
		$response  = $this->request_with_retry( $client, $path );
		$next_path = $paginator->next_path( $response );
		$products  = array();
		foreach ( $response['data'] as $product ) {
			if ( ! is_object( $product ) ) {
				throw new ApiException( 'Endless Aisles returned an unexpected product structure.' );
			}
			$row          = array_intersect_key( get_object_vars( $product ), array_flip( ProductContract::PRODUCT_FIELDS ) );
			$row['sizes'] = isset( $row['sizes'] ) && is_array( $row['sizes'] ) ? array_map(
				static function ( mixed $size ): array {
					if ( ! is_object( $size ) ) {
						throw new ApiException( 'Endless Aisles returned an unexpected option structure.' );
					}
					return ProductContract::normalize_size( get_object_vars( $size ) );
				},
				$row['sizes']
			) : array();
			$products[]   = $row;
		}
		$next_page = null;
		if ( null !== $next_path ) {
			parse_str( (string) parse_url( $next_path, PHP_URL_QUERY ), $query );
			$next_page = (int) $query['page'];
		}
		return array(
			'products'     => $products,
			'next_page'    => $next_page,
			'current_page' => (int) $response['current_page'],
		);
	}

	/** @return array<string,mixed> */
	private function request_with_retry( ApiClientInterface $client, string $path ): array {
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
				( $this->sleeper )( min( self::MAX_RETRY_AFTER, max( 1, $retry_after ) ) );
			}
		}
		throw new ApiException( 'Endless Aisles retry limit exceeded.' );
	}
}
