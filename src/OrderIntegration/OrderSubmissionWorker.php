<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

use IdeaXperts\EndlessAisles\Inventory\InventoryStoreInterface;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Submission state machine (docs/ORDER-SUBMISSION-DESIGN.md §5).
 *
 * Invariants: `submitting` is committed before HTTP; only a 200 with a vendor ID
 * records `accepted`; every automatic retry re-sends the identical payload under
 * the same custom_order_id; nothing ever resubmits under a new reference.
 */
final class OrderSubmissionWorker {
	public const LEASE_SECONDS      = 120;
	public const MAX_ATTEMPTS       = 5;
	public const STALE_INTENT_HOURS = 72;
	public const SWEEP_LIMIT        = 10;
	/** Delay before attempt n+1, indexed by attempts already made. */
	public const BACKOFF = array(
		1 => 60,
		2 => 300,
		3 => 900,
		4 => 3600,
		5 => 14400,
	);

	public function __construct(
		private readonly SubmissionGates $gates,
		private readonly InventoryStoreInterface $mappings,
		private readonly OrderSubmissionStoreInterface $submissions,
		private readonly OrderPayloadBuilder $builder,
		private readonly OrderSubmissionClient $client,
		private readonly DatabaseLogger $logger
	) {}

	public function sweep(): void {
		if ( '' !== $this->gates->configuration_failure() ) {
			return;
		}
		foreach ( $this->submissions->due( self::SWEEP_LIMIT ) as $row ) {
			$this->process( (int) $row['id'] );
		}
	}

	public function process( mixed $submission_id ): void {
		$row = $this->submissions->get( absint( $submission_id ) );
		if ( null === $row || '' !== $this->gates->configuration_failure() ) {
			return;
		}
		$id     = (int) $row['id'];
		$status = (string) $row['status'];
		if ( 'submitting' === $status ) {
			$this->recover_expired_lease( $row );
			return;
		}
		if ( ! in_array( $status, array( 'pending', 'unknown' ), true ) ) {
			return;
		}
		if ( 'pending' === $status && strtotime( (string) $row['created_at'] . ' UTC' ) < strtotime( current_time( 'mysql', true ) . ' UTC' ) - self::STALE_INTENT_HOURS * 3600 ) {
			// Never ship an order that waited this long (e.g. the switch was off and on again).
			$this->hold( $row, $status, 'manual_review', 'stale_intent', 'was queued more than 72 hours ago and was not sent automatically' );
			return;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['wc_order_id'] ) : false;
		if ( ! is_object( $order ) ) {
			$this->hold( $row, $status, 'blocked', 'order_missing', 'could not be loaded' );
			return;
		}
		$rebuilt = $this->rebuild( $order, $row );
		$failure = $rebuilt instanceof OrderPayload ? $this->gates->send_failure( $order, $row, $rebuilt ) : $rebuilt;
		if ( '' !== $failure ) {
			// After an unknown attempt the vendor may already hold the order, so it is never just "blocked".
			if ( 'unknown' === $status ) {
				$this->hold( $row, $status, 'manual_review', $failure, 'MAY already exist at the vendor but can no longer be retried safely; check with Endless Aisles before doing anything else' );
			} else {
				$this->hold( $row, $status, 'blocked', $failure, 'is blocked before sending' );
			}
			return;
		}
		$token = $this->submissions->claim( $id, $status, self::LEASE_SECONDS );
		if ( '' === $token ) {
			return; // Another worker or a state change won; it owns the outcome.
		}
		$outcome = $this->client->submit( (string) $row['environment'], $rebuilt );
		$this->settle( $row, $token, $order, $outcome );
	}

	/** @param array<string,mixed> $row */
	private function rebuild( object $order, array $row ): OrderPayload|string {
		$environment = (string) $row['environment'];
		$snapshot    = json_decode( (string) $row['line_snapshot'], true );
		$product_ids = is_array( $snapshot ) ? array_map( static fn( mixed $line ): int => is_array( $line ) ? (int) ( $line['wc_product_id'] ?? 0 ) : 0, $snapshot ) : array();
		try {
			$payload = $this->builder->build( $order, $environment, $this->mappings->mappings_for_products( 'endless-aisles:' . $environment, $environment, $product_ids ) );
		} catch ( OrderPayloadException $exception ) {
			return $exception->getMessage();
		}
		return $payload ?? 'payload_changed';
	}

