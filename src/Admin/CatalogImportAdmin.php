<?php
namespace IdeaXperts\EndlessAisles\Admin;

use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Database\ImportRepository;
use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ImportManager;
use IdeaXperts\EndlessAisles\Import\ImportPolicy;

defined( 'ABSPATH' ) || exit;

final class CatalogImportAdmin {
	private const CAPABILITY = 'manage_woocommerce';

	public function __construct( private readonly DryRunRepository $dry_runs, private readonly ImportRepository $imports, private readonly ApprovalManifest $manifests, private readonly ImportManager $manager, private readonly ImportPolicy $policy ) {}

	public function register(): void {
		add_action( 'admin_post_ideaxperts_ea_prepare_import', array( $this, 'prepare' ) );
		add_action( 'admin_post_ideaxperts_ea_confirm_import', array( $this, 'confirm' ) );
		add_action( 'admin_post_ideaxperts_ea_cancel_import', array( $this, 'cancel' ) );
	}

	public function prepare(): void {
		$this->authorize( 'ideaxperts_ea_prepare_import' );
		$run_id = isset( $_POST['dry_run_id'] ) ? absint( $_POST['dry_run_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the action-specific nonce above.
		$ids    = isset( $_POST['item_ids'] ) && is_array( $_POST['item_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['item_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorize() verified the nonce; absint allowlists IDs.
		$run    = $this->dry_runs->run( $run_id );
		$items  = $this->dry_runs->items_by_ids( $run_id, $ids );
		if ( ! $run || count( $items ) !== count( array_unique( array_filter( $ids ) ) ) ) {
			wp_die( esc_html__( 'The import selection is invalid or stale.', 'ideaxperts-endless-aisles' ) );
		}
		$settings = $this->matching_settings();
		try {
			$built = $this->manifests->build( $run, $items, array(), $this->imports->next_approval_generation( $run_id ), $settings );
		} catch ( \RuntimeException ) {
			wp_die( esc_html__( 'The import selection contains an ineligible or stale item.', 'ideaxperts-endless-aisles' ) );
		}
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( 'ideaxperts_ea_import_' . get_current_user_id() . '_' . $token, $built, 15 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'ideaxperts-endless-aisles',
					'tab'            => 'catalog',
					'confirm_import' => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function confirm(): void {
		$token = isset( $_POST['confirmation_token'] ) ? sanitize_key( wp_unslash( $_POST['confirmation_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Token is needed to derive the nonce action checked next.
		$this->authorize( 'ideaxperts_ea_confirm_import_' . $token );
		$key   = 'ideaxperts_ea_import_' . get_current_user_id() . '_' . $token;
		$built = get_transient( $key );
		if ( ! is_array( $built ) || ! isset( $built['manifest'], $built['hash'] ) || ! is_array( $built['manifest'] ) || ! $this->manifests->verify( $built['manifest'], (string) $built['hash'] ) ) {
			wp_die( esc_html__( 'The approval manifest is missing, expired, or invalid.', 'ideaxperts-endless-aisles' ) );
		}
		$manifest       = $built['manifest'];
		$manifest_items = is_array( $manifest['items'] ?? null ) ? $manifest['items'] : array();
		$item_ids       = array();
		foreach ( $manifest_items as $item ) {
			if ( ! is_array( $item ) || (int) ( $item['dry_run_item_id'] ?? 0 ) < 1 ) {
				wp_die( esc_html__( 'The approval manifest contains an invalid item.', 'ideaxperts-endless-aisles' ) );
			}
			$item_ids[] = (int) $item['dry_run_item_id'];
		}
		$dry_run  = $this->dry_runs->run( (int) ( $manifest['dry_run_id'] ?? 0 ) );
		$items    = $this->dry_runs->items_by_ids( (int) ( $manifest['dry_run_id'] ?? 0 ), $item_ids );
		$settings = $this->matching_settings();
		if ( ! $dry_run || count( $items ) !== count( $item_ids ) ) {
			wp_die( esc_html__( 'The approved dry-run data is no longer available.', 'ideaxperts-endless-aisles' ) );
		}
		try {
			$current = $this->manifests->build( $dry_run, $items, array(), (int) $manifest['approval_generation'], $settings );
		} catch ( \RuntimeException ) {
			wp_die( esc_html__( 'The approved dry-run data is no longer eligible.', 'ideaxperts-endless-aisles' ) );
		}
		if ( ! hash_equals( (string) $built['hash'], $current['hash'] ) ) {
			wp_die( esc_html__( 'The approval is stale because its source data or matching settings changed.', 'ideaxperts-endless-aisles' ) );
		}
		$actor  = get_current_user_id();
		$run_id = $this->imports->create_from_manifest( $manifest, (string) $built['hash'], $actor );
		if ( $run_id < 1 ) {
			$existing = $this->imports->run_for_approval( (int) $manifest['dry_run_id'], (int) $manifest['approval_generation'], (string) $built['hash'], $actor );
			$run_id   = is_array( $existing ) ? (int) $existing['id'] : 0;
		}
		if ( $run_id < 1 || ! $this->manager->queue( $run_id ) ) {
			wp_die( esc_html__( 'The import foundation could not queue the approved work.', 'ideaxperts-endless-aisles' ) );
		}
		delete_transient( $key );
		$this->redirect();
	}

	public function cancel(): void {
		$this->authorize( 'ideaxperts_ea_cancel_import' );
		$run_id = isset( $_POST['import_run_id'] ) ? absint( $_POST['import_run_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the action-specific nonce above.
		$this->imports->request_cancellation( $run_id );
		$this->imports->finalize_cancellation( $run_id );
		$this->redirect();
	}

	public function render(): void {
		$this->render_confirmation();
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$run   = $wpdb->get_row( "SELECT * FROM {$table} WHERE environment IN ('qa','production') AND status = 'completed' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_array( $run ) ) {
			echo '<h2>' . esc_html__( 'Catalog import foundation', 'ideaxperts-endless-aisles' ) . '</h2>';
			echo '<p><strong>' . esc_html( strtoupper( (string) $run['environment'] ) ) . '</strong> — ' . esc_html__( 'Production catalog writes remain disabled in Milestone 3A.', 'ideaxperts-endless-aisles' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ideaxperts_ea_prepare_import"><input type="hidden" name="dry_run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
			wp_nonce_field( 'ideaxperts_ea_prepare_import' );
			echo '<table class="widefat striped"><thead><tr><th></th><th>Vendor identity</th><th>Classification</th><th>Eligibility</th></tr></thead><tbody>';
			foreach ( $this->dry_runs->items( (int) $run['id'], '', '', 1, 100 ) as $item ) {
				$decision = $this->policy->evaluate( $item );
				echo '<tr><td>' . ( $decision['automatic'] ? '<input type="checkbox" name="item_ids[]" value="' . esc_attr( (string) $item['id'] ) . '">' : '&mdash;' ) . '</td><td>' . esc_html( $item['ea_product_id'] . ' / ' . $item['ea_option_id'] ) . '</td><td>' . esc_html( $item['classification'] ) . '</td><td>' . esc_html( $decision['automatic'] ? $decision['action'] : $decision['reason'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
			submit_button( __( 'Review selected import actions', 'ideaxperts-endless-aisles' ), 'secondary' );
			echo '</form>';
		}
		$latest = $this->imports->latest_run();
		if ( $latest ) {
			$counts = $this->imports->item_status_counts( (int) $latest['id'] );
			$manual = (int) ( $counts['manual_required'] ?? 0 ) + (int) ( $counts['manual_recovery'] ?? 0 );
			$failed = (int) ( $counts['blocked'] ?? 0 ) + (int) ( $counts['stale_snapshot'] ?? 0 );
			echo '<h3>' . esc_html__( 'Latest import run', 'ideaxperts-endless-aisles' ) . '</h3><p>' . esc_html( sprintf( '#%d — %s — %s — %d items', $latest['id'], strtoupper( (string) $latest['environment'] ), $latest['status'], $latest['item_count'] ) ) . '</p>';
			echo '<p>' . esc_html( sprintf( 'Ready: %d · Applied: %d · Manual: %d · Failed/blocked: %d', (int) ( $counts['ready'] ?? 0 ), (int) ( $counts['applied'] ?? 0 ), $manual, $failed ) ) . '</p>';
			if ( in_array( (string) $latest['status'], array( 'queued', 'running', 'pausing_stale', 'awaiting_reapproval', 'failed_recoverable', 'cancelling' ), true ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ideaxperts_ea_cancel_import"><input type="hidden" name="import_run_id" value="' . esc_attr( (string) $latest['id'] ) . '">';
				wp_nonce_field( 'ideaxperts_ea_cancel_import' );
				submit_button( __( 'Cancel import run', 'ideaxperts-endless-aisles' ), 'secondary', 'submit', false );
				echo '</form>';
			}
		}
	}

	private function render_confirmation(): void {
		$token = isset( $_GET['confirm_import'] ) ? sanitize_key( wp_unslash( $_GET['confirm_import'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token ) {
			return;
		}
		$built = get_transient( 'ideaxperts_ea_import_' . get_current_user_id() . '_' . $token );
		if ( ! is_array( $built ) || ! isset( $built['manifest']['items'] ) ) {
			return;
		}
		echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( 'Confirm %d immutable import actions. Milestone 3A performs validation only and cannot write products.', count( $built['manifest']['items'] ) ) ) . '</p></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ideaxperts_ea_confirm_import"><input type="hidden" name="confirmation_token" value="' . esc_attr( $token ) . '">';
		wp_nonce_field( 'ideaxperts_ea_confirm_import_' . $token );
		submit_button( __( 'Confirm and queue validation', 'ideaxperts-endless-aisles' ), 'primary' );
		echo '</form>';
	}

	private function authorize( string $nonce ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage catalog imports.', 'ideaxperts-endless-aisles' ) );
		}
		check_admin_referer( $nonce );
	}

	/** @return array{allow_sku_upc_match:mixed} */
	private function matching_settings(): array {
		$settings = get_option( 'ideaxperts_ea_settings', array() );
		return array( 'allow_sku_upc_match' => is_array( $settings ) ? ( $settings['allow_sku_upc_match'] ?? 'no' ) : 'no' );
	}

	private function redirect(): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'ideaxperts-endless-aisles',
					'tab'  => 'catalog',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
