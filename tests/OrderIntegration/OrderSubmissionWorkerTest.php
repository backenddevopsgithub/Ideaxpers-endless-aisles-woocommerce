<?php
namespace IdeaXperts\EndlessAisles\Tests\OrderIntegration;

use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderIntentRecorder;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderPayloadBuilder;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderSubmissionClient;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderSubmissionWorker;
use IdeaXperts\EndlessAisles\OrderIntegration\SubmissionGates;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Tests\Support\FakeEndlessAislesOrders;
use IdeaXperts\EndlessAisles\Tests\Support\FakeOrder;
use IdeaXperts\EndlessAisles\Tests\Support\FakeOrderItem;
use IdeaXperts\EndlessAisles\Tests\Support\MemoryInventoryStore;
use IdeaXperts\EndlessAisles\Tests\Support\MemoryOrderSubmissionStore;
use PHPUnit\Framework\TestCase;

/** docs/ORDER-SUBMISSION-DESIGN.md §13: every scenario is judged by the vendor's own order count. */
final class OrderSubmissionWorkerTest extends TestCase {
	private MemoryInventoryStore $mappings;
	private MemoryOrderSubmissionStore $submissions;
	private FakeEndlessAislesOrders $vendor;
	private FakeOrder $order;
	/** @var list<array{string,string}> */
	private array $alerts = array();

