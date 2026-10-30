<?php
namespace IdeaXperts\EndlessAisles\Inventory;

defined( 'ABSPATH' ) || exit;

/** Inventory run state: an expiring option lock, a resumable cursor, and sync_runs rows. */
final class InventoryRepository implements InventoryStoreInterface {
	public const LOCK_OPTION    = 'ideaxperts_ea_inventory_lock';
	public const CURSOR_OPTION  = 'ideaxperts_ea_inventory_cursor';
	public const SUCCESS_OPTION = 'ideaxperts_ea_inventory_last_success';

	public function acquire_lock( int $ttl_seconds ): string {
		$token = bin2hex( random_bytes( 16 ) );
		$value = $token . '|' . ( time() + $ttl_seconds );
		if ( add_option( self::LOCK_OPTION, $value, '', false ) ) {
			return $token;
		}
		$current = $this->raw_lock();
		if ( null === $current || $this->expiry( $current ) > time() ) {
			return '';
		}
		// Compare-and-swap takeover of an expired lock; a concurrent taker loses the race.
		return $this->swap_lock( $current, $value ) ? $token : '';
	}

	public function refresh_lock( string $token, int $ttl_seconds ): bool {
		$current = $this->raw_lock();
		if ( null === $current || ! $this->owns( $current, $token ) ) {
			return false;
		}
		$replacement = $token . '|' . ( time() + $ttl_seconds );
		// A same-second refresh writes an identical value and MySQL reports zero changed rows.
		return $replacement === $current || $this->swap_lock( $current, $replacement );
	}

	public function release_lock( string $token ): void {
		global $wpdb;
		$current = $this->raw_lock();
		if ( null === $current || ! $this->owns( $current, $token ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $current ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	public function cursor(): ?array {
		$value = get_option( self::CURSOR_OPTION, null );
		if ( ! is_array( $value ) || ! is_int( $value['run_id'] ?? null ) || ! is_int( $value['after_id'] ?? null ) || $value['run_id'] < 1 || $value['after_id'] < 0 || ! is_array( $value['counts'] ?? null ) ) {
			return null;
		}
		return array(
			'run_id'   => $value['run_id'],
			'after_id' => $value['after_id'],
			'counts'   => array_map( 'intval', $value['counts'] ),
		);
	}

	public function save_cursor( int $run_id, int $after_id, array $counts ): void {
		update_option(
			self::CURSOR_OPTION,
			array(
				'run_id'   => $run_id,
				'after_id' => $after_id,
				'counts'   => $counts,
			),
			false
		);
	}

	public function clear_cursor(): void {
		delete_option( self::CURSOR_OPTION );
	}

	public function active_mappings( string $scope, string $environment, int $after_id, int $limit ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'ideaxperts_ea_mappings';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, wc_product_id, ea_product_id, ea_option_id FROM {$table} WHERE source_scope = %s AND environment = %s AND mapping_status = %s AND wc_variation_id = 0 AND ea_option_id <> '' AND id > %d ORDER BY id ASC LIMIT %d", $scope, $environment, 'active', $after_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[] = array(
				'id'            => (int) $row['id'],
				'wc_product_id' => (int) $row['wc_product_id'],
				'ea_product_id' => (string) $row['ea_product_id'],
				'ea_option_id'  => (string) $row['ea_option_id'],
			);
		}
		return $result;
	}

	public function mark_synced( int $mapping_id ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->update(
			$wpdb->prefix . 'ideaxperts_ea_mappings',
			array(
				'last_synced_at' => $now,
				'updated_at'     => $now,
			),
			array( 'id' => $mapping_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function start_run(): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			$wpdb->prefix . 'ideaxperts_ea_sync_runs',
			array(
				'run_type'   => 'inventory',
				'status'     => 'running',
				'started_at' => $now,
				'message'    => '',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	public function update_run( int $run_id, string $status, array $counts, string $message ): void {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array(
			'status'          => sanitize_key( $status ),
			'processed_count' => (int) ( $counts['processed'] ?? 0 ),
			'success_count'   => (int) ( $counts['success'] ?? 0 ),
			'failure_count'   => (int) ( $counts['failure'] ?? 0 ),
			'message'         => $message,
			'updated_at'      => $now,
		);
		if ( 'running' !== $status ) {
			$data['completed_at'] = $now;
		}
		$wpdb->update( $wpdb->prefix . 'ideaxperts_ea_sync_runs', $data, array( 'id' => $run_id ) );
	}

	public function record_success( string $environment ): void {
		update_option(
			self::SUCCESS_OPTION,
			array(
				'environment'  => $environment,
				'completed_at' => current_time( 'mysql', true ),
			),
			false
		);
	}

	private function raw_lock(): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_string( $value ) ? $value : null;
	}

	private function swap_lock( string $expected, string $replacement ): bool {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $replacement, self::LOCK_OPTION, $expected ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return 1 === (int) $wpdb->rows_affected;
	}

	private function owns( string $value, string $token ): bool {
		return '' !== $token && hash_equals( $token, explode( '|', $value, 2 )[0] );
	}

	private function expiry( string $value ): int {
		$parts = explode( '|', $value, 2 );
		return isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : 0;
	}
}
