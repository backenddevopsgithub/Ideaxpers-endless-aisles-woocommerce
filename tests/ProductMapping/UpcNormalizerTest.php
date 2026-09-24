<?php
namespace IdeaXperts\EndlessAisles\Tests\ProductMapping;

use IdeaXperts\EndlessAisles\ProductMapping\UpcNormalizer;
use PHPUnit\Framework\TestCase;

final class UpcNormalizerTest extends TestCase {
	public function test_it_preserves_leading_zeroes_and_removes_formatting(): void {
		self::assertSame( '001234567890', UpcNormalizer::normalize( ' 00-1234 567 890 ' ) );
	}
}
