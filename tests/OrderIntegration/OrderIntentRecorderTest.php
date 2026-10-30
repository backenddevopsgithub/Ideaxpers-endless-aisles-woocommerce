<?php
namespace IdeaXperts\EndlessAisles\Tests\OrderIntegration;

use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderIntentRecorder;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderPayloadBuilder;
use IdeaXperts\EndlessAisles\OrderIntegration\SubmissionGates;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;
use IdeaXperts\EndlessAisles\Tests\Support\FakeOrder;
use IdeaXperts\EndlessAisles\Tests\Support\FakeOrderItem;
use IdeaXperts\EndlessAisles\Tests\Support\MemoryInventoryStore;
use IdeaXperts\EndlessAisles\Tests\Support\MemoryOrderSubmissionStore;
use PHPUnit\Framework\TestCase;

final class OrderIntentRecorderTest extends TestCase {
	private MemoryInventoryStore $mappings;
	private MemoryOrderSubmissionStore $submissions;
	/** @var list<array{string,string}> */
	private array $alerts = array();

	protected function setUp(): void {
		$GLOBALS['ea_test_options'] = array(
			'ideaxperts_ea_settings' => array(
				'enabled'          => 'yes',
				'environment'      => 'qa',
				'order_submission' => 'yes',
				'log_level'        => 'critical',
			),
		);
		( new SettingsRepository() )->replace_token( 'qa', 'secret' );
		$this->mappings           = new MemoryInventoryStore();
		$this->mappings->mappings = array(
			array( 'id' => 1, 'wc_product_id' => 701, 'ea_product_id' => '44095', 'ea_option_id' => '50770' ),
		);
		$this->submissions        = new MemoryOrderSubmissionStore();
		$this->alerts             = array();
		$GLOBALS['ea_wc_orders']  = array();
		$GLOBALS['ea_registered_hooks'][ DatabaseLogger::ALERT_HOOK ] = array(
			function ( string $level, string $message ): void {
				$this->alerts[] = array( $level, $message );
			},
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ea_registered_hooks'][ DatabaseLogger::ALERT_HOOK ] );
	}

	public function test_paid_order_with_vendor_lines_records_one_pending_intent_and_note(): void {
		$order = $this->order( 5001 );

		$this->recorder()->record( 5001 );

		self::assertCount( 1, $this->submissions->rows );
		$row = $this->submissions->rows[1];
		self::assertSame( 'pending', $row['status'] );
		self::assertSame( 64, strlen( $row['payload_hash'] ) );
		self::assertStringContainsString( '"ea_option_id":"50770"', $row['line_snapshot'] );
		self::assertStringNotContainsString( 'Anyplace', $row['line_snapshot'] );
		self::assertCount( 1, $order->notes );
		self::assertStringContainsString( 'vendor order queued as ' . $row['custom_order_id'], $order->notes[0] );
	}

	public function test_duplicate_payment_and_status_callbacks_record_once(): void {
		$order    = $this->order( 5001 );
		$recorder = $this->recorder();

		$recorder->record( 5001 );
		$recorder->record( '5001' );
		$recorder->record( 5001 );

		self::assertCount( 1, $this->submissions->rows );
		self::assertCount( 1, $order->notes );
	}

	public function test_unsendable_vendor_order_is_recorded_blocked_and_alerts(): void {
		$order                               = $this->order( 5002 );
		$order->shipping_address['country']  = 'CA';

		$this->recorder()->record( 5002 );

		self::assertSame( 'blocked', $this->submissions->rows[1]['status'] );
		self::assertSame( 'shipping_country_unsupported', $this->submissions->rows[1]['last_error_code'] );
		self::assertSame( '', $this->submissions->rows[1]['payload_hash'] );
		self::assertStringContainsString( 'NOT queued', $order->notes[0] );
		self::assertSame( array( array( 'error', 'Paid order with Endless Aisles items cannot be sent to the vendor.' ) ), $this->alerts );
	}

	public function test_nothing_is_recorded_when_submission_is_off_unpaid_or_has_no_vendor_lines(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['order_submission'] = 'no';
		$this->order( 5001 );
		$this->recorder()->record( 5001 );

		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['order_submission'] = 'yes';
		$this->order( 5003 )->status = 'on-hold';
		$this->recorder()->record( 5003 );

		$GLOBALS['ea_wc_orders'][5004] = new FakeOrder( 5004, array( new FakeOrderItem( 999, 1, 10.0 ) ) );
		$this->recorder()->record( 5004 );

		self::assertSame( array(), $this->submissions->rows );
	}

	public function test_failed_intent_storage_raises_a_critical_alert(): void {
		$this->submissions->fail = true;
		$this->order( 5001 );
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public function insert( string $table, array $data, array $formats = array() ): bool {
				return true;
			}
			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}
			public function query( string $query ): int {
				return 0;
			}
		};

