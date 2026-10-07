<?php
namespace IdeaXperts\EndlessAisles\Catalog;

use IdeaXperts\EndlessAisles\Database\DryRunRepository;
use IdeaXperts\EndlessAisles\ProductMapping\UpcNormalizer;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class StoreCatalogScanner {
	public const BATCH_SIZE = 50;

	public function __construct( private readonly SettingsRepository $settings, private readonly DryRunRepository $runs ) {}

	/** @return array{products:int,variations:int,missing_upcs:int,has_more:bool,total_products:int|null,total_pages:int|null} */
	public function scan_batch( int $run_id, int $page, string $claim_token = '' ): array {
		$result  = $this->collect_batch( $page );
		$records = $result['records'];
		unset( $result['records'] );
		if ( ! $this->runs->persist_store_page( $run_id, $claim_token, $records, array() ) ) {
			throw new \RuntimeException( 'Catalog dry-run processing failed.' );
		}
		return $result;
	}

	/** @return array{products:int,variations:int,missing_upcs:int,has_more:bool,total_products:int|null,total_pages:int|null,records:list<array<string,mixed>>} */
	public function collect_batch( int $page ): array {
		// WooCommerce supplies these catalog functions after the dependency gate.
		$result   = \wc_get_products(
			array(
				'limit'    => self::BATCH_SIZE,
				'page'     => max( 1, $page ),
				'paginate' => true,
				'return'   => 'objects',
				'status'   => array_keys( \wc_get_product_statuses() ),
			)
		);
		$products = is_object( $result ) && isset( $result->products ) && is_array( $result->products ) ? $result->products : array();
		$pages    = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : $page;
		$counts   = array(
			'products'       => 0,
			'variations'     => 0,
			'missing_upcs'   => 0,
			'has_more'       => $page < $pages,
			'total_products' => is_object( $result ) && isset( $result->total ) && is_numeric( $result->total ) ? max( 0, (int) $result->total ) : null,
			'total_pages'    => is_object( $result ) && isset( $result->max_num_pages ) ? max( 0, (int) $result->max_num_pages ) : null,
		);
		$records  = array();
		foreach ( $products as $product ) {
			if ( ! is_object( $product ) ) {
				continue;
			}
			++$counts['products'];
			$captured                = $this->capture( $product, 0 );
			$counts['missing_upcs'] += in_array( 'upc', array_column( $captured, 'identifier_type' ), true ) ? 0 : 1;
			$records                 = array_merge( $records, $captured );
			if ( method_exists( $product, 'get_children' ) ) {
				foreach ( array_chunk( $product->get_children(), self::BATCH_SIZE ) as $child_ids ) {
					foreach ( $child_ids as $child_id ) {
						$variation = \wc_get_product( $child_id );
						if ( $variation ) {
							++$counts['variations'];
							$captured                = $this->capture( $variation, method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0 );
							$counts['missing_upcs'] += in_array( 'upc', array_column( $captured, 'identifier_type' ), true ) ? 0 : 1;
							$records                 = array_merge( $records, $captured );
						}
					}
				}
			}
		}
		$counts['records'] = $records;
		return $counts;
	}

	/** @return list<array<string,mixed>> */
	private function capture( object $product, int $parent_id ): array {
		if ( ! method_exists( $product, 'get_id' ) || ! method_exists( $product, 'get_type' ) || ! method_exists( $product, 'get_status' ) || ! method_exists( $product, 'get_name' ) || ! method_exists( $product, 'get_meta' ) || ! method_exists( $product, 'get_sku' ) ) {
			return array();
		}
		$product_id   = $parent_id > 0 ? $parent_id : (int) $product->get_id();
		$variation_id = $parent_id > 0 ? (int) $product->get_id() : 0;
		$common       = array(
			'wc_product_id'     => $product_id,
			'wc_variation_id'   => $variation_id,
			'parent_product_id' => $parent_id,
			'product_type'      => (string) $product->get_type(),
			'product_status'    => (string) $product->get_status(),
			'title'             => (string) $product->get_name(),
		);
		$records      = array();
		if ( 'yes' === $this->settings->get( 'use_global_unique_id', 'yes' ) && method_exists( $product, 'get_global_unique_id' ) ) {
			$records = array_merge( $records, $this->identifier( $common, 'upc', 'global_unique_id', $product->get_global_unique_id() ) );
		}
		foreach ( (array) $this->settings->get( 'upc_meta_keys', array() ) as $key ) {
			$records = array_merge( $records, $this->identifier( $common, 'upc', 'meta:' . $key, $product->get_meta( $key, true ) ) );
		}
		if ( 'yes' === $this->settings->get( 'allow_sku_upc_match', 'no' ) ) {
			$records = array_merge( $records, $this->identifier( $common, 'sku', 'sku', $product->get_sku() ) );
		}
		return $records;
	}

	/**
	 * @param array<string,mixed> $common
	 * @return list<array<string,mixed>>
	 */
	private function identifier( array $common, string $type, string $source, mixed $value ): array {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return array();
		}
		$original   = (string) $value;
		$normalized = UpcNormalizer::normalize( $original );
		if ( '' === $normalized ) {
			return array();
		}
		return array(
			array_merge(
				$common,
				array(
					'identifier_type'       => $type,
					'identifier_source'     => $source,
					'original_identifier'   => $original,
					'normalized_identifier' => $normalized,
				)
			),
		);
	}
}
