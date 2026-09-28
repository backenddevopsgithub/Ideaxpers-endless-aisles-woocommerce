<?php
namespace IdeaXperts\EndlessAisles\Tests\Database;

use IdeaXperts\EndlessAisles\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {
	public function test_all_required_prefixed_tables_and_constraints_are_defined(): void {
		$definitions = Schema::definitions( 'wp_' );
		self::assertCount( 8, $definitions );
		self::assertStringContainsString( 'wp_ideaxperts_ea_mappings', $definitions['mappings'] );
		self::assertStringContainsString( 'normalized_upc', $definitions['mappings'] );
		self::assertStringContainsString( 'UNIQUE KEY idempotency_key', $definitions['order_submissions'] );
		self::assertStringContainsString( 'KEY created_at', $definitions['logs'] );
		self::assertStringContainsString( 'UNIQUE KEY run_option', $definitions['dry_run_items'] );
		self::assertStringContainsString( 'UNIQUE KEY run_source_identity', $definitions['store_identifiers'] );
		self::assertStringContainsString( 'resume_cursor', $definitions['dry_runs'] );
		self::assertStringContainsString( 'last_heartbeat_at', $definitions['dry_runs'] );
		self::assertStringContainsString( 'review_flags', $definitions['dry_run_items'] );
		self::assertStringContainsString( 'status_completed_at', $definitions['dry_runs'] );
		self::assertStringContainsString( 'claim_generation', $definitions['dry_runs'] );
		self::assertStringContainsString( 'UNIQUE KEY logical_action', $definitions['dry_run_actions'] );
		$columns = Schema::required_columns( 'wp_' );
		$indexes = Schema::required_indexes( 'wp_' );
		self::assertCount( 8, $columns );
		self::assertCount( 8, $indexes );
		self::assertContains( 'current_api_page', $columns['wp_ideaxperts_ea_dry_runs'] );
		self::assertContains( 'review_flags', $columns['wp_ideaxperts_ea_dry_run_items'] );
		self::assertContains( 'dispatch_token', $columns['wp_ideaxperts_ea_dry_run_actions'] );
		self::assertSame( array( 'run_id', 'ea_product_id', 'ea_option_id' ), $indexes['wp_ideaxperts_ea_dry_run_items']['run_option']['columns'] );
		self::assertTrue( $indexes['wp_ideaxperts_ea_dry_run_items']['run_option']['unique'] );
		self::assertSame( array( 'run_id', 'claim_generation', 'action_type', 'page_number' ), $indexes['wp_ideaxperts_ea_dry_run_actions']['logical_action']['columns'] );
		self::assertTrue( $indexes['wp_ideaxperts_ea_dry_run_actions']['intent_token']['unique'] );
		self::assertSame(
			array(
				'wp_ideaxperts_ea_mappings',
				'wp_ideaxperts_ea_sync_runs',
				'wp_ideaxperts_ea_logs',
				'wp_ideaxperts_ea_order_submissions',
				'wp_ideaxperts_ea_dry_runs',
				'wp_ideaxperts_ea_dry_run_items',
				'wp_ideaxperts_ea_store_identifiers',
				'wp_ideaxperts_ea_dry_run_actions',
			),
			Schema::table_names( 'wp_' )
		);
		foreach ( $definitions as $sql ) {
			self::assertStringContainsString( 'ENGINE=InnoDB', $sql );
		}
	}
}
