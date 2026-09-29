<?php
namespace IdeaXperts\EndlessAisles\Import;

use IdeaXperts\EndlessAisles\ProductMapping\UpcNormalizer;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/** Read-only, bounded-memory view of authoritative WooCommerce catalog state. */
final class LiveCatalogStateProvider implements CatalogStateProviderInterface {
	private const PAGE_SIZE = 100;

	public function __construct( private readonly SettingsRepository $settings ) {}

	/** @param array<string,mixed> $item */
	public function fingerprint( array $item, string $source_scope, string $environment, bool $force_refresh = false ): string {
		if ( ! in_array( $environment, array( 'qa', 'production' ), true ) || 'endless-aisles:' . $environment !== $source_scope ) {
			throw new \RuntimeException( 'Catalog freshness source is invalid.' );
		}
		// Reads are deliberately uncached. A forced refresh therefore always observes
		// the same scoped sources without rebuilding a complete catalog snapshot.
		unset( $force_refresh );
		$product_id   = (int) ( $item['target_wc_product_id'] ?? $item['wc_product_id'] ?? 0 );
		$variation_id = (int) ( $item['target_wc_variation_id'] ?? $item['wc_variation_id'] ?? 0 );
		$target_key   = $product_id . ':' . $variation_id;
		$upc          = (string) ( $item['normalized_upc'] ?? '' );
		$target       = $this->target( $product_id, $variation_id );
		$owners       = '' === $upc ? array() : $this->upc_owners( $upc );
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
				'target'       => $target,
				'upc'          => $upc,
				'upc_owners'   => $owners,
				'mappings'     => $mappings,
			)
		);
	}

	/** @return array<string,mixed>|null */
	private function target( int $product_id, int $variation_id ): ?array {
		$object_id = $variation_id > 0 ? $variation_id : $product_id;
		if ( $object_id < 1 ) {
			return null;
		}
		// @phpstan-ignore-next-line WooCommerce is checked before services boot.
		$product = \wc_get_product( $object_id );
		if ( ! is_object( $product ) ) {
			return null;
		}
		$parent_id = $variation_id > 0 && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		return $this->capture( $product, $parent_id );
	}

	/** @return list<string> */
	private function upc_owners( string $upc ): array {
		$owners = array();
		// Products and variations are separate bounded queries so one variable
		// product cannot materialize its complete child-ID collection.
		// @phpstan-ignore-next-line WooCommerce is checked before services boot.
		$type_groups = array( array_keys( \wc_get_product_types() ), array( 'variation' ) );
		foreach ( $type_groups as $types ) {
			for ( $page = 1; ; ++$page ) {
				// @phpstan-ignore-next-line WooCommerce is checked before services boot.
				$result   = \wc_get_products(
					array(
						'limit'    => self::PAGE_SIZE,
						'page'     => $page,
						'paginate' => true,
						'return'   => 'objects',
						'type'     => $types,
						// @phpstan-ignore-next-line
						'status'   => array_keys( \wc_get_product_statuses() ),
					)
				);
				$products = is_object( $result ) && isset( $result->products ) && is_array( $result->products ) ? $result->products : array();
				$pages    = is_object( $result ) && isset( $result->max_num_pages ) ? max( 1, (int) $result->max_num_pages ) : $page;
				foreach ( $products as $product ) {
					if ( is_object( $product ) ) {
						$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
						$this->record_owner( $owners, $product, $parent_id, $upc );
					}
				}
				if ( $page >= $pages ) {
					break;
				}
			}
		}
		$keys = array_keys( $owners );
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/** @param array<string,true> $owners */
	private function record_owner( array &$owners, object $product, int $parent_id, string $upc ): void {
		$captured = $this->capture( $product, $parent_id );
		if ( null !== $captured && in_array( $upc, $captured['upcs'], true ) ) {
			$owners[ $captured['wc_product_id'] . ':' . $captured['wc_variation_id'] ] = true;
		}
	}

	/** @return array<string,mixed>|null */
	private function capture( object $product, int $parent_id ): ?array {
		if ( ! method_exists( $product, 'get_id' ) || ! method_exists( $product, 'get_type' ) || ! method_exists( $product, 'get_status' ) || ! method_exists( $product, 'get_sku' ) || ! method_exists( $product, 'get_meta' ) ) {
			return null;
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
		return array(
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
