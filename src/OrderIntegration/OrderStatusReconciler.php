<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Follows accepted vendor orders (docs/ORDER-SUBMISSION-DESIGN.md §9). It records
 * vendor state, shipped quantities and tracking, and adds order notes. It never
 * changes the WooCommerce order status and never refunds; those stay merchant decisions.
 */
final class OrderStatusReconciler {
	public const INTERVAL_SECONDS = 1800;
	public const BATCH_LIMIT      = 20;
	public const TRACKING_META    = '_ideaxperts_ea_tracking';

	private const MESSAGES = array(
		'scheduled'         => 'Endless Aisles: vendor order #%s is scheduled.',
		'partially_shipped' => 'Endless Aisles: vendor order #%s partially shipped (%d of %d units).',
		'shipped'           => 'Endless Aisles: vendor order #%s fully shipped.',
		'cancelled'         => 'Endless Aisles: vendor order #%s was CANCELLED by the vendor. Arrange a refund or replacement manually.',
		'failed'            => 'Endless Aisles: vendor order #%s FAILED at the vendor. Contact Endless Aisles and the customer.',
	);

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly SubmissionGates $gates,
		private readonly OrderSubmissionStoreInterface $submissions,
		private readonly OrderStatusClient $client,
		private readonly DatabaseLogger $logger
	) {}

	public function run(): void {
		if ( '' !== $this->gates->reconciliation_failure() ) {
			return;
		}
		$environment = (string) $this->settings->get( 'environment', 'qa' );
		foreach ( $this->submissions->reconcilable( $environment, self::INTERVAL_SECONDS, self::BATCH_LIMIT ) as $row ) {
			$this->reconcile( $row );
		}
	}

	/** @param array<string,mixed> $row */
	public function reconcile( array $row ): void {
		$id          = (int) $row['id'];
		$from        = (string) $row['status'];
		$ea_order_id = (string) $row['ea_order_id'];
		$now         = current_time( 'mysql', true );
		$expected    = array( 'ea_order_id' => $ea_order_id );
		try {
			$vendor = $this->client->fetch( (string) $row['environment'], $ea_order_id );
		} catch ( ApiException $exception ) {
			$this->failed_fetch( $row, $exception, $now );
			return;
		}
		$to      = $vendor->local_status();
		$known   = json_decode( (string) ( $row['tracking_json'] ?? '' ), true );
		$known   = is_array( $known ) ? $known : array();
		$numbers = array_column( $known, 'number' );
		$new     = array_values( array_filter( $vendor->shipments, static fn( array $shipment ): bool => ! in_array( $shipment['number'], $numbers, true ) ) );
		$fields  = array(
			'vendor_status'       => substr( sanitize_key( $vendor->status ), 0, 32 ),
			'vendor_last_updated' => $vendor->last_updated,
			'status_synced_at'    => $now,
			'shipped_json'        => (string) wp_json_encode( $vendor->lines ),
			'tracking_json'       => (string) wp_json_encode( array_merge( $known, $new ) ),
		);
		if ( ! $this->submissions->transition( $id, $from, $expected, $to, $fields ) ) {
			return; // Another reconciler or an admin action changed the row first.
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['wc_order_id'] ) : false;
		if ( ! is_object( $order ) ) {
			return;
		}
		foreach ( $new as $shipment ) {
			$this->note( $order, sprintf( 'Endless Aisles shipment for vendor order #%s: tracking %s%s', $ea_order_id, $shipment['number'], '' !== $shipment['url'] ? ' (' . $shipment['url'] . ')' : '' ) );
		}
		if ( array() !== $new && method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->update_meta_data( self::TRACKING_META, (string) wp_json_encode( array_merge( $known, $new ) ) );
			$order->save();
		}
		if ( $to === $from || ( 'accepted' === $from && 'scheduled' === $to ) ) {
			return; // Only real progress is noted; "accepted" already reported scheduling.
		}
		list( $shipped, $ordered ) = $vendor->units();
		$this->note( $order, sprintf( self::MESSAGES[ $to ], $ea_order_id, $shipped, $ordered ) );
		if ( in_array( $to, array( 'cancelled', 'failed' ), true ) ) {
			$this->logger->log(
				'error',
				sprintf( 'Vendor order #%s for order #%d was %s by Endless Aisles.', $ea_order_id, (int) $row['wc_order_id'], $to ),
				array(
					'wc_order_id'   => (int) $row['wc_order_id'],
					'submission_id' => $id,
				)
			);
		}
	}

	/** @param array<string,mixed> $row */
	private function failed_fetch( array $row, ApiException $exception, string $now ): void {
		$id       = (int) $row['id'];
		$expected = array( 'ea_order_id' => (string) $row['ea_order_id'] );
		if ( 404 === $exception->getCode() && 'qa' === $row['environment'] ) {
			// The QA database resets daily; old QA orders are expected to disappear.
			$this->submissions->transition( $id, (string) $row['status'], $expected, 'vendor_record_expired', array( 'status_synced_at' => $now ) );
			return;
		}
		// Rotate the row to the back of the queue so one bad order cannot starve the rest.
		$this->submissions->transition( $id, (string) $row['status'], $expected, (string) $row['status'], array( 'status_synced_at' => $now ) );
		if ( 404 === $exception->getCode() ) {
			$this->logger->log( 'error', sprintf( 'Vendor order #%s for order #%d was not found at Endless Aisles.', (string) $row['ea_order_id'], (int) $row['wc_order_id'] ), array( 'submission_id' => $id ) );
			return;
		}
		$this->logger->log( 'error', 'Vendor order status reconciliation failed.', array( 'status' => $exception->getCode() ) );
	}

	private function note( object $order, string $text ): void {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $text );
		}
	}
}
