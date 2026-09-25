<?php
namespace IdeaXperts\EndlessAisles\Database;

defined( 'ABSPATH' ) || exit;

final class SyncRunRepository {
	private const EXPECTED_HISTORY_DAYS = 30;

	public function record_status( string $status, string $message ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->prefix . 'ideaxperts_ea_sync_runs',
			array(
				'run_type'   => 'inventory',
				'status'     => sanitize_key( $status ),
				'message'    => $message,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		$this->prune_expected_history();
	}

	private function prune_expected_history(): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::EXPECTED_HISTORY_DAYS * DAY_IN_SECONDS ) );
		$table  = $wpdb->prefix . 'ideaxperts_ea_sync_runs';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE run_type = %s AND status = %s AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'inventory',
				'not_implemented',
				$cutoff
			)
		);
	}
}
