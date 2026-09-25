<?php
namespace IdeaXperts\EndlessAisles\Tests\Catalog;

use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;
use PHPUnit\Framework\TestCase;

final class MatchClassifierTest extends TestCase {
	private MatchClassifier $classifier;

	protected function setUp(): void {
		$this->classifier = new MatchClassifier();
	}

	public function test_existing_mapping_has_priority_over_every_other_signal(): void {
		$option  = $this->option( array( 'discontinued' => true, 'purchasability' => false, 'price' => 2, 'wholesale' => 3 ) );
		$mapping = array(
			'ea_product_id'   => '10',
			'ea_option_id'    => '20',
			'wc_product_id'   => 7,
			'wc_variation_id' => 8,
		);
		$result  = $this->classifier->classify( $option, array( $mapping ), array( array( 'wc_product_id' => 9 ) ), array(), true, true );
		self::assertSame( 'already_linked', $result['classification'] );
		self::assertSame( 7, $result['wc_product_id'] );
		self::assertContains( 'discontinued', $result['review_flags'] );
		self::assertContains( 'not_purchasable', $result['review_flags'] );
		self::assertContains( 'duplicate_vendor_upc', $result['review_flags'] );
		self::assertContains( 'suspicious_price', $result['review_flags'] );
	}

	public function test_exact_sku_is_disabled_by_default(): void {
		$result = $this->classifier->classify( $this->option(), array(), array(), array( array( 'wc_product_id' => 9 ) ), false, false );
		self::assertSame( 'new_product_candidate', $result['classification'] );
		$result = $this->classifier->classify( $this->option(), array(), array(), array( array( 'wc_product_id' => 9 ) ), true, false );
		self::assertSame( 'exact_sku_match', $result['classification'] );
	}

	public function test_review_flags_do_not_replace_match_identity(): void {
		$flags = $this->classifier->classify( $this->option(), array(), array(), array(), false, true );
		self::assertSame( 'new_product_candidate', $flags['classification'] );
		self::assertContains( 'duplicate_vendor_upc', $flags['review_flags'] );

		$store = $this->classifier->classify( $this->option(), array(), array( array(), array() ), array(), false, false );
		self::assertSame( 'manual_review', $store['classification'] );
		self::assertContains( 'duplicate_store_upc', $store['review_flags'] );

		$missing = $this->classifier->classify( $this->option( array( 'upc' => '' ) ), array(), array(), array(), false, false );
		self::assertSame( 'manual_review', $missing['classification'] );
		self::assertContains( 'missing_vendor_upc', $missing['review_flags'] );

		$invalid = $this->classifier->classify( $this->option( array( 'upc' => 'ABC' ) ), array(), array(), array(), false, false );
		self::assertSame( 'manual_review', $invalid['classification'] );
		self::assertContains( 'invalid_vendor_upc', $invalid['review_flags'] );

		$numeric = $this->classifier->classify( $this->option( array( 'upc' => 123456789012 ) ), array(), array(), array(), false, false );
		self::assertSame( 'manual_review', $numeric['classification'] );
		self::assertContains( 'invalid_vendor_upc', $numeric['review_flags'] );

		$conflict = $this->classifier->classify(
			$this->option(),
			array(
				array(
					'ea_product_id' => 'other',
					'ea_option_id'  => '20',
				),
			),
			array(),
			array(),
			false,
			false
		);
		self::assertSame( 'manual_review', $conflict['classification'] );
		self::assertContains( 'conflicting_existing_mapping', $conflict['review_flags'] );
	}

	public function test_status_and_suspicious_prices_remain_flags_on_new_candidates(): void {
		$discontinued = $this->classifier->classify( $this->option( array( 'discontinued' => true ) ), array(), array(), array(), false, false );
		self::assertSame( 'new_product_candidate', $discontinued['classification'] );
		self::assertContains( 'discontinued', $discontinued['review_flags'] );

		$blocked = $this->classifier->classify( $this->option( array( 'purchasability' => false ) ), array(), array(), array(), false, false );
		self::assertSame( 'new_product_candidate', $blocked['classification'] );
		self::assertContains( 'not_purchasable', $blocked['review_flags'] );

		$price = $this->classifier->classify( $this->option( array( 'price' => 2, 'wholesale' => 3 ) ), array(), array(), array(), false, false );
		self::assertSame( 'new_product_candidate', $price['classification'] );
		self::assertContains( 'suspicious_price', $price['review_flags'] );
	}

	public function test_price_warnings_never_override_an_exact_upc_match(): void {
		$result = $this->classifier->classify(
			$this->option( array( 'price' => 2, 'wholesale' => 3 ) ),
			array(),
			array(
				array(
					'wc_product_id'   => 4,
					'wc_variation_id' => 5,
				),
			),
			array(),
			false,
			false
		);
		self::assertSame( 'exact_upc_match', $result['classification'] );
		self::assertContains( 'suspicious_price', $result['review_flags'] );
		self::assertSame( 4, $result['wc_product_id'] );
	}

	public function test_sanitize_flags_discards_unknown_and_duplicate_values(): void {
		self::assertSame(
			array( 'discontinued', 'suspicious_price' ),
			MatchClassifier::sanitize_flags( array( 'discontinued', 'discontinued', 'nope', 1, false, null, array(), 'suspicious_price' ) )
		);
		self::assertSame( array(), MatchClassifier::sanitize_flags( 'discontinued' ) );
	}

	/** @param array<string,mixed> $override
	 * @return array<string,mixed>
	 */
	private function option( array $override = array() ): array {
		return array_merge(
			array(
				'ea_product_id'  => '10',
				'id'             => '20',
				'upc'            => '001234567890',
				'price'          => 10,
				'wholesale'      => 5,
				'purchasability' => true,
				'discontinued'   => false,
			),
			$override
		);
	}
}
