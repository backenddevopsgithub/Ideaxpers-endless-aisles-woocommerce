<?php
namespace IdeaXperts\EndlessAisles\Import;

use IdeaXperts\EndlessAisles\Database\ImportRepository;

defined( 'ABSPATH' ) || exit;

final class ImportManager {
	public const GROUP = 'ideaxperts-endless-aisles-import';
	public const HOOK  = 'ideaxperts_ea_import_validate_item';

	public function __construct( private readonly ImportRepository $imports ) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'validate_item' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'reconcile' ) );
	}

	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	public function queue( int $run_id ): bool {
		$run = $this->imports->run( $run_id );
		if ( ! $run || ! in_array( (string) $run['status'], array( 'queued', 'running' ), true ) ) {
			return false;
		}
		$after_id = 0;
		do {
			$items = $this->imports->pending_items_after( $run_id, $after_id );
			foreach ( $items as $item ) {
				$after_id = (int) $item['id'];
				if ( $this->imports->create_action( $run_id, $after_id, (int) $run['claim_generation'], 'validate', self::HOOK ) < 1 ) {
					return false;
				}
			}
		} while ( count( $items ) === ImportRepository::BATCH_SIZE );
		if ( 'queued' === (string) $run['status'] && ! $this->imports->start_run( $run_id ) ) {
			return false;
		}
		$this->reconcile();
		return true;
	}

	public function reconcile(): void {
		$this->imports->reclaim_expired_pre_apply();
		$this->imports->reclaim_expired_action_executions();
		foreach ( $this->imports->stale_dispatches() as $action ) {
			$args = $this->action_args( $action );
			$id   = $this->find_action( (string) $action['hook'], $args );
			if ( $id > 0 ) {
				$this->imports->record_dispatched( (int) $action['id'], (string) $action['dispatch_token'], $id, (int) $action['dispatch_generation'] );
			} else {
				$this->imports->reopen_stale_dispatch( (int) $action['id'], (string) $action['dispatch_token'], (string) $action['dispatch_lease_expires_at'] );
			}
		}
		foreach ( $this->imports->dispatchable_actions() as $action ) {
			$owner = $this->imports->claim_dispatch( (int) $action['id'] );
			if ( '' === $owner ) {
				continue;
			}
			$current = $this->imports->action( (int) $action['id'] );
			if ( ! $current || 'dispatching' !== (string) $current['status'] ) {
				continue;
			}
			$args = $this->action_args( $current );
			$id   = $this->find_action( (string) $current['hook'], $args );
			if ( $id < 1 && function_exists( 'as_enqueue_async_action' ) ) {
				$id = (int) as_enqueue_async_action( (string) $current['hook'], $args, self::GROUP, true );
			}
			if ( $id < 1 || ! $this->imports->record_dispatched( (int) $current['id'], $owner, $id, (int) $current['dispatch_generation'] ) ) {
				$this->imports->fail_dispatch( (int) $action['id'], $owner );
			}
		}
		foreach ( $this->imports->stale_dispatched() as $action ) {
			$args  = $this->action_args( $action );
			$state = $this->find_action_state( (string) $action['hook'], $args, (int) $action['action_scheduler_id'] );
			if ( in_array( $state, array( 'pending', 'in-progress' ), true ) ) {
				$this->imports->retain_dispatched( (int) $action['id'], (int) $action['dispatch_generation'], (int) $action['action_scheduler_id'] );
			} else {
				$reason = 'complete' === $state ? 'scheduler_completed_without_claim' : 'scheduler_action_lost';
				$this->imports->reopen_lost_dispatched( (int) $action['id'], (int) $action['dispatch_generation'], (int) $action['action_scheduler_id'], $reason );
			}
		}
	}

	public function validate_item( mixed $action_id = 0, mixed $logical_key = '', mixed $dispatch_generation = 0 ): void {
		$action_id   = (int) $action_id;
		$logical_key = (string) $logical_key;
		$generation  = (int) $dispatch_generation;
		$execution   = $this->imports->claim_action_execution( $action_id, $logical_key, $generation );
		$action      = '' !== $execution ? $this->imports->action( $action_id ) : null;
		if ( ! $action ) {
			return;
		}
		$item_id = (int) $action['import_item_id'];
		if ( $this->imports->settle_action_for_item( $action_id, $logical_key, $execution ) ) {
			return;
		}
		$token = $this->imports->claim_item( $item_id );
		if ( '' === $token || ! $this->imports->begin_validation( $item_id, $token ) ) {
			$this->imports->settle_action_for_item( $action_id, $logical_key, $execution );
			return;
		}
		$identity_id = $this->imports->reserve_catalog_identity( $item_id, $token );
		$item        = $this->imports->item( $item_id );
		if ( $identity_id < 1 || ! $item || ( '' !== (string) $item['normalized_upc'] && $this->imports->reserve_upc( $identity_id, $item_id, $token ) < 1 ) ) {
			$this->imports->block_item( $item_id, $token, 'reservation_conflict' );
			$this->imports->settle_action_for_item( $action_id, $logical_key, $execution );
			return;
		}
		if ( ! $this->imports->accept_freshness( $item_id, $token ) ) {
			$this->imports->reject_stale( $item_id, $token );
			$this->imports->settle_action_for_item( $action_id, $logical_key, $execution );
			return;
		}
		// Milestone 3A deliberately stops at ready. No catalog writer is registered.
		$this->imports->complete_action_execution( $action_id, $logical_key, $execution );
	}

	/**
	 * @param array<string,mixed> $action Durable action.
	 * @return list<mixed>
	 */
	private function action_args( array $action ): array {
		return array( (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'] );
	}

	/** @param list<mixed> $args */
	private function find_action( string $hook, array $args ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => array( 'pending', 'in-progress' ),
				'per_page' => ImportRepository::BATCH_SIZE,
			)
		);
		foreach ( is_array( $actions ) ? $actions : array() as $action ) {
			if ( is_object( $action ) && method_exists( $action, 'get_args' ) && $action->get_args() === $args && method_exists( $action, 'get_id' ) ) {
				return (int) $action->get_id();
			}
		}
		return 0;
	}

	/** @param list<mixed> $args */
	private function find_action_state( string $hook, array $args, int $scheduler_id ): string {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return '';
		}
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => array( 'pending', 'in-progress', 'complete', 'failed', 'canceled', 'cancelled' ),
				'per_page' => ImportRepository::BATCH_SIZE,
			)
		);
		foreach ( is_array( $actions ) ? $actions : array() as $action ) {
			if ( is_object( $action ) && method_exists( $action, 'get_id' ) && (int) $action->get_id() === $scheduler_id && method_exists( $action, 'get_args' ) && $action->get_args() === $args && method_exists( $action, 'get_status' ) ) {
				return (string) $action->get_status();
			}
		}
		return '';
	}
}
