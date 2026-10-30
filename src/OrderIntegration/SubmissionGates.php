<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;

defined( 'ABSPATH' ) || exit;

/** Conditions that must all hold for intent recording and again before every vendor HTTP attempt. */
final class SubmissionGates {
	public const PAID_STATUSES = array( 'processing', 'completed' );

	public function __construct( private readonly SettingsRepository $settings ) {}

	/** Returns '' when submission is permitted in the active environment, else a stable code. */
	public function configuration_failure(): string {
		if ( 'yes' !== $this->settings->get( 'enabled', 'no' ) || 'yes' !== $this->settings->get( 'order_submission', 'no' ) ) {
			return 'submission_disabled';
		}
		$environment = (string) $this->settings->get( 'environment', 'qa' );
		if ( 'qa' === $environment ) {
			return '' !== $this->settings->token( 'qa' ) ? '' : 'credential_missing';
		}
		if ( 'production' !== $environment || SettingsValidator::PRODUCTION_CONFIRMED !== $this->settings->get( 'production_confirmed', 'no' ) || SettingsValidator::PRODUCTION_ORDERS_CONFIRMED !== $this->settings->get( 'production_orders_confirmed', 'no' ) ) {
			return 'environment_not_permitted';
		}
		return '' !== $this->settings->token( 'production' ) ? '' : 'credential_missing';
	}

	/**
	 * Status reads for already-accepted orders continue even when new submission is
	 * switched off: orders the vendor holds still need tracking and cancellation notices.
	 */
	public function reconciliation_failure(): string {
		if ( 'yes' !== $this->settings->get( 'enabled', 'no' ) ) {
			return 'integration_disabled';
		}
		$environment = (string) $this->settings->get( 'environment', 'qa' );
		if ( 'production' === $environment && SettingsValidator::PRODUCTION_CONFIRMED !== $this->settings->get( 'production_confirmed', 'no' ) ) {
			return 'environment_not_permitted';
		}
		return in_array( $environment, SettingsValidator::ENVIRONMENTS, true ) && '' !== $this->settings->token( $environment ) ? '' : 'credential_missing';
	}

	/**
	 * Full pre-send check for an existing submission row.
	 *
	 * @param array<string,mixed> $row
	 */
	public function send_failure( object $order, array $row, ?OrderPayload $rebuilt ): string {
		$configuration = $this->configuration_failure();
		if ( '' !== $configuration ) {
			return $configuration;
		}
		if ( (string) $row['environment'] !== (string) $this->settings->get( 'environment', 'qa' ) ) {
			return 'environment_changed';
		}
		if ( ! method_exists( $order, 'get_status' ) || ! in_array( (string) $order->get_status(), self::PAID_STATUSES, true ) ) {
			return 'order_not_paid';
		}
		if ( null === $rebuilt || ! hash_equals( (string) $row['payload_hash'], $rebuilt->hash ) || $rebuilt->custom_order_id() !== (string) $row['custom_order_id'] ) {
			return 'payload_changed';
		}
		return '';
	}
}
