<?php
namespace IdeaXperts\EndlessAisles\Monitoring;

use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the configured alert address for error/critical log events.
 *
 * Each distinct message is sent at most once per THROTTLE_SECONDS so a sustained
 * vendor outage produces one email, not one per scheduled run or checkout.
 */
final class AlertNotifier {
	public const STATE_OPTION     = 'ideaxperts_ea_alert_state';
	public const THROTTLE_SECONDS = 3 * 3600;
	private const MAX_KEYS        = 50;

	private bool $sending = false;

	public function __construct( private readonly SettingsRepository $settings, private readonly ?\Closure $clock = null ) {}

	public function register(): void {
		add_action( DatabaseLogger::ALERT_HOOK, array( $this, 'handle' ), 10, 3 );
	}

	public function handle( mixed $level, mixed $message, mixed $context = array() ): void {
		$recipient = sanitize_email( (string) $this->settings->get( 'alert_email', '' ) );
		if ( $this->sending || '' === $recipient || ! is_email( $recipient ) || ! is_string( $message ) || '' === $message ) {
			return;
		}
		$now   = $this->clock ? ( $this->clock )() : time();
		$key   = hash( 'sha256', $message );
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? array_filter( $state, 'is_int' ) : array();
		if ( isset( $state[ $key ] ) && $now - $state[ $key ] < self::THROTTLE_SECONDS ) {
			return;
		}
		// Record before sending so a failing mailer cannot cause a resend storm.
		$state[ $key ] = $now;
		arsort( $state );
		update_option( self::STATE_OPTION, array_slice( $state, 0, self::MAX_KEYS, true ), false );
		$this->sending = true;
		try {
			wp_mail( $recipient, $this->subject( (string) $level, $message ), $this->body( (string) $level, $message, is_array( $context ) ? $context : array(), $now ) );
		} finally {
			$this->sending = false;
		}
	}

	private function subject( string $level, string $message ): string {
		$site = trim( wp_strip_all_tags( (string) get_option( 'blogname', '' ) ) );
		return ( '' !== $site ? '[' . $site . '] ' : '' ) . 'Endless Aisles ' . ( 'critical' === $level ? 'CRITICAL' : 'alert' ) . ': ' . $message;
	}

	/** @param array<mixed> $context */
	private function body( string $level, string $message, array $context, int $now ): string {
		$lines = array(
			'The IdeaXperts Endless Aisles integration reported a problem.',
			'',
			'Message: ' . $message,
			'Level: ' . $level,
			'Environment: ' . strtoupper( (string) $this->settings->get( 'environment', 'qa' ) ),
			'Time (UTC): ' . gmdate( 'Y-m-d H:i:s', $now ),
		);
		if ( array() !== $context ) {
			$lines[] = 'Details: ' . (string) wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		if ( function_exists( 'admin_url' ) ) {
			$lines[] = '';
			$lines[] = 'Logs: ' . admin_url( 'admin.php?page=ideaxperts-endless-aisles&tab=logs' );
		}
		$lines[] = '';
		$lines[] = 'Repeats of this message are suppressed for ' . ( self::THROTTLE_SECONDS / 3600 ) . ' hours.';
		return implode( "\n", $lines );
	}
}
