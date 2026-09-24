<?php
namespace IdeaXperts\EndlessAisles\Tests\OrderIntegration;

use IdeaXperts\EndlessAisles\OrderIntegration\OrderSubmissionRepository;
use PHPUnit\Framework\TestCase;

final class OrderSubmissionRepositoryTest extends TestCase {
	public function test_duplicate_idempotency_key_cannot_be_reserved_twice(): void {
		$GLOBALS['wpdb'] = new OrderSubmissionWpdb();
		$repository      = new OrderSubmissionRepository();

		self::assertTrue( $repository->reserve( 42, array( 3, 8 ) ) );
		self::assertFalse( $repository->reserve( 42, array( 8, 3 ) ) );
		self::assertTrue( $repository->reserve( 42, array( 3, 9 ) ) );
	}
}

final class OrderSubmissionWpdb {
	public string $prefix = 'wp_';
	/** @var array<string,true> */
	private array $keys = array();

	/** @param array<string,mixed> $data @param list<string> $formats */
	public function insert( string $table, array $data, array $formats ): int|false {
		$key = (string) $data['idempotency_key'];
		if ( isset( $this->keys[ $key ] ) ) {
			return false;
		}
		$this->keys[ $key ] = true;
		return 1;
	}
}
