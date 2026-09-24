<?php
namespace IdeaXperts\EndlessAisles\Core;

use IdeaXperts\EndlessAisles\Scheduling\InventoryScheduler;

defined( 'ABSPATH' ) || exit;

final class Deactivator {
	public static function deactivate(): void {
		InventoryScheduler::unschedule_all();
	}
}