	/** @param array<string,mixed> $row */
	private function settle( array $row, string $token, object $order, SubmissionOutcome $outcome ): void {
		$id       = (int) $row['id'];
		$attempts = (int) $row['attempt_count'] + 1;
		$now      = current_time( 'mysql', true );
		$context  = array(
			'wc_order_id'   => (int) $row['wc_order_id'],
			'submission_id' => $id,
			'attempt'       => $attempts,
			'code'          => $outcome->code,
		);
		switch ( $outcome->kind ) {
			case SubmissionOutcome::ACCEPTED:
				$fields = array(
					'ea_order_id'     => $outcome->ea_order_id,
					'vendor_status'   => $outcome->vendor_status,
					'accepted_at'     => $now,
					'last_error_code' => '',
					'last_error'      => null,
				);
				if ( ! $this->submissions->settle( $id, $token, 'accepted', $fields ) ) {
					// The vendor order exists; losing the record of it must never pass quietly.
					$this->logger->log( 'critical', sprintf( 'Vendor accepted order #%d as vendor order #%s but the result could not be recorded.', (int) $row['wc_order_id'], $outcome->ea_order_id ), $context );
					return;
				}
				$this->note( $order, sprintf( 'Endless Aisles: vendor order #%s created (%s).', $outcome->ea_order_id, (string) $row['custom_order_id'] ) );
				$this->logger->log( 'info', 'Vendor order accepted.', array_merge( $context, array( 'ea_order_id' => $outcome->ea_order_id ) ) );
				return;
			case SubmissionOutcome::DUPLICATE:
				$this->finish( $id, $token, $order, 'manual_review', $outcome, $context, 'Endless Aisles already holds this order reference; find its vendor order number before doing anything else. Do not resubmit.' );
				return;
			case SubmissionOutcome::REJECTED:
				$this->finish( $id, $token, $order, 'rejected', $outcome, $context, 'Endless Aisles rejected this order (' . $outcome->detail . '). It was not created; fix the order and retry it from the Vendor orders screen.' );
				return;
			case SubmissionOutcome::NOT_CREATED:
			case SubmissionOutcome::UNKNOWN:
			default:
				$next = SubmissionOutcome::NOT_CREATED === $outcome->kind ? 'pending' : 'unknown';
				if ( $attempts >= self::MAX_ATTEMPTS ) {
					$message = SubmissionOutcome::UNKNOWN === $outcome->kind
						? 'The vendor did not confirm this order after repeated attempts; it MAY exist. Check with Endless Aisles before doing anything else. Do not resubmit.'
						: 'The vendor refused this order repeatedly before processing it (' . $outcome->code . '); it was not created.';
					$this->finish( $id, $token, $order, 'manual_review', $outcome, $context, $message );
					return;
				}
				$delay = self::BACKOFF[ $attempts ] ?? self::BACKOFF[ self::MAX_ATTEMPTS ];
				$this->submissions->settle(
					$id,
					$token,
					$next,
					array(
						'next_attempt_at' => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + $delay ),
						'last_error_code' => $outcome->code,
						'last_error'      => $outcome->detail,
					)
				);
				$this->logger->log( 'warning', 'Vendor order attempt did not complete; it will be retried with the same reference.', array_merge( $context, array( 'outcome' => $outcome->kind ) ) );
		}
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function finish( int $id, string $token, object $order, string $status, SubmissionOutcome $outcome, array $context, string $note ): void {
		$this->submissions->settle(
			$id,
			$token,
			$status,
			array(
				'last_error_code' => $outcome->code,
				'last_error'      => $outcome->detail,
			)
		);
		$this->note( $order, 'Endless Aisles: ' . $note );
		// The order number keeps alerts for different orders from throttling each other.
		$this->logger->log( 'error', sprintf( 'Vendor order for order #%d needs attention: %s.', (int) $context['wc_order_id'], $status ), $context );
	}

	/**
	 * A worker died after committing `submitting`: the request may have reached the
	 * vendor, so the row becomes `unknown` and follows the same identical-retry path.
	 *
	 * @param array<string,mixed> $row
	 */
	private function recover_expired_lease( array $row ): void {
		$now = current_time( 'mysql', true );
		if ( empty( $row['lease_expires_at'] ) || (string) $row['lease_expires_at'] > $now ) {
			return;
		}
		if ( (int) $row['attempt_count'] >= self::MAX_ATTEMPTS ) {
			$this->hold( $row, 'submitting', 'manual_review', 'worker_lost_final_attempt', 'MAY exist at the vendor after an interrupted final attempt; check with Endless Aisles before doing anything else' );
			return;
		}
		$this->submissions->transition(
			(int) $row['id'],
			'submitting',
			array( 'owner_token' => (string) $row['owner_token'] ),
			'unknown',
			array(
				'next_attempt_at' => $now,
				'last_error_code' => 'worker_lease_expired',
			)
		);
	}

	/** @param array<string,mixed> $row */
	private function hold( array $row, string $from, string $to, string $code, string $reason ): void {
		$expected = 'submitting' === $from ? array( 'owner_token' => (string) $row['owner_token'] ) : array();
		if ( ! $this->submissions->transition(
			(int) $row['id'],
			$from,
			$expected,
			$to,
			array(
				'last_error_code' => $code,
				'next_attempt_at' => null,
			)
		) ) {
			return;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['wc_order_id'] ) : false;
		if ( is_object( $order ) ) {
			$this->note( $order, sprintf( 'Endless Aisles: vendor order %s %s (%s).', (string) $row['custom_order_id'], $reason, $code ) );
		}
		$this->logger->log(
			'error',
			sprintf( 'Vendor order for order #%d needs attention: %s.', (int) $row['wc_order_id'], $to ),
			array(
				'wc_order_id'   => (int) $row['wc_order_id'],
				'submission_id' => (int) $row['id'],
				'code'          => $code,
			)
		);
	}

	private function note( object $order, string $text ): void {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $text );
		}
	}
}
