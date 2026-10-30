<?php
namespace IdeaXperts\EndlessAisles\Monitoring;

use IdeaXperts\EndlessAisles\Import\ImportManager;
use IdeaXperts\EndlessAisles\Inventory\InventoryRepository;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderSubmissionScheduler;
use IdeaXperts\EndlessAisles\Scheduling\InventoryScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Detects a stalled cron from ordinary page requests, since a dead cron cannot
 * report itself. Findings are logged at error/critical level, which alerts.
 */
final class SchedulerHealth {
	public const CHECKED_OPTION          = 'ideaxperts_ea_health_checked_at';
	public const CHECK_INTERVAL          = 900;
	public const OVERDUE_SECONDS         = 900;
	public const INVENTORY_STALE_SECONDS = 2 * 3600;

	private readonly \Closure $clock;
	private readonly \Closure $overdue;

	public function __construct( private readonly InventoryScheduler $inventory, private readonly DatabaseLogger $logger, ?\Closure $clock = null, ?\Closure $overdue = null ) {
		$this->clock   = $clock ?? static fn(): int => time();
		$this->overdue = $overdue ?? static function ( int $before ): int {
			if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
				return 0;
			}
			$count = 0;
			foreach ( array( InventoryScheduler::GROUP, ImportManager::GROUP, OrderSubmissionScheduler::GROUP ) as $group ) {
				$count += count(
					as_get_scheduled_actions(
						array(
							'group'        => $group,
							'status'       => 'pending',
							'date'         => gmdate( 'Y-m-d H:i:s', $before ),
							'date_compare' => '<=',
							'per_page'     => 5,
						),
						'ids'
					)
				);
			}
			return $count;
		};
	}

	public function register(): void {
		add_action( 'init', array( $this, 'check' ), 40 );
	}

	public function check(): void {
		$now  = ( $this->clock )();
		$last = (int) get_option( self::CHECKED_OPTION, 0 );
		if ( $now - $last < self::CHECK_INTERVAL || ! $this->inventory->is_configured() ) {
			return;
		}
		update_option( self::CHECKED_OPTION, $now, false );
		$overdue = ( $this->overdue )( $now - self::OVERDUE_SECONDS );
		if ( $overdue > 0 ) {
			$this->logger->log( 'critical', 'Scheduled Endless Aisles tasks are overdue; the server cron may have stopped.', array( 'overdue_actions' => $overdue ) );
		}
		$success   = get_option( InventoryRepository::SUCCESS_OPTION, array() );
		$stamp     = is_array( $success ) ? (string) ( $success['completed_at'] ?? '' ) : '';
		$completed = 1 === preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $stamp ) ? strtotime( $stamp . ' UTC' ) : false;
		if ( false !== $completed && $now - $completed > self::INVENTORY_STALE_SECONDS ) {
			$this->logger->log( 'error', 'Inventory has not completed successfully for over 2 hours; checkout relies on live verification.', array( 'last_completed_at' => $stamp ) );
		}
	}
}
