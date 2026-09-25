<?php
namespace IdeaXperts\EndlessAisles\Admin;

use IdeaXperts\EndlessAisles\Catalog\CsvSanitizer;
use IdeaXperts\EndlessAisles\Catalog\DryRunManager;
use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class CatalogDryRunAdmin {
	private const CAPABILITY = 'manage_woocommerce';

	public function __construct( private readonly SettingsRepository $settings, private readonly DryRunRepository $runs, private readonly DryRunManager $manager, private readonly DatabaseLogger $logger ) {}

	public function register(): void {
		add_action( 'admin_post_ideaxperts_ea_start_dry_run', array( $this, 'start' ) );
		add_action( 'admin_post_ideaxperts_ea_local_discovery', array( $this, 'local_discovery' ) );
		add_action( 'admin_post_ideaxperts_ea_resume_dry_run', array( $this, 'resume' ) );
		add_action( 'admin_post_ideaxperts_ea_cancel_dry_run', array( $this, 'cancel' ) );
		add_action( 'admin_post_ideaxperts_ea_export_dry_run', array( $this, 'export' ) );
		add_action( 'admin_post_ideaxperts_ea_purge_dry_runs', array( $this, 'purge' ) );
	}

	public function start(): void {
		$this->authorize( 'ideaxperts_ea_start_dry_run' );
		try {
			$this->manager->start( get_current_user_id() );
			$this->redirect( 'started' );
		} catch ( RuntimeException $exception ) {
			$this->redirect( 'not_ready' );
		}
	}

	public function local_discovery(): void {
		$this->authorize( 'ideaxperts_ea_local_discovery' );
		try {
			$this->manager->start_local_discovery( get_current_user_id() );
			$this->redirect( 'local_started' );
		} catch ( RuntimeException $exception ) {
			$this->redirect( 'not_ready' );
		}
	}

	public function resume(): void {
		$this->authorize( 'ideaxperts_ea_resume_dry_run' );
		$this->manager->resume( $this->posted_run_id() );
		$this->redirect( 'resumed' );
	}

	public function cancel(): void {
		$this->authorize( 'ideaxperts_ea_cancel_dry_run' );
		$this->manager->cancel( $this->posted_run_id() );
		$this->redirect( 'cancelled' );
	}

	public function purge(): void {
		$this->authorize( 'ideaxperts_ea_purge_dry_runs' );
		$this->manager->purge_expired();
		$this->logger->log( 'info', 'Expired catalog dry-run records were purged.' );
		$this->redirect( 'purged' );
	}

	public function export(): void {
		$this->authorize( 'ideaxperts_ea_export_dry_run' );
		$run_id = $this->posted_run_id();
		$run    = $this->runs->run( $run_id );
		if ( ! $run || 'completed' !== $run['status'] ) {
			wp_die( esc_html__( 'Only a completed dry run can be exported.', 'ideaxperts-endless-aisles' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="endless-aisles-dry-run-' . $run_id . '.csv"' );
		$output = fopen( 'php://output', 'wb' );
		if ( false === $output ) {
			wp_die( esc_html__( 'CSV output could not be opened.', 'ideaxperts-endless-aisles' ) );
		}
		$columns = array( 'ea_product_id', 'ea_option_id', 'original_upc', 'normalized_upc', 'wc_product_id', 'wc_variation_id', 'classification', 'review_flags', 'review_reason', 'vendor_title', 'vendor_option_description', 'retail_price', 'wholesale_price', 'map_price', 'purchasable', 'discontinued' );
		fputcsv( $output, $columns );
		for ( $page = 1; ; ++$page ) {
			$items = $this->runs->items( $run_id, '', '', $page, 100 );
			foreach ( $items as $item ) {
				fputcsv( $output, array_map( static fn( string $column ): string => CsvSanitizer::cell( $item[ $column ] ?? '' ), $columns ) );
			}
			if ( count( $items ) < 100 ) {
				break;
			}
		}
		$this->logger->log( 'info', 'Catalog dry-run CSV exported.', array( 'run_id' => $run_id ) );
		fclose( $output );
		exit;
	}

	public function render(): void {
		$active_id        = $this->runs->active_id();
		$active           = $active_id ? $this->runs->run( $active_id ) : null;
		$connection       = get_option( 'ideaxperts_ea_qa_connection_status', array() );
		$ready            = 'yes' === $this->settings->get( 'enabled', 'no' ) && 'qa' === $this->settings->get( 'environment', 'qa' ) && $this->settings->token_configured( 'qa' ) && is_array( $connection ) && 'connected' === ( $connection['status'] ?? '' );
		$product_counts   = wp_count_posts( 'product' );
		$variation_counts = wp_count_posts( 'product_variation' );
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Read-only dry run:', 'ideaxperts-endless-aisles' ) . '</strong> ' . esc_html__( 'No WooCommerce products, variations, terms, images, orders, customers, or prices are modified.', 'ideaxperts-endless-aisles' ) . '</p></div>';
		echo '<h2>' . esc_html__( 'Readiness and discovery', 'ideaxperts-endless-aisles' ) . '</h2><ul>';
		echo '<li>' . esc_html__( 'QA credential:', 'ideaxperts-endless-aisles' ) . ' ' . esc_html( $this->settings->token_configured( 'qa' ) ? 'Configured' : 'Missing' ) . '</li>';
		echo '<li>' . esc_html__( 'QA connection:', 'ideaxperts-endless-aisles' ) . ' ' . esc_html( is_array( $connection ) ? (string) ( $connection['status'] ?? 'Not tested' ) : 'Not tested' ) . '</li>';
		echo '<li>' . esc_html__( 'Products / variations:', 'ideaxperts-endless-aisles' ) . ' ' . esc_html( (string) $this->count_posts( $product_counts ) . ' / ' . (string) $this->count_posts( $variation_counts ) ) . '</li>';
		echo '<li>' . esc_html__( 'Global unique ID:', 'ideaxperts-endless-aisles' ) . ' ' . esc_html( 'yes' === $this->settings->get( 'use_global_unique_id', 'yes' ) ? 'Enabled' : 'Disabled' ) . '</li>';
		$metadata_keys = implode( ', ', (array) $this->settings->get( 'upc_meta_keys', array() ) );
		echo '<li>' . esc_html__( 'Additional metadata keys:', 'ideaxperts-endless-aisles' ) . ' ' . esc_html( '' !== $metadata_keys ? $metadata_keys : 'None' ) . '</li>';
		echo '<li>' . esc_html__( 'Exact SKU matching:', 'ideaxperts-endless-aisles' ) . ' ' . esc_html( 'yes' === $this->settings->get( 'allow_sku_upc_match', 'no' ) ? 'Enabled' : 'Disabled' ) . '</li></ul>';
		if ( ! $active ) {
			$this->action_form( 'ideaxperts_ea_local_discovery', 'Run local UPC discovery', 0, false );
		}
		if ( $active ) {
			echo '<h2>' . esc_html__( 'Current progress', 'ideaxperts-endless-aisles' ) . '</h2><p>' . esc_html( sprintf( 'Run #%d — %s — store page %d — API page %d — heartbeat %s', $active_id, $active['status'], $active['current_store_page'], $active['current_api_page'], (string) ( $active['last_heartbeat_at'] ?? '' ) ) ) . '</p>';
			$this->action_form( 'ideaxperts_ea_cancel_dry_run', 'Cancel active dry run', $active_id, false );
		} else {
			$this->action_form( 'ideaxperts_ea_start_dry_run', 'Start catalog dry run', 0, ! $ready );
			if ( ! $ready ) {
				echo '<p class="description">' . esc_html__( 'Enable the integration in QA, configure a QA token, and complete a successful QA connection test. Local catalog counts and UPC-field configuration remain visible above.', 'ideaxperts-endless-aisles' ) . '</p>';
			}
		}
		$this->render_results();
	}

	private function render_results(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_dry_runs';
		$local = $wpdb->get_row( "SELECT * FROM {$table} WHERE environment = 'local' AND status = 'completed' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_array( $local ) ) {
			echo '<h2>' . esc_html__( 'Local identifier discovery', 'ideaxperts-endless-aisles' ) . '</h2><ul>';
			foreach ( $this->runs->discovery_counts( (int) $local['id'] ) as $source => $count ) {
				echo '<li>' . esc_html( $source . ': ' . $count ) . '</li>';
			}
			echo '</ul>';
		}
		$last = $wpdb->get_row( "SELECT * FROM {$table} WHERE environment = 'qa' AND status IN ('completed','failed','cancelled') ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $last ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Last finished run', 'ideaxperts-endless-aisles' ) . '</h2><p>' . esc_html( sprintf( 'Run #%d — %s', $last['id'], $last['status'] ) ) . '</p>';
		$counters = json_decode( (string) $last['match_counters'], true );
		if ( is_array( $counters ) ) {
			echo '<ul>';
			foreach ( MatchClassifier::CLASSIFICATIONS as $classification ) {
				echo '<li>' . esc_html( $classification . ': ' . (int) ( $counters[ $classification ] ?? 0 ) ) . '</li>';
			}
			echo '</ul>';
		}
		$flags = json_decode( (string) $last['warning_counters'], true );
		if ( is_array( $flags ) ) {
			echo '<h3>' . esc_html__( 'Review flags', 'ideaxperts-endless-aisles' ) . '</h3><ul>';
			foreach ( MatchClassifier::REVIEW_FLAGS as $flag ) {
				echo '<li>' . esc_html( $flag . ': ' . (int) ( $flags[ $flag ] ?? 0 ) ) . '</li>';
			}
			echo '</ul>';
		}
		$this->action_form( 'ideaxperts_ea_purge_dry_runs', 'Purge expired dry-run records', 0, false );
		if ( 'failed' === $last['status'] ) {
			$this->action_form( 'ideaxperts_ea_resume_dry_run', 'Resume failed run', (int) $last['id'], false );
		}
		if ( 'completed' === $last['status'] ) {
			$this->action_form( 'ideaxperts_ea_export_dry_run', 'Export CSV', (int) $last['id'], false );
		}
		// These GET values only filter a read-only report; no state is changed.
		$classification = isset( $_GET['classification'] ) ? sanitize_key( wp_unslash( $_GET['classification'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search         = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page           = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$items          = $this->runs->items( (int) $last['id'], $classification, $search, $page, 50 );
		echo '<table class="widefat striped"><thead><tr><th>Product / option</th><th>UPC</th><th>WooCommerce</th><th>Classification</th><th>Flags</th><th>Reason</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			echo '<tr><td>' . esc_html( $item['ea_product_id'] . ' / ' . $item['ea_option_id'] ) . '</td><td>' . esc_html( $item['original_upc'] ) . '</td><td>' . esc_html( $item['wc_product_id'] . ' / ' . $item['wc_variation_id'] ) . '</td><td>' . esc_html( $item['classification'] ) . '</td><td>' . esc_html( (string) ( $item['review_flags'] ?? '' ) ) . '</td><td>' . esc_html( $item['review_reason'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function authorize( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage catalog dry runs.', 'ideaxperts-endless-aisles' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function posted_run_id(): int {
		return isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies the action-specific nonce first.
	}

	private function action_form( string $action, string $label, int $run_id, bool $disabled ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		if ( $run_id ) {
			echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run_id ) . '">';
		}
		wp_nonce_field( $action );
		submit_button( $label, 'secondary', 'submit', false, $disabled ? array( 'disabled' => 'disabled' ) : array() );
		echo '</form>';
	}

	private function redirect( string $notice ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'ideaxperts-endless-aisles',
					'tab'     => 'catalog',
					'dry_run' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function count_posts( mixed $counts ): int {
		return is_object( $counts ) ? array_sum( array_map( 'intval', get_object_vars( $counts ) ) ) : 0;
	}
}
