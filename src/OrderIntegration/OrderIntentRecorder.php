<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

use IdeaXperts\EndlessAisles\Inventory\InventoryStoreInterface;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Records the single durable submission intent when a paid order contains
 * vendor lines. Never contacts Endless Aisles; the submission worker does.
 */
final class OrderIntentRecorder {
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly SubmissionGates $gates,
		private readonly InventoryStoreInterface $mappings,
		private readonly OrderSubmissionStoreInterface $submissions,
		private readonly OrderPayloadBuilder $builder,
		private readonly DatabaseLogger $logger
	) {}

	public function register(): void {
		add_action( 'woocommerce_payment_complete', array( $this, 'record' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'record' ), 20, 1 );
	}

	/** Idempotent: duplicate or concurrent callbacks resolve to the same row. */
	public function record( mixed $order_id ): void {
		$order_id = absint( $order_id );
		if ( $order_id < 1 || '' !== $this->gates->configuration_failure() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) || ! in_array( (string) $order->get_status(), SubmissionGates::PAID_STATUSES, true ) ) {
			return;
		}
		$environment = (string) $this->settings->get( 'environment', 'qa' );
		if ( null !== $this->submissions->find_for_order( $order_id, $environment ) ) {
			return;
		}
		$product_ids = array();
		foreach ( method_exists( $order, 'get_items' ) ? (array) $order->get_items( 'line_item' ) : array() as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
				$product_ids[] = (int) $item->get_product_id();
			}
		}
		$mappings = $this->mappings->mappings_for_products( 'endless-aisles:' . $environment, $environment, $product_ids );
		if ( array() === $mappings ) {
			return;
		}
		$failure = '';
		$payload = null;
		try {
			$payload = $this->builder->build( $order, $environment, $mappings );
		} catch ( OrderPayloadException $exception ) {
			$failure = $exception->getMessage();
		}
		if ( null === $payload && '' === $failure ) {
			return;
		}
		$result = $this->submissions->record_intent(
			array(
				'wc_order_id'     => $order_id,
				'environment'     => $environment,
				'custom_order_id' => null !== $payload ? $payload->custom_order_id() : CustomOrderId::for_order( $order_id, $environment ),
				'status'          => '' === $failure ? 'pending' : 'blocked',
				'payload_hash'    => null !== $payload ? $payload->hash : '',
				'line_snapshot'   => null !== $payload ? (string) wp_json_encode( $payload->lines ) : '[]',
				'last_error_code' => $failure,
			)
		);
		if ( null === $result ) {
			$this->logger->log( 'critical', 'Vendor order intent could not be recorded; the order will not be sent automatically.', array( 'wc_order_id' => $order_id ) );
			return;
		}
		if ( ! $result['created'] ) {
			return;
		}
		if ( '' !== $failure ) {
			$this->note( $order, sprintf( 'Endless Aisles: this order was NOT queued for the vendor (%s). Fulfil or correct it manually.', $failure ) );
			$this->logger->log(
				'error',
				'Paid order with Endless Aisles items cannot be sent to the vendor.',
				array(
					'wc_order_id'  => $order_id,
					'failure_code' => $failure,
				)
			);
			return;
		}
		$this->note( $order, sprintf( 'Endless Aisles: vendor order queued as %s.', $payload->custom_order_id() ) );
		$this->logger->log(
			'info',
			'Vendor order intent recorded.',
			array(
				'wc_order_id'   => $order_id,
				'submission_id' => $result['id'],
				'lines'         => count( $payload->lines ),
			)
		);
	}

	private function note( object $order, string $text ): void {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $text );
		}
	}
}
