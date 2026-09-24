<?php
namespace IdeaXperts\EndlessAisles\Tests\API;

use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\API\ProductPaginator;
use PHPUnit\Framework\TestCase;

final class ProductPaginatorTest extends TestCase {
	#[\PHPUnit\Framework\Attributes\DataProvider( 'safeReferences' )]
	public function test_safe_relative_next_page_url_is_rebuilt_as_a_product_path( string $reference, string $expected ): void {
		$paginator = new ProductPaginator();
		$next      = $paginator->next_path(
			array(
				'current_page'  => 1,
				'data'          => array( array( 'id' => 1 ) ),
				'per_page'      => 10,
				'next_page_url' => $reference,
			)
		);
		self::assertSame( $expected, $next );
	}

	/** @return array<string,array{string,string}> */
	public static function safeReferences(): array {
		return array(
			'documented slash path' => array( '/?page=2', '/api/products?page=2&per_page=10' ),
			'query only'            => array( '?page=2', '/api/products?page=2&per_page=10' ),
			'explicit product path' => array( '/api/products?page=2', '/api/products?page=2&per_page=10' ),
			'per page parameter'    => array( '?page=2&per_page=25', '/api/products?page=2&per_page=25' ),
		);
	}

	public function test_repeated_page_is_rejected_as_a_loop(): void {
		$this->expectException( ApiException::class );
		$paginator = new ProductPaginator();
		$response  = array(
			'current_page'  => 1,
			'data'          => array(),
			'per_page'      => 10,
			'next_page_url' => '/?page=2',
		);
		$paginator->next_path( $response );
		$paginator->next_path( $response );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unsafeReferences' )]
	public function test_unsafe_next_page_url_is_rejected( string $reference ): void {
		$this->expectException( ApiException::class );
		( new ProductPaginator() )->next_path(
			array(
				'current_page'  => 1,
				'data'          => array(),
				'per_page'      => 10,
				'next_page_url' => $reference,
			)
		);
	}

	/** @return array<string,array{string}> */
	public static function unsafeReferences(): array {
		return array(
			'absolute URL'           => array( 'https://evil.example/?page=2' ),
			'protocol relative'      => array( '//evil.example/?page=2' ),
			'javascript scheme'      => array( 'javascript:?page=2' ),
			'mailto scheme'          => array( 'mailto:x?page=2' ),
			'malformed https scheme' => array( 'https:/path?page=2' ),
			'user and password'      => array( 'https://user:pass@evil.example:8443/?page=2' ),
			'backslash confusion'    => array( '/\\evil.example?page=2' ),
			'encoded backslash'      => array( '/%5cevil.example?page=2' ),
			'control character'      => array( "/?page=2\n" ),
			'encoded control'        => array( '/?page=2%0a' ),
			'wrong path'             => array( '/api/orders?page=2' ),
			'fragment'               => array( '/?page=2#fragment' ),
			'unknown parameter'      => array( '/?page=2&token=secret' ),
			'duplicate page'         => array( '/?page[]=2' ),
			'zero page'              => array( '/?page=0' ),
			'negative page'          => array( '/?page=-1' ),
		);
	}

	public function test_page_limit_is_enforced(): void {
		$this->expectException( ApiException::class );
		( new ProductPaginator() )->first_path( ProductPaginator::MAX_PAGES + 1, 10 );
	}
}
