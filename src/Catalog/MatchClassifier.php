<?php
namespace IdeaXperts\EndlessAisles\Catalog;

use IdeaXperts\EndlessAisles\ProductMapping\UpcNormalizer;

defined( 'ABSPATH' ) || exit;

final class MatchClassifier {
	public const CLASSIFICATIONS = array(
		'already_linked',
		'exact_upc_match',
		'exact_sku_match',
		'new_product_candidate',
		'manual_review',
	);

	public const REVIEW_FLAGS = array(
		'discontinued',
		'not_purchasable',
		'duplicate_vendor_upc',
		'duplicate_store_upc',
		'suspicious_price',
		'missing_vendor_upc',
		'invalid_vendor_upc',
		'conflicting_existing_mapping',
		'ambiguous_match',
	);

	/**
	 * @param array<string,mixed>       $option
	 * @param list<array<string,mixed>> $mappings
	 * @param list<array<string,mixed>> $upc_matches
	 * @param list<array<string,mixed>> $sku_matches
	 * @param array<string,mixed>|null  $store_mapping
	 * @return array{classification:string,reason:string,review_flags:list<string>,wc_product_id:int,wc_variation_id:int}
	 */
	public function classify( array $option, array $mappings, array $upc_matches, array $sku_matches, bool $allow_sku, bool $duplicate_vendor, ?array $store_mapping = null ): array {
		$flags = array();
		if ( ! empty( $option['discontinued'] ) ) {
			$flags[] = 'discontinued';
		}
		if ( array_key_exists( 'purchasability', $option ) && ! $option['purchasability'] ) {
			$flags[] = 'not_purchasable';
		}
		$price_warnings = PriceInspector::warnings( $option );
		if ( $price_warnings ) {
			$flags[] = 'suspicious_price';
		}
		if ( $duplicate_vendor ) {
			$flags[] = 'duplicate_vendor_upc';
		}

		if ( count( $mappings ) > 1 ) {
			return $this->result( 'manual_review', array_merge( $flags, array( 'conflicting_existing_mapping' ) ) );
		}
		if ( 1 === count( $mappings ) ) {
			$mapping    = $mappings[0];
			$ea_product = (string) ( $option['ea_product_id'] ?? '' );
			$ea_option  = (string) ( $option['id'] ?? '' );
			if ( (string) ( $mapping['ea_product_id'] ?? '' ) !== $ea_product || (string) ( $mapping['ea_option_id'] ?? '' ) !== $ea_option ) {
				return $this->result( 'manual_review', array_merge( $flags, array( 'conflicting_existing_mapping' ) ) );
			}
			return $this->result( 'already_linked', $flags, $mapping );
		}

		$upc = UpcNormalizer::inspect( $option['upc'] ?? null );
		if ( 'missing' === $upc['reason'] ) {
			$flags[] = 'missing_vendor_upc';
		} elseif ( ! $upc['valid'] ) {
			$flags[] = 'invalid_vendor_upc';
		}
		if ( count( $upc_matches ) > 1 ) {
			$flags[] = 'duplicate_store_upc';
		}

		if ( $upc['valid'] && 1 === count( $upc_matches ) ) {
			$match = $upc_matches[0];
			if ( $this->store_mapping_conflicts( $store_mapping, $option ) ) {
				return $this->result( 'manual_review', array_merge( $flags, array( 'conflicting_existing_mapping' ) ), $match );
			}
			return $this->result( 'exact_upc_match', $flags, $match );
		}
		if ( $allow_sku && count( $sku_matches ) > 1 ) {
			return $this->result( 'manual_review', array_merge( $flags, array( 'ambiguous_match' ) ) );
		}
		if ( $allow_sku && 1 === count( $sku_matches ) && $upc['valid'] ) {
			$match = $sku_matches[0];
			if ( $this->store_mapping_conflicts( $store_mapping, $option ) ) {
				return $this->result( 'manual_review', array_merge( $flags, array( 'conflicting_existing_mapping' ) ), $match );
			}
			return $this->result( 'exact_sku_match', $flags, $match );
		}
		if ( $upc['valid'] && array() === $upc_matches ) {
			return $this->result( 'new_product_candidate', $flags );
		}
		return $this->result( 'manual_review', $flags );
	}

	/**
	 * @param list<string>        $flags
	 * @param array<string,mixed> $record
	 * @return array{classification:string,reason:string,review_flags:list<string>,wc_product_id:int,wc_variation_id:int}
	 */
	private function result( string $classification, array $flags, array $record = array() ): array {
		$flags = self::sanitize_flags( $flags );
		return array(
			'classification'  => $classification,
			'reason'          => implode( ',', $flags ),
			'review_flags'    => $flags,
			'wc_product_id'   => (int) ( $record['wc_product_id'] ?? 0 ),
			'wc_variation_id' => (int) ( $record['wc_variation_id'] ?? 0 ),
		);
	}

	/** @param array<string,mixed>|null $store_mapping
	 * @param array<string,mixed>       $option
	 */
	private function store_mapping_conflicts( ?array $store_mapping, array $option ): bool {
		if ( ! $store_mapping ) {
			return false;
		}
		return (string) ( $store_mapping['ea_product_id'] ?? '' ) !== (string) ( $option['ea_product_id'] ?? '' )
			|| (string) ( $store_mapping['ea_option_id'] ?? '' ) !== (string) ( $option['id'] ?? '' );
	}

	/** @param mixed $flags
	 * @return list<string>
	 */
	public static function sanitize_flags( mixed $flags ): array {
		if ( ! is_array( $flags ) ) {
			return array();
		}
		$allowed = array();
		foreach ( $flags as $flag ) {
			if ( is_string( $flag ) && in_array( $flag, self::REVIEW_FLAGS, true ) ) {
				$allowed[ $flag ] = $flag;
			}
		}
		return array_values( $allowed );
	}
}
