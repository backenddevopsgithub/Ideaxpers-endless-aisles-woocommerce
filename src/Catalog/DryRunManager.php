<?php
namespace IdeaXperts\EndlessAisles\Catalog;

use IdeaXperts\EndlessAisles\API\ApiException;
use IdeaXperts\EndlessAisles\API\CatalogService;
use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\ProductMapping\UpcNormalizer;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class DryRunManager {
	public const GROUP        = 'ideaxperts-endless-aisles';
	public const STORE_HOOK   = 'ideaxperts_ea_dry_run_store_batch';
	public const CATALOG_HOOK = 'ideaxperts_ea_dry_run_catalog_page';
	public const PURGE_HOOK   = 'ideaxperts_ea_dry_run_purge';

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly DryRunRepository $runs,
		private readonly StoreCatalogScanner $scanner,
		private readonly CatalogService $catalog,
		private readonly DatabaseLogger $logger,
		private readonly MatchClassifier $classifier
	) {}

	public function register(): void {
		add_action( self::STORE_HOOK, array( $this, 'store_batch' ), 10, 2 );
		add_action( self::CATALOG_HOOK, array( $this, 'catalog_page' ), 10, 2 );
		add_action( self::PURGE_HOOK, array( $this, 'continue_purge' ) );
	}

	public function start( int $user_id ): int {
		$connection = get_option( 'ideaxperts_ea_qa_connection_status', array() );
		if ( 'yes' !== $this->settings->get( 'enabled', 'no' ) || 'qa' !== $this->settings->get( 'environment', 'qa' ) || ! $this->settings->token_configured( 'qa' ) || ! is_array( $connection ) || 'connected' !== ( $connection['status'] ?? '' ) ) {
			throw new RuntimeException( 'An enabled QA configuration and successful QA connection test are required.' );
		}
		$run_id = $this->runs->claim_new( $user_id );
		if ( $run_id < 1 ) {
			throw new RuntimeException( 'A catalog dry run is already active.' );
		}
		$this->begin_store_scan( $run_id, 'Catalog dry run started.', array( 'environment' => 'qa' ) );
		return $run_id;
	}

	public function start_local_discovery( int $user_id ): int {
		$run_id = $this->runs->claim_new( $user_id, 'local' );
		if ( $run_id < 1 ) {
			throw new RuntimeException( 'A catalog scan is already active.' );
		}
		$this->begin_store_scan( $run_id, 'Local catalog identifier discovery started.' );
		return $run_id;
	}

	public function resume( int $run_id ): void {
		$run = $this->runs->run( $run_id );
		if ( ! $run || 'failed' !== $run['status'] ) {
			throw new RuntimeException( 'Only an inactive failed run can be resumed.' );
		}
		if ( ! $this->runs->claim_existing( $run_id ) ) {
			throw new RuntimeException( 'A catalog dry run is already active.' );
		}
		$cursor = (string) $run['resume_cursor'];
		if ( 1 !== preg_match( '/^(store|catalog):([1-9][0-9]*)$/', $cursor, $matches ) ) {
			$this->runs->release_lock( $run_id );
			throw new RuntimeException( 'The failed run has no safe resume cursor.' );
		}
		$status = 'store' === $matches[1] ? 'scanning_store' : 'fetching_catalog';
		$hook   = 'store' === $matches[1] ? self::STORE_HOOK : self::CATALOG_HOOK;
		$moved  = $this->runs->transition(
			$run_id,
			array( 'failed' ),
			array(
				'status'            => $status,
				'error_summary'     => '',
				'last_heartbeat_at' => current_time( 'mysql', true ),
			)
		);
		if ( ! $moved ) {
			$this->runs->release_lock( $run_id );
			throw new RuntimeException( 'Only an inactive failed run can be resumed.' );
		}
		$this->schedule( $hook, array( $run_id, (int) $matches[2] ) );
		$this->logger->log( 'info', 'Catalog dry run resumed.', array( 'run_id' => $run_id ) );
	}

	public function cancel( int $run_id ): void {
		$cancelled = $this->runs->transition(
			$run_id,
			DryRunRepository::ACTIVE_STATUSES,
			array(
				'status'          => 'cancelled',
				'cancellation_at' => current_time( 'mysql', true ),
				'completed_at'    => current_time( 'mysql', true ),
			)
		);
		if ( ! $cancelled ) {
			return;
		}
		$this->unschedule_run( $run_id );
		$this->runs->release_lock( $run_id );
		$this->purge_expired();
		$this->logger->log( 'info', 'Catalog dry run cancelled.', array( 'run_id' => $run_id ) );
	}

	public function recover_stale(): int {
		$run_id = $this->runs->recover_stale_active();
		if ( $run_id > 0 ) {
			$this->unschedule_run( $run_id );
		}
		return $run_id;
	}

	public function handle_deactivation(): void {
		self::unschedule_all_jobs();
		$failed = $this->runs->fail_active_runs( 'The plugin was deactivated.' );
		foreach ( $failed as $run_id ) {
			$this->runs->release_lock( $run_id );
		}
	}

	public function purge_expired(): int {
		$deleted = $this->runs->purge_expired();
		if ( $this->runs->has_purge_remaining() ) {
			$this->schedule( self::PURGE_HOOK, array() );
		}
		return $deleted;
	}

	public function continue_purge(): void {
		$this->purge_expired();
	}

	public function store_batch( int $run_id, int $page ): void {
		$this->recover_stale();
		$run = $this->runs->run( $run_id );
		if ( ! $run || 'scanning_store' !== $run['status'] || (int) ( $run['current_store_page'] ?? 0 ) >= $page ) {
			return;
		}
		if ( ! $this->runs->heartbeat( $run_id ) ) {
			return;
		}
		try {
			$result = $this->scanner->scan_batch( $run_id, $page );
			if ( 'scanning_store' !== ( $this->runs->run( $run_id )['status'] ?? '' ) ) {
				return;
			}
			$counts      = $this->runs->store_record_counts( $run_id );
			$is_local    = 'local' === $run['environment'];
			$next_status = $result['has_more'] ? 'scanning_store' : ( $is_local ? 'completed' : 'fetching_catalog' );
			$advanced    = $this->runs->transition(
				$run_id,
				array( 'scanning_store' ),
				array(
					'current_store_page'      => $page,
					'products_inspected'      => $counts['products'],
					'variations_inspected'    => $counts['variations'],
					'store_records_inspected' => $counts['products'] + $counts['variations'],
					'resume_cursor'           => $result['has_more'] ? 'store:' . ( $page + 1 ) : ( $is_local ? '' : 'catalog:1' ),
					'status'                  => $next_status,
					'completed_at'            => $is_local && ! $result['has_more'] ? current_time( 'mysql', true ) : null,
					'last_heartbeat_at'       => current_time( 'mysql', true ),
				)
			);
			if ( ! $advanced ) {
				return;
			}
			if ( $result['has_more'] ) {
				$this->schedule( self::STORE_HOOK, array( $run_id, $page + 1 ) );
			} elseif ( ! $is_local ) {
				$this->schedule( self::CATALOG_HOOK, array( $run_id, 1 ) );
			} else {
				$this->runs->release_lock( $run_id );
				$this->purge_expired();
				$this->logger->log( 'info', 'Local catalog identifier discovery completed.', array( 'run_id' => $run_id ) );
			}
		} catch ( \Throwable $exception ) {
			$this->fail( $run_id, 'store:' . $page, $exception );
		}
	}

	public function catalog_page( int $run_id, int $page ): void {
		$this->recover_stale();
		$run = $this->runs->run( $run_id );
		if ( ! $run || 'fetching_catalog' !== $run['status'] || (int) ( $run['current_api_page'] ?? 0 ) >= $page ) {
			return;
		}
		if ( ! $this->runs->heartbeat( $run_id ) ) {
			return;
		}
		try {
			$result = $this->catalog->page( $page );
			if ( 'fetching_catalog' !== ( $this->runs->run( $run_id )['status'] ?? '' ) ) {
				return;
			}
			foreach ( $result['products'] as $product ) {
				foreach ( $product['sizes'] as $option ) {
					$option['ea_product_id'] = (string) ( $product['id'] ?? '' );
					$upc                     = UpcNormalizer::inspect( $option['upc'] ?? null );
					$product_id              = (string) ( $product['id'] ?? '' );
					$option_id               = (string) ( $option['id'] ?? '' );
					$duplicate_vendor        = $this->runs->vendor_upc_used_by_other_option( $run_id, $upc['normalized'], $product_id, $option_id );
					$upc_matches             = $this->runs->identifier_matches( $run_id, $upc['normalized'], 'upc' );
					$sku_matches             = $this->runs->identifier_matches( $run_id, $upc['normalized'], 'sku' );
					$store_mapping           = null;
					$identity                = 1 === count( $upc_matches ) ? $upc_matches[0] : ( 1 === count( $sku_matches ) ? $sku_matches[0] : null );
					if ( $identity ) {
						$store_mapping = $this->runs->mapping_for_store_item( (int) ( $identity['wc_product_id'] ?? 0 ), (int) ( $identity['wc_variation_id'] ?? 0 ) );
					}
					$match = $this->classifier->classify(
						$option,
						$this->runs->mappings( $product_id, $option_id ),
						$upc_matches,
						$sku_matches,
						'yes' === $this->settings->get( 'allow_sku_upc_match', 'no' ),
						$duplicate_vendor,
						$store_mapping
					);
					$this->runs->item(
						$run_id,
						array(
							'ea_product_id'             => $product_id,
							'ea_option_id'              => $option_id,
							'original_upc'              => $upc['original'],
							'normalized_upc'            => $upc['normalized'],
							'wc_product_id'             => $match['wc_product_id'],
							'wc_variation_id'           => $match['wc_variation_id'],
							'classification'            => $match['classification'],
							'review_flags'              => $match['review_flags'],
							'review_reason'             => $match['reason'],
							'vendor_title'              => (string) ( $product['title'] ?? '' ),
							'vendor_option_description' => (string) ( $option['description'] ?? '' ),
							'retail_price'              => (string) ( $option['price'] ?? '' ),
							'wholesale_price'           => (string) ( $option['wholesale'] ?? '' ),
							'map_price'                 => (string) ( $option['minimum_advertised_price'] ?? '' ),
							'purchasable'               => ! empty( $option['purchasability'] ) ? 1 : 0,
							'discontinued'              => ! empty( $option['discontinued'] ) ? 1 : 0,
						)
					);
					if ( $duplicate_vendor ) {
						$this->runs->add_review_flag_for_upc( $run_id, $upc['normalized'], 'duplicate_vendor_upc' );
					}
				}
			}
			$next     = $result['next_page'];
			$advanced = $this->runs->transition(
				$run_id,
				array( 'fetching_catalog' ),
				array(
					'current_api_page'  => $page,
					'match_counters'    => wp_json_encode( $this->runs->classification_counts( $run_id ) ),
					'warning_counters'  => wp_json_encode( $this->runs->flag_counts( $run_id ) ),
					'resume_cursor'     => null === $next ? '' : 'catalog:' . $next,
					'status'            => null === $next ? 'completed' : 'fetching_catalog',
					'completed_at'      => null === $next ? current_time( 'mysql', true ) : null,
					'last_heartbeat_at' => current_time( 'mysql', true ),
				)
			);
			if ( ! $advanced ) {
				return;
			}
			$this->logger->log(
				'info',
				null === $next ? 'Catalog dry run completed.' : 'Catalog dry-run page processed.',
				array(
					'run_id' => $run_id,
					'page'   => $page,
				)
			);
			if ( null === $next ) {
				$this->runs->release_lock( $run_id );
				$this->purge_expired();
				return;
			}
			$this->schedule( self::CATALOG_HOOK, array( $run_id, $next ) );
		} catch ( \Throwable $exception ) {
			$this->fail( $run_id, 'catalog:' . $page, $exception );
		}
	}

	public static function unschedule_all_jobs(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( self::STORE_HOOK );
		as_unschedule_all_actions( self::CATALOG_HOOK );
		as_unschedule_all_actions( self::PURGE_HOOK );
	}

	/** @param array<string,mixed> $context */
	private function begin_store_scan( int $run_id, string $message, array $context = array() ): void {
		$moved = $this->runs->transition(
			$run_id,
			array( 'pending' ),
			array(
				'status'            => 'scanning_store',
				'resume_cursor'     => 'store:1',
				'last_heartbeat_at' => current_time( 'mysql', true ),
			)
		);
		if ( ! $moved ) {
			$this->runs->release_lock( $run_id );
			throw new RuntimeException( 'The dry run could not be started.' );
		}
		$this->schedule( self::STORE_HOOK, array( $run_id, 1 ) );
		$this->logger->log( 'info', $message, array_merge( array( 'run_id' => $run_id ), $context ) );
	}

	/** @param list<int> $args */
	private function schedule( string $hook, array $args ): void {
		// Action Scheduler is provided by WooCommerce at runtime.
		// @phpstan-ignore-next-line
		\as_enqueue_async_action( $hook, $args, self::GROUP, true );
	}

	private function unschedule_run( int $run_id ): void {
		if ( function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_unschedule_action' ) ) {
			foreach ( array( self::STORE_HOOK, self::CATALOG_HOOK ) as $hook ) {
				$actions = as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'group'    => self::GROUP,
						'per_page' => 100,
						'status'   => array( 'pending', 'in-progress' ),
					)
				);
				foreach ( is_array( $actions ) ? $actions : array() as $action ) {
					if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
						continue;
					}
					$args = $action->get_args();
					if ( isset( $args[0] ) && (int) $args[0] === $run_id ) {
						as_unschedule_action( $hook, $args, self::GROUP );
					}
				}
			}
			return;
		}
		self::unschedule_all_jobs();
	}

	private function fail( int $run_id, string $cursor, \Throwable $exception ): void {
		$message = $exception instanceof ApiException ? $exception->getMessage() : 'Catalog dry-run processing failed.';
		$failed  = $this->runs->transition(
			$run_id,
			DryRunRepository::ACTIVE_STATUSES,
			array(
				'status'        => 'failed',
				'error_summary' => $message,
				'resume_cursor' => $cursor,
			)
		);
		if ( ! $failed ) {
			return;
		}
		$this->unschedule_run( $run_id );
		$this->runs->release_lock( $run_id );
		$this->logger->log(
			'error',
			'Catalog dry run failed.',
			array(
				'run_id' => $run_id,
				'error'  => $message,
			)
		);
	}
}
