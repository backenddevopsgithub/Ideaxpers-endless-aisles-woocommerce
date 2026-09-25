<?php
namespace IdeaXperts\EndlessAisles\Tests\Catalog;

use IdeaXperts\EndlessAisles\Catalog\PriceInspector;
use PHPUnit\Framework\TestCase;

final class PriceInspectorTest extends TestCase {
	public function test_documented_price_anomalies_are_reported(): void {
		$warnings = PriceInspector::warnings(
			array(
				'price'                    => '5',
				'wholesale'                => 6,
				'minimum_advertised_price' => 7,
				'msrp'                     => 'bad',
			)
		);
		self::assertContains( 'retail_below_wholesale', $warnings );
		self::assertContains( 'retail_below_map', $warnings );
		self::assertContains( 'invalid_msrp', $warnings );
		self::assertContains( 'missing_price', PriceInspector::warnings( array( 'wholesale' => 1 ) ) );
		self::assertContains(
			'non_positive_wholesale',
			PriceInspector::warnings(
				array(
					'price'     => 1,
					'wholesale' => 0,
				)
			)
		);
	}
}
