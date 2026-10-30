<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

use IdeaXperts\EndlessAisles\OrderIntegration\OrderSubmissionStoreInterface;

/**
 * Same unique keys and compare-and-swap semantics as the real table, on the
 * test clock (`$GLOBALS['ea_now']`, read through current_time()).
 */
final class MemoryOrderSubmissionStore implements OrderSubmissionStoreInterface {
	/** @var array<int,array<string,mixed>> */
	public array $rows = array();
	public bool $fail  = false;
	/** Called after a successful claim, before the caller continues: simulates another worker. */
	public ?\Closure $after_claim = null;

	public function record_intent( array $intent ): ?array {
		if ( $this->fail ) {
			return null;
		}
		foreach ( $this->rows as $id => $row ) {
			if ( ( $row['wc_order_id'] === $intent['wc_order_id'] && $row['environment'] === $intent['environment'] ) || $row['custom_order_id'] === $intent['custom_order_id'] ) {
				return array(
					'id'      => $id,
					'created' => false,
				);
			}
		}
		$id                = count( $this->rows ) + 1;
		$now               = current_time( 'mysql', true );
		$this->rows[ $id ] = array_merge(
			$intent,
			array(
				'id'               => $id,
				'owner_token'      => '',
				'lease_expires_at' => null,
				'attempt_count'    => 0,
				'next_attempt_at'  => 'pending' === $intent['status'] ? $now : null,
				'ea_order_id'      => '',
				'vendor_status'    => '',
				'last_error'       => null,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		return array(
			'id'      => $id,
			'created' => true,
		);
	}

	public function find_for_order( int $wc_order_id, string $environment ): ?array {
		foreach ( $this->rows as $row ) {
			if ( $row['wc_order_id'] === $wc_order_id && $row['environment'] === $environment ) {
				return $row;
			}
		}
		return null;
	}

	public function recent( int $limit ): array {
		return array_slice( array_reverse( array_values( $this->rows ) ), 0, $limit );
	}

	public function get( int $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}

	public function due( int $limit ): array {
		$now = current_time( 'mysql', true );
		$due = array_filter(
			$this->rows,
			static fn( array $row ): bool => ( in_array( $row['status'], array( 'pending', 'unknown' ), true ) && null !== $row['next_attempt_at'] && $row['next_attempt_at'] <= $now )
				|| ( 'submitting' === $row['status'] && null !== $row['lease_expires_at'] && $row['lease_expires_at'] <= $now )
		);
		return array_slice( array_values( $due ), 0, $limit );
	}

	public function reconcilable( string $environment, int $interval_seconds, int $limit ): array {
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) . ' UTC' ) - $interval_seconds );
		$rows   = array_filter(
			$this->rows,
			static fn( array $row ): bool => $row['environment'] === $environment && in_array( $row['status'], array( 'accepted', 'scheduled', 'partially_shipped' ), true ) && '' !== (string) $row['ea_order_id'] && ( empty( $row['status_synced_at'] ) || $row['status_synced_at'] <= $cutoff )
		);
		return array_slice( array_values( $rows ), 0, $limit );
	}

	public function claim( int $id, string $from_status, int $lease_seconds ): string {
		$row = $this->rows[ $id ] ?? null;
		$now = current_time( 'mysql', true );
		if ( null === $row || $row['status'] !== $from_status || null === $row['next_attempt_at'] || $row['next_attempt_at'] > $now ) {
			return '';
		}
		$token             = bin2hex( random_bytes( 16 ) );
		$this->rows[ $id ] = array_merge(
			$row,
			array(
				'status'           => 'submitting',
				'owner_token'      => $token,
				'lease_expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + $lease_seconds ),
				'attempt_count'    => (int) $row['attempt_count'] + 1,
				'next_attempt_at'  => null,
			)
		);
		if ( $this->after_claim ) {
			( $this->after_claim )( $id );
		}
		return $token;
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
		$row = $this->rows[ $id ] ?? null;
		if ( null === $row ) {
			return false;
		}
		foreach ( $where as $key => $value ) {
			if ( (string) $row[ $key ] !== (string) $value ) {
				return false;
			}
		}
		$data = array_merge( $fields, array( 'status' => $to_status ) );
		if ( 'submitting' !== $to_status ) {
			$data['owner_token']      = '';
			$data['lease_expires_at'] = null;
		}
		$this->rows[ $id ] = array_merge( $row, $data );
		return true;
	}
}
