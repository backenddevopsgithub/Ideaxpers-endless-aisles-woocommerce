<?php
namespace IdeaXperts\EndlessAisles\Tests\Monitoring;

use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Monitoring\AlertNotifier;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class AlertNotifierTest extends TestCase {
	private int $now = 1_800_000_000;

	protected function setUp(): void {
		$GLOBALS['ea_test_options'] = array(
			'blogname'               => 'Small Town Pets',
			'ideaxperts_ea_settings' => array(
				'environment' => 'qa',
				'log_level'   => 'critical',
				'alert_email' => 'ops@example.test',
			),
		);
		$GLOBALS['ea_mail']         = array();
		$GLOBALS['ea_mail_failure'] = false;
		$this->now                  = 1_800_000_000;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ea_registered_hooks'][ DatabaseLogger::ALERT_HOOK ] );
	}

	public function test_error_sends_one_descriptive_email(): void {
		$this->notifier()->handle( 'error', 'Catalog dry run failed.', array( 'run_id' => 9 ) );

		self::assertCount( 1, $GLOBALS['ea_mail'] );
		self::assertSame( 'ops@example.test', $GLOBALS['ea_mail'][0]['to'] );
		self::assertSame( '[Small Town Pets] Endless Aisles alert: Catalog dry run failed.', $GLOBALS['ea_mail'][0]['subject'] );
		self::assertStringContainsString( 'Environment: QA', $GLOBALS['ea_mail'][0]['message'] );
		self::assertStringContainsString( '"run_id":9', $GLOBALS['ea_mail'][0]['message'] );
		self::assertStringContainsString( 'suppressed for 3 hours', $GLOBALS['ea_mail'][0]['message'] );
	}

	public function test_critical_is_marked_in_the_subject(): void {
		$this->notifier()->handle( 'critical', 'Scheduled tasks are overdue.' );

		self::assertStringContainsString( 'Endless Aisles CRITICAL: Scheduled tasks are overdue.', $GLOBALS['ea_mail'][0]['subject'] );
	}

	public function test_repeats_are_throttled_per_message_and_resume_after_the_window(): void {
		$notifier = $this->notifier();

		$notifier->handle( 'error', 'Inventory synchronization failed.' );
		$this->now += 1800;
		$notifier->handle( 'error', 'Inventory synchronization failed.' );
		$notifier->handle( 'error', 'Checkout vendor stock verification failed.' );
		self::assertCount( 2, $GLOBALS['ea_mail'] );

		$this->now += AlertNotifier::THROTTLE_SECONDS;
		$notifier->handle( 'error', 'Inventory synchronization failed.' );
		self::assertCount( 3, $GLOBALS['ea_mail'] );
	}

	public function test_failing_mailer_is_still_throttled(): void {
		$GLOBALS['ea_mail_failure'] = true;
		$notifier                   = $this->notifier();

		$notifier->handle( 'error', 'Catalog dry run failed.' );
		$notifier->handle( 'error', 'Catalog dry run failed.' );

		self::assertCount( 1, $GLOBALS['ea_mail'] );
	}

	public function test_missing_or_invalid_recipient_sends_nothing(): void {
		foreach ( array( '', 'not-an-email' ) as $recipient ) {
			$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['alert_email'] = $recipient;
			$this->notifier()->handle( 'error', 'Catalog dry run failed.' );
		}

		self::assertSame( array(), $GLOBALS['ea_mail'] );
	}

	public function test_logger_dispatches_only_error_levels_with_redacted_data_regardless_of_log_level(): void {
		$this->notifier()->register();
		$logger = new DatabaseLogger();

		$logger->log( 'warning', 'Inventory synchronization completed with item failures.' );
		$logger->log( 'error', 'Inventory synchronization failed.', array( 'token' => 'super-secret-value' ) );

		self::assertCount( 1, $GLOBALS['ea_mail'] );
		self::assertStringNotContainsString( 'super-secret-value', $GLOBALS['ea_mail'][0]['message'] );
	}

	private function notifier(): AlertNotifier {
		return new AlertNotifier( new SettingsRepository(), fn(): int => $this->now );
	}
}
