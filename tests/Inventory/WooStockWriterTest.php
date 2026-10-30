<?php
namespace IdeaXperts\EndlessAisles\Tests\Inventory;

use IdeaXperts\EndlessAisles\Inventory\StockWriterInterface;
use IdeaXperts\EndlessAisles\Inventory\WooStockWriter;
use IdeaXperts\EndlessAisles\Tests\Support\ReadOnlyProduct;
use PHPUnit\Framework\TestCase;

final class WooStockWriterTest extends TestCase {
	private const MAPPING = array(
		'id'            => 1,
		'wc_product_id' => 700,
		'ea_product_id' => '44095',
		'ea_option_id'  => '50770',
	);

	protected function setUp(): void {
		$GLOBALS['ea_wc_writes']      = array();
		$GLOBALS['ea_wc_product_map'] = array();
	}

	public function test_owned_product_receives_managed_stock_without_backorders(): void {
		$product = $this->owned();

		$outcome = ( new WooStockWriter() )->apply( self::MAPPING, 'qa', 12, '2026-10-30 09:00:00' );

		self::assertSame( StockWriterInterface::UPDATED, $outcome );
		self::assertTrue( $product->manage_stock );
		self::assertSame( 12, $product->stock_quantity );
		self::assertSame( 'no', $product->backorders );
		self::assertSame( '2026-10-30 09:00:00', $product->meta[ WooStockWriter::OBSERVED_META ] );
		self::assertSame( array( $product ), $GLOBALS['ea_wc_writes'] );
	}

	public function test_zero_quantity_is_written_so_woocommerce_marks_out_of_stock(): void {
		$product                 = $this->owned();
		$product->manage_stock   = true;
		$product->stock_quantity = 5;

		self::assertSame( StockWriterInterface::UPDATED, ( new WooStockWriter() )->apply( self::MAPPING, 'qa', 0, '2026-10-30 09:00:00' ) );
		self::assertSame( 0, $product->stock_quantity );
	}

	public function test_identical_stock_is_not_saved(): void {
		$product                 = $this->owned();
		$product->manage_stock   = true;
		$product->stock_quantity = 4;

		self::assertSame( StockWriterInterface::UNCHANGED, ( new WooStockWriter() )->apply( self::MAPPING, 'qa', 4, '2026-10-30 09:00:00' ) );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
	}

	public function test_older_observation_never_overwrites_newer_stock(): void {
		$product = $this->owned();
		$product->update_meta_data( WooStockWriter::OBSERVED_META, '2026-10-30 09:30:00' );

		self::assertSame( StockWriterInterface::STALE, ( new WooStockWriter() )->apply( self::MAPPING, 'qa', 1, '2026-10-30 09:00:00' ) );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
		self::assertNull( $product->stock_quantity );
	}

	public function test_products_without_matching_creation_markers_are_never_written(): void {
		$cases = array(
			'merchant product'  => array( '_ideaxperts_ea_create_operation' => '' ),
			'other environment' => array( '_ideaxperts_ea_create_env' => 'production' ),
			'other option'      => array( '_ideaxperts_ea_create_option' => '99999' ),
			'other product'     => array( '_ideaxperts_ea_create_product' => '1' ),
		);
		foreach ( $cases as $label => $override ) {
			$GLOBALS['ea_wc_writes'] = array();
			$product                 = $this->owned();
			foreach ( $override as $key => $value ) {
				$product->meta[ $key ] = $value;
			}
			self::assertSame( StockWriterInterface::NOT_OWNED, ( new WooStockWriter() )->apply( self::MAPPING, 'qa', 3, '2026-10-30 09:00:00' ), $label );
			self::assertSame( array(), $GLOBALS['ea_wc_writes'], $label );
		}
	}

	public function test_missing_or_non_simple_products_are_reported_missing(): void {
		self::assertSame( StockWriterInterface::MISSING, ( new WooStockWriter() )->apply( self::MAPPING, 'qa', 3, '2026-10-30 09:00:00' ) );

		$GLOBALS['ea_wc_product_map'][700] = new ReadOnlyProduct( 700, 'variable', 'publish', 'Name', '', '' );
		self::assertSame( StockWriterInterface::MISSING, ( new WooStockWriter() )->apply( self::MAPPING, 'qa', 3, '2026-10-30 09:00:00' ) );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
	}

	private function owned(): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		foreach (
			array(
				'_ideaxperts_ea_create_operation' => 'op-1',
				'_ideaxperts_ea_create_env'       => 'qa',
				'_ideaxperts_ea_create_product'   => '44095',
				'_ideaxperts_ea_create_option'    => '50770',
			) as $key => $value
		) {
			$product->update_meta_data( $key, $value );
		}
		$GLOBALS['ea_wc_product_map'][700] = $product;
		return $product;
	}
}
