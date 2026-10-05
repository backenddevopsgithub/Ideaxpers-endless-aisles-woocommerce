<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Import\CreationPricingPolicyInterface;
use IdeaXperts\EndlessAisles\Import\SimpleProductProjection;
use PHPUnit\Framework\TestCase;

final class SimpleProductProjectionTest extends TestCase {
	private function vendor(): array {
		return array( 'classification' => 'new_product_candidate', 'ea_product_id' => 'p', 'ea_option_id' => 'o', 'normalized_upc' => '001234567890', 'review_flags' => array(), 'purchasable' => 1, 'discontinued' => 0, 'vendor_title' => 'Safe', 'vendor_option_description' => '<p>Safe</p>' );
	}

	/** @dataProvider invalidVendors */
	public function test_projection_rejects_unsafe_snapshot( string $field, mixed $value ): void {
		$vendor = $this->vendor();
		$vendor[ $field ] = $value;
		$this->expectException( \RuntimeException::class );
		( new SimpleProductProjection() )->build( $vendor );
	}

	public static function invalidVendors(): array {
		$cases = array();
		foreach ( array( 'exact_upc_match', 'exact_sku_match', 'manual_review', 'already_linked' ) as $classification ) { $cases[] = array( 'classification', $classification ); }
		foreach ( array( 'discontinued', 'not_purchasable', 'suspicious_price', 'duplicate_vendor_upc', 'duplicate_store_upc', 'invalid_vendor_upc', 'conflicting_existing_mapping', 'ambiguous_match' ) as $flag ) { $cases[] = array( 'review_flags', array( $flag ) ); }
		return array_merge( $cases, array( array( 'purchasable', 0 ), array( 'discontinued', 1 ), array( 'normalized_upc', '123' ), array( 'normalized_upc', '00123456789X' ), array( 'ea_product_id', '' ), array( 'ea_option_id', '' ), array( 'vendor_title', str_repeat( 'x', 501 ) ), array( 'vendor_title', "bad\xFF" ), array( 'vendor_option_description', str_repeat( 'x', 16001 ) ) ) );
	}

	/** @dataProvider badPrices */
	public function test_approved_policy_output_still_requires_valid_price( string $price ): void {
		$policy = new class( $price ) implements CreationPricingPolicyInterface {
			public function policy_id(): string { return 'fixture'; }
			public function policy_version(): string { return 'v1'; }
			public function configuration_hash(): string { return hash( 'sha256', 'fixture' ); }
			public function __construct( private readonly string $price ) {}
			public function regular_price( array $vendor ): ?string { return $this->price; }
		};
		$this->expectException( \RuntimeException::class );
		( new SimpleProductProjection( $policy ) )->build( $this->vendor() );
	}

	public static function badPrices(): array {
		return array_map( static fn( string $price ): array => array( $price ), array( 'NaN', 'INF', '-1', '0', '1e2', '1,200', '1000000000', ' 10', '1.23456' ) );
	}

	public function test_no_weight_or_other_future_fields_are_inferred_from_vendor_data(): void {
		$vendor = array_merge( $this->vendor(), array( 'shipping_weight_lbs' => 'NaN', 'sale_price' => '1', 'image_url' => 'bad', 'dimensions' => array( 1, 2, 3 ) ) );
		self::assertSame( array( 'title', 'description', 'upc', 'regular_price', 'failure_code' ), array_keys( ( new SimpleProductProjection() )->build( $vendor ) ) );
	}
}
