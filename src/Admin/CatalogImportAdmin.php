<?php
namespace IdeaXperts\EndlessAisles\Admin;

use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Database\Migrator;
use IdeaXperts\EndlessAisles\Database\ImportRepository;
use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ImportManager;
use IdeaXperts\EndlessAisles\Import\ImportPolicy;
use IdeaXperts\EndlessAisles\Import\CreationPreview;
use IdeaXperts\EndlessAisles\Catalog\DryRunManager;

defined( 'ABSPATH' ) || exit;

final class CatalogImportAdmin {
	private const CAPABILITY = 'manage_woocommerce';

	public function __construct( private readonly DryRunRepository $dry_runs, private readonly ImportRepository $imports, private readonly ApprovalManifest $manifests, private readonly ImportManager $manager, private readonly ImportPolicy $policy, private readonly CreationPreview $preview, private readonly DryRunManager $dry_run_manager ) {}

	public function register(): void {
		add_action( 'admin_post_ideaxperts_ea_production_preview', array( $this, 'start_production_preview' ) );
		add_action( 'admin_post_ideaxperts_ea_prepare_import', array( $this, 'prepare' ) );
		add_action( 'admin_post_ideaxperts_ea_confirm_import', array( $this, 'confirm' ) );
		add_action( 'admin_post_ideaxperts_ea_cancel_import', array( $this, 'cancel' ) );
	}

	public function start_production_preview(): void {
		$this->authorize( 'ideaxperts_ea_production_preview' );
		if ( ! ( new Migrator() )->ready() ) {
			wp_die( esc_html__( 'Database upgrade required / incomplete. Production Preview cannot start.', 'ideaxperts-endless-aisles' ) );
		}
		try {
			$this->dry_run_manager->start_production_preview( get_current_user_id() );
		} catch ( \RuntimeException ) {
			wp_die( esc_html__( 'Production preview requires enabled Production settings, credentials, a successful connection test, and no active dry run.', 'ideaxperts-endless-aisles' ) );
		}
		$this->redirect();
	}

