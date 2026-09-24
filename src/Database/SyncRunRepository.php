<?php
namespace IdeaXperts\EndlessAisles\Database;

defined( 'ABSPATH' ) || exit;

final class SyncRunRepository {
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
	}
}
