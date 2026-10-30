<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

defined( 'ABSPATH' ) || exit;

final class OrderSubmissionRepository implements OrderSubmissionStoreInterface {
	private const TABLE = 'ideaxperts_ea_order_submissions';

	public function record_intent( array $intent ): ?array {
		global $wpdb;
		$existing = $this->find_for_order( $intent['wc_order_id'], $intent['environment'] );
		if ( null !== $existing ) {
			return array(
				'id'      => (int) $existing['id'],
				'created' => false,
			);
		}
		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'wc_order_id'     => $intent['wc_order_id'],
				// Retained unique column; derived from the vendor-side key so it can never diverge.
				'idempotency_key' => hash( 'sha256', $intent['custom_order_id'] ),
				'environment'     => $intent['environment'],
				'source_scope'    => 'endless-aisles:' . $intent['environment'],
				'custom_order_id' => $intent['custom_order_id'],
				'status'          => $intent['status'],
				'payload_hash'    => $intent['payload_hash'],
				'line_snapshot'   => $intent['line_snapshot'],
				'last_error_code' => $intent['last_error_code'],
				'next_attempt_at' => 'pending' === $intent['status'] ? $now : null,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		if ( false !== $inserted ) {
			return array(
				'id'      => (int) $wpdb->insert_id,
				'created' => true,
			);
		}
		// A concurrent callback won the unique (order, environment) key: return its row.
		$existing = $this->find_for_order( $intent['wc_order_id'], $intent['environment'] );
		return null === $existing ? null : array(
			'id'      => (int) $existing['id'],
			'created' => false,
		);
	}

	public function find_for_order( int $wc_order_id, string $environment ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_order_id = %d AND environment = %s", $wc_order_id, $environment ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	public function recent( int $limit ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, wc_order_id, environment, custom_order_id, status, attempt_count, ea_order_id, vendor_status, last_error_code, updated_at FROM {$table} ORDER BY id DESC LIMIT %d", min( 100, max( 1, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? array_values( $rows ) : array();
	}
}
