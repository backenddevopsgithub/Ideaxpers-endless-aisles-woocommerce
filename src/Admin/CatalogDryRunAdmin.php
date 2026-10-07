<?php
namespace IdeaXperts\EndlessAisles\Admin;

use IdeaXperts\EndlessAisles\Catalog\CsvSanitizer;
use IdeaXperts\EndlessAisles\Catalog\DryRunManager;
use IdeaXperts\EndlessAisles\Catalog\DryRunProgress;
use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Database\Migrator;
use Closure;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class CatalogDryRunAdmin {
	private const CAPABILITY = 'manage_woocommerce';

	private ?bool $schema_ready = null;

	/** @param Closure():bool|null $readiness Optional read-only readiness provider. */
	public function __construct( private readonly SettingsRepository $settings, private readonly DryRunRepository $runs, private readonly DryRunManager $manager, private readonly DatabaseLogger $logger, private readonly ?Closure $readiness = null ) {}

	public function schema_ready(): bool {
		return $this->schema_ready ??= null !== $this->readiness ? ( $this->readiness )() : ( new Migrator() )->ready();
	}

	private function start_problem( bool $local ): string {
		if ( ! $this->schema_ready() ) {
			return 'schema_not_ready';
		}
		if ( $this->runs->active_id() ) {
			return 'already_active';
		}
		$connection = get_option( 'ideaxperts_ea_qa_connection_status', array() );
		return ! $local && ( 'yes' !== $this->settings->get( 'enabled', 'no' ) || 'qa' !== $this->settings->get( 'environment', 'qa' ) || ! $this->settings->token_configured( 'qa' ) || ! is_array( $connection ) || 'connected' !== ( $connection['status'] ?? '' ) ) ? 'qa_unavailable' : '';
	}

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
		$problem = $this->start_problem( false );
		if ( '' !== $problem ) {
			$this->redirect( $problem );
		}
		try {
			$run_id = $this->manager->start( get_current_user_id() );
		} catch ( RuntimeException $exception ) {
			$this->redirect( 'A catalog dry run is already active.' === $exception->getMessage() ? 'already_active' : 'not_ready' );
		}
		$this->redirect( 'started', $run_id );
	}

	public function local_discovery(): void {
		$this->authorize( 'ideaxperts_ea_local_discovery' );
		$problem = $this->start_problem( true );
		if ( '' !== $problem ) {
			$this->redirect( $problem );
		}
		try {
			$run_id = $this->manager->start_local_discovery( get_current_user_id() );
		} catch ( RuntimeException $exception ) {
			$this->redirect( 'A catalog dry run is already active.' === $exception->getMessage() ? 'already_active' : 'not_ready' );
		}
		$this->redirect( 'local_started', $run_id );
	}

	public function resume(): void {
		$this->authorize( 'ideaxperts_ea_resume_dry_run' );
		if ( ! $this->schema_ready() ) {
			$this->redirect( 'schema_not_ready' );
		}
		$this->manager->resume( $this->posted_run_id() );
		$this->redirect( 'resumed', $this->posted_run_id() );
	}

	public function cancel(): void {
		$run_id     = $this->posted_run_id();
		$generation = $this->posted_claim_generation();
		$this->authorize( 'ideaxperts_ea_cancel_dry_run_' . $run_id . '_' . $generation );
		$this->redirect( $this->manager->cancel( $run_id, $generation ) ? 'cancelled' : 'cancelling', $run_id );
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
		if ( StatusRefresh::requested() ) {
			StatusRefresh::authorize();
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( ! $this->schema_ready() ) {
			$this->notice( 'error', 'Database upgrade required / incomplete. Local UPC discovery and catalog dry runs cannot start until the required schema is ready.' );
			$this->action_form( 'ideaxperts_ea_local_discovery', 'Run local UPC discovery', 0, true );
			$this->action_form( 'ideaxperts_ea_start_dry_run', 'Start catalog dry run', 0, true );
			return;
		}
		$this->render_start_notice();
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
		$this->action_form( 'ideaxperts_ea_local_discovery', $active ? 'Discovery / dry run in progress' : 'Run local UPC discovery', 0, (bool) $active );
		$this->action_form( 'ideaxperts_ea_start_dry_run', $active ? 'Dry run in progress' : 'Start catalog dry run', 0, (bool) $active || ! $ready );
		echo '<p><a class="button" href="' . esc_url(
			add_query_arg(
				array(
					'page'              => 'ideaxperts-endless-aisles',
					'tab'               => 'catalog',
					'ea_status_refresh' => '1',
					'_ea_status_nonce'  => wp_create_nonce( StatusRefresh::NONCE_ACTION ),
				),
				admin_url( 'admin.php' )
			)
		) . '">Refresh status</a> <span class="description">Status is saved after each batch. Refresh to see the latest saved state.</span></p>';
		if ( $active ) {
			$this->render_progress( $active );
			$this->action_form( 'ideaxperts_ea_cancel_dry_run', 'Cancel active dry run', $active_id, false, (int) ( $active['claim_generation'] ?? 0 ) );
		} else {
			if ( ! $ready ) {
				echo '<p class="description">' . esc_html__( 'Enable the integration in QA, configure a QA token, and complete a successful QA connection test. Local catalog counts and UPC-field configuration remain visible above.', 'ideaxperts-endless-aisles' ) . '</p>';
			}
		}
		foreach ( array( 'local', 'qa', 'production' ) as $environment ) {
			$latest = $this->runs->latest_run( $environment );
			if ( $latest && (int) $latest['id'] !== $active_id ) {
				$this->render_progress( $latest );
			}
		}
		$this->render_results();
	}

	private function notice( string $type, string $message ): void {
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_start_notice(): void {
		$code     = isset( $_GET['dry_run'] ) ? sanitize_key( wp_unslash( $_GET['dry_run'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentation only.
		$messages = array(
			'already_active'   => 'Dry run already active. The existing run remains authoritative.',
			'schema_not_ready' => 'Database upgrade required / incomplete.',
			'qa_unavailable'   => 'Unable to start dry run: QA API connection unavailable. Enable QA, configure its credential and complete a successful connection test.',
			'not_ready'        => 'Unable to start dry run: the run could not be safely queued. Check the saved run state and redacted logs.',
		);
		if ( isset( $messages[ $code ] ) ) {
			$this->notice( 'already_active' === $code ? 'warning' : 'error', $messages[ $code ] );
		}
		$id  = isset( $_GET['dry_run_id'] ) ? absint( $_GET['dry_run_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only run lookup.
		$run = $id ? $this->runs->run( $id ) : null;
		if ( $run && 'resumed' === $code && DryRunProgress::summarize( $run )['active'] ) {
			$this->notice( 'info', 'Dry run resumed. Refresh status for saved progress.' );
		} elseif ( $run && in_array( $code, array( 'cancelled', 'cancelling' ), true ) && in_array( $run['status'], array( 'cancelled', 'cancelling' ), true ) ) {
			$this->notice( 'warning', 'cancelled' === $run['status'] ? 'Dry run cancelled.' : 'Dry run cancellation is pending safe finalization.' );
		}
		if ( in_array( $code, array( 'started', 'local_started' ), true ) && $run && ( 'local_started' === $code ? 'local' === $run['environment'] : 'qa' === $run['environment'] ) && DryRunProgress::summarize( $run )['active'] ) {
			$this->notice( 'info', 'local' === $run['environment'] ? 'Local UPC discovery started. Work is queued or running in the background.' : 'Catalog dry run started. Work is queued or running in the background.' );
		}
	}

	/** @param array<string,mixed> $run */
	public function render_progress( array $run ): void {
		$p     = DryRunProgress::summarize( $run );
		$local = 'local' === $run['environment'];
		$title = $local ? 'Local UPC discovery' : 'Catalog dry run';
		echo '<div class="card" style="max-width:100%"><h2>' . esc_html( $title . ' — Run #' . $run['id'] ) . '</h2><p><strong>' . esc_html( 'Environment: ' . $p['environment'] . ' | State: ' . $p['state'] ) . '</strong></p>';
		if ( 'completed' === $run['status'] ) {
			$this->notice( 'success', $title . ' completed.' );
		} elseif ( 'failed' === $run['status'] ) {
			$this->notice( 'error', $title . ' failed. ' . ( '' !== $p['error'] ? $p['error'] : 'The operation failed. Check the redacted logs.' ) );
		}
		$values = array(
			'Current phase'                   => $p['phase'],
			'Started at (UTC)'                => $run['started_at'] ?? 'Unavailable',
			'Last updated (UTC)'              => $run['updated_at'] ?? 'Unavailable',
			'Completed at (UTC)'              => $run['completed_at'] ?? '—',
			'Last heartbeat (UTC)'            => $run['last_heartbeat_at'] ?? '—',
			'Products scanned'                => $run['store_products_scanned'] ?? 'Unavailable for legacy run',
			'Variations inspected'            => $run['store_variations_scanned'] ?? 'Unavailable for legacy run',
			'Local batches total (last scan)' => $run['store_total_pages'] ?? 'Unknown',
			'Local batches processed'         => max( 0, (int) ( $run['current_store_page'] ?? 0 ) ),
			'Recorded run error summaries'    => $p['error_count'],
		);
		if ( $local ) {
			$summary                                 = $this->runs->discovery_summary( (int) $run['id'] );
			$values['Products total (last scan)']    = $run['store_total_products'] ?? 'Unknown';
			$values['UPC records discovered']        = $summary['upc_records'] ?? 'Unavailable';
			$values['Objects with UPCs']             = $summary['owners_with_upc'] ?? 'Unavailable';
			$values['Scanned objects missing UPCs']  = $run['store_missing_upcs'] ?? 'Unavailable for legacy run';
			$values['Duplicate UPC values']          = $summary['duplicate_upcs'] ?? 'Unavailable';
			$values['Objects with conflicting UPCs'] = $summary['conflicting_owners'] ?? 'Unavailable';
		} else {
			$values['Vendor products processed']          = $p['processed'] ?? 'Unavailable for legacy run';
			$values['Vendor products total']              = $p['total'] ?? 'Unknown';
			$values['API pages processed']                = max( 0, (int) ( $run['current_api_page'] ?? 0 ) );
			$values['API pages total']                    = $run['catalog_total_pages'] ?? 'Unknown';
			$values['Current / next API page']            = preg_match( '/^catalog:([1-9][0-9]*)$/', (string) ( $run['resume_cursor'] ?? '' ), $cursor ) ? (int) $cursor[1] : 'No catalog page pending';
			$values['Options processed']                  = $p['options'];
			$review                                       = $this->runs->review_summary( (int) $run['id'] );
			$values['Review required (distinct options)'] = $review['review_required'] ?? 'Unavailable';
			$values['Retail below MAP']                   = $review['retail_below_map'] ?? 'Unavailable';
			$values                                       = array_merge( $values, $p['matches'], $p['flags'] );
		}
		echo '<dl style="display:grid;grid-template-columns:minmax(180px,1fr) minmax(120px,2fr);gap:6px">';
		foreach ( $values as $label => $value ) {
			echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( (string) $value ) . '</dd>';
		}
		echo '</dl>';
		if ( null !== $p['percent'] ) {
			echo '<p><label for="ea-progress-' . esc_attr( (string) $run['id'] ) . '">' . esc_html( $local ? 'Local catalog scan: ' : 'Catalog scan: ' ) . esc_html( (string) $p['percent'] ) . '%</label> <progress id="ea-progress-' . esc_attr( (string) $run['id'] ) . '" value="' . esc_attr( (string) $p['percent'] ) . '" max="100">' . esc_html( (string) $p['percent'] ) . '%</progress></p>';
		} elseif ( $p['active'] ) {
			echo '<p><progress aria-label="Scan in progress; total unknown"></progress> ' . esc_html__( 'In progress — total unknown. Refresh status for saved counts.', 'ideaxperts-endless-aisles' ) . '</p>';
		}
		echo '</div>';
	}

	public function render_operation_history(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->schema_ready() ) {
			return;
		}
		echo '<h2>Recent discovery / catalog operations</h2><p>Saved run state; visible regardless of the configured log level. Page details are updated after each successful batch.</p><ul>';
		foreach ( $this->runs->recent_runs() as $run ) {
			$p = DryRunProgress::summarize( $run );
			echo '<li>' . esc_html( sprintf( 'Run #%d | %s | %s | Started %s | Updated %s | Store batch %d | API page %d | %s', $run['id'], $p['environment'], $p['phase'], $run['started_at'] ?? 'Unavailable', $run['updated_at'] ?? 'Unavailable', $run['current_store_page'] ?? 0, $run['current_api_page'] ?? 0, $p['error'] ) ) . '</li>';
		}
		echo '</ul>';
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
		$last = $wpdb->get_row( "SELECT * FROM {$table} WHERE environment IN ('qa','production') AND status IN ('completed','failed','cancelled') ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $last ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Last finished run', 'ideaxperts-endless-aisles' ) . '</h2><p>' . esc_html( sprintf( 'Run #%d — %s — %s', $last['id'], strtoupper( (string) $last['environment'] ), $last['status'] ) ) . '</p>';
		$counters = json_decode( (string) ( $last['match_counters'] ?? '' ), true );
		if ( is_array( $counters ) ) {
			echo '<ul>';
			foreach ( MatchClassifier::CLASSIFICATIONS as $classification ) {
				echo '<li>' . esc_html( $classification . ': ' . (int) ( $counters[ $classification ] ?? 0 ) ) . '</li>';
			}
			echo '</ul>';
		}
		$flags = json_decode( (string) ( $last['warning_counters'] ?? '' ), true );
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

	private function posted_claim_generation(): int {
		return isset( $_POST['claim_generation'] ) ? absint( $_POST['claim_generation'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller binds and verifies the nonce with this value.
	}

	private function action_form( string $action, string $label, int $run_id, bool $disabled, int $claim_generation = 0 ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		if ( $run_id ) {
			echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run_id ) . '">';
		}
		if ( $claim_generation > 0 ) {
			echo '<input type="hidden" name="claim_generation" value="' . esc_attr( (string) $claim_generation ) . '">';
		}
		$nonce_action = 'ideaxperts_ea_cancel_dry_run' === $action ? $action . '_' . $run_id . '_' . $claim_generation : $action;
		wp_nonce_field( $nonce_action );
		submit_button( $label, 'secondary', 'submit', false, $disabled ? array( 'disabled' => 'disabled' ) : array() );
		echo '</form>';
	}

	private function redirect( string $notice, int $run_id = 0 ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'ideaxperts-endless-aisles',
					'tab'        => 'catalog',
					'dry_run'    => $notice,
					'dry_run_id' => $run_id,
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
