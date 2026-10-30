<?php
/**
 * Plugin composition root.
 *
 * @package IdeaXperts\EndlessAisles
 */

namespace IdeaXperts\EndlessAisles;

use IdeaXperts\EndlessAisles\Admin\Admin;
use IdeaXperts\EndlessAisles\Admin\StatusRefresh;
use IdeaXperts\EndlessAisles\Admin\CatalogDryRunAdmin;
use IdeaXperts\EndlessAisles\Admin\CatalogImportAdmin;
use IdeaXperts\EndlessAisles\API\CatalogService;
use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\API\ConnectionTester;
use IdeaXperts\EndlessAisles\API\EndpointRegistry;
use IdeaXperts\EndlessAisles\API\StockService;
use IdeaXperts\EndlessAisles\Inventory\CheckoutStockGuard;
use IdeaXperts\EndlessAisles\Inventory\InventoryRepository;
use IdeaXperts\EndlessAisles\Inventory\InventorySync;
use IdeaXperts\EndlessAisles\Inventory\WooStockWriter;
use IdeaXperts\EndlessAisles\Core\Container;
use IdeaXperts\EndlessAisles\Core\Dependencies;
use IdeaXperts\EndlessAisles\Database\Migrator;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Database\SyncRunRepository;
use IdeaXperts\EndlessAisles\Database\ImportRepository;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Monitoring\AlertNotifier;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderIntentRecorder;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderPayloadBuilder;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderSubmissionClient;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderSubmissionRepository;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderSubmissionScheduler;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderSubmissionWorker;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderStatusClient;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderStatusReconciler;
use IdeaXperts\EndlessAisles\OrderIntegration\SubmissionGates;
use IdeaXperts\EndlessAisles\Monitoring\SchedulerHealth;
use IdeaXperts\EndlessAisles\Scheduling\InventoryScheduler;
use IdeaXperts\EndlessAisles\Catalog\DryRunManager;
use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;
use IdeaXperts\EndlessAisles\Catalog\StoreCatalogScanner;
use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ImportManager;
use IdeaXperts\EndlessAisles\Import\ImportPolicy;
use IdeaXperts\EndlessAisles\Import\LiveCatalogStateProvider;
use IdeaXperts\EndlessAisles\Import\SimpleProductProjection;
use IdeaXperts\EndlessAisles\Import\SimpleProductCreation;
use IdeaXperts\EndlessAisles\Import\ConfiguredCreationPricingPolicy;
use IdeaXperts\EndlessAisles\Import\CreationPreview;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?self $instance = null;
	private Container $container;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->container = new Container();
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		Dependencies::register_notice();

		if ( ! Dependencies::woocommerce_available() ) {
			return;
		}

		$this->register_services();
		$this->container->get( AlertNotifier::class )->register();
		if ( StatusRefresh::requested() ) {
			StatusRefresh::authorize();
			// A status refresh must not migrate, schedule or reconcile background work.
			$this->container->get( Admin::class )->register();
			return;
		}
		( new Migrator() )->maybe_migrate();
		$this->container->get( Admin::class )->register();
		$this->container->get( InventoryScheduler::class )->register();
		$this->container->get( CheckoutStockGuard::class )->register();
		$this->container->get( SchedulerHealth::class )->register();
		$this->container->get( OrderIntentRecorder::class )->register();
		$this->container->get( OrderSubmissionScheduler::class )->register();
		$this->container->get( DryRunManager::class )->register();
		$this->container->get( ImportManager::class )->register();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'ideaxperts-endless-aisles', false, dirname( plugin_basename( IDEAXPERTS_EA_FILE ) ) . '/languages' );
	}

	private function register_services(): void {
		$this->container->set( SettingsRepository::class, static fn() => new SettingsRepository() );
		$this->container->set( DatabaseLogger::class, static fn() => new DatabaseLogger() );
		$this->container->set( SyncRunRepository::class, static fn() => new SyncRunRepository() );
		$this->container->set( DryRunRepository::class, static fn() => new DryRunRepository() );
		$this->container->set( LiveCatalogStateProvider::class, fn() => new LiveCatalogStateProvider( $this->container->get( SettingsRepository::class ) ) );
		$this->container->set( ImportRepository::class, fn() => new ImportRepository( $this->container->get( LiveCatalogStateProvider::class ) ) );
		$this->container->set( ImportPolicy::class, static fn() => new ImportPolicy() );
		$this->container->set(
			SimpleProductProjection::class,
			function () {
				$config = $this->container->get( SettingsRepository::class )->creation_pricing_configuration();
				return new SimpleProductProjection( is_array( $config ) ? new ConfiguredCreationPricingPolicy( $config ) : null );
			}
		);
		$this->container->set( SimpleProductCreation::class, fn() => new SimpleProductCreation( $this->container->get( ImportRepository::class ), projection: $this->container->get( SimpleProductProjection::class ) ) );
		$this->container->set( ApprovalManifest::class, fn() => new ApprovalManifest( $this->container->get( ImportPolicy::class ), $this->container->get( LiveCatalogStateProvider::class ), $this->container->get( SimpleProductProjection::class ) ) );
		$this->container->set( CreationPreview::class, fn() => new CreationPreview( $this->container->get( ApprovalManifest::class ), $this->container->get( ImportPolicy::class ), $this->container->get( LiveCatalogStateProvider::class ) ) );
		$this->container->set( ImportManager::class, fn() => new ImportManager( $this->container->get( ImportRepository::class ), $this->container->get( SimpleProductCreation::class ) ) );
		$this->container->set( MatchClassifier::class, static fn() => new MatchClassifier() );
		$this->container->set( BaseUrlResolver::class, static fn() => new BaseUrlResolver() );
		$this->container->set( EndpointRegistry::class, static fn() => new EndpointRegistry() );
		$this->container->set(
			CatalogService::class,
			fn() => new CatalogService(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( BaseUrlResolver::class ),
				$this->container->get( DatabaseLogger::class )
			)
		);
		$this->container->set(
			StoreCatalogScanner::class,
			fn() => new StoreCatalogScanner( $this->container->get( SettingsRepository::class ), $this->container->get( DryRunRepository::class ) )
		);
		$this->container->set(
			DryRunManager::class,
			fn() => new DryRunManager(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( DryRunRepository::class ),
				$this->container->get( StoreCatalogScanner::class ),
				$this->container->get( CatalogService::class ),
				$this->container->get( DatabaseLogger::class ),
				$this->container->get( MatchClassifier::class )
			)
		);
		$this->container->set(
			CatalogDryRunAdmin::class,
			fn() => new CatalogDryRunAdmin(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( DryRunRepository::class ),
				$this->container->get( DryRunManager::class ),
				$this->container->get( DatabaseLogger::class )
			)
		);
		$this->container->set(
			ConnectionTester::class,
			fn() => new ConnectionTester(
				$this->container->get( EndpointRegistry::class ),
				$this->container->get( BaseUrlResolver::class ),
				$this->container->get( SettingsRepository::class ),
				$this->container->get( DatabaseLogger::class )
			)
		);
		$this->container->set(
			StockService::class,
			fn() => new StockService(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( BaseUrlResolver::class ),
				$this->container->get( DatabaseLogger::class )
			)
		);
		$this->container->set( AlertNotifier::class, fn() => new AlertNotifier( $this->container->get( SettingsRepository::class ) ) );
		$this->container->set( SchedulerHealth::class, fn() => new SchedulerHealth( $this->container->get( InventoryScheduler::class ), $this->container->get( DatabaseLogger::class ) ) );
		$this->container->set( InventoryRepository::class, static fn() => new InventoryRepository() );
		$this->container->set( OrderSubmissionRepository::class, static fn() => new OrderSubmissionRepository() );
		$this->container->set( SubmissionGates::class, fn() => new SubmissionGates( $this->container->get( SettingsRepository::class ) ) );
		$this->container->set(
			OrderSubmissionScheduler::class,
			fn() => new OrderSubmissionScheduler(
				new OrderSubmissionWorker(
					$this->container->get( SubmissionGates::class ),
					$this->container->get( InventoryRepository::class ),
					$this->container->get( OrderSubmissionRepository::class ),
					new OrderPayloadBuilder(),
					new OrderSubmissionClient( $this->container->get( SettingsRepository::class ), $this->container->get( BaseUrlResolver::class ), $this->container->get( DatabaseLogger::class ) ),
					$this->container->get( DatabaseLogger::class )
				),
				$this->container->get( SubmissionGates::class ),
				new OrderStatusReconciler(
					$this->container->get( SettingsRepository::class ),
					$this->container->get( SubmissionGates::class ),
					$this->container->get( OrderSubmissionRepository::class ),
					new OrderStatusClient( $this->container->get( SettingsRepository::class ), $this->container->get( BaseUrlResolver::class ), $this->container->get( DatabaseLogger::class ) ),
					$this->container->get( DatabaseLogger::class )
				)
			)
		);
		$this->container->set(
			OrderIntentRecorder::class,
			fn() => new OrderIntentRecorder(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( SubmissionGates::class ),
				$this->container->get( InventoryRepository::class ),
				$this->container->get( OrderSubmissionRepository::class ),
				new OrderPayloadBuilder(),
				$this->container->get( DatabaseLogger::class )
			)
		);
		$this->container->set( WooStockWriter::class, static fn() => new WooStockWriter() );
		$this->container->set(
			CheckoutStockGuard::class,
			fn() => new CheckoutStockGuard(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( StockService::class ),
				$this->container->get( InventoryRepository::class ),
				$this->container->get( WooStockWriter::class ),
				$this->container->get( DatabaseLogger::class )
			)
		);
		$this->container->set(
			InventorySync::class,
			fn() => new InventorySync(
				$this->container->get( StockService::class ),
				$this->container->get( InventoryRepository::class ),
				$this->container->get( WooStockWriter::class ),
				$this->container->get( DatabaseLogger::class ),
				continuation: static fn(): bool => InventoryScheduler::schedule_continuation()
			)
		);
		$this->container->set(
			InventoryScheduler::class,
			fn() => new InventoryScheduler(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( DatabaseLogger::class ),
				$this->container->get( SyncRunRepository::class ),
				$this->container->get( InventorySync::class )
			)
		);
		$this->container->set(
			CatalogImportAdmin::class,
			fn() => new CatalogImportAdmin(
				$this->container->get( DryRunRepository::class ),
				$this->container->get( ImportRepository::class ),
				$this->container->get( ApprovalManifest::class ),
				$this->container->get( ImportManager::class ),
				$this->container->get( ImportPolicy::class ),
				$this->container->get( CreationPreview::class ),
				$this->container->get( DryRunManager::class )
			)
		);
		$this->container->set(
			Admin::class,
			fn() => new Admin(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( DatabaseLogger::class ),
				$this->container->get( InventoryScheduler::class ),
				$this->container->get( ConnectionTester::class ),
				$this->container->get( CatalogDryRunAdmin::class ),
				$this->container->get( CatalogImportAdmin::class ),
				$this->container->get( OrderSubmissionRepository::class )
			)
		);
	}
}
