<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Import\ImportPolicy;
use PHPUnit\Framework\TestCase;

final class ImportPolicyTest extends TestCase {
	private ImportPolicy $policy;

	protected function setUp(): void {
		$this->policy = new ImportPolicy();
	}

	public function test_only_safe_new_and_linked_items_are_automatic(): void {
		self::assertSame( 'create', $this->policy->evaluate( $this->item( 'new_product_candidate' ) )['action'] );
		self::assertTrue( $this->policy->evaluate( $this->item( 'new_product_candidate' ) )['automatic'] );
		self::assertSame( 'validate', $this->policy->evaluate( $this->item( 'already_linked' ) )['action'] );
		self::assertFalse( $this->policy->evaluate( $this->item( 'exact_upc_match', array(), 9 ) )['eligible'] );
		self::assertFalse( $this->policy->evaluate( $this->item( 'exact_sku_match', array(), 9 ) )['eligible'] );
	}

	public function test_manual_matches_require_explicit_resolution_and_persisted_target(): void {
		self::assertTrue( $this->policy->evaluate( $this->item( 'exact_upc_match', array(), 9 ), true )['eligible'] );
		self::assertSame( 'link', $this->policy->evaluate( $this->item( 'exact_sku_match', array(), 9 ), true )['action'] );
		self::assertFalse( $this->policy->evaluate( $this->item( 'exact_upc_match' ), true )['eligible'] );
	}

	/** @dataProvider blocking_flags */
	public function test_blocking_flags_cannot_be_overridden_by_selection( string $flag ): void {
		self::assertFalse( $this->policy->evaluate( $this->item( 'new_product_candidate', array( $flag ) ), true )['eligible'] );
	}

	/** @return array<string,array{string}> */
	public static function blocking_flags(): array {
		return array_combine( array( 'missing_vendor_upc', 'invalid_vendor_upc', 'duplicate_vendor_upc', 'duplicate_store_upc', 'conflicting_existing_mapping', 'ambiguous_match' ), array_map( static fn( string $flag ): array => array( $flag ), array( 'missing_vendor_upc', 'invalid_vendor_upc', 'duplicate_vendor_upc', 'duplicate_store_upc', 'conflicting_existing_mapping', 'ambiguous_match' ) ) );
	}

	public function test_discontinued_unmapped_is_skipped_and_review_flags_remain_distinct(): void {
		self::assertSame( 'skip', $this->policy->evaluate( $this->item( 'new_product_candidate', array( 'discontinued' ) ) )['action'] );
		self::assertFalse( $this->policy->evaluate( $this->item( 'new_product_candidate', array( 'suspicious_price' ) ) )['eligible'] );
		self::assertFalse( $this->policy->evaluate( $this->item( 'new_product_candidate', array( 'suspicious_price' ) ), true )['eligible'] );
	}

	/** @param list<string> $flags @return array<string,mixed> */
	private function item( string $classification, array $flags = array(), int $wc_product_id = 0 ): array {
		return array(
			'classification'  => $classification,
			'review_flags'    => wp_json_encode( $flags ),
			'wc_product_id'   => $wc_product_id,
			'wc_variation_id' => 0,
		);
	}
}
