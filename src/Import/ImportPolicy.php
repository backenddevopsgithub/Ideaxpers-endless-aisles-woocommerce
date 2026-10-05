<?php
namespace IdeaXperts\EndlessAisles\Import;

use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;

defined( 'ABSPATH' ) || exit;

final class ImportPolicy {
	public const VERSION = '3c-v1';

	/**
	 * Derive an action exclusively from the persisted dry-run result.
	 *
	 * @param array<string,mixed> $item Persisted dry-run item.
	 * @return array{eligible:bool,automatic:bool,action:string,reason:string}
	 */
	public function evaluate( array $item, bool $manual_resolution = false ): array {
		$classification = (string) ( $item['classification'] ?? '' );
		$flags          = MatchClassifier::sanitize_flags( json_decode( (string) ( $item['review_flags'] ?? '[]' ), true ) );
		$blocking       = array( 'missing_vendor_upc', 'invalid_vendor_upc', 'duplicate_vendor_upc', 'duplicate_store_upc', 'conflicting_existing_mapping', 'ambiguous_match' );
		if ( array_intersect( $flags, $blocking ) ) {
			return $this->result( false, false, 'blocked', 'blocking_review_flag' );
		}
		if ( 'manual_review' === $classification ) {
			return $this->result( false, false, 'blocked', 'manual_review' );
		}
		if ( in_array( 'discontinued', $flags, true ) ) {
			return 'already_linked' === $classification
				? $this->result( true, true, 'validate', 'linked_discontinued' )
				: $this->result( true, true, 'skip', 'unmapped_discontinued' );
		}
		if ( 'already_linked' === $classification ) {
			return $this->result( true, true, 'validate', 'already_linked' );
		}
		if ( 'new_product_candidate' === $classification ) {
			if ( array() !== $flags || ( isset( $item['purchasable'] ) && 1 !== (int) $item['purchasable'] ) || ! empty( $item['discontinued'] ) ) {
				return $this->result( false, false, 'blocked', 'explicit_review_required' );
			}
			return $this->result( true, true, 'create', 'safe_new_candidate' );
		}
		if ( in_array( $classification, array( 'exact_upc_match', 'exact_sku_match' ), true ) ) {
			$target = (int) ( $item['wc_product_id'] ?? 0 ) > 0 || (int) ( $item['wc_variation_id'] ?? 0 ) > 0;
			return $manual_resolution && $target
				? $this->result( true, false, 'link', 'explicit_existing_match' )
				: $this->result( false, false, 'blocked', 'manual_match_approval_required' );
		}
		return $this->result( false, false, 'blocked', 'unsupported_classification' );
	}

	/** @return array{eligible:bool,automatic:bool,action:string,reason:string} */
	private function result( bool $eligible, bool $automatic, string $action, string $reason ): array {
		return compact( 'eligible', 'automatic', 'action', 'reason' );
	}
}
