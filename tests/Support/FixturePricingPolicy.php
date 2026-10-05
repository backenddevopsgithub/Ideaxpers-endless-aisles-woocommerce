<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

use IdeaXperts\EndlessAisles\Import\CreationPricingPolicyInterface;

/** Mutable policy double for approval/execution drift tests only. */
final class FixturePricingPolicy implements CreationPricingPolicyInterface {
	public function __construct( public string $price = '10.00', public string $id = 'fixture', public string $version = 'v1', public string $config = 'fixture' ) {}
	public function regular_price( array $vendor ): ?string { return $this->price; }
	public function policy_id(): string { return $this->id; }
	public function policy_version(): string { return $this->version; }
	public function configuration_hash(): string { return hash( 'sha256', $this->config ); }
}
