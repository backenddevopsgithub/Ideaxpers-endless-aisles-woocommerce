<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ConfiguredCreationPricingPolicy;
use IdeaXperts\EndlessAisles\Import\CreationPreview;
use IdeaXperts\EndlessAisles\Import\ImportPolicy;
use IdeaXperts\EndlessAisles\Import\SimpleProductProjection;
use IdeaXperts\EndlessAisles\Tests\Support\FixedCatalogStateProvider;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use PHPUnit\Framework\TestCase;

final class CreationPreviewTest extends TestCase {
	private FixedCatalogStateProvider $state;
	private array $item;
	protected function setUp(): void {
		$GLOBALS['ea_now'] = '2026-10-02 12:00:00';
		$GLOBALS['ea_test_options'] = array( 'woocommerce_currency' => 'USD', 'woocommerce_price_num_decimals' => 2 );
		$GLOBALS['ea_wc_writes'] = array();
		$GLOBALS['wpdb'] = new DryRunMemoryWpdb();
		$this->state = new FixedCatalogStateProvider();
		$this->item = array( 'id' => 1, 'environment' => 'production', 'source_scope' => 'endless-aisles:production', 'classification' => 'new_product_candidate', 'review_flags' => '[]', 'ea_product_id' => 'p', 'ea_option_id' => 'o', 'normalized_upc' => '001234567890', 'wc_product_id' => 0, 'wc_variation_id' => 0, 'vendor_title' => 'Title', 'vendor_option_description' => '<p>Description</p>', 'retail_price' => '20', 'purchasable' => 1, 'discontinued' => 0, 'created_at' => $GLOBALS['ea_now'] );
	}
	private function preview( bool $priced = true ): CreationPreview {
		$policy = new ImportPolicy();
		return new CreationPreview( new ApprovalManifest( $policy, $this->state, new SimpleProductProjection( $priced ? new ConfiguredCreationPricingPolicy( ConfiguredCreationPricingPolicyTest::config() ) : null ) ), $policy, $this->state );
	}
	private function preview_run( string $environment = 'production' ): array {
		return array( 'id' => 1, 'environment' => $environment, 'status' => 'completed' );
	}
	public function test_production_preview_is_priced_but_has_zero_mutations(): void {
		$before = $GLOBALS['wpdb']->tables;
		$result = $this->preview()->inspect( $this->preview_run(), $this->item, array() );
		self::assertTrue( $result['eligible'] );
		self::assertSame( '20.00', $result['binding']['regular_price'] );
		self::assertSame( 'no mapping', $result['mapping_state'] );
		self::assertSame( $before, $GLOBALS['wpdb']->tables );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
		self::assertSame( 'retail_price', json_decode( $result['binding']['pricing_configuration'], true )['source_field'] );
	}
	public function test_missing_policy_blocks_creation_and_qa_cannot_authorize_production(): void {
		$result = $this->preview( false )->inspect( $this->preview_run(), $this->item, array() );
		self::assertFalse( $result['eligible'] );
		self::assertSame( 'pricing_policy_missing', $result['reason'] );
		self::assertSame( 'preview_environment_mismatch', $this->preview()->inspect( $this->preview_run( 'qa' ), $this->item, array() )['reason'] );
		$this->item['environment'] = 'qa';
		$this->item['source_scope'] = 'endless-aisles:qa';
		$qa = $this->preview()->inspect( $this->preview_run( 'qa' ), $this->item, array() );
		self::assertSame( 'qa', $qa['binding']['environment'] );
		self::assertNotSame( $result['binding']['desired_hash'], '' );
		self::assertSame( array(), $GLOBALS['wpdb']->tables['wp_ideaxperts_ea_import_runs'] );
	}
	/** @dataProvider blockers */
	public function test_blocked_candidate_does_not_mutate_state( string $field, mixed $value ): void {
		$this->item[$field] = $value;
		$before = $GLOBALS['wpdb']->tables;
		self::assertFalse( $this->preview()->inspect( $this->preview_run(), $this->item, array() )['eligible'] );
		self::assertSame( $before, $GLOBALS['wpdb']->tables );
		self::assertSame( array(), $GLOBALS['ea_wc_writes'] );
	}
	public static function blockers(): array {
		return array( array( 'classification', 'exact_upc_match' ), array( 'classification', 'exact_sku_match' ), array( 'classification', 'already_linked' ), array( 'classification', 'manual_review' ), array( 'review_flags', '["duplicate_vendor_upc"]' ), array( 'purchasable', 0 ), array( 'discontinued', 1 ), array( 'created_at', '2026-10-01 00:00:00' ), array( 'retail_price', '' ) );
	}
	public function test_live_mapping_and_ownership_block_creation_and_bad_item_is_isolated(): void {
		$this->state->mappings = array( array( 'wc_product_id' => 42 ) );
		self::assertSame( 'creation_existing_product', $this->preview()->inspect( $this->preview_run(), $this->item, array() )['reason'] );
		$this->state->mappings = array();
		$this->state->upc_owners = array( '42:0' );
		self::assertFalse( $this->preview()->inspect( $this->preview_run(), $this->item, array() )['eligible'] );
		$this->state->upc_owners = array();
		$this->state->sku_owners = array( '42:0' );
		self::assertSame( 'creation_existing_product', $this->preview()->inspect( $this->preview_run(), $this->item, array() )['reason'] );
		$this->state->sku_owners = array();
		$this->state->on_inspect = static function (): void { throw new \RuntimeException( 'read failed' ); };
		self::assertSame( 'preview_state_unavailable', $this->preview()->inspect( $this->preview_run(), $this->item, array() )['reason'] );
		$this->state->on_inspect = null;
		self::assertTrue( $this->preview()->inspect( $this->preview_run(), $this->item, array() )['eligible'] );
	}
}
