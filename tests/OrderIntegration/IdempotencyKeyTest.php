<?php
namespace IdeaXperts\EndlessAisles\Tests\OrderIntegration;

use IdeaXperts\EndlessAisles\OrderIntegration\IdempotencyKey;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeyTest extends TestCase {
	public function test_key_is_stable_regardless_of_line_item_order(): void {
		self::assertSame( IdempotencyKey::generate( 42, array( 9, 2, 4 ) ), IdempotencyKey::generate( 42, array( 4, 9, 2 ) ) );
		self::assertSame( 64, strlen( IdempotencyKey::generate( 42, array( 2 ) ) ) );
	}
}
