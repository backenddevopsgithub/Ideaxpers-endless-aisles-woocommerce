<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ImportPolicy;
use IdeaXperts\EndlessAisles\Tests\Support\FixedCatalogStateProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApprovalManifestTest extends TestCase {
	public function test_manifest_is_canonical_immutable_and_server_derived(): void {
		$builder = new ApprovalManifest( new ImportPolicy(), new FixedCatalogStateProvider() );
		$run     = array(
			'id'               => 7,
			'status'           => 'completed',
			'environment'      => 'qa',
			'claim_generation' => 2,
		);
		$item    = array(
			'id'              => 3,
			'classification'  => 'new_product_candidate',
			'review_flags'    => '[]',
			'ea_product_id'   => 'p1',
			'ea_option_id'    => 'o1',
			'normalized_upc'  => '001234567890',
			'wc_product_id'   => 0,
			'wc_variation_id' => 0,
			'retail_price'    => '10',
			'vendor_title'    => 'Safe title',
			'purchasable'     => 1,
			'discontinued'    => 0,
		);
		$built   = $builder->build( $run, array( $item ), array(), 1, array( 'sku' => 'no' ) );
		self::assertTrue( $builder->verify( $built['manifest'], $built['hash'] ) );
		self::assertSame( 'create', $built['manifest']['items'][0]['action'] );
		self::assertSame( 'endless-aisles:qa', $built['manifest']['source_scope'] );
		$tampered                       = $built['manifest'];
		$tampered['items'][0]['action'] = 'link';
		self::assertFalse( $builder->verify( $tampered, $built['hash'] ) );
		$tampered                                        = $built['manifest'];
		$tampered['items'][0]['target']['wc_product_id'] = 999;
		self::assertFalse( $builder->verify( $tampered, $built['hash'] ) );
	}

	public function test_wrong_environment_and_unsafe_selection_are_rejected(): void {
		$builder = new ApprovalManifest( new ImportPolicy(), new FixedCatalogStateProvider() );
		$this->expectException( RuntimeException::class );
		$builder->build(
			array(
				'id'          => 1,
				'status'      => 'completed',
				'environment' => 'local',
			),
			array(),
			array(),
			1,
			array()
		);
	}

	public function test_manual_match_cannot_be_smuggled_without_resolution(): void {
		$builder = new ApprovalManifest( new ImportPolicy(), new FixedCatalogStateProvider() );
		$this->expectException( RuntimeException::class );
		$builder->build(
			array(
				'id'          => 1,
				'status'      => 'completed',
				'environment' => 'production',
			),
			array(
				array(
					'id'             => 2,
					'classification' => 'exact_upc_match',
					'review_flags'   => '[]',
					'ea_product_id'  => 'p',
					'ea_option_id'   => 'o',
					'normalized_upc' => '001234567890',
					'wc_product_id'  => 9,
				),
			),
			array(),
			1,
			array()
		);
	}

	public function test_manual_match_with_explicit_persisted_resolution_becomes_link_only(): void {
		$builder = new ApprovalManifest( new ImportPolicy(), new FixedCatalogStateProvider() );
		$built   = $builder->build(
			array(
				'id'          => 1,
				'status'      => 'completed',
				'environment' => 'production',
			),
			array(
				array(
					'id'              => 2,
					'classification'  => 'exact_sku_match',
					'review_flags'    => '[]',
					'ea_product_id'   => 'p',
					'ea_option_id'    => 'o',
					'normalized_upc'  => '001234567890',
					'wc_product_id'   => 9,
					'wc_variation_id' => 0,
				),
			),
			array( 2 => true ),
			1,
			array()
		);

		self::assertSame( 'link', $built['manifest']['items'][0]['action'] );
		self::assertSame( 9, $built['manifest']['items'][0]['target']['wc_product_id'] );
	}
}
