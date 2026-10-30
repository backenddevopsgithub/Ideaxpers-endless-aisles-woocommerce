<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

use IdeaXperts\EndlessAisles\Inventory\ProductOwnership;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the canonical `PUT /api/orders` body for the vendor lines of one
 * WooCommerce order. The same order must always produce the same hash, so a
 * retry can prove it re-sends exactly what was frozen at intent time.
 *
 * Merchant decisions still open (docs/ORDER-SUBMISSION-DESIGN.md §11) are kept
 * at their conservative defaults: unit price is the pre-discount, pre-tax line
 * subtotal; customer email and phone are not sent; US addresses only.
 */
final class OrderPayloadBuilder {
	private const STATE_PATTERN = '/\A[A-Z]{2}\z/';

	/**
	 * @param array<int,array{id:int,wc_product_id:int,ea_product_id:string,ea_option_id:string}> $mappings Keyed by product ID.
	 * @return OrderPayload|null Null when the order has no vendor lines.
	 * @throws OrderPayloadException With a stable failure code when vendor lines exist but cannot be sent.
	 */
	public function build( object $order, string $environment, array $mappings ): ?OrderPayload {
		$line_items = array();
		$snapshot   = array();
		$items      = method_exists( $order, 'get_items' ) ? (array) $order->get_items( 'line_item' ) : array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$product_id = (int) $item->get_product_id();
			if ( ! isset( $mappings[ $product_id ] ) ) {
				continue; // Merchant-fulfilled line in a mixed cart.
			}
			$mapping = $mappings[ $product_id ];
			if ( ! method_exists( $item, 'get_variation_id' ) || ! method_exists( $item, 'get_product' ) || ! method_exists( $item, 'get_quantity' ) || ! method_exists( $item, 'get_subtotal' ) ) {
				throw new OrderPayloadException( 'line_item_invalid' );
			}
			if ( 0 !== (int) $item->get_variation_id() ) {
				throw new OrderPayloadException( 'line_variation_unsupported' );
			}
			$product = $item->get_product();
			if ( ! is_object( $product ) || ! ProductOwnership::owned( $product, $environment, $mapping ) ) {
				throw new OrderPayloadException( 'line_not_vendor_owned' );
			}
			if ( ! ctype_digit( $mapping['ea_option_id'] ) || strlen( $mapping['ea_option_id'] ) > 18 ) {
				throw new OrderPayloadException( 'line_identifier_invalid' );
			}
			$quantity = (int) $item->get_quantity();
			$subtotal = (float) $item->get_subtotal();
			if ( $quantity < 1 ) {
				throw new OrderPayloadException( 'line_quantity_invalid' );
			}
			$price = round( $subtotal / $quantity, 2 );
			if ( $price <= 0 ) {
				throw new OrderPayloadException( 'line_price_invalid' );
			}
			$line_items[] = array(
				'id'       => (int) $mapping['ea_option_id'],
				'quantity' => $quantity,
				'price'    => $price,
			);
			$snapshot[]   = array(
				'wc_product_id' => $product_id,
				'ea_product_id' => $mapping['ea_product_id'],
				'ea_option_id'  => $mapping['ea_option_id'],
				'quantity'      => $quantity,
				'price'         => number_format( $price, 2, '.', '' ),
			);
		}
		if ( array() === $line_items ) {
			return null;
		}
		usort( $line_items, static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] );
		usort( $snapshot, static fn( array $a, array $b ): int => strcmp( $a['ea_option_id'], $b['ea_option_id'] ) );
		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		if ( $order_id < 1 ) {
			throw new OrderPayloadException( 'order_invalid' );
		}
		$payload = array(
			'custom_order_id' => CustomOrderId::for_order( $order_id, $environment ),
			'shipping'        => $this->shipping( $order ),
			'line_items'      => $line_items,
		);
		return new OrderPayload( $payload, $snapshot, self::hash( $payload ) );
	}

	/** @param array<string,mixed> $payload */
	public static function hash( array $payload ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonical( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	/** @return array<string,string> */
	private function shipping( object $order ): array {
		$use_shipping = method_exists( $order, 'has_shipping_address' ) && $order->has_shipping_address();
		$prefix       = $use_shipping ? 'shipping' : 'billing';
		$field        = static function ( string $name ) use ( $order, $prefix ): string {
			$method = 'get_' . $prefix . '_' . $name;
			return method_exists( $order, $method ) ? trim( sanitize_text_field( (string) $order->$method() ) ) : '';
		};
		$country      = strtoupper( $field( 'country' ) );
		if ( '' !== $country && 'US' !== $country ) {
			throw new OrderPayloadException( 'shipping_country_unsupported' );
		}
		$shipping = array(
			'first_name'     => $field( 'first_name' ),
			'last_name'      => $field( 'last_name' ),
			'address_line_1' => $field( 'address_1' ),
			'city'           => $field( 'city' ),
			'state'          => strtoupper( $field( 'state' ) ),
			'zip'            => $field( 'postcode' ),
		);
		foreach ( $shipping as $value ) {
			if ( '' === $value ) {
				throw new OrderPayloadException( 'shipping_address_incomplete' );
			}
		}
		if ( 1 !== preg_match( self::STATE_PATTERN, $shipping['state'] ) ) {
			throw new OrderPayloadException( 'shipping_state_invalid' );
		}
		$line_2 = $field( 'address_2' );
		if ( '' !== $line_2 ) {
			$shipping['address_line_2'] = $line_2;
		}
		$shipping['country'] = 'US';
		return $shipping;
	}

	private static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return array_map( array( self::class, 'canonical' ), $value );
	}
}
