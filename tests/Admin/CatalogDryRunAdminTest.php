<?php
namespace IdeaXperts\EndlessAisles\Tests\Admin;

use IdeaXperts\EndlessAisles\Admin\CatalogDryRunAdmin;
use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\API\CatalogService;
use IdeaXperts\EndlessAisles\Catalog\DryRunManager;
use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;
use IdeaXperts\EndlessAisles\Catalog\StoreCatalogScanner;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Tests\API\SequenceClient;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use IdeaXperts\EndlessAisles\Tests\Support\ReadOnlyProduct;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/AdminFunctions.php';

final class CatalogDryRunAdminTest extends TestCase {
    private DryRunMemoryWpdb $db;
    private DryRunRepository $runs;
    private DryRunManager $manager;
    private CatalogDryRunAdmin $admin;
    private SettingsRepository $settings;
    protected function setUp(): void {
        $_GET = $_POST = array();
        $GLOBALS['wpdb'] = $this->db = new DryRunMemoryWpdb();
        $GLOBALS['ea_test_options'] = array( 'ideaxperts_ea_settings' => array( 'enabled' => 'yes', 'environment' => 'qa', 'log_level' => 'critical' ), 'ideaxperts_ea_qa_connection_status' => array( 'status' => 'connected' ) );
        $GLOBALS['ea_action_queue'] = $GLOBALS['ea_wc_products'] = $GLOBALS['ea_wc_writes'] = $GLOBALS['ea_wc_reads'] = $GLOBALS['ea_wc_pages'] = array();
        $GLOBALS['ea_wc_max_pages'] = 1;
        $GLOBALS['ea_enqueue_failure'] = $GLOBALS['ea_unschedule_failure'] = $GLOBALS['ea_add_option_failure'] = false;
        $GLOBALS['ea_after_enqueue'] = null;
        $GLOBALS['ea_admin_allowed'] = $GLOBALS['ea_nonce_valid'] = true;
        $GLOBALS['ea_nonce_actions'] = array();
        $this->settings = new SettingsRepository();
        $this->settings->replace_token( 'qa', 'test-only-secret' );
        $GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] = array( 'status' => 'connected' );
        $this->runs = new DryRunRepository();
        $client = new SequenceClient( array() );
        $this->manager = new DryRunManager( $this->settings, $this->runs, new StoreCatalogScanner( $this->settings, $this->runs ), new CatalogService( $this->settings, new BaseUrlResolver(), new DatabaseLogger(), static fn() => $client ), new DatabaseLogger(), new MatchClassifier() );
        $this->admin = $this->admin( true );
    }
    protected function tearDown(): void {
        $_GET = $_POST = array();
        $GLOBALS['ea_admin_allowed'] = $GLOBALS['ea_nonce_valid'] = true;
        unset( $GLOBALS['wpdb'] );
    }
    private function admin( bool $ready ): CatalogDryRunAdmin {
        return new CatalogDryRunAdmin( $this->settings, $this->runs, $this->manager, new DatabaseLogger(), static fn(): bool => $ready );
    }
    private function html(): string {
        ob_start();
        try { $this->admin->render(); return (string) ob_get_contents(); } finally { ob_end_clean(); }
    }
    private function post( string $method ): array {
        try { $this->admin->$method(); self::fail( 'Expected redirect' ); } catch ( \EaAdminRedirect $redirect ) {
            parse_str( (string) parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $query );
            $_GET = $query;
            return $query;
        }
    }
    public function test_idle_forms_are_native_posts_with_nonces_and_manual_refresh(): void {
        $html = $this->html();
        self::assertStringContainsString( 'method="post" action="https://example.test/wp-admin/admin-post.php"', $html );
        self::assertStringContainsString( 'name="action" value="ideaxperts_ea_local_discovery"', $html );
        self::assertStringContainsString( 'name="_wpnonce" value="ideaxperts_ea_start_dry_run"', $html );
        self::assertStringContainsString( 'Refresh status', $html );
        self::assertStringNotContainsString( 'disabled="disabled"', $html );
        self::assertSame( array(), $GLOBALS['ea_action_queue'] );
        self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
    }
    public function test_start_notice_is_bound_to_persisted_qa_run_and_repeated_click_does_not_enqueue(): void {
        $query = $this->post( 'start' );
        self::assertSame( 'started', $query['dry_run'] );
        self::assertSame( '1', $query['dry_run_id'] );
        self::assertCount( 1, $GLOBALS['ea_action_queue'] );
        $html = $this->html();
        self::assertStringContainsString( 'Catalog dry run started.', $html );
        self::assertStringContainsString( 'Environment: QA | State: Running', $html );
        self::assertStringContainsString( 'Inspecting local catalog', $html );
        self::assertStringContainsString( 'disabled="disabled"', $html );
        self::assertStringContainsString( 'Cancel active dry run', $html );
        $this->html();
        self::assertCount( 1, $GLOBALS['ea_action_queue'] );
        self::assertSame( 'already_active', $this->post( 'start' )['dry_run'] );
        self::assertStringContainsString( 'Dry run already active.', $this->html() );
        self::assertCount( 1, $GLOBALS['ea_action_queue'] );
        self::assertCount( 1, $this->db->tables['wp_ideaxperts_ea_dry_runs'] );
    }
    public function test_successful_local_discovery_shows_real_completion_and_counts_after_reload(): void {
        $GLOBALS['ea_wc_products'] = array( new ReadOnlyProduct( 11, 'simple', 'publish', 'One', '00123', '' ), new ReadOnlyProduct( 12, 'simple', 'publish', 'Two', '', '' ) );
        $this->post( 'local_discovery' );
        self::assertStringContainsString( 'Local UPC discovery started.', $this->html() );
        $action = array_shift( $GLOBALS['ea_action_queue'] );
        $this->manager->store_batch( ...$action['args'] );
        $_GET = array();
        $html = $this->html();
        self::assertStringContainsString( 'notice-success', $html );
        self::assertStringContainsString( 'Local UPC discovery completed.', $html );
        self::assertStringContainsString( 'Products scanned</strong></dt><dd>2', $html );
        self::assertStringContainsString( 'UPC records discovered</strong></dt><dd>1', $html );
        self::assertStringContainsString( 'Scanned objects missing UPCs</strong></dt><dd>1', $html );
        self::assertStringNotContainsString( 'Local UPC discovery started.', $html );
        self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
    }
    public function test_local_failure_and_catalog_failure_are_red_and_redacted(): void {
        foreach ( array( 'local', 'qa' ) as $environment ) {
            $id = $this->runs->create( 9, $environment );
            $this->db->tables['wp_ideaxperts_ea_dry_runs'][ $id - 1 ]['status'] = 'failed';
            $this->db->tables['wp_ideaxperts_ea_dry_runs'][ $id - 1 ]['error_summary'] = 'SQL secret api-token password <script>';
        }
        $html = $this->html();
        self::assertStringContainsString( 'Local UPC discovery failed.', $html );
        self::assertStringContainsString( 'Catalog dry run failed.', $html );
        self::assertStringContainsString( 'notice-error', $html );
        self::assertStringNotContainsString( 'api-token', $html );
        self::assertStringNotContainsString( 'notice-success', $html );
    }
    public function test_schema_blocks_both_start_actions_and_does_not_query_missing_run_tables(): void {
        $this->admin = $this->admin( false );
        self::assertSame( 'schema_not_ready', $this->post( 'local_discovery' )['dry_run'] );
        self::assertSame( 'schema_not_ready', $this->post( 'start' )['dry_run'] );
        self::assertStringContainsString( 'Database upgrade required / incomplete', $this->html() );
        self::assertSame( array(), $this->db->queries );
        self::assertSame( array(), $GLOBALS['ea_action_queue'] );
    }
    public function test_qa_connection_failure_has_safe_notice_without_run_creation(): void {
        $GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] = array( 'status' => 'failed' );
        self::assertSame( 'qa_unavailable', $this->post( 'start' )['dry_run'] );
        self::assertStringContainsString( 'QA API connection unavailable', $this->html() );
        self::assertSame( array(), $this->db->tables['wp_ideaxperts_ea_dry_runs'] );
    }
    public function test_forged_started_query_never_claims_success(): void {
        $_GET = array( 'dry_run' => 'started', 'dry_run_id' => 999 );
        self::assertStringNotContainsString( 'Catalog dry run started.', $this->html() );
        self::assertStringNotContainsString( 'notice-success', $this->html() );
    }
    public function test_queue_completed_failed_and_production_labels_come_from_rows(): void {
        $id = $this->runs->create( 9, 'production' );
        $row =& $this->db->tables['wp_ideaxperts_ea_dry_runs'][0];
        self::assertStringContainsString( 'Environment: Production Preview | State: Queued', $this->html() );
        $row['status'] = 'fetching_catalog';
        $row['catalog_products_processed'] = 58;
        $row['catalog_total_products'] = 100;
        $row['current_api_page'] = 6;
        $row['catalog_total_pages'] = 10;
        $row['match_counters'] = '{"exact_upc_match":2,"new_product_candidate":3}';
        self::assertStringContainsString( 'value="58" max="100"', $this->html() );
        self::assertStringContainsString( 'Options processed</strong></dt><dd>5', $this->html() );
        self::assertStringContainsString( 'exact_upc_match</strong></dt><dd>2', $this->html() );
        $row['status'] = 'completed';
        $html = $this->html();
        self::assertStringContainsString( 'Catalog dry run completed.', $html );
        self::assertStringContainsString( 'State: Completed', $html );
        self::assertStringContainsString( 'notice-success', $html );
    }
    public function test_nonce_and_capability_checks_precede_any_work(): void {
        foreach ( array( 'start', 'local_discovery' ) as $method ) {
            $GLOBALS['ea_admin_allowed'] = false;
            try { $this->admin->$method(); self::fail( 'Expected capability denial' ); } catch ( \EaAdminDenied $error ) { self::assertStringContainsString( 'not allowed', $error->getMessage() ); }
            $GLOBALS['ea_admin_allowed'] = true;
            $GLOBALS['ea_nonce_valid'] = false;
            try { $this->admin->$method(); self::fail( 'Expected nonce denial' ); } catch ( \EaAdminDenied $error ) { self::assertSame( 'Invalid nonce', $error->getMessage() ); }
        }
        self::assertSame( array(), $GLOBALS['ea_action_queue'] );
        self::assertSame( array(), $this->db->tables['wp_ideaxperts_ea_dry_runs'] );
    }
    public function test_product_repeated_on_two_pages_does_not_invent_missing_upcs(): void {
        $product = new ReadOnlyProduct( 11, 'simple', 'publish', 'One', '00123', '' );
        $GLOBALS['ea_wc_pages'] = array( 1 => array( $product ), 2 => array( $product ) );
        $this->post( 'local_discovery' );
        for ( $page = 1; $page <= 2; ++$page ) {
            $action = array_shift( $GLOBALS['ea_action_queue'] );
            $this->manager->store_batch( ...$action['args'] );
        }
        self::assertStringContainsString( 'Scanned objects missing UPCs</strong></dt><dd>0', $this->html() );
    }
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_refresh_boot_and_admin_lifecycle_do_not_dispatch_or_mutate(): void {
        require_once dirname( __DIR__ ) . '/Support/WooCommerceRuntime.php';
        define( 'IDEAXPERTS_EA_FILE', dirname( __DIR__, 2 ) . '/ideaxperts-endless-aisles.php' );
        $GLOBALS['ea_registered_hooks'] = array();
        $GLOBALS['ea_test_options']['ideaxperts_ea_schema_version'] = \IdeaXperts\EndlessAisles\Database\Schema::VERSION;
        $GLOBALS['ea_test_options']['ideaxperts_ea_schema_integrity'] = \IdeaXperts\EndlessAisles\Database\Schema::VERSION;
        $GLOBALS['ea_enqueue_failure'] = true;
        $this->post( 'local_discovery' );
        $GLOBALS['ea_enqueue_failure'] = false;
        $GLOBALS['ea_now'] = '2026-09-25 12:00:00';
        $GLOBALS['pagenow'] = 'admin.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = array( 'page' => 'ideaxperts-endless-aisles', 'tab' => 'catalog', 'ea_status_refresh' => '1', '_ea_status_nonce' => wp_create_nonce( 'ideaxperts_ea_status_refresh' ) );
        $tables = $this->db->tables;
        $options = $GLOBALS['ea_test_options'];
        $schedule_calls = $GLOBALS['ea_schedule_calls'];
        \IdeaXperts\EndlessAisles\Plugin::instance()->boot();
        do_action( 'init' );
        do_action( 'admin_init' );
        $container = ( new \ReflectionProperty( \IdeaXperts\EndlessAisles\Plugin::class, 'container' ) )->getValue( \IdeaXperts\EndlessAisles\Plugin::instance() );
        ob_start();
        try {
            $container->get( \IdeaXperts\EndlessAisles\Admin\Admin::class )->render();
            $container->get( \IdeaXperts\EndlessAisles\Admin\Admin::class )->render();
            self::assertStringContainsString( 'Refresh status', (string) ob_get_contents() );
        } finally { ob_end_clean(); }
        self::assertSame( array(), $GLOBALS['ea_action_queue'] );
        self::assertSame( $tables, $this->db->tables );
        self::assertSame( $options, $GLOBALS['ea_test_options'] );
        self::assertSame( $schedule_calls, $GLOBALS['ea_schedule_calls'] );
        self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
        self::assertArrayHasKey( 'admin_menu', $GLOBALS['ea_registered_hooks'] );
    }
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_refresh_authentication_fails_before_migration_or_background_hooks(): void {
        require_once dirname( __DIR__ ) . '/Support/WooCommerceRuntime.php';
        $GLOBALS['pagenow'] = 'admin.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = array( 'page' => 'ideaxperts-endless-aisles', 'tab' => 'catalog', 'ea_status_refresh' => '1' );
        $GLOBALS['ea_test_options']['ideaxperts_ea_schema_version'] = '3.0.0';
        $tables = $this->db->tables;
        $options = $GLOBALS['ea_test_options'];
        foreach ( array( false, true ) as $allowed ) {
            $GLOBALS['ea_admin_allowed'] = $allowed;
            try {
                \IdeaXperts\EndlessAisles\Plugin::instance()->boot();
                self::fail( 'Refresh without permission/nonce must terminate' );
            } catch ( \EaAdminDenied $error ) {
                self::assertStringContainsString( $allowed ? 'expired or is invalid' : 'not allowed', $error->getMessage() );
            }
            self::assertSame( $tables, $this->db->tables );
            self::assertSame( $options, $GLOBALS['ea_test_options'] );
            self::assertSame( array(), $this->db->queries );
            self::assertSame( array(), $GLOBALS['ea_action_queue'] );
        }
    }

    public function test_history_bound_escaping_and_environment_tampering(): void {
        for ( $i = 1; $i <= 12; ++$i ) { $this->runs->create( 9, 'qa' ); }
        $this->db->tables['wp_ideaxperts_ea_dry_runs'][11]['error_summary'] = 'secret SQL Authorization raw payload';
        $this->db->tables['wp_ideaxperts_ea_dry_runs'][11]['updated_at'] = '<script>alert(1)</script>';
        $_GET = array( 'environment' => 'production', 'status' => 'completed' );
        ob_start();
        try { $this->admin->render_operation_history(); $html = (string) ob_get_contents(); } finally { ob_end_clean(); }
        self::assertSame( 10, substr_count( $html, '<li>' ) );
        self::assertStringContainsString( 'Run #12 | QA', $html );
        self::assertStringNotContainsString( 'Run #2 |', $html );
        self::assertStringNotContainsString( 'secret', $html );
        self::assertStringNotContainsString( '<script>', $html );
        self::assertStringContainsString( '&lt;script&gt;', $html );
        self::assertCount( 10, $this->runs->recent_runs( 10000 ) );
        self::assertStringContainsString( 'Environment: QA | State: Queued', $this->html() );
        self::assertStringNotContainsString( 'Catalog dry run completed.', $this->html() );
        self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
        $GLOBALS['ea_admin_allowed'] = false;
        self::assertSame( '', $this->html() );
    }
    public function test_logs_history_remains_visible_at_critical_log_level_and_is_read_only(): void {
        $this->post( 'local_discovery' );
        ob_start();
        try { $this->admin->render_operation_history(); $html = (string) ob_get_contents(); } finally { ob_end_clean(); }
        self::assertStringContainsString( 'Run #1 | Local', $html );
        self::assertStringContainsString( 'Inspecting local catalog', $html );
        self::assertCount( 1, $GLOBALS['ea_action_queue'] );
    }
}
