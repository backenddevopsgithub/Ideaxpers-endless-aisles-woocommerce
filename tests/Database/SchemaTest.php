<?php
namespace IdeaXperts\EndlessAisles\Tests\Database;

use IdeaXperts\EndlessAisles\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {
	public function test_all_required_prefixed_tables_and_constraints_are_defined(): void {
		$definitions = Schema::definitions( 'wp_' );
		self::assertCount( 7, $definitions );
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
		self::assertSame(
			array(
				'wp_ideaxperts_ea_dry_runs'      => array( 'last_heartbeat_at' ),
				'wp_ideaxperts_ea_dry_run_items' => array( 'review_flags' ),
			),
			Schema::required_columns( 'wp_' )
		);
		self::assertSame(
			array(
				'wp_ideaxperts_ea_mappings',
				'wp_ideaxperts_ea_sync_runs',
				'wp_ideaxperts_ea_logs',
				'wp_ideaxperts_ea_order_submissions',
				'wp_ideaxperts_ea_dry_runs',
				'wp_ideaxperts_ea_dry_run_items',
				'wp_ideaxperts_ea_store_identifiers',
			),
			Schema::table_names( 'wp_' )
		);
	}
}
