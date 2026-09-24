<?php
namespace IdeaXperts\EndlessAisles\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsValidator {
	public const ENVIRONMENTS         = array( 'qa', 'production' );
	public const LOG_LEVELS           = array( 'debug', 'info', 'warning', 'error', 'critical' );
	public const PRODUCTION_CONFIRMED = 'confirmed-v1';

	/**
	 * @param array<string,mixed> $input Untrusted settings input.
	 * @return array<string,mixed>
	 */
	public function validate( array $input ): array {
		$environment  = sanitize_key( (string) ( $input['environment'] ?? 'qa' ) );
		$log_level    = sanitize_key( (string) ( $input['log_level'] ?? 'warning' ) );
		$confirmation = $input['production_confirmed'] ?? null;
		$confirmed    = 1 === $confirmation || '1' === $confirmation;

		return array(
			'enabled'              => ! empty( $input['enabled'] ) ? 'yes' : 'no',
			'environment'          => in_array( $environment, self::ENVIRONMENTS, true ) ? $environment : 'qa',
			'production_confirmed' => 'production' === $environment && $confirmed ? self::PRODUCTION_CONFIRMED : 'no',
			'inventory_interval'   => 30,
			'log_level'            => in_array( $log_level, self::LOG_LEVELS, true ) ? $log_level : 'warning',
			'alert_email'          => sanitize_email( (string) ( $input['alert_email'] ?? '' ) ),
			'import_status'        => 'draft',
		);
	}
}
