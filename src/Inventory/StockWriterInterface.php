<?php
namespace IdeaXperts\EndlessAisles\Inventory;

defined( 'ABSPATH' ) || exit;

interface StockWriterInterface {
	public const UPDATED   = 'updated';
	public const UNCHANGED = 'unchanged';
	public const MISSING   = 'product_missing';
	public const NOT_OWNED = 'not_owned';
	public const STALE     = 'stale_observation';

	/**
	 * Apply one vendor quantity to a plugin-created product.
	 *
	 * @param array{id:int,wc_product_id:int,ea_product_id:string,ea_option_id:string} $mapping
	 */
	public function apply( array $mapping, string $environment, int $quantity, string $observed_at ): string;
}
