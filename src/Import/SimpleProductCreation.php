<?php
namespace IdeaXperts\EndlessAisles\Import;

use IdeaXperts\EndlessAisles\Database\ImportRepository;

defined( 'ABSPATH' ) || exit;

/** Orchestrates the committed intent, single save, and read-only recovery boundary. */
final class SimpleProductCreation {
	public function __construct( private readonly ImportRepository $imports, private readonly SimpleProductWriterInterface $writer = new WooSimpleProductWriter(), private readonly SimpleProductProjection $projection = new SimpleProductProjection() ) {}

	/**
	 * Validate known configuration/content failures before claiming any reservation.
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $action
	 */
	public function preflight( array $item, string $token, array $action, string $execution ): bool {
		try {
			$vendor = $this->imports->creation_snapshot( (int) $item['id'] );
			if ( ! $vendor ) {
				throw new \RuntimeException( 'creation_snapshot_invalid' );
			}
			$desired = $this->projection->build( $vendor );
			if ( ! $this->imports->creation_binding_matches( (int) $item['id'], $this->projection->binding( $vendor, (string) $item['environment'], $desired ) ) ) {
				throw new \RuntimeException( 'approval_projection_changed' );
			}
			if ( 'production' === $item['environment'] && '' !== $desired['failure_code'] ) {
				throw new \RuntimeException( 'pricing_policy_missing' );
			}
			return true;
		} catch ( \RuntimeException $error ) {
			$reason = in_array( $error->getMessage(), array( 'approval_projection_changed', 'creation_policy_invalid', 'pricing_policy_missing', 'creation_snapshot_invalid', 'creation_ineligible', 'creation_invalid_upc', 'creation_title_missing', 'creation_invalid_price', 'creation_invalid_content' ), true ) ? $error->getMessage() : 'creation_projection_invalid';
			$this->imports->block_item( (int) $item['id'], $token, $reason );
			$this->imports->settle_action_for_item( (int) $action['id'], (string) $action['logical_key'], $execution );
			return false;
		}
	}

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $action
	 */
	public function apply( array $item, int $identity_id, string $token, array $action, string $execution ): void {
		$id = (int) $item['id'];
		try {
			$vendor = $this->imports->creation_snapshot( $id );
			if ( ! $vendor ) {
				throw new \RuntimeException( 'creation_snapshot_invalid' );
			}
			$desired = $this->projection->build( $vendor );
			$binding = $this->projection->binding( $vendor, (string) $item['environment'], $desired );
			if ( ! $this->imports->creation_binding_matches( $id, $binding ) ) {
				throw new \RuntimeException( 'approval_projection_changed' );
			}
		} catch ( \RuntimeException $error ) {
			$reason = in_array( $error->getMessage(), array( 'approval_projection_changed', 'creation_policy_invalid', 'creation_snapshot_invalid', 'creation_ineligible', 'creation_invalid_upc', 'creation_title_missing', 'creation_invalid_price', 'creation_invalid_content' ), true ) ? $error->getMessage() : 'creation_projection_invalid';
			$this->imports->block_item( $id, $token, $reason );
			$this->imports->settle_action_for_item( (int) $action['id'], (string) $action['logical_key'], $execution );
			return;
		}
		if ( 'qa' === $item['environment'] ) {
			$desired['approval_binding_hash'] = ApprovalManifest::hash( $binding );
			$this->imports->finalize_simple_creation( $id, (int) $action['id'], (string) $item['operation_uuid'], 0, '', $desired, $token, $execution, (int) $action['dispatch_generation'] );
			return;
		}
		if ( '' !== $desired['failure_code'] ) {
			$this->imports->block_item( $id, $token, $desired['failure_code'] );
			$this->imports->settle_action_for_item( (int) $action['id'], (string) $action['logical_key'], $execution );
			return;
		}
		if ( ! ImportManager::ensure_recovery_scheduled() ) {
			$this->imports->block_item( $id, $token, 'creation_recovery_unavailable' );
			$this->imports->settle_action_for_item( (int) $action['id'], (string) $action['logical_key'], $execution );
			return;
		}
		if ( ! $this->imports->acquire_final_write_permit( $id, $identity_id, $token, (int) $item['approval_generation'], (int) $action['id'], (string) $action['logical_key'], (int) $action['dispatch_generation'], $execution, $binding, $this->projection ) ) {
			$this->preflight( $item, $token, $action, $execution );
			$this->imports->settle_action_for_item( (int) $action['id'], (string) $action['logical_key'], $execution );
			return;
		}
		if ( ! $this->imports->creation_can_start( $id, (int) $action['id'], $token, $execution, (int) $action['dispatch_generation'] ) ) {
			return; // Committed intent remains reserved for read-only reconciliation.
		}
		$current = $this->imports->item( $id );
		if ( ! $current ) {
			return;
		}
		$returned_id = 0;
		try {
			$returned_id = $this->writer->create_draft( $current, $desired );
		} catch ( \Throwable ) {
			// Woo exceptions cannot prove that post insertion never occurred.
			$this->imports->move_applying_to_reconciling( $id, $token );
		}
		$this->recover( $current, $action, $returned_id, $token );
	}

	public function reconcile(): void {
		foreach ( $this->imports->unresolved_creations() as $item ) {
			$token = $this->imports->claim_creation_recovery( (int) $item['id'] );
			if ( '' === $token ) {
				continue;
			}
			$item = $this->imports->item( (int) $item['id'] );
			if ( ! $item ) {
				continue;
			}
			$action = $this->imports->creation_action( (int) $item['id'] );
			if ( $action ) {
				$this->recover( $item, $action, 0, $token, true );
			}
		}
	}

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $action
	 */
	private function recover( array $item, array $action, int $returned_id, string $token, bool $recovery = false ): void {
		$failure = '';
		$wc_id   = 0;
		if ( $recovery && (int) $item['attempt_count'] > ImportRepository::MAX_RECOVERY_ATTEMPTS ) {
			$this->imports->finalize_simple_creation( (int) $item['id'], (int) $action['id'], (string) $item['operation_uuid'], 0, 'recovery_attempts_exhausted', array(), $token );
			return;
		}
		try {
			$ids = $this->writer->correlated_drafts( $item );
			if ( count( $ids ) !== 1 ) {
				$failure = $ids ? 'correlation_multiple' : 'correlation_missing';
			} else {
				$wc_id = $ids[0];
				if ( $returned_id > 0 && $returned_id !== $wc_id ) {
					$failure = 'correlation_returned_id_mismatch';
				}
			}
		} catch ( \Throwable $error ) {
			if ( 'correlation_read_failed' === $error->getMessage() ) {
				if ( $recovery ) {
					$this->imports->defer_creation_recovery( (int) $item['id'], $token );
				}
				return;
			}
			$failure = 'correlation_invalid';
		}
		$result = $this->imports->finalize_simple_creation( (int) $item['id'], (int) $action['id'], (string) $item['operation_uuid'], $wc_id, $failure, array(), $token );
		if ( 'inspection_retry' === $result ) {
			$this->imports->defer_creation_recovery( (int) $item['id'], $token, 'creation_inspection_read_failed' );
		} elseif ( 'retry' === $result && $recovery ) {
			$this->imports->defer_creation_recovery( (int) $item['id'], $token );
		}
	}
}
