<?php
namespace IdeaXperts\EndlessAisles\Core;

use IdeaXperts\EndlessAisles\Scheduling\InventoryScheduler;
use IdeaXperts\EndlessAisles\Catalog\DryRunManager;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;

defined( 'ABSPATH' ) || exit;

final class Deactivator {
	public static function deactivate(): void {
		InventoryScheduler::unschedule_all();
		DryRunManager::unschedule_all_jobs();
		$runs   = new DryRunRepository();
		$failed = $runs->fail_active_runs( 'The plugin was deactivated.' );
		foreach ( $failed as $run_id ) {
			$runs->release_lock( $run_id );
		}
	}
}
