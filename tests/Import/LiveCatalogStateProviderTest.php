<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Import\LiveCatalogStateProvider;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use IdeaXperts\EndlessAisles\Tests\Support\ReadOnlyProduct;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test fixture globals are shared by bootstrap fakes.
final class LiveCatalogStateProviderTest extends TestCase {
	private LiveCatalogStateProvider $provider;
	/** @var array<string,mixed> */
	private array $item;

	protected function setUp(): void {
		$GLOBALS['wpdb']                                      = new DryRunMemoryWpdb();
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings'] = array(
			'use_global_unique_id' => 'yes',
			'upc_meta_keys'        => array( 'upc' ),
			'allow_sku_upc_match'  => 'yes',
		);
		$GLOBALS['ea_wc_product_map']                         = array();
		$GLOBALS['ea_wc_products']                            = array( new ReadOnlyProduct( 5, 'simple', 'publish', 'Target', '001234567890', 'SKU-1' ) );
		$this->provider                                       = new LiveCatalogStateProvider( new SettingsRepository() );
		$this->item = array(
			'ea_product_id'          => 'p1',
			'ea_option_id'           => 'o1',
			'normalized_upc'         => '009999999999',
			'target_wc_product_id'   => 5,
			'target_wc_variation_id' => 0,
		);
	}

	/** @dataProvider catalogMutations */
	public function test_authoritative_fingerprint_changes_for_relevant_catalog_mutations( callable $mutate ): void {
		$before = $this->provider->fingerprint( $this->item, 'endless-aisles:production', 'production', true );
		$mutate();
		$after = $this->provider->fingerprint( $this->item, 'endless-aisles:production', 'production', true );
		self::assertNotSame( $before, $after );
	}

	/** @return array<string,array{callable():void}> */
	public static function catalogMutations(): array {
		return array(
			'target deleted'      => array(
				static function (): void {
					$GLOBALS['ea_wc_products'] = array();
				},
			),
			'target UPC changed'  => array(
				static function (): void {
					$GLOBALS['ea_wc_products'] = array( new ReadOnlyProduct( 5, 'simple', 'publish', 'Target', '008888888888', 'SKU-1' ) );
				},
			),
			'target SKU changed'  => array(
				static function (): void {
					$GLOBALS['ea_wc_products'] = array( new ReadOnlyProduct( 5, 'simple', 'publish', 'Target', '001234567890', 'SKU-2' ) );
				},
			),
			'target type changed' => array(
				static function (): void {
					$GLOBALS['ea_wc_products'] = array( new ReadOnlyProduct( 5, 'variable', 'publish', 'Target', '001234567890', 'SKU-1' ) );
				},
			),
			'mapping added'       => array(
				static function (): void {
					$GLOBALS['wpdb']->insert(
						'wp_ideaxperts_ea_mappings',
						array(
							'source_scope'    => 'endless-aisles:production',
							'environment'     => 'production',
							'wc_product_id'   => 5,
							'wc_variation_id' => 0,
							'ea_product_id'   => 'p1',
							'ea_option_id'    => 'o1',
							'normalized_upc'  => '009999999999',
							'mapping_status'  => 'active',
						)
					);
				},
			),
		);
	}

	public function test_production_fingerprint_rejects_upc_owned_by_another_store_object(): void {
		$GLOBALS['ea_wc_products'][] = new ReadOnlyProduct( 8, 'simple', 'publish', 'Other', '009999999999', 'SKU-8' );
		$this->expectException( \RuntimeException::class );
		$this->provider->fingerprint( $this->item, 'endless-aisles:production', 'production', true );
	}

	public function test_variation_parent_change_changes_fingerprint(): void {
		$child                                = new ReadOnlyProduct( 11, 'variation', 'publish', 'Child', '009999999999', 'SKU-C' );
		$GLOBALS['ea_wc_product_map'][11]     = $child;
		$GLOBALS['ea_wc_products']            = array( new ReadOnlyProduct( 5, 'variable', 'publish', 'Parent', '', '', array( 11 ) ) );
		$this->item['target_wc_variation_id'] = 11;
		$before                               = $this->provider->fingerprint( $this->item, 'endless-aisles:production', 'production', true );
		$GLOBALS['ea_wc_products']            = array( new ReadOnlyProduct( 6, 'variable', 'publish', 'Other parent', '', '', array( 11 ) ) );
		$this->item['target_wc_product_id']   = 6;
		$after                                = $this->provider->fingerprint( $this->item, 'endless-aisles:production', 'production', true );
		self::assertNotSame( $before, $after );
	}

	/** @dataProvider mappingMutations */
	public function test_mapping_removal_or_redirect_changes_fingerprint( callable $mutate ): void {
		$GLOBALS['wpdb']->insert(
			'wp_ideaxperts_ea_mappings',
			array(
				'source_scope'    => 'endless-aisles:production',
				'environment'     => 'production',
				'wc_product_id'   => 5,
				'wc_variation_id' => 0,
				'ea_product_id'   => 'p1',
				'ea_option_id'    => 'o1',
				'normalized_upc'  => '009999999999',
				'mapping_status'  => 'active',
			)
		);
		$before = $this->provider->fingerprint( $this->item, 'endless-aisles:production', 'production', true );
		$mutate();
		$after = $this->provider->fingerprint( $this->item, 'endless-aisles:production', 'production', true );
		self::assertNotSame( $before, $after );
	}

	/** @return array<string,array{callable():void}> */
	public static function mappingMutations(): array {
		return array(
			'mapping removed'    => array(
				static function (): void {
										$GLOBALS['wpdb']->tables['wp_ideaxperts_ea_mappings'] = array(); },
			),
			'mapping redirected' => array(
				static function (): void {
										$GLOBALS['wpdb']->tables['wp_ideaxperts_ea_mappings'][0]['wc_product_id'] = 99; },
			),
		);
	}
}
