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
}
