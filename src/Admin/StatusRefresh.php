<?php
namespace IdeaXperts\EndlessAisles\Admin;

defined( 'ABSPATH' ) || exit;

/** Recognizes only the dedicated catalog status GET, before mutating boot hooks. */
final class StatusRefresh {
	public const NONCE_ACTION = 'ideaxperts_ea_status_refresh';

	public static function requested(): bool {
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$page    = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$refresh = isset( $_GET['ea_status_refresh'] ) && is_string( $_GET['ea_status_refresh'] ) ? sanitize_key( wp_unslash( $_GET['ea_status_refresh'] ) ) : '';
		return 'GET' === $method && 'admin.php' === ( $GLOBALS['pagenow'] ?? '' ) && 'ideaxperts-endless-aisles' === $page && 'catalog' === $tab && '1' === $refresh;
	}

	public static function authorize(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to view catalog status.', 'ideaxperts-endless-aisles' ) );
		}
		$nonce = isset( $_GET['_ea_status_nonce'] ) && is_string( $_GET['_ea_status_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_ea_status_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'The status refresh link has expired or is invalid. Open the Catalog tab for a fresh link.', 'ideaxperts-endless-aisles' ) );
		}
	}
}