		try {
			$this->recorder()->record( 5001 );
		} finally {
			unset( $GLOBALS['wpdb'] );
		}

		self::assertSame( 'critical', $this->alerts[0][0] ?? '' );
	}

	public function test_gates_require_both_production_confirmations(): void {
		$gates = new SubmissionGates( new SettingsRepository() );
		self::assertSame( '', $gates->configuration_failure() );

		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['environment'] = 'production';
		( new SettingsRepository() )->replace_token( 'production', 'secret' );
		self::assertSame( 'environment_not_permitted', $gates->configuration_failure() );

		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['production_confirmed'] = SettingsValidator::PRODUCTION_CONFIRMED;
		self::assertSame( 'environment_not_permitted', $gates->configuration_failure() );

		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['production_orders_confirmed'] = SettingsValidator::PRODUCTION_ORDERS_CONFIRMED;
		self::assertSame( '', $gates->configuration_failure() );

		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['enabled'] = 'no';
		self::assertSame( 'submission_disabled', $gates->configuration_failure() );
	}

	public function test_send_gate_rejects_unpaid_changed_or_cross_environment_orders(): void {
		$order    = $this->order( 5001 );
		$mappings = $this->mappings->mappings_for_products( 'endless-aisles:qa', 'qa', array( 701 ) );
		$payload  = ( new OrderPayloadBuilder() )->build( $order, 'qa', $mappings );
		$row      = array(
			'environment'     => 'qa',
			'payload_hash'    => $payload?->hash,
			'custom_order_id' => $payload?->custom_order_id(),
		);
		$gates    = new SubmissionGates( new SettingsRepository() );

		self::assertSame( '', $gates->send_failure( $order, $row, $payload ) );

		$order->shipping_address['postcode'] = '99999';
		self::assertSame( 'payload_changed', $gates->send_failure( $order, $row, ( new OrderPayloadBuilder() )->build( $order, 'qa', $mappings ) ) );
		self::assertSame( 'payload_changed', $gates->send_failure( $order, $row, null ) );

		$order->status = 'refunded';
		self::assertSame( 'order_not_paid', $gates->send_failure( $order, $row, $payload ) );

		self::assertSame( 'environment_changed', $gates->send_failure( $order, array_merge( $row, array( 'environment' => 'production' ) ), $payload ) );
	}

	private function order( int $id ): FakeOrder {
		$order                       = new FakeOrder( $id, array( new FakeOrderItem( 701, 1, 36.99, FakeOrderItem::owned_product( '44095', '50770' ) ), new FakeOrderItem( 999, 1, 5.0 ) ) );
		$GLOBALS['ea_wc_orders'][ $id ] = $order;
		return $order;
	}

	private function recorder(): OrderIntentRecorder {
		$settings = new SettingsRepository();
		return new OrderIntentRecorder( $settings, new SubmissionGates( $settings ), $this->mappings, $this->submissions, new OrderPayloadBuilder(), new DatabaseLogger() );
	}
}
