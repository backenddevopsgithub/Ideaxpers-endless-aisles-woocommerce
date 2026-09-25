<?php
namespace IdeaXperts\EndlessAisles\Tests\Core;

use IdeaXperts\EndlessAisles\Catalog\DryRunManager;
use IdeaXperts\EndlessAisles\Core\Deactivator;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use PHPUnit\Framework\TestCase;

final class DeactivatorTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']            = new DryRunMemoryWpdb();
		$GLOBALS['ea_action_queue'] = array(
			array(
				'id'     => 41,
				'status' => 'pending',
				'hook'   => DryRunManager::STORE_HOOK,
				'args'   => array( 1, 2 ),
				'group'  => DryRunManager::GROUP,
			),
			array(
				'id'     => 42,
				'status' => 'pending',
				'hook'   => DryRunManager::CATALOG_HOOK,
				'args'   => array( 1, 3 ),
				'group'  => DryRunManager::GROUP,
			),
		);
		$GLOBALS['ea_now']          = '2026-09-24 12:00:00';
		$GLOBALS['ea_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_deactivation_fails_active_runs_releases_owned_lock_and_keeps_completed_reports(): void {
		$runs   = new DryRunRepository();
		$active = $runs->claim_new( 1 );
		$runs->transition( $active, array( 'pending' ), array( 'status' => 'scanning_store' ) );
		$completed = $runs->create( 2, 'qa' );
		$runs->transition( $completed, array( 'pending' ), array( 'status' => 'completed', 'completed_at' => current_time( 'mysql', true ) ) );

		Deactivator::deactivate();

		self::assertSame( 'failed', $runs->run( $active )['status'] );
		self::assertSame( 'The plugin was deactivated.', $runs->run( $active )['error_summary'] );
		self::assertSame( 'completed', $runs->run( $completed )['status'] );
		self::assertSame( array(), $GLOBALS['ea_action_queue'] );
		self::assertGreaterThan( 0, $runs->claim_new( 9 ) );
	}
}
