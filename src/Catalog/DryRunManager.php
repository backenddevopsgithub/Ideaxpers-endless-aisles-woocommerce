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
	public const GROUP          = 'ideaxperts-endless-aisles';
	public const STORE_HOOK     = 'ideaxperts_ea_dry_run_store_batch';
	public const CATALOG_HOOK   = 'ideaxperts_ea_dry_run_catalog_page';
	public const PURGE_HOOK     = 'ideaxperts_ea_dry_run_purge';
	public const RECONCILE_HOOK = 'ideaxperts_ea_dry_run_reconcile';

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly DryRunRepository $runs,
		private readonly StoreCatalogScanner $scanner,
		private readonly CatalogService $catalog,
		private readonly DatabaseLogger $logger,
		private readonly MatchClassifier $classifier
	) {}

	public function register(): void {
		add_action( self::STORE_HOOK, array( $this, 'store_batch' ), 10, 7 );
		add_action( self::CATALOG_HOOK, array( $this, 'catalog_page' ), 10, 7 );
		add_action( self::PURGE_HOOK, array( $this, 'continue_purge' ), 10, 7 );
		add_action( self::RECONCILE_HOOK, array( $this, 'reconcile' ) );
		add_action( 'admin_init', array( $this, 'reconcile' ) );
	}

	public function start( int $user_id ): int {
		$connection = get_option( 'ideaxperts_ea_qa_connection_status', array() );
		if ( 'yes' !== $this->settings->get( 'enabled', 'no' ) || 'qa' !== $this->settings->get( 'environment', 'qa' ) || ! $this->settings->token_configured( 'qa' ) || ! is_array( $connection ) || 'connected' !== ( $connection['status'] ?? '' ) ) {
			throw new RuntimeException( 'An enabled QA configuration and successful QA connection test are required.' );
		}
		return $this->start_claimed( $user_id, 'qa', 'Catalog dry run started.' );
	}

	public function start_local_discovery( int $user_id ): int {
		return $this->start_claimed( $user_id, 'local', 'Local catalog identifier discovery started.' );
	}

	private function start_claimed( int $user_id, string $environment, string $message ): int {
		$run_id = $this->runs->claim_new( $user_id, $environment );
		if ( $run_id < 1 ) {
			throw new RuntimeException( 'A catalog dry run is already active.' );
		}
		$token      = $this->runs->claim_token( $run_id );
		$generation = $this->runs->claim_generation( $run_id );
		$ok         = $this->runs->update_and_then(
			$run_id,
			$token,
			array( 'pending' ),
			array(
				'status'            => 'scanning_store',
				'resume_cursor'     => 'store:1',
				'last_heartbeat_at' => current_time( 'mysql', true ),
			),
			fn(): bool => ! get_option( DryRunRepository::DEACTIVATING_OPTION, false ) && $this->runs->create_action_intent( $run_id, $token, $generation, 'store', self::STORE_HOOK, 1 ) > 0,
			$generation
		);
		if ( ! $ok ) {
			throw new RuntimeException( 'The dry run could not be started.' );
		}
		$this->reconcile();
		$this->logger->log(
			'info',
			$message,
			array(
				'run_id'      => $run_id,
				'environment' => $environment,
			)
		);
		return $run_id;
	}

	public function resume( int $run_id ): void {
		$run = $this->runs->run( $run_id );
		if ( ! $run || 'failed' !== $run['status'] || 1 !== preg_match( '/^(store|catalog):([1-9][0-9]*)$/', (string) $run['resume_cursor'], $matches ) ) {
			throw new RuntimeException( 'Only a failed run with a safe resume cursor can be resumed.' );
		}
		$token = $this->runs->claim_existing_token( $run_id );
		if ( '' === $token ) {
			throw new RuntimeException( 'A catalog dry run is already active.' );
		}
		$hook       = 'store' === $matches[1] ? self::STORE_HOOK : self::CATALOG_HOOK;
		$status     = 'store' === $matches[1] ? 'scanning_store' : 'fetching_catalog';
		$generation = $this->runs->claim_generation( $run_id );
		if ( ! $this->runs->update_and_then(
			$run_id,
			$token,
			array( 'failed' ),
			array(
				'status'            => $status,
				'error_summary'     => '',
				'last_heartbeat_at' => current_time( 'mysql', true ),
			),
			fn(): bool => ! get_option( DryRunRepository::DEACTIVATING_OPTION, false ) && $this->runs->create_action_intent( $run_id, $token, $generation, 'store' === $matches[1] ? 'store' : 'catalog', $hook, (int) $matches[2] ) > 0,
			$generation
		) ) {
			throw new RuntimeException( 'The dry run could not be resumed.' );
		}
		$this->reconcile();
	}

	public function cancel( int $run_id, int $claim_generation = 0 ): bool {
		$claim = $this->runs->request_cancellation( $run_id, $claim_generation );
		if ( null === $claim ) {
			return false;
		}
		$this->reconcile();
		$run = $this->runs->run( $run_id );
		return is_array( $run ) && 'cancelled' === $run['status'];
	}

	public function recover_stale(): int {
		$run_id = $this->runs->recover_stale_active();
		if ( $run_id > 0 ) {
			$this->reconcile();
		}
		return $run_id;
	}

	public function handle_deactivation(): void {
		if ( ! $this->runs->begin_deactivation() ) {
			return;
		}
		$run_ids = array_values( array_unique( array_merge( $this->runs->active_ids(), array( $this->runs->lock_run_id() ) ) ) );
		foreach ( $run_ids as $run_id ) {
			if ( $run_id < 1 ) {
				continue;
			}
			$token      = $this->runs->claim_token( $run_id );
			$generation = $this->runs->claim_generation( $run_id );
			$this->runs->begin_failure( $run_id, $token, $generation, 'The plugin was deactivated.' );
		}
		$this->runs->mark_maintenance_intents_cancel_requested();
		$this->reconcile_cancellations();
	}

	public function purge_expired(): int {
		$deleted = $this->runs->purge_expired();
		if ( $this->runs->has_purge_remaining() ) {
			if ( $this->runs->create_maintenance_intent( 'purge', self::PURGE_HOOK ) < 1 ) {
				$this->logger->log( 'error', 'Catalog dry-run retention continuation could not be recorded.' );
			} else {
				$this->reconcile();
			}
		}
		return $deleted;
	}

	public function continue_purge( mixed $intent_id = 0, mixed $intent_token = '', mixed $run_id = 0, mixed $claim_token = '', mixed $generation = 0, mixed $hook = '', mixed $page = 0 ): void {
		$intent_id    = (int) $intent_id;
		$intent_token = (string) $intent_token;
		$run_id       = (int) $run_id;
		$claim_token  = (string) $claim_token;
		$generation   = (int) $generation;
		$hook         = (string) $hook;
		$page         = (int) $page;
		$execution    = $this->runs->claim_intent_execution( $intent_id, $intent_token, $run_id, $claim_token, $generation, 'purge', $hook, $page );
		if ( '' === $execution || 0 !== $run_id || self::PURGE_HOOK !== $hook ) {
			return;
		}
		$this->runs->purge_expired();
		if ( ! $this->runs->refresh_intent_execution( $intent_id, $intent_token, $execution ) ) {
			return;
		}
		if ( $this->runs->has_purge_remaining() ) {
			$next_generation = $generation + 1;
			if ( $this->runs->create_maintenance_intent( 'purge', self::PURGE_HOOK, $next_generation ) < 1 ) {
				$this->logger->log( 'error', 'Catalog dry-run retention continuation could not be recorded.' );
				return;
			}
		}
		if ( ! $this->runs->complete_intent_execution( $intent_id, $intent_token, $execution ) ) {
			return;
		}
		$this->reconcile();
	}

	public function store_batch( mixed $intent_id = 0, mixed $intent_token = '', mixed $run_id = 0, mixed $token = '', mixed $generation = 0, mixed $hook = '', mixed $page = 0 ): void {
		$intent_id    = (int) $intent_id;
		$intent_token = (string) $intent_token;
		$run_id       = (int) $run_id;
		$token        = (string) $token;
		$generation   = (int) $generation;
		$hook         = (string) $hook;
		$page         = (int) $page;
		$execution    = self::STORE_HOOK === $hook ? $this->runs->claim_intent_execution( $intent_id, $intent_token, $run_id, $token, $generation, 'store', $hook, $page, 'scanning_store', 'current_store_page' ) : '';
		$run          = '' !== $execution ? $this->runs->run( $run_id ) : null;
		if ( ! $run || ! $this->runs->heartbeat( $run_id, $token, $generation ) ) {
			return;
		}
		try {
			$result = $this->scanner->collect_batch( $page );
			if ( ! $this->runs->refresh_intent_execution( $intent_id, $intent_token, $execution ) ) {
				return;
			}
			$records  = $result['records'];
			$is_local = 'local' === $run['environment'];
			$more     = (bool) $result['has_more'];
			$hook     = $more ? self::STORE_HOOK : self::CATALOG_HOOK;
			$next     = $more ? $page + 1 : 1;
			$ok       = $this->runs->persist_store_page_execution(
				$run_id,
				$token,
				$generation,
				$intent_id,
				$intent_token,
				$execution,
				self::STORE_HOOK,
				$page,
				$records,
				array(
					'current_store_page'      => $page,
					'products_inspected'      => (int) $run['products_inspected'] + (int) $result['products'],
					'variations_inspected'    => (int) $run['variations_inspected'] + (int) $result['variations'],
					'store_records_inspected' => (int) $run['store_records_inspected'] + (int) $result['products'] + (int) $result['variations'],
					'resume_cursor'           => $more ? 'store:' . ( $page + 1 ) : ( $is_local ? '' : 'catalog:1' ),
					'status'                  => $more ? 'scanning_store' : ( $is_local ? 'completed' : 'fetching_catalog' ),
					'completed_at'            => $is_local && ! $more ? current_time( 'mysql', true ) : null,
					'last_heartbeat_at'       => current_time( 'mysql', true ),
				),
				$is_local && ! $more ? null : array(
					'action_type' => $more ? 'store' : 'catalog',
					'hook'        => $hook,
					'page'        => $next,
				)
			);
			if ( ! $ok ) {
				throw new RuntimeException();
			}
			if ( $is_local && ! $more ) {
				$this->runs->release_lock( $run_id, $token, $generation );
				$this->purge_expired();
			} else {
				$this->reconcile();
			}
		} catch ( \Throwable $exception ) {
			$this->fail( $run_id, $token, $generation, 'store:' . $page, $exception );
		}
	}

	public function catalog_page( mixed $intent_id = 0, mixed $intent_token = '', mixed $run_id = 0, mixed $token = '', mixed $generation = 0, mixed $hook = '', mixed $page = 0 ): void {
		$intent_id    = (int) $intent_id;
		$intent_token = (string) $intent_token;
		$run_id       = (int) $run_id;
		$token        = (string) $token;
		$generation   = (int) $generation;
		$hook         = (string) $hook;
		$page         = (int) $page;
		$execution    = self::CATALOG_HOOK === $hook ? $this->runs->claim_intent_execution( $intent_id, $intent_token, $run_id, $token, $generation, 'catalog', $hook, $page, 'fetching_catalog', 'current_api_page' ) : '';
		if ( '' === $execution || ! $this->runs->heartbeat( $run_id, $token, $generation ) ) {
			return;
		}
		try {
			$result = $this->catalog->page( $page );
			$built  = $this->build_catalog_items( $run_id, $result['products'] );
			if ( ! $this->runs->refresh_intent_execution( $intent_id, $intent_token, $execution ) ) {
				return;
			}
			$next = $result['next_page'];
			$ok   = $this->runs->persist_catalog_page_execution(
				$run_id,
				$token,
				$generation,
				$intent_id,
				$intent_token,
				$execution,
				self::CATALOG_HOOK,
				$page,
				$built['items'],
				$built['duplicates'],
				array(
					'current_api_page'  => $page,
					'resume_cursor'     => null === $next ? '' : 'catalog:' . $next,
					'status'            => null === $next ? 'completed' : 'fetching_catalog',
					'completed_at'      => null === $next ? current_time( 'mysql', true ) : null,
					'last_heartbeat_at' => current_time( 'mysql', true ),
				),
				null === $next ? null : array(
					'action_type' => 'catalog',
					'hook'        => self::CATALOG_HOOK,
					'page'        => $next,
				)
			);
			if ( ! $ok ) {
				throw new RuntimeException();
			}
			if ( null === $next ) {
				$this->runs->release_lock( $run_id, $token, $generation );
				$this->purge_expired();
			} else {
				$this->reconcile();
			}
		} catch ( \Throwable $exception ) {
			$this->fail( $run_id, $token, $generation, 'catalog:' . $page, $exception );
		}
	}

	/**
	 * @param list<array<string,mixed>> $products
	 * @return array{items:list<array<string,mixed>>,duplicates:list<string>}
	 */
	private function build_catalog_items( int $run_id, array $products ): array {
		$items      = array();
		$duplicates = array();
		$seen       = array();
		foreach ( $products as $product ) {
			foreach ( $product['sizes'] as $option ) {
				$option['ea_product_id'] = (string) ( $product['id'] ?? '' );
				$upc                     = UpcNormalizer::inspect( $option['upc'] ?? null );
				$product_id              = (string) ( $product['id'] ?? '' );
				$option_id               = (string) ( $option['id'] ?? '' );
					$stored_duplicate    = $this->runs->vendor_upc_used_by_other_option( $run_id, $upc['normalized'], $product_id, $option_id );
				if ( null === $stored_duplicate ) {
					throw new RuntimeException();
				}
					$duplicate              = $stored_duplicate || ( '' !== $upc['normalized'] && isset( $seen[ $upc['normalized'] ] ) );
				$seen[ $upc['normalized'] ] = true;
				$upc_matches                = $this->runs->identifier_matches( $run_id, $upc['normalized'], 'upc' );
				$sku_matches                = $this->runs->identifier_matches( $run_id, $upc['normalized'], 'sku' );
				$identity                   = 1 === count( $upc_matches ) ? $upc_matches[0] : ( 1 === count( $sku_matches ) ? $sku_matches[0] : null );
				$mapping                    = $identity ? $this->runs->mapping_for_store_item( (int) $identity['wc_product_id'], (int) $identity['wc_variation_id'] ) : null;
				$match                      = $this->classifier->classify( $option, $this->runs->mappings( $product_id, $option_id ), $upc_matches, $sku_matches, 'yes' === $this->settings->get( 'allow_sku_upc_match', 'no' ), $duplicate, $mapping );
				$items[]                    = array(
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
				);
				if ( $duplicate ) {
					$duplicates[] = $upc['normalized'];
				}
			}
		}
		return array(
			'items'      => $items,
			'duplicates' => $duplicates,
		);
	}

	public function reconcile(): void {
		if ( ! $this->runs->session_usable() || get_option( DryRunRepository::DEACTIVATING_OPTION, false ) ) {
			$this->reconcile_cancellations();
			return;
		}
		$this->runs->reclaim_expired_executions();
		foreach ( $this->runs->stale_dispatching_intents() as $intent ) {
			$this->reconcile_stale_dispatch( $intent );
		}
		foreach ( $this->runs->dispatchable_intents() as $intent ) {
			$this->dispatch_intent( $intent );
		}
		foreach ( $this->runs->exhausted_intents() as $intent ) {
			$this->runs->begin_failure(
				(int) $intent['run_id'],
				(string) $intent['claim_token'],
				(int) $intent['claim_generation'],
				'Catalog dry-run scheduling could not be completed.'
			);
		}
		$this->reconcile_cancellations();
	}

	/** @param array<string,mixed> $intent */
	private function dispatch_intent( array $intent ): void {
		$intent_id      = (int) $intent['id'];
		$intent_token   = (string) $intent['intent_token'];
		$dispatch_token = $this->runs->mark_intent_dispatching( $intent_id, $intent_token );
		if ( '' === $dispatch_token ) {
			return;
		}
		$args = self::intent_args( $intent );
		$id   = self::find_action( (string) $intent['hook'], $args, true );
		if ( $id < 1 ) {
			// Action Scheduler is provided by WooCommerce at runtime and may use an external store.
			$expired_lease = 'failed' === (string) $intent['status'] && '' !== (string) ( $intent['started_at'] ?? '' ) && '' === (string) ( $intent['execution_token'] ?? '' );
			// @phpstan-ignore-next-line
			$id = (int) \as_enqueue_async_action( (string) $intent['hook'], $args, self::GROUP, ! $expired_lease );
		}
		if ( $id < 1 || ! $this->runs->mark_intent_dispatched( $intent_id, $intent_token, $dispatch_token, $id ) ) {
			$this->runs->mark_intent_dispatch_failed( $intent_id, $intent_token, $dispatch_token );
		}
	}

	/** @param array<string,mixed> $intent */
	private function reconcile_stale_dispatch( array $intent ): void {
		$intent_id      = (int) $intent['id'];
		$intent_token   = (string) $intent['intent_token'];
		$dispatch_token = (string) ( $intent['dispatch_token'] ?? '' );
		$args           = self::intent_args( $intent );
		$id             = self::find_action( (string) $intent['hook'], $args, true );
		if ( $id > 0 ) {
			$this->runs->mark_intent_dispatched( $intent_id, $intent_token, $dispatch_token, $id );
			return;
		}
		$this->runs->release_expired_dispatch( $intent_id, $intent_token, $dispatch_token, (string) ( $intent['dispatch_lease_expires_at'] ?? '' ) );
	}

	public function reconcile_cancellations(): void {
		self::reconcile_repository_cancellations( $this->runs );
		foreach ( $this->runs->cleanup_runs() as $run ) {
			$run_id     = (int) $run['id'];
			$token      = (string) $run['claim_token'];
			$generation = (int) $run['claim_generation'];
			$terminal   = 'cancelling' === $run['status'] ? 'cancelled' : 'failed';
			if ( $this->runs->finalize_cleanup( $run_id, $token, $generation, $terminal ) ) {
				$this->runs->release_lock( $run_id, $token, $generation );
				$this->logger->log( 'cancelled' === $terminal ? 'info' : 'error', 'cancelled' === $terminal ? 'Catalog dry run cancelled.' : 'Catalog dry run failed.', array( 'run_id' => $run_id ) );
			}
		}
	}

	public static function reconcile_repository_cancellations( DryRunRepository $runs ): void {
		foreach ( $runs->cancellation_intents() as $intent ) {
			$args = self::intent_args( $intent );
			if ( ! self::remove_exact_actions( (string) $intent['hook'], $args ) ) {
				continue;
			}
			$runs->cancel_intent( (int) $intent['id'], (string) $intent['intent_token'] );
		}
	}

	/**
	 * @param array<string,mixed> $intent Intent row.
	 * @return list<mixed>
	 */
	private static function intent_args( array $intent ): array {
		return array(
			(int) $intent['id'],
			(string) $intent['intent_token'],
			(int) $intent['run_id'],
			(string) $intent['claim_token'],
			(int) $intent['claim_generation'],
			(string) $intent['hook'],
			(int) $intent['page_number'],
		);
	}

	/** @param array<mixed> $args */
	private static function find_action( string $hook, array $args, bool $include_in_progress = false ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => self::GROUP,
				'per_page' => DryRunRepository::ACTION_BATCH_SIZE,
				'status'   => $include_in_progress ? array( 'pending', 'in-progress' ) : array( 'pending' ),
			)
		);
		foreach ( is_array( $actions ) ? $actions : array() as $action ) {
			if ( is_object( $action ) && method_exists( $action, 'get_args' ) && $action->get_args() === $args && method_exists( $action, 'get_id' ) ) {
				return (int) $action->get_id();
			}
		}
		return 0;
	}

	/** @param array<mixed> $args */
	private static function remove_exact_actions( string $hook, array $args ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_action' ) ) {
			return false;
		}
		for ( $removed = 0; $removed < DryRunRepository::ACTION_BATCH_SIZE; ++$removed ) {
			if ( self::find_action( $hook, $args, true ) < 1 ) {
				return true;
			}
			if ( (int) as_unschedule_action( $hook, $args, self::GROUP ) < 1 ) {
				return false;
			}
		}
		return ! self::exact_action_exists( $hook, $args );
	}

	/** @param array<mixed> $args */
	private static function exact_action_exists( string $hook, array $args ): bool {
		return self::find_action( $hook, $args, true ) > 0;
	}

	private function fail( int $run_id, string $token, int $generation, string $cursor, \Throwable $exception ): void {
		$message = $exception instanceof ApiException ? $exception->getMessage() : 'Catalog dry-run processing failed.';
		if ( null !== $this->runs->begin_failure( $run_id, $token, $generation, $message, $cursor ) ) {
			$this->reconcile_cancellations();
		}
	}
}
