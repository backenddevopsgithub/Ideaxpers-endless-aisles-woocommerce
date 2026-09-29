<?php
namespace IdeaXperts\EndlessAisles\Tests\Database;

use IdeaXperts\EndlessAisles\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {
	public function test_all_required_prefixed_tables_and_constraints_are_defined(): void {
		$definitions = Schema::definitions( 'wp_' );
		self::assertCount( 15, $definitions );
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
		self::assertStringContainsString( "source_scope varchar(191) NOT NULL DEFAULT 'endless-aisles:qa'", $definitions['dry_runs'] );
		self::assertStringContainsString( 'UNIQUE KEY logical_action', $definitions['dry_run_actions'] );
		self::assertStringContainsString( 'source_scope varchar(191) NOT NULL', $definitions['dry_run_actions'] );
		$columns = Schema::required_columns( 'wp_' );
		$indexes = Schema::required_indexes( 'wp_' );
		self::assertCount( 15, $columns );
		self::assertCount( 15, $indexes );
		self::assertStringContainsString( 'UNIQUE KEY vendor_identity', $definitions['catalog_identities'] );
		self::assertStringContainsString( 'UNIQUE KEY store_identifier', $definitions['store_identifier_reservations'] );
		self::assertStringContainsString( 'source_scope varchar(191) NOT NULL', $definitions['import_actions'] );
		self::assertStringContainsString( 'environment varchar(16) NOT NULL', $definitions['import_actions'] );
		self::assertSame( array( 'namespace', 'identifier_type', 'normalized_identifier' ), $indexes['wp_ideaxperts_ea_store_identifier_reservations']['store_identifier']['columns'] );
		self::assertTrue( $indexes['wp_ideaxperts_ea_store_identifier_reservations']['store_identifier']['unique'] );
		self::assertSame( array( 'source_scope', 'entity_kind', 'ea_product_id', 'ea_option_id' ), $indexes['wp_ideaxperts_ea_catalog_identities']['vendor_identity']['columns'] );
		self::assertSame( array( 'status', 'dispatch_lease_expires_at', 'id' ), $indexes['wp_ideaxperts_ea_import_actions']['dispatch_lease']['columns'] );
		self::assertFalse( $indexes['wp_ideaxperts_ea_import_actions']['dispatch_lease']['unique'] );
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
				'wp_ideaxperts_ea_import_runs',
				'wp_ideaxperts_ea_import_items',
				'wp_ideaxperts_ea_catalog_identities',
				'wp_ideaxperts_ea_store_identifier_reservations',
				'wp_ideaxperts_ea_import_actions',
				'wp_ideaxperts_ea_import_events',
				'wp_ideaxperts_ea_vendor_snapshots',
			),
			Schema::table_names( 'wp_' )
		);
		foreach ( $definitions as $sql ) {
			self::assertStringContainsString( 'ENGINE=InnoDB', $sql );
		}
		foreach ( Schema::table_names( 'wp_' ) as $table ) {
			$sql = '';
			foreach ( $definitions as $definition ) {
				if ( str_contains( $definition, 'CREATE TABLE ' . $table . ' ' ) ) {
					$sql = $definition;
					break;
				}
			}
			self::assertNotSame( '', $sql, 'Missing DDL for ' . $table );
			foreach ( $columns[ $table ] as $column ) {
				self::assertMatchesRegularExpression( '/\n ' . preg_quote( $column, '/' ) . '\s/', $sql, 'Missing column ' . $table . '.' . $column );
			}
			foreach ( $indexes[ $table ] as $name => $index ) {
				$prefix = 'PRIMARY' === $name ? 'PRIMARY KEY  (' : ( $index['unique'] ? 'UNIQUE KEY ' : 'KEY ' ) . $name . ' (';
				self::assertStringContainsString( $prefix . implode( ',', $index['columns'] ) . ')', $sql, 'Missing or malformed index ' . $table . '.' . $name );
			}
		}
		self::assertStringContainsString( 'identity_key char(64) NOT NULL', $definitions['catalog_identities'] );
		self::assertStringContainsString( 'identifier_key char(64) NOT NULL', $definitions['store_identifier_reservations'] );
		self::assertStringContainsString( 'normalized_identifier varchar(191) NOT NULL', $definitions['store_identifier_reservations'] );
	}
}
