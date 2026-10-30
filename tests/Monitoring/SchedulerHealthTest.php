<?php
namespace IdeaXperts\EndlessAisles\Tests\Monitoring;

use IdeaXperts\EndlessAisles\Inventory\InventoryRepository;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Monitoring\SchedulerHealth;
use IdeaXperts\EndlessAisles\Scheduling\InventoryScheduler;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class SchedulerHealthTest extends TestCase {
	private int $now;
	private int $overdue = 0;
	/** @var list<int> */
	private array $queried_before = array();
	/** @var list<array{string,string}> */
	private array $alerts = array();

	protected function setUp(): void {
		$this->now                  = strtotime( '2026-10-30 12:00:00 UTC' );
		$this->overdue              = 0;
		$this->queried_before       = array();
		$this->alerts               = array();
		$GLOBALS['ea_test_options'] = array(
			'ideaxperts_ea_settings' => array(
				'enabled'     => 'yes',
				'environment' => 'qa',
				'log_level'   => 'critical',
			),
		);
		( new SettingsRepository() )->replace_token( 'qa', 'secret' );
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public function insert( string $table, array $data, array $formats = array() ): bool {
				return true;
			}
			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}
			public function query( string $query ): int {
				return 0;
			}
		};
		$GLOBALS['ea_registered_hooks'][ DatabaseLogger::ALERT_HOOK ] = array(
			function ( string $level, string $message ): void {
				$this->alerts[] = array( $level, $message );
			},
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ea_registered_hooks'][ DatabaseLogger::ALERT_HOOK ], $GLOBALS['wpdb'] );
	}

	public function test_overdue_scheduled_actions_raise_a_critical_alert(): void {
		$this->overdue = 2;

		$this->health()->check();

		self::assertSame( array( $this->now - SchedulerHealth::OVERDUE_SECONDS ), $this->queried_before );
		self::assertSame( 'critical', $this->alerts[0][0] ?? '' );
		self::assertStringContainsString( 'server cron may have stopped', $this->alerts[0][1] ?? '' );
	}

	public function test_healthy_queue_and_recent_inventory_raise_nothing(): void {
		$GLOBALS['ea_test_options'][ InventoryRepository::SUCCESS_OPTION ] = array( 'completed_at' => '2026-10-30 11:30:00' );

		$this->health()->check();

		self::assertSame( array(), $this->alerts );
	}

	public function test_inventory_without_a_success_for_two_hours_raises_an_error(): void {
		$GLOBALS['ea_test_options'][ InventoryRepository::SUCCESS_OPTION ] = array( 'completed_at' => '2026-10-30 09:59:00' );

		$this->health()->check();

		self::assertSame( array( array( 'error', 'Inventory has not completed successfully for over 2 hours; checkout relies on live verification.' ) ), $this->alerts );
	}

	public function test_checks_are_throttled_to_the_interval(): void {
		$this->overdue = 1;
		$health        = $this->health();

		$health->check();
		$this->now += SchedulerHealth::CHECK_INTERVAL - 1;
		$health->check();
		self::assertCount( 1, $this->alerts );

		$this->now += 1;
		$health->check();
		self::assertCount( 2, $this->alerts );
	}

	public function test_unconfigured_integration_is_not_checked(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['enabled'] = 'no';
		$this->overdue = 3;

		$this->health()->check();

		self::assertSame( array(), $this->queried_before );
		self::assertSame( array(), $this->alerts );
	}

	private function health(): SchedulerHealth {
		$logger = new DatabaseLogger();
		return new SchedulerHealth(
			new InventoryScheduler( new SettingsRepository(), $logger ),
			$logger,
			fn(): int => $this->now,
			function ( int $before ): int {
				$this->queried_before[] = $before;
				return $this->overdue;
			}
		);
	}
}
