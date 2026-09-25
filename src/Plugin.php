<?php
/**
 * Plugin composition root.
 *
 * @package IdeaXperts\EndlessAisles
 */

namespace IdeaXperts\EndlessAisles;

use IdeaXperts\EndlessAisles\Admin\Admin;
use IdeaXperts\EndlessAisles\Admin\CatalogDryRunAdmin;
use IdeaXperts\EndlessAisles\API\CatalogService;
use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\API\ConnectionTester;
use IdeaXperts\EndlessAisles\API\EndpointRegistry;
use IdeaXperts\EndlessAisles\Core\Container;
use IdeaXperts\EndlessAisles\Core\Dependencies;
use IdeaXperts\EndlessAisles\Database\Migrator;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Database\SyncRunRepository;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Scheduling\InventoryScheduler;
use IdeaXperts\EndlessAisles\Catalog\DryRunManager;
use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;
use IdeaXperts\EndlessAisles\Catalog\StoreCatalogScanner;
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
		( new Migrator() )->maybe_migrate();
		$this->container->get( Admin::class )->register();
		$this->container->get( InventoryScheduler::class )->register();
		$this->container->get( DryRunManager::class )->register();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'ideaxperts-endless-aisles', false, dirname( plugin_basename( IDEAXPERTS_EA_FILE ) ) . '/languages' );
	}

	private function register_services(): void {
		$this->container->set( SettingsRepository::class, static fn() => new SettingsRepository() );
		$this->container->set( DatabaseLogger::class, static fn() => new DatabaseLogger() );
		$this->container->set( SyncRunRepository::class, static fn() => new SyncRunRepository() );
		$this->container->set( DryRunRepository::class, static fn() => new DryRunRepository() );
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
			InventoryScheduler::class,
			fn() => new InventoryScheduler(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( DatabaseLogger::class ),
				$this->container->get( SyncRunRepository::class )
			)
		);
		$this->container->set(
			Admin::class,
			fn() => new Admin(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( DatabaseLogger::class ),
				$this->container->get( InventoryScheduler::class ),
				$this->container->get( ConnectionTester::class ),
				$this->container->get( CatalogDryRunAdmin::class )
			)
		);
	}
}
