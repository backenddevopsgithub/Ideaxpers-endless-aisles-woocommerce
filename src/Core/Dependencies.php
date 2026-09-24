<?php
namespace IdeaXperts\EndlessAisles\Core;

defined( 'ABSPATH' ) || exit;

final class Dependencies {
	public static function woocommerce_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	public static function register_notice(): void {
		if ( self::woocommerce_available() ) {
			return;
		}
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'IdeaXperts Endless Aisles requires WooCommerce to be installed and active. Integration services have not started.', 'ideaxperts-endless-aisles' );
		echo '</p></div>';
	}
}
