<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

use IdeaXperts\EndlessAisles\OrderIntegration\OrderSubmissionStoreInterface;

/** Enforces the same unique (wc_order_id, environment) and custom_order_id keys as the real table. */
final class MemoryOrderSubmissionStore implements OrderSubmissionStoreInterface {
	/** @var array<int,array<string,mixed>> */
	public array $rows = array();
	public bool $fail = false;

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
		$this->rows[ $id ] = array_merge( $intent, array( 'id' => $id ) );
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
}
