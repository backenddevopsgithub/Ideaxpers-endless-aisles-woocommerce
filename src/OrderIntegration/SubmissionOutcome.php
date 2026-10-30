<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

defined( 'ABSPATH' ) || exit;

/** What one `PUT /api/orders` attempt proves about the vendor's state. */
final class SubmissionOutcome {
	/** 200 with a vendor order ID: the order exists and we know its ID. */
	public const ACCEPTED = 'accepted';
	/** The vendor already holds this custom_order_id: the order exists, its ID is unknown. */
	public const DUPLICATE = 'duplicate';
	/** Validation failure: the vendor did not create the order. */
	public const REJECTED = 'rejected';
	/** Refused before processing (auth, rate limit, local gate): safe to retry later. */
	public const NOT_CREATED = 'not_created';
	/** Timeout, transport failure, 5xx or malformed success: the order may or may not exist. */
	public const UNKNOWN = 'unknown';

	public function __construct(
		public readonly string $kind,
		public readonly string $code,
		public readonly string $ea_order_id = '',
		public readonly string $vendor_status = '',
		public readonly string $detail = ''
	) {}
}
