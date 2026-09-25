<?php
namespace IdeaXperts\EndlessAisles\Tests\ProductMapping;

use IdeaXperts\EndlessAisles\ProductMapping\ProductContract;
use PHPUnit\Framework\TestCase;

final class ProductContractTest extends TestCase {
	public function test_upc_remains_a_string_with_leading_zeroes(): void {
		$size = ProductContract::normalize_size(
			array(
				'id'           => 1,
				'upc'          => '001234567890',
				'undocumented' => 'drop',
			)
		);
		self::assertSame( '001234567890', $size['upc'] );
		self::assertArrayNotHasKey( 'undocumented', $size );
	}

	public function test_numeric_json_upc_is_not_coerced_to_a_string(): void {
		$size = ProductContract::normalize_size( array( 'upc' => 123456789012 ) );
		self::assertSame( 123456789012, $size['upc'] );
	}
}