	public function prepare(): void {
		$this->authorize( 'ideaxperts_ea_prepare_import' );
		$run_id        = isset( $_POST['dry_run_id'] ) ? absint( $_POST['dry_run_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the action-specific nonce above.
		$automatic_ids = isset( $_POST['item_ids'] ) && is_array( $_POST['item_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['item_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorize() verified the nonce; absint allowlists IDs.
		$manual_ids    = isset( $_POST['manual_item_ids'] ) && is_array( $_POST['manual_item_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['manual_item_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorize() verified the nonce; absint allowlists IDs.
		$ids           = array_values( array_unique( array_merge( $automatic_ids, $manual_ids ) ) );
		$run           = $this->dry_runs->run( $run_id );
		$items         = $this->dry_runs->items_by_ids( $run_id, $ids );
		if ( ! $run || count( $items ) !== count( array_unique( array_filter( $ids ) ) ) ) {
			wp_die( esc_html__( 'The import selection is invalid or stale.', 'ideaxperts-endless-aisles' ) );
		}
		$settings = $this->matching_settings();
		try {
			$this->validate_creation_selection( $run, $items, $settings );
			$built = $this->manifests->build( $run, $items, array_fill_keys( $manual_ids, true ), $this->imports->next_approval_generation( $run_id ), $settings );
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
			$this->validate_creation_selection( $dry_run, $items, $settings );
			$manual = array();
			foreach ( $manifest_items as $manifest_item ) {
				if ( is_array( $manifest_item ) && 'link' === (string) ( $manifest_item['action'] ?? '' ) ) {
					$manual[ (int) $manifest_item['dry_run_item_id'] ] = true;
				}
			}
			$current = $this->manifests->build( $dry_run, $items, $manual, (int) $manifest['approval_generation'], $settings );
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
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		echo '<h2>Controlled QA product creation</h2><p>Select 1 to 5 explicitly priced candidates from a completed QA dry run, review their exact fields, then confirm to queue Draft creation.</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="ideaxperts-endless-aisles"><input type="hidden" name="tab" value="catalog"><label>Completed QA dry run ID <input type="number" min="1" name="creation_dry_run_id"></label>';
		submit_button( 'Open QA candidates', 'secondary', 'submit', false );
		echo '</form>';
		$this->render_confirmation();
		global $wpdb;
		$table        = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$selected_run = isset( $_GET['creation_dry_run_id'] ) ? absint( $_GET['creation_dry_run_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only run selection.
		$run          = $selected_run > 0 ? $this->dry_runs->run( $selected_run ) : $wpdb->get_row( "SELECT * FROM {$table} WHERE environment = 'qa' AND status = 'completed' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_array( $run ) && 'qa' === $run['environment'] && 'completed' === $run['status'] ) {
			echo '<h2>' . esc_html__( 'Catalog import approval', 'ideaxperts-endless-aisles' ) . '</h2>';
			echo '<p>QA � Controlled creation saves real Draft products after an approved pricing policy and explicit confirmation.</p>';
			$this->render_preview_notice( (string) $run['environment'] );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ideaxperts_ea_prepare_import"><input type="hidden" name="dry_run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
			wp_nonce_field( 'ideaxperts_ea_prepare_import' );
			echo '<table class="widefat striped"><thead><tr><th>Approve</th><th>Vendor identity</th><th>Match source</th><th>WooCommerce target</th><th>Eligibility</th></tr></thead><tbody>';
			foreach ( $this->dry_runs->items( (int) $run['id'], 'new_product_candidate', '', $this->candidate_page(), 20 ) as $item ) {
				$decision  = $this->policy->evaluate( $item );
				$manual    = $this->policy->evaluate( $item, true );
				$is_link   = $manual['eligible'] && 'link' === $manual['action'];
				$is_create = $decision['eligible'] && 'create' === $decision['action'];
				$target    = (int) $item['wc_variation_id'] > 0 ? 'Variation #' . (int) $item['wc_variation_id'] . ' (parent #' . (int) $item['wc_product_id'] . ')' : 'Product #' . (int) $item['wc_product_id'];
				if ( 'new_product_candidate' === $item['classification'] ) {
					$preview               = $this->preview->inspect( $run, $item, $this->matching_settings() );
					$binding               = $preview['binding'];
					$is_create             = $is_create && $preview['eligible'];
					$decision['automatic'] = $is_create;
					$decision['reason']    = $preview['reason'];
					$target                = 'New simple Draft: ' . (string) $item['vendor_title'] . ' / Description: ' . substr( wp_strip_all_tags( (string) $item['vendor_option_description'] ), 0, 240 ) . ' / UPC ' . (string) $item['normalized_upc'] . ' / Price: ' . (string) ( $binding['regular_price'] ?? 'unresolved' ) . ' / Policy: ' . (string) ( $binding['pricing_id'] ?? 'unavailable' ) . ':' . (string) ( $binding['pricing_version'] ?? '' ) . ' / Approval reference: ' . (string) ( json_decode( (string) ( $binding['pricing_configuration'] ?? '{}' ), true )['approval_reference'] ?? 'unavailable' ) . ' / Vendor retail / wholesale / MAP: ' . (string) $item['retail_price'] . ' / ' . (string) $item['wholesale_price'] . ' / ' . (string) $item['map_price'] . ' / Purchasable: ' . (int) $item['purchasable'] . ' / Discontinued: ' . (int) $item['discontinued'] . ' / Classification: ' . (string) $item['classification'] . ' / Review flags: ' . (string) $item['review_flags'] . ' / Environment: ' . (string) $item['environment'] . ' / Observed UTC: ' . (string) $item['created_at'] . ' / Source completed UTC: ' . (string) $run['completed_at'] . ' / Mapping: ' . $preview['mapping_state'] . ' / Preview: ' . ( $preview['eligible'] ? 'eligible; explicit approval required' : $preview['reason'] );
				}
				echo '<tr><td>';
				if ( $is_link ) {
					echo '<label><input type="checkbox" name="manual_item_ids[]" value="' . esc_attr( (string) $item['id'] ) . '"> ' . esc_html__( 'Explicitly approve preview link', 'ideaxperts-endless-aisles' ) . '</label>';
				} elseif ( $is_create ) {
					echo '<label><input type="checkbox" name="item_ids[]" value="' . esc_attr( (string) $item['id'] ) . '"> ' . esc_html__( 'Create new Draft product', 'ideaxperts-endless-aisles' ) . '</label>';
				} elseif ( $decision['automatic'] ) {
					echo '<input type="checkbox" name="item_ids[]" value="' . esc_attr( (string) $item['id'] ) . '">';
				} else {
					echo '&mdash;';
				}
				echo '</td><td>' . esc_html( $item['ea_product_id'] . ' / ' . $item['ea_option_id'] ) . '</td><td>' . esc_html( (string) $item['classification'] ) . '</td><td>' . esc_html( $target ) . '</td><td>' . esc_html( $is_link ? 'Link only; merchant content remains unchanged.' : ( $decision['automatic'] ? $decision['action'] : $decision['reason'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			submit_button( __( 'Review selected import actions', 'ideaxperts-endless-aisles' ), 'secondary' );
			echo '</form>';
			$base = add_query_arg(
				array(
					'page'                => 'ideaxperts-endless-aisles',
					'tab'                 => 'catalog',
					'creation_dry_run_id' => (int) $run['id'],
				),
				admin_url( 'admin.php' )
			);
			echo '<p><a href="' . esc_url( add_query_arg( 'candidate_page', max( 1, $this->candidate_page() - 1 ), $base ) ) . '">Previous candidates</a> | <a href="' . esc_url( add_query_arg( 'candidate_page', $this->candidate_page() + 1, $base ) ) . '">Next candidates</a></p>';
		}
		$latest = $this->imports->latest_run();
		if ( $latest ) {
			$this->render_preview_notice( (string) $latest['environment'] );
			$counts = $this->imports->item_status_counts( (int) $latest['id'] );
			$manual = (int) ( $counts['manual_required'] ?? 0 ) + (int) ( $counts['manual_recovery'] ?? 0 );
			$failed = (int) ( $counts['blocked'] ?? 0 ) + (int) ( $counts['stale_snapshot'] ?? 0 );
			echo '<h3>' . esc_html__( 'Latest import run', 'ideaxperts-endless-aisles' ) . '</h3><p>' . esc_html( sprintf( '#%d — %s — %s — %d items', $latest['id'], strtoupper( (string) $latest['environment'] ), $latest['status'], $latest['item_count'] ) ) . '</p>';
			echo '<p>' . esc_html( sprintf( 'Ready: %d · Applied: %d · Manual: %d · Failed/blocked: %d', (int) ( $counts['ready'] ?? 0 ), (int) ( $counts['applied'] ?? 0 ), $manual, $failed ) ) . '</p>';
			foreach ( $this->imports->items( (int) $latest['id'], 5 ) as $result ) {
				echo '<p>' . esc_html( sprintf( '%s / %s � %s � %s � WooCommerce #%d', $result['ea_product_id'], $result['ea_option_id'], 'applied' === $result['status'] && (int) $result['target_wc_product_id'] > 0 ? 'created / already linked' : (string) $result['status'], $result['failure_code'], $result['target_wc_product_id'] ) ) . '</p>';
			}
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
		try {
			$manifest = $built['manifest'];
			$ids      = array();
			$manual   = array();
			foreach ( $manifest['items'] as $item ) {
				$ids[] = (int) $item['dry_run_item_id'];
				if ( 'link' === $item['action'] ) {
					$manual[ (int) $item['dry_run_item_id'] ] = true;
				}
			}
			$run   = $this->dry_runs->run( (int) $manifest['dry_run_id'] );
			$items = $this->dry_runs->items_by_ids( (int) $manifest['dry_run_id'], $ids );
			if ( ! $run || count( $items ) !== count( $ids ) ) {
				throw new \RuntimeException( 'preview_stale' );
			}
			$this->validate_creation_selection( $run, $items, $this->matching_settings() );
			$current = $this->manifests->build( $run, $items, $manual, (int) $manifest['approval_generation'], $this->matching_settings() );
			if ( ! hash_equals( (string) $built['hash'], $current['hash'] ) ) {
				throw new \RuntimeException( 'preview_stale' );
			}
		} catch ( \RuntimeException ) {
			echo '<p><strong>Stale or blocked approval projection.</strong> Request a fresh preview and explicitly approve it again. No work has been queued.</p>';
			return;
		}
		echo '<p><strong>Projection current.</strong> Awaiting explicit confirmation; the worker rechecks approval before any save.</p>';
		$description = 'qa' === ( $built['manifest']['environment'] ?? '' ) ? 'Confirm %d immutable controlled QA actions. Approved candidates create real WooCommerce Draft products.' : 'Confirm %d immutable import actions. Approved new candidates request real simple Draft products after policy validation; existing matches create plugin-owned links.';
		echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( $description, count( $built['manifest']['items'] ) ) ) . '</p></div>';
		foreach ( $built['manifest']['items'] as $approved ) {
			if ( ! is_array( $approved ) || 'create' !== ( $approved['action'] ?? '' ) || ! is_array( $approved['creation_binding'] ?? null ) || ! is_array( $approved['vendor'] ?? null ) ) {
				continue;
			}
			$binding = $approved['creation_binding'];
			$vendor  = $approved['vendor'];
			echo '<p><strong>' . esc_html( sanitize_text_field( (string) ( $vendor['vendor_title'] ?? '' ) ) ) . '</strong> / UPC ' . esc_html( (string) $binding['normalized_upc'] ) . ' / Regular price: ' . esc_html( '' === $binding['regular_price'] ? 'policy missing — reapproval required when available' : (string) $binding['regular_price'] ) . ' / Policy: ' . esc_html( (string) $binding['pricing_id'] . ':' . (string) $binding['pricing_version'] ) . '</p>';
			echo '<div>' . wp_kses_post( (string) ( $vendor['vendor_option_description'] ?? '' ) ) . '</div>';
			echo '<p>Approved field projection: <code>' . esc_html( (string) $binding['desired_hash'] ) . '</code></p>';
			echo '<p>Pricing configuration: <code>' . esc_html( (string) ( $binding['pricing_configuration'] ?? 'not configured' ) ) . '</code> / SHA-256: <code>' . esc_html( (string) $binding['pricing_config'] ) . '</code></p>';
		}
		$this->render_preview_notice( (string) ( $built['manifest']['environment'] ?? '' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ideaxperts_ea_confirm_import"><input type="hidden" name="confirmation_token" value="' . esc_attr( $token ) . '">';
		wp_nonce_field( 'ideaxperts_ea_confirm_import_' . $token );
		submit_button( __( 'Confirm and queue approved actions', 'ideaxperts-endless-aisles' ), 'primary' );
		echo '</form>';
	}

	/**
	 * @param array<string,mixed> $run
	 * @param list<array<string,mixed>> $items
	 * @param array<string,mixed> $settings
	 */
	private function validate_creation_selection( array $run, array $items, array $settings ): void {
		if ( array() === $items || count( $items ) > ApprovalManifest::MAX_QA_CREATIONS || 'qa' !== ( $run['environment'] ?? '' ) ) {
			throw new \RuntimeException( 'Select 1 to 5 QA candidates.' );
		}
		$configuration = get_option( 'ideaxperts_ea_settings', array() );
		if ( ! is_array( $configuration ) || 'qa' !== ( $configuration['environment'] ?? 'qa' ) ) {
			throw new \RuntimeException( 'Controlled creation requires active QA settings.' );
		}
		foreach ( $items as $item ) {
			if ( 'new_product_candidate' !== ( $item['classification'] ?? '' ) || ! $this->preview->inspect( $run, $item, $settings )['eligible'] ) {
				throw new \RuntimeException( 'Select eligible, explicitly priced QA candidates only.' );
			}
		}
	}

	private function candidate_page(): int {
		return isset( $_GET['candidate_page'] ) ? max( 1, absint( $_GET['candidate_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
	}

	private function render_preview_notice( string $environment ): void {
		if ( 'qa' === $environment ) {
			echo '<p><strong>' . esc_html__( 'QA only', 'ideaxperts-endless-aisles' ) . '</strong> — ' . esc_html__( 'Controlled approvals create real Draft products and QA mappings. Missing pricing policy blocks creation. Legacy preview approvals remain read-only.', 'ideaxperts-endless-aisles' ) . '</p>';
		}
	}

	private function authorize( string $nonce ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage catalog imports.', 'ideaxperts-endless-aisles' ) );
		}
		check_admin_referer( $nonce );
	}

	/** @return array{allow_sku_upc_match:mixed,controlled_qa_creation:bool} */
	private function matching_settings(): array {
		$settings = get_option( 'ideaxperts_ea_settings', array() );
		return array(
			'controlled_qa_creation' => true,
			'allow_sku_upc_match'    => is_array( $settings ) ? ( $settings['allow_sku_upc_match'] ?? 'no' ) : 'no',
		);
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
