<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

defined( 'ABSPATH' ) || exit;

final class IdempotencyKey {
	/** @param list<int> $line_item_ids */
	public static function generate( int $order_id, array $line_item_ids ): string {
		$ids = array_map( 'absint', $line_item_ids );
		sort( $ids, SORT_NUMERIC );
		return hash( 'sha256', 'ea-order-v1|' . $order_id . '|' . implode( ',', $ids ) );
	}
}
