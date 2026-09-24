<?php
namespace IdeaXperts\EndlessAisles\Tests\Database;

use IdeaXperts\EndlessAisles\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {
	public function test_all_required_prefixed_tables_and_constraints_are_defined(): void {
		$definitions = Schema::definitions( 'wp_' );
		self::assertCount( 4, $definitions );
		self::assertStringContainsString( 'wp_ideaxperts_ea_mappings', $definitions['mappings'] );
		self::assertStringContainsString( 'normalized_upc', $definitions['mappings'] );
		self::assertStringContainsString( 'UNIQUE KEY idempotency_key', $definitions['order_submissions'] );
		self::assertStringContainsString( 'KEY created_at', $definitions['logs'] );
		self::assertSame(
			array(
				'wp_ideaxperts_ea_mappings',
				'wp_ideaxperts_ea_sync_runs',
				'wp_ideaxperts_ea_logs',
				'wp_ideaxperts_ea_order_submissions',
			),
			Schema::table_names( 'wp_' )
		);
	}
}
