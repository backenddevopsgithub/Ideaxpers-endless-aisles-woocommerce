<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

defined( 'ABSPATH' ) || exit;

/** One async action per new intent plus a 5-minute sweeper for retries and expired leases. */
final class OrderSubmissionScheduler {
	public const SUBMIT_HOOK    = 'ideaxperts_ea_submit_order';
	public const SWEEP_HOOK     = 'ideaxperts_ea_submit_orders_sweep';
	public const GROUP          = 'ideaxperts-endless-aisles-orders';
	public const SWEEP_INTERVAL = 300;

	public function __construct( private readonly OrderSubmissionWorker $worker, private readonly SubmissionGates $gates ) {}

	public function register(): void {
		add_action( self::SUBMIT_HOOK, array( $this->worker, 'process' ), 10, 1 );
		add_action( self::SWEEP_HOOK, array( $this->worker, 'sweep' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 30 );
	}

	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$scheduled = false !== as_next_scheduled_action( self::SWEEP_HOOK, array(), self::GROUP );
		if ( '' !== $this->gates->configuration_failure() ) {
			if ( $scheduled && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::SWEEP_HOOK, array(), self::GROUP );
			}
			return;
		}
		if ( ! $scheduled ) {
			as_schedule_recurring_action( time() + self::SWEEP_INTERVAL, self::SWEEP_INTERVAL, self::SWEEP_HOOK, array(), self::GROUP, true );
		}
	}

	/** Queue a prompt attempt; the sweeper still finds the row if this fails. */
	public static function enqueue( int $submission_id ): bool {
		return function_exists( 'as_enqueue_async_action' ) && as_enqueue_async_action( self::SUBMIT_HOOK, array( $submission_id ), self::GROUP, true ) > 0;
	}

	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// Group-wide: per-order submit actions carry arguments that a hook+args match would miss.
			// Rows stay durable; the sweeper resumes them after reactivation (subject to the 72-hour rule).
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}
}
