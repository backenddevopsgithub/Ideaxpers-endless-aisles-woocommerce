<?php
namespace IdeaXperts\EndlessAisles\Import;

use IdeaXperts\EndlessAisles\ProductMapping\UpcNormalizer;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/** Read-only, request-local view of the authoritative WooCommerce catalog. */
final class LiveCatalogStateProvider implements CatalogStateProviderInterface {
	/** @var array<string,array<string,mixed>>|null */
	private ?array $objects = null;

	public function __construct( private readonly SettingsRepository $settings ) {}

	/** @param array<string,mixed> $item */
	public function fingerprint( array $item, string $source_scope, string $environment, bool $force_refresh = false ): string {
		if ( ! in_array( $environment, array( 'qa', 'production' ), true ) || 'endless-aisles:' . $environment !== $source_scope ) {
			throw new \RuntimeException( 'Catalog freshness source is invalid.' );
		}
		if ( $force_refresh ) {
			$this->objects = null;
		}
		$objects      = $this->objects();
		$product_id   = (int) ( $item['target_wc_product_id'] ?? $item['wc_product_id'] ?? 0 );
		$variation_id = (int) ( $item['target_wc_variation_id'] ?? $item['wc_variation_id'] ?? 0 );
		$target_key   = $product_id . ':' . $variation_id;
		$upc          = (string) ( $item['normalized_upc'] ?? '' );
		$owners       = array();
		foreach ( $objects as $key => $object ) {
			if ( '' !== $upc && in_array( $upc, $object['upcs'], true ) ) {
				$owners[] = $key;
			}
		}
		sort( $owners, SORT_STRING );
		if ( 'production' === $environment ) {
			foreach ( $owners as $owner ) {
				if ( $owner !== $target_key ) {
					throw new \RuntimeException( 'The normalized UPC is already owned by a different WooCommerce object.' );
				}
			}
		}
		$mappings = $this->mappings(
			$source_scope,
			(string) ( $item['ea_product_id'] ?? '' ),
			(string) ( $item['ea_option_id'] ?? '' ),
			$product_id,
			$variation_id
		);
		return ApprovalManifest::hash(
			array(
				'version'      => 1,
				'source_scope' => $source_scope,
				'environment'  => $environment,
				'target'       => $objects[ $target_key ] ?? null,
				'upc'          => $upc,
				'upc_owners'   => $owners,
				'mappings'     => $mappings,
			)
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function objects(): array {
		if ( null !== $this->objects ) {
			return $this->objects;
		}
		$this->objects = array();
		for ( $page = 1; ; ++$page ) {
			// @phpstan-ignore-next-line WooCommerce is checked before services boot.
			$result   = \wc_get_products(
				array(
					'limit'    => 100,
					'page'     => $page,
					'paginate' => true,
					'return'   => 'objects',
					// @phpstan-ignore-next-line
					'status'   => array_keys( \wc_get_product_statuses() ),
				)
			);
			$products = is_object( $result ) && isset( $result->products ) && is_array( $result->products ) ? $result->products : array();
			$pages    = is_object( $result ) && isset( $result->max_num_pages ) ? max( 1, (int) $result->max_num_pages ) : $page;
			foreach ( $products as $product ) {
				if ( is_object( $product ) ) {
					$this->capture( $product, 0 );
					if ( method_exists( $product, 'get_children' ) && method_exists( $product, 'get_id' ) ) {
						foreach ( $product->get_children() as $child_id ) {
							// @phpstan-ignore-next-line
							$child = \wc_get_product( (int) $child_id );
							if ( is_object( $child ) ) {
								$this->capture( $child, (int) $product->get_id() );
							}
						}
					}
				}
			}
			if ( $page >= $pages ) {
				break;
			}
		}
		ksort( $this->objects, SORT_STRING );
		return $this->objects;
	}

	private function capture( object $product, int $parent_id ): void {
		if ( ! method_exists( $product, 'get_id' ) || ! method_exists( $product, 'get_type' ) || ! method_exists( $product, 'get_status' ) || ! method_exists( $product, 'get_sku' ) || ! method_exists( $product, 'get_meta' ) ) {
			return;
		}
		$product_id   = $parent_id > 0 ? $parent_id : (int) $product->get_id();
		$variation_id = $parent_id > 0 ? (int) $product->get_id() : 0;
		$upcs         = array();
		if ( 'yes' === $this->settings->get( 'use_global_unique_id', 'yes' ) && method_exists( $product, 'get_global_unique_id' ) ) {
			$upcs[] = UpcNormalizer::normalize( (string) $product->get_global_unique_id() );
		}
		foreach ( (array) $this->settings->get( 'upc_meta_keys', array() ) as $key ) {
			$upcs[] = UpcNormalizer::normalize( (string) $product->get_meta( $key, true ) );
		}
		$upcs = array_values( array_unique( array_filter( $upcs, static fn( string $value ): bool => '' !== $value ) ) );
		sort( $upcs, SORT_STRING );
		$this->objects[ $product_id . ':' . $variation_id ] = array(
			'wc_product_id'     => $product_id,
			'wc_variation_id'   => $variation_id,
			'product_type'      => (string) $product->get_type(),
			'product_status'    => (string) $product->get_status(),
			'parent_product_id' => $parent_id,
			'sku'               => 'yes' === $this->settings->get( 'allow_sku_upc_match', 'no' ) ? (string) $product->get_sku() : '',
			'upcs'              => $upcs,
		);
	}

	/** @return list<array<string,mixed>> */
	private function mappings( string $scope, string $product, string $option, int $wc_product, int $wc_variation ): array {
		global $wpdb;
		$table            = $wpdb->prefix . 'ideaxperts_ea_mappings';
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is derived from the trusted WordPress prefix.
				"SELECT source_scope,environment,wc_product_id,wc_variation_id,ea_product_id,ea_option_id,normalized_upc,mapping_status FROM {$table} WHERE source_scope = %s AND ((ea_product_id = %s AND ea_option_id = %s) OR (wc_product_id = %d AND wc_variation_id = %d)) ORDER BY id ASC",
				$scope,
				$product,
				$option,
				$wc_product,
				$wc_variation
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Catalog mapping freshness read failed.' );
		}
		return array_values( $rows );
	}
}
