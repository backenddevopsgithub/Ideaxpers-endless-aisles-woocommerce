<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Import\WooSimpleProductWriter;
use PHPUnit\Framework\TestCase;

final class WooSimpleProductWriterTest extends TestCase {
	private array $item;
	private array $desired;

	protected function setUp(): void {
		$GLOBALS['ea_wc_writes'] = array();
		$GLOBALS['ea_wc_product_map'] = array();
		$this->item = array( 'id' => 1, 'status' => 'applying', 'environment' => 'qa', 'source_scope' => 'endless-aisles:qa', 'operation_uuid' => str_repeat( 'a', 32 ), 'ea_product_id' => 'p', 'ea_option_id' => 'o', 'normalized_upc' => '001234567890' );
		$this->desired = array( 'title' => 'Title', 'description' => '<p>Safe</p>', 'regular_price' => '10', 'upc' => '001234567890', 'failure_code' => '' );
		$GLOBALS['wpdb'] = new class {
			public string $posts = 'wp_posts';
			public string $postmeta = 'wp_postmeta';
			public string $last_error = '';
			public string $sql = '';
			public array $args = array();
			public array $ids = array( 601 );
			public bool $fail = false;
			public function prepare( string $sql, mixed ...$args ): string { $this->args = $args; return $sql; }
			public function get_col( string $sql ): array { $this->sql = $sql; if ( $this->fail ) { $this->last_error = 'Read failed'; } return $this->ids; }
		};
	}

	public function test_real_writer_sets_only_allowlisted_initial_fields_and_all_markers_before_save(): void {
		$writer = new WooSimpleProductWriter();
		self::assertSame( 601, $writer->create_draft( $this->item, $this->desired ) );
		$product = $GLOBALS['ea_wc_writes'][0];
		self::assertSame( array( 'status' => 'draft', 'name' => 'Title', 'description' => '<p>Safe</p>', 'regular_price' => '10', 'global_unique_id' => '001234567890' ), $product->fields );
		self::assertCount( 8, $product->meta );
		self::assertSame( $this->item['operation_uuid'], $product->meta[ WooSimpleProductWriter::OPERATION_META ] );
		self::assertSame( array( 601 ), $writer->correlated_drafts( $this->item ) );
		self::assertStringContainsString( 'BINARY m.meta_value = %s', $GLOBALS['wpdb']->sql );
		self::assertStringContainsString( 'LIMIT 2', $GLOBALS['wpdb']->sql );
		self::assertSame( array( 'product', WooSimpleProductWriter::OPERATION_META, $this->item['operation_uuid'] ), $GLOBALS['wpdb']->args );
	}

	/** @dataProvider markerFields */
	public function test_partial_metadata_or_wrong_marker_fails_closed( string $key ): void {
		$writer = new WooSimpleProductWriter();
		$writer->create_draft( $this->item, $this->desired );
		unset( $GLOBALS['ea_wc_product_map'][601]->meta[ $key ] );
		$this->expectException( \RuntimeException::class );
		$writer->correlated_drafts( $this->item );
	}

	public static function markerFields(): array {
		return array_map( static fn( string $key ): array => array( $key ), array( WooSimpleProductWriter::OPERATION_META, '_ideaxperts_ea_create_scope', '_ideaxperts_ea_create_env', '_ideaxperts_ea_create_product', '_ideaxperts_ea_create_option', '_ideaxperts_ea_create_item', '_ideaxperts_ea_create_version', '_ideaxperts_ea_create_upc' ) );
	}

	public function test_production_cannot_call_writer_save(): void {
		$this->item['environment'] = 'production';
		$this->expectException( \RuntimeException::class );
		try { ( new WooSimpleProductWriter() )->create_draft( $this->item, $this->desired ); }
		finally { self::assertSame( array(), $GLOBALS['ea_wc_writes'] ); }
	}

	public function test_lookup_read_failure_is_not_treated_as_absence(): void {
		$GLOBALS['wpdb']->fail = true;
		$this->expectException( \RuntimeException::class );
		( new WooSimpleProductWriter() )->correlated_drafts( $this->item );
	}

	public function test_zero_and_multiple_results_are_bounded_without_picking_one(): void {
		$writer = new WooSimpleProductWriter();
		$writer->create_draft( $this->item, $this->desired );
		$GLOBALS['wpdb']->ids = array();
		self::assertSame( array(), $writer->correlated_drafts( $this->item ) );
		$GLOBALS['ea_wc_product_map'][602] = clone $GLOBALS['ea_wc_product_map'][601];
		$GLOBALS['wpdb']->ids = array( 601, 602 );
		self::assertSame( array( 601, 602 ), $writer->correlated_drafts( $this->item ) );
	}
	public function test_recovery_checks_original_approved_fields(): void {
		$writer = new WooSimpleProductWriter();
		$writer->create_draft( $this->item, $this->desired );
		$binding = array( 'title_hash' => hash( 'sha256', 'Title' ), 'description_hash' => hash( 'sha256', '<p>Safe</p>' ), 'regular_price' => '10' );
		self::assertSame( array( 601 ), $writer->correlated_drafts( $this->item, $binding ) );
		$GLOBALS['ea_wc_product_map'][601]->fields['regular_price'] = '11';
		$this->expectException( \RuntimeException::class );
		$writer->correlated_drafts( $this->item, $binding );
	}

}
