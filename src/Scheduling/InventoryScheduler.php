<?php
namespace IdeaXperts\EndlessAisles\Scheduling;

use IdeaXperts\EndlessAisles\Database\SyncRunRepository;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;

defined( 'ABSPATH' ) || exit;

final class InventoryScheduler {
	public const HOOK             = 'ideaxperts_ea_inventory_sync';
	public const GROUP            = 'ideaxperts-endless-aisles';
	public const INTERVAL_MINUTES = 30;

	public function __construct( private readonly SettingsRepository $settings, private readonly DatabaseLogger $logger, private readonly ?SyncRunRepository $sync_runs = null ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'ensure_scheduled' ), 30 );
		add_action( self::HOOK, array( $this, 'handle' ) );
	}

	public function ensure_scheduled(): void {
		if ( ! $this->configured() ) {
			if ( $this->is_scheduled() ) {
				self::unschedule_all();
			}
			return;
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, self::INTERVAL_MINUTES * MINUTE_IN_SECONDS, self::HOOK, array(), self::GROUP, true );
		}
	}

	public function handle(): void {
		if ( ! $this->configured() ) {
			$this->logger->log( 'info', 'Inventory synchronization skipped: integration is not configured.' );
			return;
		}
		$this->record_status( 'not_implemented', 'Inventory import is not implemented in Milestone 1.' );
		$this->logger->log( 'info', 'Inventory synchronization skipped: importing inventory is outside Milestone 1.' );
	}

	public function is_scheduled(): bool {
		return function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( self::HOOK, array(), self::GROUP );
	}

	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	private function record_status( string $status, string $message ): void {
		( $this->sync_runs ?? new SyncRunRepository() )->record_status( $status, $message );
	}

	private function configured(): bool {
		$environment = (string) $this->settings->get( 'environment', 'qa' );
		if ( 'yes' !== $this->settings->get( 'enabled', 'no' ) || ! in_array( $environment, SettingsValidator::ENVIRONMENTS, true ) ) {
			return false;
		}
		if ( 'production' === $environment && SettingsValidator::PRODUCTION_CONFIRMED !== $this->settings->get( 'production_confirmed', 'no' ) ) {
			return false;
		}
		return '' !== $this->settings->token( $environment );
	}
}
