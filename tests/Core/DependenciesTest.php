<?php
namespace IdeaXperts\EndlessAisles\Tests\Core;

use IdeaXperts\EndlessAisles\Core\Dependencies;
use PHPUnit\Framework\TestCase;

final class DependenciesTest extends TestCase {
	public function test_woocommerce_dependency_is_detected_by_its_runtime_class(): void {
		self::assertFalse( Dependencies::woocommerce_available() );
	}
}
