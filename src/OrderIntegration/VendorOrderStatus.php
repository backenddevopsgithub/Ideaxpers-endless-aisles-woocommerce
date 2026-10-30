<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

defined( 'ABSPATH' ) || exit;

/** Normalized `GET /api/orders/{id}` result: shipping lines excluded, tracking validated. */
final class VendorOrderStatus {
	/**
	 * @param list<array{id:string,quantity:int,shipped:int}> $lines
	 * @param list<array{number:string,url:string}>         $shipments
	 */
	public function __construct(
		public readonly string $status,
		public readonly array $lines,
		public readonly array $shipments,
		public readonly string $last_updated
	) {}

	/** Local state derived from the vendor's status and per-line shipped quantities. */
	public function local_status(): string {
		if ( in_array( $this->status, array( 'canceled', 'cancelled' ), true ) ) {
			return 'cancelled';
		}
		if ( 'failed' === $this->status ) {
			return 'failed';
		}
		$ordered = array_sum( array_column( $this->lines, 'quantity' ) );
		$shipped = array_sum( array_map( static fn( array $line ): int => min( $line['quantity'], $line['shipped'] ), $this->lines ) );
		if ( $ordered > 0 && $shipped >= $ordered && 'shipped' === $this->status ) {
			return 'shipped';
		}
		if ( $shipped > 0 || 'shipped' === $this->status ) {
			// "Shipped" with units still outstanding is reported as partial, never as complete.
			return 'partially_shipped';
		}
		return 'scheduled';
	}

	/** @return array{0:int,1:int} Shipped units, ordered units. */
	public function units(): array {
		return array(
			array_sum( array_map( static fn( array $line ): int => min( $line['quantity'], $line['shipped'] ), $this->lines ) ),
			array_sum( array_column( $this->lines, 'quantity' ) ),
		);
	}
}
