<?php
namespace IdeaXperts\EndlessAisles\Inventory;

use IdeaXperts\EndlessAisles\Import\WooSimpleProductWriter;

defined( 'ABSPATH' ) || exit;

/** A product is vendor-owned only when it carries this plugin's creation markers for the same option. */
final class ProductOwnership {
	/** @param array{ea_product_id:string,ea_option_id:string} $mapping */
	public static function owned( object $product, string $environment, array $mapping ): bool {
		return method_exists( $product, 'get_type' ) && method_exists( $product, 'get_meta' )
			&& 'simple' === $product->get_type()
			&& '' !== (string) $product->get_meta( WooSimpleProductWriter::OPERATION_META, true )
			&& (string) $product->get_meta( '_ideaxperts_ea_create_env', true ) === $environment
			&& (string) $product->get_meta( '_ideaxperts_ea_create_product', true ) === $mapping['ea_product_id']
			&& (string) $product->get_meta( '_ideaxperts_ea_create_option', true ) === $mapping['ea_option_id'];
	}
}
