<?php
namespace IdeaXperts\EndlessAisles\API;

defined( 'ABSPATH' ) || exit;

final class ProductPaginator {
	public const MAX_PAGES    = 1000;
	public const MAX_PER_PAGE = 1000;
	public const MAX_RECORDS  = 100000;

	/** @var array<int,true> */
	private array $visited_pages = array();
	private int $records_seen    = 0;

	public function first_path( int $page = 1, int $per_page = 10 ): string {
		return $this->path( $page, $per_page );
	}

	/**
	 * @param array<string,mixed> $response Documented product-list response.
	 */
	public function next_path( array $response ): ?string {
		if ( ! isset( $response['current_page'], $response['data'], $response['per_page'] ) || ! is_numeric( $response['current_page'] ) || ! is_array( $response['data'] ) || ! is_numeric( $response['per_page'] ) ) {
			throw new ApiException( 'Endless Aisles returned an invalid pagination structure.' );
		}
		$current_page = (int) $response['current_page'];
		if ( $current_page < 1 || $current_page > self::MAX_PAGES || isset( $this->visited_pages[ $current_page ] ) ) {
			throw new ApiException( 'Endless Aisles pagination loop detected.' );
		}
		$this->visited_pages[ $current_page ] = true;
		$this->records_seen                  += count( $response['data'] );
		if ( count( $this->visited_pages ) > self::MAX_PAGES || $this->records_seen > self::MAX_RECORDS ) {
			throw new ApiException( 'Endless Aisles pagination safety limit exceeded.' );
		}

		$next = $response['next_page_url'] ?? null;
		if ( null === $next ) {
			return null;
		}
		if ( ! is_string( $next ) || '' === $next || str_starts_with( $next, '//' ) || str_contains( $next, '\\' ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $next ) || 1 === preg_match( '/[\x00-\x1F\x7F\\\\]/', rawurldecode( $next ) ) ) {
			throw new ApiException( 'Endless Aisles returned an unsafe pagination URL.' );
		}
		$parts = parse_url( $next );
		if ( false === $parts || array_intersect_key( $parts, array_flip( array( 'scheme', 'host', 'user', 'pass', 'port', 'fragment' ) ) ) ) {
			throw new ApiException( 'Endless Aisles returned an unsafe pagination URL.' );
		}
		$path = $parts['path'] ?? '';
		if ( ! in_array( $path, array( '', '/', '/api/products' ), true ) ) {
			throw new ApiException( 'Endless Aisles returned an unsafe pagination URL.' );
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( array_diff( array_keys( $query ), array( 'page', 'per_page' ) ) ) {
			throw new ApiException( 'Endless Aisles returned unsupported pagination parameters.' );
		}
		$next_page = isset( $query['page'] ) ? $this->positive_integer_parameter( $query['page'] ) : $current_page + 1;
		$per_page  = isset( $query['per_page'] ) ? $this->positive_integer_parameter( $query['per_page'] ) : (int) $response['per_page'];
		if ( isset( $this->visited_pages[ $next_page ] ) ) {
			throw new ApiException( 'Endless Aisles pagination loop detected.' );
		}
		return $this->path( $next_page, $per_page );
	}

	private function positive_integer_parameter( mixed $value ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			throw new ApiException( 'Endless Aisles returned an invalid pagination parameter.' );
		}
		return (int) $value;
	}

	private function path( int $page, int $per_page ): string {
		if ( $page < 1 || $page > self::MAX_PAGES || $per_page < 1 || $per_page > self::MAX_PER_PAGE ) {
			throw new ApiException( 'Endless Aisles pagination request exceeds a safety limit.' );
		}
		return '/api/products?page=' . $page . '&per_page=' . $per_page;
	}
}
