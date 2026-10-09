<?php
namespace IdeaXperts\EndlessAisles\Tests\Import;

use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\ConfiguredCreationPricingPolicy;
use PHPUnit\Framework\TestCase;

final class ConfiguredCreationPricingPolicyTest extends TestCase {
	public static function config(): array {
		return array( 'approval_reference' => 'test-only-merchant-decision', 'rule' => 'direct_source', 'source_field' => 'retail_price', 'currency' => 'USD', 'decimal_places' => 2, 'max_age_seconds' => 300, 'map_rule' => 'ignore_map', 'zero_map_rule' => 'block' );
	}
	protected function setUp(): void {
		$GLOBALS['ea_now'] = '2026-10-02 12:00:00';
		$GLOBALS['ea_test_options'] = array( 'woocommerce_currency' => 'USD', 'woocommerce_price_num_decimals' => 2 );
	}
	public function test_identity_config_hash_and_decimal_output_are_deterministic(): void {
		$config = self::config();
		$policy = new ConfiguredCreationPricingPolicy( $config );
		$vendor = array( 'retail_price' => '000.50', 'source_observed_at' => $GLOBALS['ea_now'] );
		self::assertSame( 'ea-approved-direct-source', $policy->policy_id() );
		self::assertSame( '1', $policy->policy_version() );
		self::assertSame( ApprovalManifest::hash( $config ), $policy->configuration_hash() );
		self::assertSame( $policy->configuration_hash(), ( new ConfiguredCreationPricingPolicy( array_reverse( $config, true ) ) )->configuration_hash() );
		self::assertSame( '0.50', $policy->regular_price( $vendor ) );
		self::assertSame( $policy->regular_price( $vendor ), $policy->regular_price( $vendor ) );
		self::assertSame( '', $policy->failure_code( $vendor ) );
	}
	/** @dataProvider invalidPrices */
	public function test_unsafe_source_is_rejected( mixed $price, string $reason ): void {
		$policy = new ConfiguredCreationPricingPolicy( self::config() );
		$vendor = array( 'retail_price' => $price, 'source_observed_at' => $GLOBALS['ea_now'] );
		self::assertNull( $policy->regular_price( $vendor ) );
		self::assertSame( $reason, $policy->failure_code( $vendor ) );
	}
	public static function invalidPrices(): array {
		return array_map( static fn( mixed $price ): array => array( $price, null === $price || '' === $price ? 'pricing_source_missing' : 'pricing_source_invalid' ), array( null, '', 'zero', '0', '0.00', '-1', '1e2', '1,000', ' 10', '10.', '.5', '1.234', '100000000', 12.34 ) );
	}
	/** @dataProvider badConfiguration */
	public function test_configuration_and_currency_fail_closed( string $key, mixed $value, string $reason ): void {
		$config = self::config();
		$config[$key] = $value;
		$policy = new ConfiguredCreationPricingPolicy( $config );
		$vendor = array( 'retail_price' => '10', 'source_observed_at' => $GLOBALS['ea_now'] );
		self::assertSame( $reason, $policy->failure_code( $vendor ) );
		self::assertNull( $policy->regular_price( $vendor ) );
	}
	public static function badConfiguration(): array {
		return array( array( 'approval_reference', '', 'pricing_configuration_invalid' ), array( 'source_field', 'msrp', 'pricing_configuration_invalid' ), array( 'rule', 'markup', 'pricing_configuration_invalid' ), array( 'currency', 'EUR', 'pricing_currency_unsupported' ), array( 'decimal_places', 4, 'pricing_currency_unsupported' ), array( 'max_age_seconds', 0, 'pricing_configuration_invalid' ), array( 'minimum_price', '11', 'pricing_out_of_bounds' ), array( 'maximum_price', '9', 'pricing_out_of_bounds' ), array( 'maximum_price', 'NaN', 'pricing_configuration_invalid' ) );
	}
	/** @dataProvider staleSources */
	public function test_missing_stale_and_future_source_timestamp_is_rejected( string $timestamp ): void {
		$policy = new ConfiguredCreationPricingPolicy( self::config() );
		self::assertSame( 'pricing_source_stale', $policy->failure_code( array( 'retail_price' => '10', 'source_observed_at' => $timestamp ) ) );
	}
	public static function staleSources(): array {
		return array( array( '' ), array( 'invalid' ), array( '2026-10-02 11:54:59' ), array( '2026-10-02 12:00:01' ) );
	}
	/** @dataProvider mapRules */
	public function test_map_and_zero_map_follow_explicit_configuration( string $rule, string $zero, string $map, string $failure ): void {
		$config = self::config();
		$config['map_rule'] = $rule;
		$config['zero_map_rule'] = $zero;
		$policy = new ConfiguredCreationPricingPolicy( $config );
		$vendor = array( 'retail_price' => '20', 'map_price' => $map, 'source_observed_at' => $GLOBALS['ea_now'] );
		self::assertSame( $failure, $policy->failure_code( $vendor ) );
		self::assertSame( '' === $failure ? '20.00' : null, $policy->regular_price( $vendor ) );
	}
	public static function mapRules(): array {
		return array( array( 'block_below_map', 'no_restriction', '25', 'pricing_below_map' ), array( 'block_below_map', 'no_restriction', '20', '' ), array( 'block_below_map', 'no_restriction', '0.00', '' ), array( 'block_below_map', 'block', '0', 'pricing_zero_map_review' ), array( 'block_below_map', 'block', '', 'pricing_map_invalid' ), array( 'ignore_map', 'block', '25', '' ), array( '', 'block', '0', 'pricing_configuration_invalid' ), array( 'block_below_map', '', '0', 'pricing_configuration_invalid' ) );
	}

	public function test_preview_configuration_excludes_unrecognized_secret_fields(): void {
		$config = self::config();
		$config['api_token'] = 'secret-fixture-must-not-appear';
		$policy = new ConfiguredCreationPricingPolicy( $config );
		self::assertArrayNotHasKey( 'api_token', $policy->configuration() );
		self::assertStringNotContainsString( 'secret-fixture-must-not-appear', wp_json_encode( $policy->configuration() ) );
	}

}
