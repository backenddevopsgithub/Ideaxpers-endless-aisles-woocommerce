<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

final class FakeOrderItem {
	public function __construct( private readonly int $product_id, private readonly int $quantity, private readonly float $subtotal, private readonly ?object $product = null, private readonly int $variation_id = 0 ) {}

	public function get_product_id(): int {
		return $this->product_id;
	}
	public function get_variation_id(): int {
		return $this->variation_id;
	}
	public function get_quantity(): int {
		return $this->quantity;
	}
	public function get_subtotal(): float {
		return $this->subtotal;
	}
	public function get_product(): ?object {
		return $this->product;
	}

	/** A simple product carrying this plugin's creation markers for the given vendor option. */
	public static function owned_product( string $ea_product, string $ea_option, string $environment = 'qa' ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		foreach (
			array(
				'_ideaxperts_ea_create_operation' => 'op-' . $ea_option,
				'_ideaxperts_ea_create_env'       => $environment,
				'_ideaxperts_ea_create_product'   => $ea_product,
				'_ideaxperts_ea_create_option'    => $ea_option,
			) as $key => $value
		) {
			$product->update_meta_data( $key, $value );
		}
		return $product;
	}
}
