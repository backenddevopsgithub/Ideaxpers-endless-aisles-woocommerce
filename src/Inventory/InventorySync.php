<?php
namespace IdeaXperts\EndlessAisles\Inventory;

use Closure;
use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\API\StockService;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls vendor stock for active mappings in bounded, resumable, single-owner passes.
 *
 * Failure policy: an API failure leaves every remaining product's stock untouched and
 * fails the run; a SKU the vendor omits is treated as unavailable (quantity 0).
 */
final class InventorySync {
	public const LOCK_TTL    = 120;
	public const TIME_BUDGET = 25;

	public const COMPLETED   = 'completed';
	public const PARTIAL     = 'completed_with_errors';
	public const FAILED      = 'failed';
	public const CONTINUED   = 'continued';
	public const LOCKED      = 'locked';
	public const LOCK_LOST   = 'lock_lost';
	public const START_ERROR = 'start_failed';

	private const COUNTERS = array( 'processed', 'success', 'failure', 'updated', 'unchanged', 'not_found', 'invalid', 'not_owned', 'missing', 'stale' );

	private readonly Closure $clock;
	private readonly Closure $continuation;

	public function __construct(
		private readonly StockService $stock,
		private readonly InventoryStoreInterface $store,
		private readonly StockWriterInterface $writer,
		private readonly DatabaseLogger $logger,
		?Closure $clock = null,
		?Closure $continuation = null
	) {
		$this->clock        = $clock ?? static fn(): int => time();
		$this->continuation = $continuation ?? static fn(): bool => false;
	}

	public function run( string $environment ): string {
		$token = $this->store->acquire_lock( self::LOCK_TTL );
		if ( '' === $token ) {
			return self::LOCKED;
		}
		try {
			return $this->run_owned( $environment, $token );
		} finally {
			$this->store->release_lock( $token );
		}
	}

	private function run_owned( string $environment, string $token ): string {
		$cursor = $this->store->cursor();
		if ( null === $cursor ) {
			$run_id = $this->store->start_run();
			if ( $run_id < 1 ) {
				$this->logger->log( 'error', 'Inventory synchronization could not record a run.' );
				return self::START_ERROR;
			}
			$after  = 0;
			$counts = array_fill_keys( self::COUNTERS, 0 );
			$this->store->save_cursor( $run_id, $after, $counts );
		} else {
			$run_id = $cursor['run_id'];
			$after  = $cursor['after_id'];
			$counts = array_merge( array_fill_keys( self::COUNTERS, 0 ), $cursor['counts'] );
		}
		$scope   = 'endless-aisles:' . $environment;
		$started = ( $this->clock )();
		while ( true ) {
			$mappings = $this->store->active_mappings( $scope, $environment, $after, StockService::BATCH_SIZE );
			if ( array() === $mappings ) {
				break;
			}
			$skus   = array_values( array_filter( array_map( static fn( array $mapping ): string => $mapping['ea_option_id'], $mappings ), array( StockService::class, 'valid_sku' ) ) );
			$result = array(
				'quantities' => array(),
				'missing'    => array(),
				'invalid'    => array(),
			);
			try {
				if ( array() !== $skus ) {
					$result = $this->stock->quantities( $environment, $skus );
				}
			} catch ( ApiException $exception ) {
				$this->store->update_run( $run_id, self::FAILED, $counts, 'Stock request failed: ' . $exception->getMessage() );
				$this->store->clear_cursor();
				$this->logger->log(
					'error',
					'Inventory synchronization failed; remaining stock left unchanged.',
					array(
						'run_id'   => $run_id,
						'after_id' => $after,
						'status'   => $exception->getCode(),
					)
				);
				return self::FAILED;
			}
			$observed_at = current_time( 'mysql', true );
			foreach ( $mappings as $mapping ) {
				++$counts['processed'];
				$after = $mapping['id'];
				$sku   = $mapping['ea_option_id'];
				if ( ! StockService::valid_sku( $sku ) || in_array( $sku, $result['invalid'], true ) ) {
					++$counts['invalid'];
					++$counts['failure'];
					continue;
				}
				if ( in_array( $sku, $result['missing'], true ) ) {
					++$counts['not_found'];
				}
				try {
					$outcome = $this->writer->apply( $mapping, $environment, $result['quantities'][ $sku ] ?? 0, $observed_at );
				} catch ( \Throwable ) {
					++$counts['failure'];
					continue;
				}
				if ( StockWriterInterface::UPDATED === $outcome || StockWriterInterface::UNCHANGED === $outcome ) {
					++$counts[ $outcome ];
					++$counts['success'];
					$this->store->mark_synced( $mapping['id'] );
				} elseif ( StockWriterInterface::STALE === $outcome ) {
					++$counts['stale'];
					++$counts['success'];
				} else {
					++$counts[ StockWriterInterface::NOT_OWNED === $outcome ? 'not_owned' : 'missing' ];
					++$counts['failure'];
				}
			}
			$this->store->save_cursor( $run_id, $after, $counts );
			if ( count( $mappings ) < StockService::BATCH_SIZE ) {
				break;
			}
			if ( ( $this->clock )() - $started >= self::TIME_BUDGET ) {
				$this->store->update_run( $run_id, 'running', $counts, $this->summary( $counts ) );
				if ( ! ( $this->continuation )() ) {
					$this->logger->log( 'warning', 'Inventory continuation could not be scheduled; the next recurring run resumes.', array( 'run_id' => $run_id ) );
				}
				return self::CONTINUED;
			}
			if ( ! $this->store->refresh_lock( $token, self::LOCK_TTL ) ) {
				$this->logger->log( 'warning', 'Inventory synchronization lost its lock; the cursor is retained.', array( 'run_id' => $run_id ) );
				return self::LOCK_LOST;
			}
		}
		$status = $counts['failure'] > 0 ? self::PARTIAL : self::COMPLETED;
		$this->store->update_run( $run_id, $status, $counts, $this->summary( $counts ) );
		$this->store->clear_cursor();
		$this->store->record_success( $environment );
		if ( self::PARTIAL === $status ) {
			$this->logger->log( 'warning', 'Inventory synchronization completed with item failures.', array_merge( array( 'run_id' => $run_id ), $counts ) );
		}
		return $status;
	}

	/** @param array<string,int> $counts */
	private function summary( array $counts ): string {
		$parts = array();
		foreach ( array_diff( self::COUNTERS, array( 'success', 'failure' ) ) as $key ) {
			$parts[] = $key . '=' . $counts[ $key ];
		}
		return implode( ' ', $parts );
	}
}
