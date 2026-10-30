<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

defined( 'ABSPATH' ) || exit;

/**
 * One async action per new intent, a 5-minute sweeper for retries and expired
 * leases, and a 30-minute status reconciliation for accepted vendor orders.
 */
final class OrderSubmissionScheduler {
	public const SUBMIT_HOOK    = 'ideaxperts_ea_submit_order';
	public const SWEEP_HOOK     = 'ideaxperts_ea_submit_orders_sweep';
	public const RECONCILE_HOOK = 'ideaxperts_ea_reconcile_orders';
	public const GROUP          = 'ideaxperts-endless-aisles-orders';
	public const SWEEP_INTERVAL = 300;

	public function __construct( private readonly OrderSubmissionWorker $worker, private readonly SubmissionGates $gates, private readonly ?OrderStatusReconciler $reconciler = null ) {}

	public function register(): void {
		add_action( self::SUBMIT_HOOK, array( $this->worker, 'process' ), 10, 1 );
		add_action( self::SWEEP_HOOK, array( $this->worker, 'sweep' ) );
		if ( null !== $this->reconciler ) {
			add_action( self::RECONCILE_HOOK, array( $this->reconciler, 'run' ) );
		}
		add_action( 'init', array( $this, 'ensure_scheduled' ), 30 );
	}

	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$this->keep( self::SWEEP_HOOK, self::SWEEP_INTERVAL, '' === $this->gates->configuration_failure() );
		// Reconciliation outlives the submission switch: accepted orders still need following.
		$this->keep( self::RECONCILE_HOOK, OrderStatusReconciler::INTERVAL_SECONDS, null !== $this->reconciler && '' === $this->gates->reconciliation_failure() );
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

	private function keep( string $hook, int $interval, bool $wanted ): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$scheduled = false !== as_next_scheduled_action( $hook, array(), self::GROUP );
		if ( ! $wanted ) {
			if ( $scheduled && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
			return;
		}
		if ( ! $scheduled ) {
			as_schedule_recurring_action( time() + $interval, $interval, $hook, array(), self::GROUP, true );
		}
	}
}
