<?php
namespace IdeaXperts\EndlessAisles\Tests\Catalog;

use IdeaXperts\EndlessAisles\Catalog\DryRunProgress;
use PHPUnit\Framework\TestCase;

final class DryRunProgressTest extends TestCase {
    public function test_percent_uses_saved_products_and_never_claims_early_completion(): void {
        $run = array( 'status' => 'fetching_catalog', 'environment' => 'qa', 'catalog_products_processed' => 58, 'catalog_total_products' => 100 );
        self::assertSame( 58, DryRunProgress::summarize( $run )['percent'] );
        $run['catalog_products_processed'] = 110;
        self::assertSame( 100, DryRunProgress::summarize( $run )['percent'] );
        self::assertSame( 'Running', DryRunProgress::summarize( $run )['state'] );
        $run['status'] = 'completed';
        self::assertSame( 100, DryRunProgress::summarize( $run )['percent'] );
    }
    public function test_unknown_zero_totals_and_local_scans_do_not_invent_percent(): void {
        foreach ( array( array(), array( 'catalog_total_products' => 0 ), array( 'environment' => 'local', 'catalog_total_products' => 10, 'catalog_products_processed' => 5 ) ) as $run ) {
            self::assertNull( DryRunProgress::summarize( $run )['percent'] );
        }
        self::assertNull( DryRunProgress::summarize( array() )['processed'] );
    }
    public function test_pages_can_supply_percentage_without_product_total(): void {
        self::assertSame( 50, DryRunProgress::summarize( array( 'current_api_page' => 2, 'catalog_total_pages' => 4 ) )['percent'] );
    }
    public function test_classifications_flags_and_errors_are_allowlisted(): void {
        $p = DryRunProgress::summarize( array( 'match_counters' => '{"exact_upc_match":4,"new_product_candidate":2,"manual_review":3,"unexpected":800}', 'warning_counters' => '{"duplicate_vendor_upc":2,"suspicious_price":-3}', 'error_summary' => 'SQL password token secret <script>' ) );
        self::assertSame( 9, $p['options'] );
        self::assertSame( 4, $p['matches']['exact_upc_match'] );
        self::assertSame( 2, $p['flags']['duplicate_vendor_upc'] );
        self::assertSame( 0, $p['flags']['suspicious_price'] );
        self::assertArrayNotHasKey( 'unexpected', $p['matches'] );
        self::assertStringNotContainsString( 'secret', $p['error'] );
        self::assertSame( 1, $p['error_count'] );
    }
    public function test_environment_and_persisted_states(): void {
        foreach ( array( 'pending' => 'Queued', 'scanning_store' => 'Running', 'fetching_catalog' => 'Running', 'recovering' => 'Running', 'cancelling' => 'Running', 'completed' => 'Completed', 'failed' => 'Failed', 'cancelled' => 'Cancelled' ) as $status => $state ) {
            $p = DryRunProgress::summarize( array( 'status' => $status, 'environment' => 'production' ) );
            self::assertSame( $state, $p['state'] );
            self::assertSame( 'Production Preview', $p['environment'] );
        }
        self::assertSame( 'QA', DryRunProgress::summarize( array( 'environment' => 'qa' ) )['environment'] );
        self::assertSame( 'Local — no API requests', DryRunProgress::summarize( array( 'environment' => 'local' ) )['environment'] );
    }
}
