<?php
namespace IdeaXperts\EndlessAisles\Settings;

use IdeaXperts\EndlessAisles\Security\CredentialCipher;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class SettingsRepository {
	private const OPTION       = 'ideaxperts_ea_settings';
	private const TOKEN_OPTION = 'ideaxperts_ea_credentials';

	/** @return array<string,mixed> */
	public function all(): array {
		$defaults = array(
			'enabled'              => 'no',
			'environment'          => 'qa',
			'production_confirmed' => 'no',
			'inventory_interval'   => 30,
			'log_level'            => 'warning',
			'alert_email'          => '',
			'import_status'        => 'draft',
			'use_global_unique_id' => 'yes',
			'upc_meta_keys'        => array(),
			'allow_sku_upc_match'  => 'no',
		);
		$value    = get_option( self::OPTION, array() );
		$settings = wp_parse_args( is_array( $value ) ? $value : array(), $defaults );
		if ( 'production' !== $settings['environment'] || SettingsValidator::PRODUCTION_CONFIRMED !== $settings['production_confirmed'] ) {
			$settings['production_confirmed'] = 'no';
		}
		return $settings;
	}

	public function get( string $key, mixed $fallback = null ): mixed {
		$settings = $this->all();
		return $settings[ $key ] ?? $fallback;
	}

	/** @param array<string,mixed> $input */
	public function save( array $input ): void {
		$validated   = ( new SettingsValidator() )->validate( $input );
		$credentials = get_option( self::TOKEN_OPTION, array() );
		$credentials = is_array( $credentials ) ? $credentials : array();
		$cipher      = new CredentialCipher();
		$changed     = false;
		foreach ( array( 'qa', 'production' ) as $environment ) {
			$field = $environment . '_token';
			if ( isset( $input[ $field ] ) && '' !== trim( (string) $input[ $field ] ) ) {
				$credentials[ $environment ] = $cipher->encrypt( trim( (string) $input[ $field ] ) );
				$changed                     = true;
				if ( in_array( $environment, array( 'qa', 'production' ), true ) ) {
					update_option(
						'ideaxperts_ea_' . $environment . '_connection_status',
						array(
							'status'    => 'not_tested',
							'tested_at' => '',
						),
						false
					);
				}
			}
		}
		if ( $changed ) {
			update_option( self::TOKEN_OPTION, $credentials, false );
		}
		update_option( self::OPTION, $validated, false );
	}

	public function replace_token( string $environment, string $token ): void {
		if ( ! in_array( $environment, SettingsValidator::ENVIRONMENTS, true ) ) {
			throw new RuntimeException( 'Invalid credential environment.' );
		}
		$cipher                      = new CredentialCipher();
		$credentials                 = get_option( self::TOKEN_OPTION, array() );
		$credentials                 = is_array( $credentials ) ? $credentials : array();
		$credentials[ $environment ] = $cipher->encrypt( $token );
		update_option( self::TOKEN_OPTION, $credentials, false );
		if ( in_array( $environment, array( 'qa', 'production' ), true ) ) {
			update_option(
				'ideaxperts_ea_' . $environment . '_connection_status',
				array(
					'status'    => 'not_tested',
					'tested_at' => '',
				),
				false
			);
		}
	}

	public function token( string $environment ): string {
		if ( ! in_array( $environment, SettingsValidator::ENVIRONMENTS, true ) ) {
			return '';
		}
		$credentials = get_option( self::TOKEN_OPTION, array() );
		$encrypted   = is_array( $credentials ) ? (string) ( $credentials[ $environment ] ?? '' ) : '';
		if ( '' === $encrypted ) {
			return '';
		}
		try {
			return ( new CredentialCipher() )->decrypt( $encrypted );
		} catch ( RuntimeException ) {
			return '';
		}
	}

	public function token_configured( string $environment ): bool {
		$credentials = get_option( self::TOKEN_OPTION, array() );
		return is_array( $credentials ) && ! empty( $credentials[ $environment ] );
	}
}
