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

	public function get( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	public function due( int $limit ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql', true );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE (status IN (%s,%s) AND next_attempt_at IS NOT NULL AND next_attempt_at <= %s) OR (status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s) ORDER BY COALESCE(next_attempt_at, lease_expires_at) ASC, id ASC LIMIT %d", 'pending', 'unknown', $now, 'submitting', $now, min( 50, max( 1, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	public function claim( int $id, string $from_status, int $lease_seconds ): string {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql', true );
		$token = bin2hex( random_bytes( 16 ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, owner_token = %s, lease_expires_at = %s, attempt_count = attempt_count + 1, next_attempt_at = NULL, submitted_at = %s, updated_at = %s WHERE id = %d AND status = %s AND next_attempt_at IS NOT NULL AND next_attempt_at <= %s", 'submitting', $token, gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + $lease_seconds ), $now, $now, $id, $from_status, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === (int) $wpdb->rows_affected ? $token : '';
	}

	public function settle( int $id, string $token, string $to_status, array $fields ): bool {
		return '' !== $token && $this->update_where(
			$id,
			array(
				'status'      => 'submitting',
				'owner_token' => $token,
			),
			$to_status,
			$fields
		);
	}

	public function transition( int $id, string $from_status, array $expected, string $to_status, array $fields ): bool {
		return $this->update_where( $id, array_merge( $expected, array( 'status' => $from_status ) ), $to_status, $fields );
	}

	/**
	 * @param array<string,string|int> $where
	 * @param array<string,string|int|null> $fields
	 */
	private function update_where( int $id, array $where, string $to_status, array $fields ): bool {
		global $wpdb;
		$data = array_merge(
			$fields,
			array(
				'status'     => $to_status,
				'updated_at' => current_time( 'mysql', true ),
			)
		);
		if ( 'submitting' !== $to_status ) {
			$data['owner_token']      = '';
			$data['lease_expires_at'] = null;
		}
		$updated = $wpdb->update( $wpdb->prefix . self::TABLE, $data, array_merge( $where, array( 'id' => $id ) ) );
		return 1 === $updated;
	}

	public function recent( int $limit ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, wc_order_id, environment, custom_order_id, status, attempt_count, ea_order_id, vendor_status, last_error_code, updated_at FROM {$table} ORDER BY id DESC LIMIT %d", min( 100, max( 1, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? array_values( $rows ) : array();
	}
}
