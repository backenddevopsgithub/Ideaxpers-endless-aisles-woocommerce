<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Admin\CatalogImportAdmin;
use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\API\CatalogService;
use IdeaXperts\EndlessAisles\Catalog\DryRunManager;
use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;
use IdeaXperts\EndlessAisles\Catalog\StoreCatalogScanner;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Database\ImportRepository;
use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\CreationPreview;
use IdeaXperts\EndlessAisles\Import\ConfiguredCreationPricingPolicy;
use IdeaXperts\EndlessAisles\Import\ImportManager;
use IdeaXperts\EndlessAisles\Import\ImportPolicy;
use IdeaXperts\EndlessAisles\Import\SimpleProductProjection;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use IdeaXperts\EndlessAisles\Tests\Support\FixedCatalogStateProvider;
use PHPUnit\Framework\TestCase;

final class ProductionApprovalSelectionTest extends TestCase {
	/** @dataProvider selections */
	public function test_admin_requires_exactly_one_priced_production_creation( int $count, bool $priced, bool $allowed ): void {
		$GLOBALS['ea_now'] = '2026-10-02 12:00:00';
		$GLOBALS['ea_test_options'] = array( 'woocommerce_currency' => 'USD', 'woocommerce_price_num_decimals' => 2 );
		$GLOBALS['wpdb'] = new DryRunMemoryWpdb();
		$policy = new ImportPolicy();
		$state = new FixedCatalogStateProvider();
		$manifests = new ApprovalManifest( $policy, $state, new SimpleProductProjection( $priced ? new ConfiguredCreationPricingPolicy( ConfiguredCreationPricingPolicyTest::config() ) : null ) );
		$imports = new ImportRepository( $state );
		$runs = new DryRunRepository();
		$settings = new SettingsRepository();
		$logger = new DatabaseLogger();
		$dry_manager = new DryRunManager( $settings, $runs, new StoreCatalogScanner( $settings, $runs ), new CatalogService( $settings, new BaseUrlResolver(), $logger ), $logger, new MatchClassifier() );
		$admin = new CatalogImportAdmin( $runs, $imports, $manifests, new ImportManager( $imports ), $policy, new CreationPreview( $manifests, $policy, $state ), $dry_manager );
		$run = array( 'id' => 1, 'environment' => 'production', 'status' => 'completed' );
		$items = array();
		for ( $id = 1; $id <= $count; ++$id ) {
			$items[] = array( 'id' => $id, 'environment' => 'production', 'source_scope' => 'endless-aisles:production', 'classification' => 'new_product_candidate', 'review_flags' => '[]', 'ea_product_id' => 'p', 'ea_option_id' => (string) $id, 'normalized_upc' => '001234567890', 'wc_product_id' => 0, 'wc_variation_id' => 0, 'vendor_title' => 'Title', 'retail_price' => '20', 'purchasable' => 1, 'discontinued' => 0, 'created_at' => $GLOBALS['ea_now'] );
		}
		$before = $GLOBALS['wpdb']->tables;
		$accepted = true;
		try {
			( new \ReflectionMethod( $admin, 'validate_creation_selection' ) )->invoke( $admin, $run, $items, array() );
		} catch ( \RuntimeException ) {
			$accepted = false;
		}
		self::assertSame( $allowed, $accepted );
		self::assertSame( $before, $GLOBALS['wpdb']->tables );
	}
	public static function selections(): array {
		return array( array( 1, true, true ), array( 2, true, false ), array( 1, false, false ) );
	}
}
