<?php
namespace IdeaXperts\EndlessAisles\Tests\Catalog;

use IdeaXperts\EndlessAisles\Catalog\StoreCatalogScanner;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use IdeaXperts\EndlessAisles\Tests\Support\ReadOnlyProduct;
use PHPUnit\Framework\TestCase;

final class StoreCatalogScannerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']            = new DryRunMemoryWpdb();
		$GLOBALS['ea_wc_writes']    = array();
		$GLOBALS['ea_wc_reads']     = array();
		$GLOBALS['ea_wc_max_pages'] = 1;
		$GLOBALS['ea_test_options'] = array(
			'ideaxperts_ea_settings' => array(
				'use_global_unique_id' => 'yes',
				'upc_meta_keys'        => array(),
				'allow_sku_upc_match'  => 'no',
			),
		);
		$variation = new ReadOnlyProduct( 11, 'variation', 'publish', 'Widget Blue', '001234567891', '' );
		$parent    = new ReadOnlyProduct( 5, 'variable', 'publish', 'Widget', '001234567890', '', array( 11 ) );
		$GLOBALS['ea_wc_products']    = array( $parent );
		$GLOBALS['ea_wc_product_map'] = array( 11 => $variation );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_scanner_records_identifiers_without_woocommerce_writes(): void {
		$run_id  = $this->active_run();
		$scanner = new StoreCatalogScanner( new SettingsRepository(), new DryRunRepository() );
		$result  = $scanner->scan_batch( $run_id, 1, ( new DryRunRepository() )->claim_token( $run_id ) );
		self::assertSame( 1, $result['products'] );
		self::assertSame( 1, $result['variations'] );
		self::assertFalse( $result['has_more'] );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
		foreach ( $GLOBALS['ea_wc_reads'] as $read ) {
			self::assertContains( $read[0], array( 'wc_get_products', 'wc_get_product' ) );
		}
		$counts = ( new DryRunRepository() )->store_record_counts( $run_id );
		self::assertSame( 1, $counts['products'] );
		self::assertSame( 1, $counts['variations'] );
	}

	public function test_scanner_source_does_not_call_woocommerce_write_functions(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Catalog/StoreCatalogScanner.php' );
		foreach ( array( 'update_post_meta', 'delete_post_meta', 'wp_insert_post', 'wp_update_post', 'wc_update_product_stock', '->save(', '->update(', '->delete(' ) as $write ) {
			self::assertStringNotContainsString( $write, $source );
		}
	}

	public function test_collect_batch_uses_registered_wordpress_statuses_without_a_woocommerce_status_helper(): void {
		// Staging WooCommerce 10.7.0 does not provide wc_get_product_statuses().
		self::assertFalse( function_exists( 'wc_get_product_statuses' ) );
		$before  = $GLOBALS['wpdb']->tables;
		$scanner = new StoreCatalogScanner( new SettingsRepository(), new DryRunRepository() );
		$result  = $scanner->collect_batch( 1 );
		self::assertSame( 1, $result['products'] );
		self::assertSame( 1, $result['variations'] );
		self::assertCount( 2, $result['records'] );
		self::assertSame( array_keys( get_post_stati() ), $GLOBALS['ea_wc_reads'][0][1]['status'] );
		self::assertContains( 'draft', $GLOBALS['ea_wc_reads'][0][1]['status'] );
		self::assertSame( $before, $GLOBALS['wpdb']->tables );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
	}

	public function test_two_sources_on_one_variation_are_one_store_identity(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['upc_meta_keys'] = array( 'upc' );
		$variation = new ReadOnlyProduct( 11, 'variation', 'publish', 'Widget Blue', '001234567891', '', array(), array( 'upc' => '001234567891' ) );
		$parent    = new ReadOnlyProduct( 5, 'variable', 'publish', 'Widget', '', '', array( 11 ) );
		$GLOBALS['ea_wc_products']    = array( $parent );
		$GLOBALS['ea_wc_product_map'] = array( 11 => $variation );
		$run_id                       = $this->active_run();
		( new StoreCatalogScanner( new SettingsRepository(), new DryRunRepository() ) )->scan_batch( $run_id, 1, ( new DryRunRepository() )->claim_token( $run_id ) );
		$matches = ( new DryRunRepository() )->identifier_matches( $run_id, '001234567891', 'upc' );
		self::assertCount( 1, $matches );
	}

	private function active_run(): int {
		$runs   = new DryRunRepository();
		$run_id = $runs->claim_new( 1 );
		$runs->transition( $run_id, array( 'pending' ), array( 'status' => 'scanning_store' ), $runs->claim_token( $run_id ) );
		return $run_id;
	}
}
