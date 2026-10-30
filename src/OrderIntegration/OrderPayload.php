<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

defined( 'ABSPATH' ) || exit;

final class OrderPayload {
	/**
	 * @param array<string,mixed>       $payload Vendor request body; contains the shipping address.
	 * @param list<array<string,mixed>> $lines   Line snapshot without personal data, safe to persist.
	 */
	public function __construct( public readonly array $payload, public readonly array $lines, public readonly string $hash ) {}

	public function custom_order_id(): string {
		return (string) $this->payload['custom_order_id'];
	}
}
