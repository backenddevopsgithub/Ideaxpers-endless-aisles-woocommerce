<?php
namespace IdeaXperts\EndlessAisles\Inventory;

use IdeaXperts\EndlessAisles\Import\WooSimpleProductWriter;

defined( 'ABSPATH' ) || exit;

/** Writes stock only to simple products carrying this plugin's creation markers for the same vendor option. */
final class WooStockWriter implements StockWriterInterface {
	public const OBSERVED_META = '_ideaxperts_ea_stock_observed_at';

	public function apply( array $mapping, string $environment, int $quantity, string $observed_at ): string {
		clean_post_cache( $mapping['wc_product_id'] );
		$product = wc_get_product( $mapping['wc_product_id'] );
		if ( ! $product instanceof \WC_Product_Simple ) {
			return self::MISSING;
		}
		if ( 'simple' !== $product->get_type() || '' === (string) $product->get_meta( WooSimpleProductWriter::OPERATION_META, true ) || (string) $product->get_meta( '_ideaxperts_ea_create_env', true ) !== $environment || (string) $product->get_meta( '_ideaxperts_ea_create_product', true ) !== $mapping['ea_product_id'] || (string) $product->get_meta( '_ideaxperts_ea_create_option', true ) !== $mapping['ea_option_id'] ) {
			return self::NOT_OWNED;
		}
		$previous = (string) $product->get_meta( self::OBSERVED_META, true );
		if ( '' !== $previous && strcmp( $previous, $observed_at ) > 0 ) {
			return self::STALE;
		}
		if ( $product->get_manage_stock() && $quantity === $product->get_stock_quantity() && 'no' === $product->get_backorders() ) {
			return self::UNCHANGED;
		}
		$product->set_manage_stock( true );
		$product->set_backorders( 'no' );
		$product->set_stock_quantity( $quantity );
		$product->update_meta_data( self::OBSERVED_META, $observed_at );
		$product->save();
		return self::UPDATED;
	}
}
