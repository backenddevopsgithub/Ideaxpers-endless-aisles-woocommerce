<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

defined( 'ABSPATH' ) || exit;

final class OrderSubmissionRepository {
	/**
	 * Atomically reserve an order payload through the table's unique key.
	 *
	 * @param list<int> $line_item_ids Endless Aisles line-item IDs only.
	 */
	public function reserve( int $order_id, array $line_item_ids ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return false !== $wpdb->insert(
			$wpdb->prefix . 'ideaxperts_ea_order_submissions',
			array(
				'wc_order_id'     => $order_id,
				'idempotency_key' => IdempotencyKey::generate( $order_id, $line_item_ids ),
				'status'          => 'pending',
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}
}
