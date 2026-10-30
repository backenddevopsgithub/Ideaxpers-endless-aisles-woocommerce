<?php
namespace IdeaXperts\EndlessAisles\Tests\Settings;

use IdeaXperts\EndlessAisles\Settings\SettingsValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsValidatorTest extends TestCase {
	public function test_invalid_values_fall_back_and_interval_is_bounded(): void {
		$result = ( new SettingsValidator() )->validate(
			array(
				'enabled'            => 1,
				'environment'        => 'evil',
				'inventory_interval' => 1,
				'log_level'          => 'verbose',
				'alert_email'        => ' admin@example.com ',
			)
		);
		self::assertSame( 'yes', $result['enabled'] );
		self::assertSame( 'qa', $result['environment'] );
		self::assertSame( 'no', $result['production_confirmed'] );
		self::assertSame( 30, $result['inventory_interval'] );
		self::assertSame( 'warning', $result['log_level'] );
		self::assertSame( 'draft', $result['import_status'] );
	}

	public function test_production_is_an_explicit_valid_environment(): void {
		$result = ( new SettingsValidator() )->validate( array( 'environment' => 'production' ) );
		self::assertSame( 'production', $result['environment'] );
		self::assertSame( 'no', $result['production_confirmed'] );
	}

	#[DataProvider( 'acceptedConfirmationValues' )]
	public function test_production_confirmation_accepts_only_exact_checkbox_values( mixed $value ): void {
		$result = ( new SettingsValidator() )->validate(
			array(
				'environment'          => 'production',
				'production_confirmed' => $value,
			)
		);
		self::assertSame( SettingsValidator::PRODUCTION_CONFIRMED, $result['production_confirmed'] );
	}

	/** @return array<string,array{mixed}> */
	public static function acceptedConfirmationValues(): array {
		return array(
			'integer one' => array( 1 ),
			'string one'  => array( '1' ),
		);
	}

	#[DataProvider( 'rejectedConfirmationValues' )]
	public function test_production_confirmation_rejects_every_other_value( mixed $value ): void {
		$result = ( new SettingsValidator() )->validate(
			array(
				'environment'          => 'production',
				'production_confirmed' => $value,
			)
		);
		self::assertSame( 'no', $result['production_confirmed'] );
	}

	/** @return array<string,array{mixed}> */
	public static function rejectedConfirmationValues(): array {
		return array(
			'boolean true'     => array( true ),
			'yes'              => array( 'yes' ),
			'no'               => array( 'no' ),
			'true string'      => array( 'true' ),
			'false string'     => array( 'false' ),
			'integer two'      => array( 2 ),
			'negative integer' => array( -1 ),
			'arbitrary string' => array( 'confirm' ),
			'float one'        => array( 1.0 ),
			'null'             => array( null ),
			'empty string'     => array( '' ),
		);
	}

	public function test_qa_selection_clears_production_confirmation(): void {
		$result = ( new SettingsValidator() )->validate(
			array(
				'environment'          => 'qa',
				'production_confirmed' => 1,
			)
		);
		self::assertSame( 'no', $result['production_confirmed'] );
	}

	public function test_upc_metadata_keys_are_allowlisted_and_deduplicated(): void {
		$result = ( new SettingsValidator() )->validate(
			array(
				'upc_meta_keys'       => "_upc, gtin.value\nunsafe key\n_upc",
				'allow_sku_upc_match' => '1',
			)
		);
		self::assertSame( array( '_upc', 'gtin.value' ), $result['upc_meta_keys'] );
		self::assertSame( 'yes', $result['allow_sku_upc_match'] );
	}

	public function test_controlled_pricing_requires_explicit_enable_and_persists_approved_defaults(): void {
		$validator = new SettingsValidator();
		self::assertSame( array(), $validator->validate( array() )['creation_pricing'] );
		$result = $validator->validate( array( 'creation_pricing' => array( 'enabled' => '1', 'approval_reference' => 'staging approval', 'rule' => 'direct_source', 'source_field' => 'retail_price', 'currency' => 'usd', 'decimal_places' => '2', 'max_age_seconds' => '86400' ) ) )['creation_pricing'];
		self::assertSame( 'direct_source', $result['rule'] );
		self::assertSame( 'retail_price', $result['source_field'] );
		self::assertSame( 'USD', $result['currency'] );
		self::assertSame( 2, $result['decimal_places'] );
		self::assertSame( 86400, $result['max_age_seconds'] );
		self::assertSame( 'draft_below_map', $result['map_rule'] );
		self::assertSame( 'no_restriction', $result['zero_map_rule'] );
		$manipulated = $validator->validate( array( 'creation_pricing' => array( 'enabled' => '1', 'rule' => 'direct_source', 'source_field' => 'wholesale_price', 'currency' => 'EUR', 'decimal_places' => '4', 'max_age_seconds' => '1' ) ) )['creation_pricing'];
		self::assertSame( 'retail_price', $manipulated['source_field'] );
		self::assertSame( 'USD', $manipulated['currency'] );
		self::assertSame( 2, $manipulated['decimal_places'] );
		self::assertSame( 86400, $manipulated['max_age_seconds'] );
	}

	public function test_unverified_checkout_blocks_unless_allow_is_explicit(): void {
		$validator = new SettingsValidator();
		self::assertSame( 'block', $validator->validate( array() )['checkout_unverified'] );
		self::assertSame( 'block', $validator->validate( array( 'checkout_unverified' => 'ALLOW' ) )['checkout_unverified'] );
		self::assertSame( 'allow', $validator->validate( array( 'checkout_unverified' => 'allow' ) )['checkout_unverified'] );
	}
}
