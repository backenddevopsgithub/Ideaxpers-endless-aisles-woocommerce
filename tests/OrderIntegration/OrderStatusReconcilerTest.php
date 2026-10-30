<?php
namespace IdeaXperts\EndlessAisles\Tests\OrderIntegration;

use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderStatusClient;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderStatusReconciler;
use IdeaXperts\EndlessAisles\OrderIntegration\SubmissionGates;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;
use IdeaXperts\EndlessAisles\Tests\API\SequenceClient;
use IdeaXperts\EndlessAisles\Tests\Support\FakeOrder;
use IdeaXperts\EndlessAisles\Tests\Support\MemoryOrderSubmissionStore;
use PHPUnit\Framework\TestCase;

final class OrderStatusReconcilerTest extends TestCase {
	private MemoryOrderSubmissionStore $submissions;
	private FakeOrder $order;
	/** @var list<array{string,string}> */
	private array $alerts = array();

	protected function setUp(): void {
		$GLOBALS['ea_now']          = '2026-10-30 12:00:00';
		$GLOBALS['ea_test_options'] = array(
			'ideaxperts_ea_settings' => array(
				'enabled'          => 'yes',
				'environment'      => 'qa',
				'order_submission' => 'no',
				'log_level'        => 'critical',
			),
		);
		( new SettingsRepository() )->replace_token( 'qa', 'secret' );
		$this->submissions = new MemoryOrderSubmissionStore();
		$this->submissions->record_intent(
			array(
				'wc_order_id'     => 5001,
				'environment'     => 'qa',
				'custom_order_id' => 'eaX-qa-5001',
				'status'          => 'pending',
				'payload_hash'    => str_repeat( 'a', 64 ),
				'line_snapshot'   => '[]',
				'last_error_code' => '',
			)
		);
		$this->submissions->rows[1] = array_merge( $this->submissions->rows[1], array( 'status' => 'accepted', 'ea_order_id' => '10030', 'status_synced_at' => null, 'tracking_json' => null ) );
		$this->order                = new FakeOrder( 5001, array() );
		$GLOBALS['ea_wc_orders']    = array( 5001 => $this->order );
		$this->alerts               = array();
		$GLOBALS['ea_registered_hooks'][ DatabaseLogger::ALERT_HOOK ] = array(
			function ( string $level, string $message ): void {
				$this->alerts[] = array( $level, $message );
			},
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ea_registered_hooks'][ DatabaseLogger::ALERT_HOOK ] );
		$GLOBALS['ea_now'] = '2026-09-24 12:00:00';
	}

	public function test_documented_shipped_response_parses_lines_and_tracking_without_the_shipping_line(): void {
		$status = OrderStatusClient::parse( $this->documentedShipped() );

		self::assertSame( 'shipped', $status->status );
		self::assertSame( array( array( 'id' => '43308', 'quantity' => 1, 'shipped' => 1 ) ), $status->lines );
		self::assertSame( '123456789012', $status->shipments[0]['number'] );
		self::assertStringStartsWith( 'https://www.fedex.com/', $status->shipments[0]['url'] );
		self::assertSame( 'shipped', $status->local_status() );
	}

	public function test_scheduled_order_records_state_without_a_redundant_note(): void {
		$client = new SequenceClient( array( $this->response( 'Scheduled', 2, null ) ) );

		$this->reconciler( $client )->run();

		self::assertSame( 'scheduled', $this->row()['status'] );
		self::assertSame( 'scheduled', $this->row()['vendor_status'] );
		self::assertSame( '2026-10-30 12:00:00', $this->row()['status_synced_at'] );
		self::assertSame( array( array( 'GET', '/api/orders/10030' ) ), $client->requests );
		self::assertSame( array(), $this->order->notes );
	}

	public function test_partial_then_full_shipment_notes_progress_and_tracking_exactly_once(): void {
		$tracking = array( array( 'number' => '1Z999', 'url' => 'https://track.example.test/1Z999' ) );
		$client   = new SequenceClient(
			array(
				$this->response( 'Scheduled', 2, 1, $tracking ),
				$this->response( 'Scheduled', 2, 1, $tracking ),
				$this->response( 'Shipped', 2, 2, array_merge( $tracking, array( array( 'number' => '1Z888', 'url' => 'javascript:alert(1)' ) ) ) ),
			)
		);
		$reconciler = $this->reconciler( $client );

		$reconciler->run();
		self::assertSame( 'partially_shipped', $this->row()['status'] );
		self::assertSame(
			array(
				'Endless Aisles shipment for vendor order #10030: tracking 1Z999 (https://track.example.test/1Z999)',
				'Endless Aisles: vendor order #10030 partially shipped (1 of 2 units).',
			),
			$this->order->notes
		);

		$reconciler->run();
		self::assertCount( 1, $client->requests, 'Not rechecked within the interval.' );

		$this->advance( OrderStatusReconciler::INTERVAL_SECONDS );
		$reconciler->run();
		self::assertCount( 2, $this->order->notes, 'Known tracking and unchanged status add nothing.' );

		$this->advance( OrderStatusReconciler::INTERVAL_SECONDS );
		$reconciler->run();
		self::assertSame( 'shipped', $this->row()['status'] );
		self::assertSame( 'Endless Aisles shipment for vendor order #10030: tracking 1Z888', $this->order->notes[2], 'Unsafe tracking URLs are dropped.' );
		self::assertSame( 'Endless Aisles: vendor order #10030 fully shipped.', $this->order->notes[3] );
		self::assertCount( 2, json_decode( $this->order->meta[ OrderStatusReconciler::TRACKING_META ], true ) );
		self::assertSame( 'processing', $this->order->status, 'The WooCommerce order status is never changed.' );

		$this->advance( OrderStatusReconciler::INTERVAL_SECONDS );
		$reconciler->run();
		self::assertCount( 3, $client->requests, 'Shipped orders are terminal.' );
	}

	public function test_shipped_status_with_outstanding_units_is_partial_not_complete(): void {
		$this->reconciler( new SequenceClient( array( $this->response( 'Shipped', 3, 1 ) ) ) )->run();

		self::assertSame( 'partially_shipped', $this->row()['status'] );
		self::assertStringContainsString( '1 of 3 units', end( $this->order->notes ) );
	}

	public function test_vendor_cancellation_and_failure_note_and_alert_but_never_refund(): void {
		foreach ( array( 'Canceled' => 'cancelled', 'Failed' => 'failed' ) as $vendor => $local ) {
			$this->setUp();
			$this->reconciler( new SequenceClient( array( $this->response( $vendor, 1, 0 ) ) ) )->run();

			self::assertSame( $local, $this->row()['status'] );
			self::assertStringContainsString( strtoupper( $local ), end( $this->order->notes ) );
			self::assertSame( array( 'error', 'Vendor order #10030 for order #5001 was ' . $local . ' by Endless Aisles.' ), end( $this->alerts ) );
			self::assertSame( 'processing', $this->order->status );
		}
	}

	public function test_qa_404_is_expected_after_the_daily_reset(): void {
		$this->reconciler( new SequenceClient( array( new ApiException( 'Endless Aisles returned an unsuccessful response.', array(), 404 ) ) ) )->run();

		self::assertSame( 'vendor_record_expired', $this->row()['status'] );
		self::assertSame( array(), $this->alerts );
	}

	public function test_production_404_alerts_and_keeps_following_the_order(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['environment']          = 'production';
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['production_confirmed'] = SettingsValidator::PRODUCTION_CONFIRMED;
		( new SettingsRepository() )->replace_token( 'production', 'secret' );
		$this->submissions->rows[1]['environment'] = 'production';

		$this->reconciler( new SequenceClient( array( new ApiException( 'Endless Aisles returned an unsuccessful response.', array(), 404 ) ) ) )->run();

		self::assertSame( 'accepted', $this->row()['status'] );
		self::assertSame( '2026-10-30 12:00:00', $this->row()['status_synced_at'] );
		self::assertSame( array( 'error', 'Vendor order #10030 for order #5001 was not found at Endless Aisles.' ), end( $this->alerts ) );
	}

	public function test_vendor_outage_rotates_the_queue_and_leaves_state_unchanged(): void {
		$failure = new ApiException( 'Endless Aisles request timed out.' );
		$client  = new SequenceClient( array( $failure, $failure, $failure ) );

		$this->reconciler( $client )->run();

		self::assertCount( 3, $client->requests, 'Idempotent GETs use the bounded retry.' );
		self::assertSame( 'accepted', $this->row()['status'] );
		self::assertSame( '2026-10-30 12:00:00', $this->row()['status_synced_at'] );
		self::assertSame( array( 'error', 'Vendor order status reconciliation failed.' ), end( $this->alerts ) );
	}

	public function test_malformed_vendor_order_is_a_failure_not_a_state_change(): void {
		$this->reconciler( new SequenceClient( array( array( 'data' => (object) array( 'id' => 10030 ) ) ) ) )->run();

		self::assertSame( 'accepted', $this->row()['status'] );
		self::assertSame( array( 'error', 'Vendor order status reconciliation failed.' ), end( $this->alerts ) );
	}

	public function test_disabled_integration_reads_nothing_but_the_submission_switch_does_not_stop_reconciliation(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['enabled'] = 'no';
		$client = new SequenceClient( array() );
		$this->reconciler( $client )->run();
		self::assertSame( array(), $client->requests );

		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['enabled'] = 'yes';
		$client = new SequenceClient( array( $this->response( 'Scheduled', 1, 0 ) ) );
		$this->reconciler( $client )->run();
		self::assertCount( 1, $client->requests, 'order_submission is "no" in this fixture.' );
	}

	/**
	 * @param list<array{number:string,url:string}> $shipments
	 * @return array<string,mixed>
	 */
	private function response( string $status, int $quantity, ?int $shipped, array $shipments = array() ): array {
		return array(
			'data' => (object) array(
				'id'           => 10030,
				'status'       => $status,
				'line_items'   => array(
					(object) array( 'id' => 50770, 'quantity' => $quantity, 'quantity_shipped' => $shipped, 'is_shipping' => false ),
					(object) array( 'id' => 1, 'quantity' => 1, 'is_shipping' => true ),
				),
				'shipments'    => array_map( static fn( array $shipment ): object => (object) $shipment, $shipments ),
				'last_updated' => '2026-10-30 11:59:00',
			),
		);
	}

	/** @return array<string,mixed> Abridged from the documented "order that has shipped" sample. */
	private function documentedShipped(): array {
		return array(
			'data' => (object) array(
				'id'           => 13560,
				'status'       => 'Shipped',
				'line_items'   => array(
					(object) array( 'id' => 43308, 'product_id' => 39957, 'upc' => '655199036110', 'quantity' => 1, 'quantity_shipped' => 1, 'price' => 0.89, 'is_shipping' => false ),
					(object) array( 'id' => 1, 'product_id' => 1, 'upc' => null, 'quantity' => 1, 'price' => 5.95, 'is_shipping' => true ),
				),
				'shipments'    => array(
					(object) array(
						'url'    => 'https://www.fedex.com/apps/fedextrack/?action=track&trackingnumber=123456789012&cntry_code=us',
						'number' => '123456789012',
					),
				),
				'last_updated' => '2018-02-01 09:50:07',
			),
		);
	}

	/** @return array<string,mixed> */
	private function row(): array {
		return (array) $this->submissions->get( 1 );
	}

	private function advance( int $seconds ): void {
		$GLOBALS['ea_now'] = gmdate( 'Y-m-d H:i:s', strtotime( $GLOBALS['ea_now'] . ' UTC' ) + $seconds );
	}

	private function reconciler( SequenceClient $client ): OrderStatusReconciler {
		$settings = new SettingsRepository();
		return new OrderStatusReconciler(
			$settings,
			new SubmissionGates( $settings ),
			$this->submissions,
			new OrderStatusClient( $settings, new BaseUrlResolver(), new DatabaseLogger(), static fn(): SequenceClient => $client, static function (): void {} ),
			new DatabaseLogger()
		);
	}
}
