<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Database\ImportRepository;
use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ImportAuditPayload;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use PHPUnit\Framework\TestCase;

final class ImportAuditPayloadTest extends TestCase {
	public function test_allowlist_and_deterministic_hashes_preserve_large_context_evidence(): void {
		$target = array( 'wc_product_id' => PHP_INT_MAX, 'ea_product_id' => str_repeat( '😀', 191 ), 'ea_option_id' => str_repeat( '"', 191 ) );
		$data = array( 'environment' => 'qa', 'preview_target' => $target, 'password' => 'SECRET', 'raw_payload' => 'SECRET' );
		$json = ImportAuditPayload::encode( $data );
		self::assertNotNull( $json );
		self::assertSame( $json, ImportAuditPayload::encode( array_reverse( $data, true ) ) );
		$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 'qa', $decoded['environment'] );
		self::assertSame( ApprovalManifest::hash( $target ), $decoded['preview_target']['sha256'] );
		self::assertGreaterThan( 764, $decoded['preview_target']['bytes'] );
		self::assertStringNotContainsString( 'SECRET', $json );
		self::assertLessThanOrEqual( ImportAuditPayload::MAX_BYTES, strlen( $json ) );
	}

	public function test_all_allowlisted_fields_at_byte_boundary_remain_bounded(): void {
		$fields = ( new \ReflectionClass( ImportAuditPayload::class ) )->getConstant( 'FIELDS' );
		$data = array_fill_keys( $fields, str_repeat( '😀', 31 ) . 'aa' );
		$json = ImportAuditPayload::encode( $data );
		self::assertNotNull( $json );
		self::assertLessThanOrEqual( 4000, strlen( $json ) );
		self::assertArrayHasKey( 'environment', json_decode( $json, true, 512, JSON_THROW_ON_ERROR ) );
		$data = array_fill_keys( $fields, str_repeat( '😀', 191 ) );
		$json = ImportAuditPayload::encode( $data );
		self::assertNotNull( $json );
		self::assertLessThanOrEqual( 4000, strlen( $json ) );
		self::assertSame( $json, ImportAuditPayload::encode( array_reverse( $data, true ) ) );
	}

	public function test_schema_boundary_references_and_manual_conflict_event_use_same_budget(): void {
		$wpdb = new DryRunMemoryWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$item = array( 'id' => 1, 'ea_product_id' => str_repeat( '😀', 191 ), 'ea_option_id' => str_repeat( '\\', 191 ), 'target_wc_product_id' => PHP_INT_MAX, 'target_wc_variation_id' => PHP_INT_MAX - 1, 'operation_uuid' => str_repeat( 'f', 64 ) );
		$wpdb->tables['wp_ideaxperts_ea_import_items'][] = $item;
		$method = new \ReflectionMethod( ImportRepository::class, 'event' );
		$data = array( 'environment' => str_repeat( '😀', 16 ), 'source_scope' => str_repeat( '😀', 191 ), 'failure_code' => str_repeat( 'x', 64 ), 'mapping_count' => PHP_INT_MAX, 'preview_target' => $item );
		self::assertTrue( $method->invoke( new ImportRepository(), 1, 1, 1, 'existing_link_manual_required', 7, $data ) );
		$event = $wpdb->tables['wp_ideaxperts_ea_import_events'][0];
		self::assertLessThanOrEqual( 4000, strlen( $event['event_data'] ) );
		self::assertSame( $item['operation_uuid'], $event['operation_uuid'] );
		self::assertSame( $item['ea_product_id'], $event['ea_product_id'] );
		self::assertSame( $item['ea_option_id'], $event['ea_option_id'] );
		self::assertSame( ApprovalManifest::hash( $data['source_scope'] ), json_decode( $event['event_data'], true )['source_scope']['sha256'] );
	}
}
