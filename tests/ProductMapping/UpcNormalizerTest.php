<?php
namespace IdeaXperts\EndlessAisles\Tests\ProductMapping;

use IdeaXperts\EndlessAisles\ProductMapping\UpcNormalizer;
use PHPUnit\Framework\TestCase;

final class UpcNormalizerTest extends TestCase {
	public function test_it_preserves_leading_zeroes_and_removes_formatting(): void {
		self::assertSame( '001234567890', UpcNormalizer::normalize( ' 00-1234 567 890 ' ) );
	}

	public function test_it_accepts_common_gtin_lengths_and_preserves_original(): void {
		foreach ( array( '00000001', '001234567890', '0012345678901', '00012345678901' ) as $value ) {
			$result = UpcNormalizer::inspect( $value );
			self::assertTrue( $result['valid'] );
			self::assertSame( $value, $result['original'] );
			self::assertSame( $value, $result['normalized'] );
		}
	}

	public function test_it_flags_non_digits_and_unsupported_lengths_without_repairing(): void {
		self::assertSame( 'non_digit', UpcNormalizer::inspect( '0123.4567' )['reason'] );
		self::assertSame( 'unsupported_length', UpcNormalizer::inspect( '123456789' )['reason'] );
		self::assertSame( '123456789', UpcNormalizer::inspect( '123456789' )['normalized'] );
		self::assertSame( 'numeric_json', UpcNormalizer::inspect( 123456789012 )['reason'] );
		self::assertFalse( UpcNormalizer::inspect( 123456789012 )['valid'] );
		self::assertSame( '', UpcNormalizer::inspect( 123456789012 )['normalized'] );
	}
}
