<?php
namespace IdeaXperts\EndlessAisles\Inventory;

use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\API\StockService;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Live vendor stock check immediately before WooCommerce accepts an order.
 *
 * Only cart lines for actively mapped products are checked; other products are
 * never inspected. When the vendor cannot be asked, the merchant's
 * `checkout_unverified` setting decides (default: block).
 */
final class CheckoutStockGuard {
	public const ERROR_CODE = 'ideaxperts_ea_stock_unavailable';

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly StockService $stock,
		private readonly InventoryStoreInterface $store,
		private readonly StockWriterInterface $writer,
		private readonly DatabaseLogger $logger
	) {}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'validate_store_api_checkout' ), 20, 2 );
	}

	/** Classic checkout: errors added here stop order creation. */
	public function validate_classic_checkout( mixed $data, mixed $errors ): void {
		if ( ! $errors instanceof \WP_Error || ! function_exists( 'WC' ) || ! is_object( WC()->cart ) ) {
			return;
		}
		$lines = array();
		foreach ( (array) WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			$lines[] = array(
				'product_id'   => (int) ( $item['product_id'] ?? 0 ),
				'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
				'quantity'     => (int) ( $item['quantity'] ?? 0 ),
				'name'         => is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '',
			);
		}
		foreach ( $this->problems( $lines ) as $message ) {
			$errors->add( self::ERROR_CODE, $message );
		}
	}

	/** Block checkout: the order is still an unpaid checkout draft when this fires. */
	public function validate_store_api_checkout( mixed $order, mixed $request = null ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}
		$lines = array();
		foreach ( (array) $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$lines[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0,
				'quantity'     => method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0,
				'name'         => method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '',
			);
		}
		$problems = $this->problems( $lines );
		if ( array() === $problems ) {
			return;
		}
		$route_exception = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
		if ( class_exists( $route_exception ) ) {
			throw new $route_exception( self::ERROR_CODE, implode( ' ', $problems ), 409 );
		}
		throw new \RuntimeException( implode( ' ', $problems ) );
	}

	/**
	 * @param list<array{product_id:int,variation_id:int,quantity:int,name:string}> $lines
	 * @return list<string> Customer-facing messages; empty when checkout may proceed.
	 */
	public function problems( array $lines ): array {
		$requested = array();
		$names     = array();
		foreach ( $lines as $line ) {
			if ( $line['product_id'] < 1 || 0 !== $line['variation_id'] || $line['quantity'] < 1 ) {
				continue;
			}
			$requested[ $line['product_id'] ] = ( $requested[ $line['product_id'] ] ?? 0 ) + $line['quantity'];
			$names[ $line['product_id'] ]     = '' !== $line['name'] ? $line['name'] : ( $names[ $line['product_id'] ] ?? '' );
		}
		if ( array() === $requested ) {
			return array();
		}
		$environment = (string) $this->settings->get( 'environment', 'qa' );
		$mappings    = $this->store->mappings_for_products( 'endless-aisles:' . $environment, $environment, array_keys( $requested ) );
		if ( array() === $mappings ) {
			return array();
		}
		$quantities = array();
		$unverified = array();
		try {
			if ( 'yes' !== $this->settings->get( 'enabled', 'no' ) ) {
				throw new ApiException( 'Endless Aisles integration is disabled.' );
			}
			$skus = array();
			foreach ( $mappings as $product_id => $mapping ) {
				if ( StockService::valid_sku( $mapping['ea_option_id'] ) ) {
					$skus[] = $mapping['ea_option_id'];
				} else {
					$unverified[] = $product_id;
				}
			}
			foreach ( array_chunk( array_values( array_unique( $skus ) ), StockService::BATCH_SIZE ) as $chunk ) {
				$result     = $this->stock->quantities( $environment, $chunk );
				$quantities = $result['quantities'] + $quantities;
				foreach ( $result['missing'] as $sku ) {
					$quantities[ $sku ] = 0;
				}
				foreach ( $result['invalid'] as $sku ) {
					foreach ( $mappings as $product_id => $mapping ) {
						if ( $mapping['ea_option_id'] === $sku ) {
							$unverified[] = $product_id;
						}
					}
				}
			}
		} catch ( ApiException $exception ) {
			$this->logger->log(
				'error',
				'Checkout vendor stock verification failed.',
				array(
					'status' => $exception->getCode(),
					'policy' => $this->policy(),
				)
			);
			return 'allow' === $this->policy() ? array() : array( __( 'We could not confirm stock with our supplier for some items in your cart. Please try again in a few minutes.', 'ideaxperts-endless-aisles' ) );
		}
		$messages    = array();
		$observed_at = current_time( 'mysql', true );
		foreach ( $mappings as $product_id => $mapping ) {
			$name = '' !== ( $names[ $product_id ] ?? '' ) ? $names[ $product_id ] : __( 'An item in your cart', 'ideaxperts-endless-aisles' );
			if ( in_array( $product_id, $unverified, true ) ) {
				if ( 'allow' !== $this->policy() ) {
					/* translators: %s: product name. */
					$messages[] = sprintf( __( 'We could not confirm stock for "%s". Please try again in a few minutes.', 'ideaxperts-endless-aisles' ), $name );
				}
				continue;
			}
			$available = $quantities[ $mapping['ea_option_id'] ] ?? 0;
			try {
				// Refresh local stock so the storefront reflects what the vendor just reported.
				$this->writer->apply( $mapping, $environment, $available, $observed_at );
			} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// The live quantity below still decides; a failed local refresh never unblocks checkout.
			}
			if ( $requested[ $product_id ] > $available ) {
				$messages[] = 0 === $available
					/* translators: %s: product name. */
					? sprintf( __( '"%s" is no longer available from our supplier. Please remove it to continue.', 'ideaxperts-endless-aisles' ), $name )
					/* translators: 1: product name, 2: available quantity. */
					: sprintf( __( 'Only %2$d of "%1$s" are available from our supplier. Please reduce the quantity to continue.', 'ideaxperts-endless-aisles' ), $name, $available );
			}
		}
		if ( array() !== $messages ) {
			$this->logger->log( 'warning', 'Checkout blocked by live vendor stock verification.', array( 'items' => count( $messages ) ) );
		}
		return $messages;
	}

	private function policy(): string {
		return 'allow' === $this->settings->get( 'checkout_unverified', 'block' ) ? 'allow' : 'block';
	}
}
