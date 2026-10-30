<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

defined( 'ABSPATH' ) || exit;

/**
 * The vendor-side idempotency key. Endless Aisles rejects a repeated
 * `custom_order_id` per retailer account, so it must be stable across every
 * retry of one WooCommerce order and distinct across sites and environments.
 */
final class CustomOrderId {
	public static function for_order( int $wc_order_id, string $environment ): string {
		$site = function_exists( 'home_url' ) ? (string) home_url() : (string) get_option( 'home', '' );
		return 'ea' . substr( hash( 'sha256', strtolower( untrailingslashit( $site ) ) ), 0, 8 ) . '-' . ( 'production' === $environment ? 'prod' : 'qa' ) . '-' . $wc_order_id;
	}
}