	protected function setUp(): void {
		$GLOBALS['ea_now']          = '2026-10-30 12:00:00';
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
			array(
				'id'            => 1,
				'wc_product_id' => 701,
				'ea_product_id' => '44095',
				'ea_option_id'  => '50770',
			),
		);
		$this->submissions        = new MemoryOrderSubmissionStore();
		$this->vendor             = new FakeEndlessAislesOrders();
		$this->alerts             = array();
		$this->order              = new FakeOrder( 5001, array( new FakeOrderItem( 701, 2, 73.98, FakeOrderItem::owned_product( '44095', '50770' ) ), new FakeOrderItem( 999, 1, 5.0 ) ) );
		$GLOBALS['ea_wc_orders']  = array( 5001 => $this->order );
		$GLOBALS['wpdb']          = new class() {
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
		$GLOBALS['ea_registered_hooks'][ DatabaseLogger::ALERT_HOOK ] = array(
			function ( string $level, string $message ): void {
				$this->alerts[] = array( $level, $message );
			},
		);
		$settings = new SettingsRepository();
		( new OrderIntentRecorder( $settings, new SubmissionGates( $settings ), $this->mappings, $this->submissions, new OrderPayloadBuilder(), new DatabaseLogger(), static fn(): bool => true ) )->record( 5001 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ea_registered_hooks'][ DatabaseLogger::ALERT_HOOK ], $GLOBALS['wpdb'] );
		$GLOBALS['ea_now'] = '2026-09-24 12:00:00';
	}

	public function test_success_creates_exactly_one_vendor_order_and_records_its_id(): void {
		$this->worker()->process( 1 );

		self::assertCount( 1, $this->vendor->orders );
		self::assertSame( 'accepted', $this->row()['status'] );
		self::assertSame( '10030', $this->row()['ea_order_id'] );
		self::assertSame( 'scheduled', $this->row()['vendor_status'] );
		self::assertSame( 'PUT', $this->vendor->requests[0][0] );
		self::assertSame( '/api/orders', $this->vendor->requests[0][1] );
		self::assertSame( $this->row()['custom_order_id'], $this->vendor->requests[0][2]['custom_order_id'] ?? null );
		self::assertCount( 1, $this->vendor->requests[0][2]['line_items'] ?? array() );
		self::assertStringContainsString( 'vendor order #10030 created', end( $this->order->notes ) );
	}

	public function test_timeout_after_vendor_accepts_never_creates_a_second_order(): void {
		$this->vendor->behaviours = array( 'accept_timeout' );
		$worker                   = $this->worker();

		$worker->process( 1 );
		self::assertSame( 'unknown', $this->row()['status'] );
		self::assertSame( 'vendor_timeout', $this->row()['last_error_code'] );

		$this->advance( 60 );
		$worker->sweep();

		self::assertCount( 1, $this->vendor->orders );
		self::assertCount( 2, $this->vendor->requests );
		self::assertSame( $this->vendor->requests[0][2], $this->vendor->requests[1][2], 'The retry must be byte-identical.' );
		self::assertSame( 'manual_review', $this->row()['status'] );
		self::assertSame( 'vendor_duplicate_reference', $this->row()['last_error_code'] );
		self::assertStringContainsString( 'Do not resubmit', end( $this->order->notes ) );
		self::assertSame( array( 'error', 'Vendor order for order #5001 needs attention: manual_review.' ), end( $this->alerts ) );
	}

	public function test_timeout_before_the_vendor_sees_the_order_is_retried_once_successfully(): void {
		$this->vendor->behaviours = array( 'timeout' );
		$worker                   = $this->worker();

		$worker->process( 1 );
		$this->advance( 60 );
		$worker->sweep();

		self::assertCount( 1, $this->vendor->orders );
		self::assertSame( 'accepted', $this->row()['status'] );
	}

	public function test_server_error_is_unknown_not_rejected_because_the_order_may_exist(): void {
		$this->vendor->behaviours = array( 'accept_500' );
		$worker                   = $this->worker();

		$worker->process( 1 );
		self::assertSame( 'unknown', $this->row()['status'] );

		$this->advance( 60 );
		$worker->sweep();
		self::assertCount( 1, $this->vendor->orders );
		self::assertSame( 'manual_review', $this->row()['status'] );
	}

	public function test_malformed_success_is_unknown(): void {
		$this->vendor->behaviours = array( 'malformed' );

		$this->worker()->process( 1 );

		self::assertSame( 'unknown', $this->row()['status'] );
		self::assertSame( 'vendor_success_malformed', $this->row()['last_error_code'] );
		self::assertCount( 1, $this->vendor->orders );
	}

	public function test_retries_wait_for_backoff_and_stop_in_manual_review_after_the_limit(): void {
		$this->vendor->behaviours = array_fill( 0, 10, 'server_error' );
		$worker                   = $this->worker();

		$worker->process( 1 );
		$worker->sweep();
		self::assertCount( 1, $this->vendor->requests, 'No retry before the backoff elapses.' );

		for ( $attempt = 1; $attempt < OrderSubmissionWorker::MAX_ATTEMPTS; ++$attempt ) {
			$this->advance( OrderSubmissionWorker::BACKOFF[ $attempt ] );
			$worker->sweep();
		}

		self::assertCount( OrderSubmissionWorker::MAX_ATTEMPTS, $this->vendor->requests );
		self::assertSame( 'manual_review', $this->row()['status'] );
		self::assertStringContainsString( 'MAY exist', end( $this->order->notes ) );
		$this->advance( 86400 );
		$worker->sweep();
		self::assertCount( OrderSubmissionWorker::MAX_ATTEMPTS, $this->vendor->requests );
		self::assertSame( array(), $this->vendor->orders );
	}

	public function test_validation_failure_is_rejected_and_never_retried_automatically(): void {
		$this->vendor->behaviours = array( 'validation' );
		$worker                   = $this->worker();

		$worker->process( 1 );
		$this->advance( 86400 );
		$worker->sweep();

		self::assertSame( 'rejected', $this->row()['status'] );
		self::assertSame( 'vendor_validation_failed', $this->row()['last_error_code'] );
		self::assertStringContainsString( 'shipping.zip field is required', (string) $this->row()['last_error'] );
		self::assertCount( 1, $this->vendor->requests );
	}

	public function test_refusal_before_processing_returns_to_pending_and_succeeds_later(): void {
		$this->vendor->behaviours = array( 'unauthorized' );
		$worker                   = $this->worker();

		$worker->process( 1 );
		self::assertSame( 'pending', $this->row()['status'] );
		self::assertSame( 'vendor_auth_failed', $this->row()['last_error_code'] );

		$this->advance( 60 );
		$worker->sweep();
		self::assertSame( 'accepted', $this->row()['status'] );
		self::assertCount( 1, $this->vendor->orders );
	}

	public function test_worker_crash_after_committing_submitting_recovers_through_the_safe_retry(): void {
		self::assertNotSame( '', $this->submissions->claim( 1, 'pending', OrderSubmissionWorker::LEASE_SECONDS ) );
		$worker = $this->worker();

		$worker->sweep();
		self::assertSame( 'submitting', $this->row()['status'], 'An unexpired lease is never taken over.' );

		$this->advance( OrderSubmissionWorker::LEASE_SECONDS + 1 );
		$worker->sweep();
		self::assertSame( 'unknown', $this->row()['status'] );
		self::assertSame( 'worker_lease_expired', $this->row()['last_error_code'] );

		$worker->sweep();
		self::assertSame( 'accepted', $this->row()['status'] );
		self::assertCount( 1, $this->vendor->orders );
	}

	public function test_concurrent_worker_cannot_send_while_another_owns_the_row(): void {
		$worker                         = $this->worker();
		$this->submissions->after_claim = function ( int $id ) use ( $worker ): void {
			$this->submissions->after_claim = null;
			$worker->process( $id );
		};

		$worker->process( 1 );

		self::assertCount( 1, $this->vendor->requests );
		self::assertCount( 1, $this->vendor->orders );
	}

	public function test_duplicate_scheduler_callbacks_after_acceptance_do_nothing(): void {
		$worker = $this->worker();
		$worker->process( 1 );
		$worker->process( 1 );
		$this->advance( 3600 );
		$worker->sweep();

		self::assertCount( 1, $this->vendor->requests );
	}

	public function test_refund_before_sending_blocks_without_any_request(): void {
		$this->order->status = 'refunded';

		$this->worker()->process( 1 );

		self::assertSame( 'blocked', $this->row()['status'] );
		self::assertSame( 'order_not_paid', $this->row()['last_error_code'] );
		self::assertSame( array(), $this->vendor->requests );
	}

	public function test_order_edited_after_intent_is_blocked_not_sent(): void {
		$this->order->shipping_address['address_1'] = '1 Different St';

		$this->worker()->process( 1 );

		self::assertSame( 'blocked', $this->row()['status'] );
		self::assertSame( 'payload_changed', $this->row()['last_error_code'] );
		self::assertSame( array(), $this->vendor->requests );
	}

	public function test_unknown_order_that_becomes_unsendable_goes_to_manual_review_not_blocked(): void {
		$this->vendor->behaviours = array( 'accept_timeout' );
		$worker                   = $this->worker();
		$worker->process( 1 );
		$this->order->status = 'cancelled';

		$this->advance( 60 );
		$worker->sweep();

		self::assertSame( 'manual_review', $this->row()['status'] );
		self::assertStringContainsString( 'MAY already exist', end( $this->order->notes ) );
		self::assertCount( 1, $this->vendor->requests );
	}

	public function test_stale_pending_intent_is_never_sent(): void {
		$this->advance( OrderSubmissionWorker::STALE_INTENT_HOURS * 3600 + 1 );

		$this->worker()->sweep();

		self::assertSame( 'manual_review', $this->row()['status'] );
		self::assertSame( 'stale_intent', $this->row()['last_error_code'] );
		self::assertSame( array(), $this->vendor->requests );
	}

	public function test_kill_switch_stops_all_sending_and_leaves_rows_untouched(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings']['order_submission'] = 'no';
		$worker = $this->worker();

		$worker->process( 1 );
		$worker->sweep();

		self::assertSame( 'pending', $this->row()['status'] );
		self::assertSame( array(), $this->vendor->requests );
	}

	public function test_failed_record_of_an_accepted_order_raises_a_critical_alert_with_the_vendor_id(): void {
		$this->submissions->after_claim = function ( int $id ): void {
			$this->submissions->after_claim                = null;
			$this->submissions->rows[ $id ]['owner_token'] = 'stolen';
		};

		$this->worker()->process( 1 );

		self::assertCount( 1, $this->vendor->orders );
		self::assertSame( array( 'critical', 'Vendor accepted order #5001 as vendor order #10030 but the result could not be recorded.' ), end( $this->alerts ) );
	}

	/** @return array<string,mixed> */
	private function row(): array {
		return (array) $this->submissions->get( 1 );
	}

	private function advance( int $seconds ): void {
		$GLOBALS['ea_now'] = gmdate( 'Y-m-d H:i:s', strtotime( $GLOBALS['ea_now'] . ' UTC' ) + $seconds );
	}

	private function worker(): OrderSubmissionWorker {
		$settings = new SettingsRepository();
		$vendor   = $this->vendor;
		return new OrderSubmissionWorker(
			new SubmissionGates( $settings ),
			$this->mappings,
			$this->submissions,
			new OrderPayloadBuilder(),
			new OrderSubmissionClient( $settings, new BaseUrlResolver(), new DatabaseLogger(), static fn(): FakeEndlessAislesOrders => $vendor ),
			new DatabaseLogger()
		);
	}
}
