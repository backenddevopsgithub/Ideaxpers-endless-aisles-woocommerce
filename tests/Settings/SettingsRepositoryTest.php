<?php
namespace IdeaXperts\EndlessAisles\Tests\Settings;

use IdeaXperts\EndlessAisles\Security\CredentialCipher;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class SettingsRepositoryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ea_test_options'] = array();
	}

	public function test_empty_token_fields_preserve_existing_environment_credentials(): void {
		$cipher                = new CredentialCipher();
		$qa_ciphertext         = $cipher->encrypt( 'existing-qa-token' );
		$production_ciphertext = $cipher->encrypt( 'existing-production-token' );
		$GLOBALS['ea_test_options']['ideaxperts_ea_credentials'] = array(
			'qa'         => $qa_ciphertext,
			'production' => $production_ciphertext,
		);

		( new SettingsRepository() )->save(
			array(
				'qa_token'         => '',
				'production_token' => '   ',
				'environment'      => 'qa',
			)
		);

		self::assertSame( $qa_ciphertext, $GLOBALS['ea_test_options']['ideaxperts_ea_credentials']['qa'] );
		self::assertSame( $production_ciphertext, $GLOBALS['ea_test_options']['ideaxperts_ea_credentials']['production'] );
	}

	public function test_tampered_stored_credential_fails_closed(): void {
		$ciphertext = ( new CredentialCipher() )->encrypt( 'existing-qa-token' );
		$GLOBALS['ea_test_options']['ideaxperts_ea_credentials'] = array( 'qa' => substr( $ciphertext, 0, -2 ) . 'xx' );

		self::assertSame( '', ( new SettingsRepository() )->token( 'qa' ) );
	}

	public function test_qa_and_production_credentials_remain_separate(): void {
		$repository = new SettingsRepository();
		$repository->replace_token( 'qa', 'qa-token' );
		$repository->replace_token( 'production', 'production-token' );

		self::assertSame( 'qa-token', $repository->token( 'qa' ) );
		self::assertSame( 'production-token', $repository->token( 'production' ) );
		self::assertNotSame( $GLOBALS['ea_test_options']['ideaxperts_ea_credentials']['qa'], $GLOBALS['ea_test_options']['ideaxperts_ea_credentials']['production'] );
	}

	public function test_repository_fails_closed_for_migrated_confirmation_values(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings'] = array(
			'environment'          => 'production',
			'production_confirmed' => 'yes',
		);
		self::assertSame( 'no', ( new SettingsRepository() )->get( 'production_confirmed' ) );
	}

	public function test_repository_accepts_only_canonical_stored_confirmation(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings'] = array(
			'environment'          => 'production',
			'production_confirmed' => \IdeaXperts\EndlessAisles\Settings\SettingsValidator::PRODUCTION_CONFIRMED,
		);
		self::assertSame( \IdeaXperts\EndlessAisles\Settings\SettingsValidator::PRODUCTION_CONFIRMED, ( new SettingsRepository() )->get( 'production_confirmed' ) );
	}

	public function test_replacing_the_qa_token_clears_connection_readiness(): void {
		$repository = new SettingsRepository();
		$GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] = array(
			'status'    => 'connected',
			'tested_at' => '2026-09-24 11:00:00',
		);
		$repository->replace_token( 'qa', 'new-qa-token' );

		self::assertSame( 'new-qa-token', $repository->token( 'qa' ) );
		self::assertSame( 'not_tested', $GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status']['status'] );
	}

	public function test_replacing_the_production_token_does_not_clear_qa_readiness(): void {
		$repository = new SettingsRepository();
		$GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status'] = array(
			'status'    => 'connected',
			'tested_at' => '2026-09-24 11:00:00',
		);
		$repository->replace_token( 'production', 'new-production-token' );

		self::assertSame( 'new-production-token', $repository->token( 'production' ) );
		self::assertSame( 'connected', $GLOBALS['ea_test_options']['ideaxperts_ea_qa_connection_status']['status'] );
	}
}
