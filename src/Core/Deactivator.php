<?php
namespace IdeaXperts\EndlessAisles\Core;

use IdeaXperts\EndlessAisles\Scheduling\InventoryScheduler;
use IdeaXperts\EndlessAisles\Catalog\DryRunManager;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;

defined( 'ABSPATH' ) || exit;

final class Deactivator {
	public static function deactivate(): void {
		$runs = new DryRunRepository();
		if ( ! $runs->begin_deactivation() ) {
			return;
		}
		InventoryScheduler::unschedule_all();
		$run_ids = array_values( array_unique( array_merge( $runs->active_ids(), array( $runs->lock_run_id() ) ) ) );
		foreach ( $run_ids as $run_id ) {
			if ( $run_id < 1 ) {
				continue;
			}
			$token      = $runs->claim_token( $run_id );
			$generation = $runs->claim_generation( $run_id );
			$runs->begin_failure( $run_id, $token, $generation, 'The plugin was deactivated.' );
		}
		$runs->mark_maintenance_intents_cancel_requested();
		DryRunManager::reconcile_repository_cancellations( $runs );
		foreach ( $runs->cleanup_runs() as $run ) {
			$run_id     = (int) $run['id'];
			$token      = (string) $run['claim_token'];
			$generation = (int) $run['claim_generation'];
			if ( $runs->finalize_cleanup( $run_id, $token, $generation, 'failed' ) ) {
				$runs->release_lock( $run_id, $token, $generation );
			}
		}
	}
}
