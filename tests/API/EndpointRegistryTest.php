<?php
namespace IdeaXperts\EndlessAisles\Tests\API;

use IdeaXperts\EndlessAisles\API\EndpointRegistry;
use PHPUnit\Framework\TestCase;

final class EndpointRegistryTest extends TestCase {
	public function test_documented_order_creation_is_registered_but_not_used_as_connection_test(): void {
		$registry = new EndpointRegistry();
		self::assertSame(
			array(
				'method' => 'PUT',
				'path'   => '/api/orders',
			),
			$registry->all()['create_order']
		);
		self::assertSame( '/api/products?page=1&per_page=1', $registry->connection_test_path() );
	}
}
