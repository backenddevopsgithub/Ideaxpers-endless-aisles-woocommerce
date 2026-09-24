<?php
namespace IdeaXperts\EndlessAisles\Tests\Scheduling;

use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Scheduling\InventoryScheduler;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class InventorySchedulerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ea_scheduled']                              = false;
		$GLOBALS['ea_schedule_calls']                         = 0;
		$GLOBALS['ea_schedule_interval']                      = 0;
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings'] = array( 'inventory_interval' => 30 );
	}

	public function test_repeated_ensure_does_not_create_duplicate_actions(): void {
		$scheduler = new InventoryScheduler( new SettingsRepository(), new DatabaseLogger() );
		$scheduler->ensure_scheduled();
		$scheduler->ensure_scheduled();
		self::assertSame( 1, $GLOBALS['ea_schedule_calls'] );
		self::assertSame( 30 * MINUTE_IN_SECONDS, $GLOBALS['ea_schedule_interval'] );
	}
}
