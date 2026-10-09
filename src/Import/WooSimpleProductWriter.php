<?php
namespace IdeaXperts\EndlessAisles\Import;

defined( 'ABSPATH' ) || exit;

/** The sole WooCommerce writer, restricted to a first simple Draft save. */
final class WooSimpleProductWriter implements SimpleProductWriterInterface {
	public const OPERATION_META = '_ideaxperts_ea_create_operation';

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,string>
	 */
	private function markers( array $item ): array {
		return array(
			self::OPERATION_META            => (string) $item['operation_uuid'],
			'_ideaxperts_ea_create_scope'   => (string) $item['source_scope'],
			'_ideaxperts_ea_create_env'     => (string) $item['environment'],
			'_ideaxperts_ea_create_product' => (string) $item['ea_product_id'],
			'_ideaxperts_ea_create_option'  => (string) $item['ea_option_id'],
			'_ideaxperts_ea_create_item'    => (string) $item['id'],
			'_ideaxperts_ea_create_version' => '3c-v1',
			'_ideaxperts_ea_create_upc'     => (string) $item['normalized_upc'],
		);
	}

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,string> $projection
	 */
	public function create_draft( array $item, array $projection ): int {
		if ( 'qa' !== $item['environment'] || 'endless-aisles:qa' !== $item['source_scope'] || 'applying' !== $item['status'] || '' !== $projection['failure_code'] ) {
			throw new \RuntimeException( 'creation_not_authoritative' );
		}
		$product = new \WC_Product_Simple();
		if ( ! method_exists( $product, 'set_global_unique_id' ) ) {
			throw new \RuntimeException( 'creation_upc_api_missing' );
		}
		$product->set_status( 'draft' );
		$product->set_name( $projection['title'] );
		$product->set_description( $projection['description'] );
		$product->set_regular_price( $projection['regular_price'] );
		$product->set_global_unique_id( $projection['upc'] );
		foreach ( $this->markers( $item ) as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
		return $product->save();
	}

	/** @param array<string,mixed> $item @return list<int> */
	public function correlated_drafts( array $item, array $approved_binding = array() ): array {
		global $wpdb;
		$wpdb->last_error = '';
		$ids              = $wpdb->get_col(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress-owned table identifiers.
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key = %s AND BINARY m.meta_value = %s ORDER BY p.ID ASC LIMIT 2",
				'product',
				self::OPERATION_META,
				$item['operation_uuid']
			)
		);
		if ( ! is_array( $ids ) || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'correlation_read_failed' );
		}
		foreach ( $ids as $id ) {
			clean_post_cache( (int) $id );
			$product = wc_get_product( (int) $id );
			if ( ! $product instanceof \WC_Product_Simple || 'simple' !== $product->get_type() || 'draft' !== $product->get_status() || ! method_exists( $product, 'get_global_unique_id' ) || (string) $product->get_global_unique_id() !== (string) $item['normalized_upc'] ) {
				throw new \RuntimeException( 'correlation_object_invalid' );
			}
			if ( $approved_binding && ( ! hash_equals( (string) $approved_binding['title_hash'], hash( 'sha256', (string) $product->get_name() ) ) || ! hash_equals( (string) $approved_binding['description_hash'], hash( 'sha256', (string) $product->get_description() ) ) || (string) $approved_binding['regular_price'] !== (string) $product->get_regular_price() ) ) {
				throw new \RuntimeException( 'correlation_approved_fields_changed' );
			}
			$product->read_meta_data( true );
			foreach ( $this->markers( $item ) as $key => $value ) {
				if ( (string) $product->get_meta( $key, true ) !== $value ) {
					throw new \RuntimeException( 'correlation_metadata_invalid' );
				}
			}
		}
		return array_map( 'intval', $ids );
	}
}
