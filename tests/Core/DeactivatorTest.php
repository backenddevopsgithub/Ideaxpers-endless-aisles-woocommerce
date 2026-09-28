<?php
namespace IdeaXperts\EndlessAisles\Tests\Core;

use IdeaXperts\EndlessAisles\Catalog\DryRunManager;
use IdeaXperts\EndlessAisles\Core\Deactivator;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Tests\Support\DryRunMemoryWpdb;
use PHPUnit\Framework\TestCase;

final class DeactivatorTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']                  = new DryRunMemoryWpdb();
		$GLOBALS['ea_action_queue']       = array(
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
		$GLOBALS['ea_now']                = '2026-09-24 12:00:00';
		$GLOBALS['ea_test_options']       = array();
		$GLOBALS['ea_add_option_failure'] = false;
		$GLOBALS['ea_unschedule_failure'] = false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_deactivation_fails_active_runs_releases_owned_lock_and_keeps_completed_reports(): void {
		$runs   = new DryRunRepository();
		$active = $runs->claim_new( 1 );
		$runs->transition( $active, array( 'pending' ), array( 'status' => 'scanning_store' ), $runs->claim_token( $active ) );
		$completed = $runs->create( 2, 'qa' );
		$runs->transition(
			$completed,
			array( 'pending' ),
			array(
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql', true ),
			)
		);

		Deactivator::deactivate();

		self::assertSame( 'failed', $runs->run( $active )['status'] );
		self::assertSame( 'The plugin was deactivated.', $runs->run( $active )['error_summary'] );
		self::assertSame( 'completed', $runs->run( $completed )['status'] );
		self::assertCount( 2, $GLOBALS['ea_action_queue'] );
		delete_option( DryRunRepository::DEACTIVATING_OPTION );
		self::assertGreaterThan( 0, $runs->claim_new( 9 ) );
	}

	public function test_deactivation_marker_failure_does_not_pretend_cleanup_is_safe(): void {
		$runs   = new DryRunRepository();
		$active = $runs->claim_new( 1 );
		$runs->transition( $active, array( 'pending' ), array( 'status' => 'scanning_store' ), $runs->claim_token( $active ), $runs->claim_generation( $active ) );
		$GLOBALS['ea_add_option_failure'] = true;

		Deactivator::deactivate();

		self::assertSame( 'scanning_store', $runs->run( $active )['status'] );
		self::assertFalse( get_option( DryRunRepository::DEACTIVATING_OPTION, false ) );
	}
}
