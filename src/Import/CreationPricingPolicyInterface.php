<?php
namespace IdeaXperts\EndlessAisles\Import;

defined( 'ABSPATH' ) || exit;

/** Implement only after a merchant Production pricing rule is approved. */
interface CreationPricingPolicyInterface {
	/** Stable business identity/version, changed when policy implementation changes. */
	public function policy_id(): string;
	public function policy_version(): string;
	/** SHA-256 of canonical configuration; never contains configuration secrets. */
	public function configuration_hash(): string;

	/** @param array<string,mixed> $vendor Approved persisted snapshot. */
	public function regular_price( array $vendor ): ?string;
}
