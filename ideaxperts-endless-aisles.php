<?php
/**
 * Plugin Name:       IdeaXperts Endless Aisles for WooCommerce
 * Description:       Secure integration foundation for Small Town Pets Endless Aisles.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            IdeaXperts
 * Text Domain:       ideaxperts-endless-aisles
 * Domain Path:       /languages
 * WC requires at least: 8.0
 *
 * @package IdeaXperts\EndlessAisles
 */

defined( 'ABSPATH' ) || exit;

define( 'IDEAXPERTS_EA_VERSION', '0.1.0' );
define( 'IDEAXPERTS_EA_FILE', __FILE__ );
define( 'IDEAXPERTS_EA_PATH', plugin_dir_path( __FILE__ ) );

$ideaxperts_ea_autoloader = IDEAXPERTS_EA_PATH . 'vendor/autoload.php';
if ( is_readable( $ideaxperts_ea_autoloader ) ) {
	require_once $ideaxperts_ea_autoloader;
} else {
	add_action(
		'admin_notices',
		static function (): void {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'IdeaXperts Endless Aisles could not start because Composer dependencies are missing. Run composer install.', 'ideaxperts-endless-aisles' );
				echo '</p></div>';
			}
		}
	);
	return;
}

register_activation_hook( __FILE__, array( IdeaXperts\EndlessAisles\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( IdeaXperts\EndlessAisles\Core\Deactivator::class, 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function (): void {
		$features_util = 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
		if ( class_exists( $features_util ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		IdeaXperts\EndlessAisles\Plugin::instance()->boot();
	},
	20
);
