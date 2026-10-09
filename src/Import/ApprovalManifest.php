<?php
namespace IdeaXperts\EndlessAisles\Import;

use RuntimeException;
use IdeaXperts\EndlessAisles\ProductMapping\UpcNormalizer;

defined( 'ABSPATH' ) || exit;

final class ApprovalManifest {
	public const MAX_ITEMS         = 100000;
	public const MAX_QA_CREATIONS  = 5;
	public const MAX_QA_SOURCE_AGE = 86400;

	public function __construct( private readonly ImportPolicy $policy, private readonly ?CatalogStateProviderInterface $catalog_state = null, private readonly SimpleProductProjection $projection = new SimpleProductProjection() ) {}

	/**
	 * @param array<string,mixed>       $dry_run
	 * @param list<array<string,mixed>> $items
	 * @param array<int,bool>           $manual_resolutions Keys are persisted dry-run item IDs.
	 * @param array<string,mixed>        $matching_settings
	 * @return array{manifest:array<string,mixed>,hash:string,matching_settings_hash:string}
	 */
	public function build( array $dry_run, array $items, array $manual_resolutions, int $approval_generation, array $matching_settings ): array {
		$environment = (string) ( $dry_run['environment'] ?? '' );
		if ( 'completed' !== (string) ( $dry_run['status'] ?? '' ) || ! in_array( $environment, array( 'qa', 'production' ), true ) || $approval_generation < 1 ) {
			throw new RuntimeException( 'Only a completed, scoped dry run can be approved.' );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			throw new RuntimeException( 'The approval selection exceeds the safety limit.' );
		}
		$controlled = true === ( $matching_settings['controlled_qa_creation'] ?? false );
		if ( $controlled && ( 'qa' !== $environment || 'endless-aisles:qa' !== ( $dry_run['source_scope'] ?? '' ) || ! self::qa_source_fresh( (string) ( $dry_run['completed_at'] ?? '' ) ) ) ) {
			throw new RuntimeException( 'Controlled creation requires a fresh completed QA dry run.' );
		}
		$creation_count = 0;
		$identities     = array();
		$upcs           = array();
		$manifest_items = array();
		foreach ( $items as $item ) {
			$item_id           = (int) ( $item['id'] ?? 0 );
			$manual_resolution = isset( $manual_resolutions[ $item_id ] ) && true === $manual_resolutions[ $item_id ];
			$decision          = $this->policy->evaluate( $item, $manual_resolution );
			if ( 1 > $item_id || ! $decision['eligible'] ) {
				throw new RuntimeException( 'The selection contains an item that is not eligible for its requested approval mode.' );
			}
			$classification = (string) ( $item['classification'] ?? '' );
			$vendor         = array(
				'ea_product_id'             => (string) ( $item['ea_product_id'] ?? '' ),
				'ea_option_id'              => (string) ( $item['ea_option_id'] ?? '' ),
				'normalized_upc'            => (string) ( $item['normalized_upc'] ?? '' ),
				'classification'            => $classification,
				'vendor_sku'                => 'exact_sku_match' === $classification ? UpcNormalizer::normalize( (string) ( $item['normalized_upc'] ?? '' ) ) : '',
				'review_flags'              => json_decode( (string) ( $item['review_flags'] ?? '[]' ), true ),
				'retail_price'              => (string) ( $item['retail_price'] ?? '' ),
				'wholesale_price'           => (string) ( $item['wholesale_price'] ?? '' ),
				'map_price'                 => (string) ( $item['map_price'] ?? '' ),
				'source_observed_at'        => (string) ( $item['created_at'] ?? '' ),
				'purchasable'               => (int) ( $item['purchasable'] ?? 0 ),
				'discontinued'              => (int) ( $item['discontinued'] ?? 0 ),
				'vendor_title'              => (string) ( $item['vendor_title'] ?? '' ),
				'vendor_option_description' => (string) ( $item['vendor_option_description'] ?? '' ),
			);
			if ( $controlled && 'create' === $decision['action'] ) {
				if ( ++$creation_count > self::MAX_QA_CREATIONS || ( $item['environment'] ?? '' ) !== 'qa' || ( $item['source_scope'] ?? '' ) !== 'endless-aisles:qa' ) {
					throw new RuntimeException( 'Controlled creation selection exceeds its QA safety boundary.' );
				}
				$key = self::hash( array( $vendor['ea_product_id'], $vendor['ea_option_id'] ) );
				if ( isset( $identities[ $key ] ) || isset( $upcs[ $vendor['normalized_upc'] ] ) ) {
					throw new RuntimeException( 'Duplicate creation identity or UPC in selection.' );
				}
				$identities[ $key ]                = true;
				$upcs[ $vendor['normalized_upc'] ] = true;
				$vendor['creation_mode']           = 'controlled_qa';
			}
			$source_scope = 'endless-aisles:' . $environment;
			if ( ! $this->catalog_state ) {
				throw new RuntimeException( 'Live catalog state is required for approval.' );
			}
			$inspection = $this->catalog_state->inspect( $item, $source_scope, $environment );
			if ( 'create' === $decision['action'] && ( null !== $inspection['target'] || array() !== $inspection['upc_owners'] || array() !== $inspection['sku_owners'] || array() !== $inspection['mappings'] ) ) {
				throw new RuntimeException( 'New creation requires current mapping and UPC absence.' );
			}
			if ( 'link' === $decision['action'] && (int) ( $item['wc_variation_id'] ?? 0 ) > 0 && ! LiveCatalogStateProvider::valid_variation_parent( $inspection['target'], (int) ( $item['wc_product_id'] ?? 0 ) ) ) {
				throw new RuntimeException( 'Variation approval requires an existing variable parent.' );
			}
			$local            = array(
				'wc_product_id'     => (int) ( $item['wc_product_id'] ?? 0 ),
				'wc_variation_id'   => (int) ( $item['wc_variation_id'] ?? 0 ),
				'approved_state'    => $inspection['target'],
				'approved_mappings' => $inspection['mappings'],
			);
			$live_hash        = $inspection['fingerprint'];
			$creation_binding = 'create' === $decision['action'] ? $this->projection->binding( $vendor, $environment, $this->projection->build( $vendor ) ) : array();
			$manifest_items[] = array(
				'dry_run_item_id'       => $item_id,
				'creation_binding'      => $creation_binding,
				'action'                => $decision['action'],
				'entity_kind'           => (int) $local['wc_variation_id'] > 0 ? 'variation' : 'option',
				'group_key'             => hash( 'sha256', $environment . "\0" . $vendor['ea_product_id'] ),
				'vendor'                => $vendor,
				'target'                => $local,
				'expected_vendor_hash'  => self::hash( $vendor ),
				'expected_local_hash'   => self::hash( $local ),
				'expected_mapping_hash' => self::hash(
					array(
						'product' => $vendor['ea_product_id'],
						'option'  => $vendor['ea_option_id'],
						'target'  => $local,
					)
				),
				'expected_live_hash'    => $live_hash,
			);
		}
		usort( $manifest_items, static fn( array $a, array $b ): int => $a['dry_run_item_id'] <=> $b['dry_run_item_id'] );
		$settings_hash = self::hash( $matching_settings );
		$manifest      = array(
			'version'                => 1,
			'policy_version'         => ImportPolicy::VERSION,
			'dry_run_id'             => (int) $dry_run['id'],
			'dry_run_generation'     => (int) ( $dry_run['claim_generation'] ?? 1 ),
			'environment'            => $environment,
			'source_scope'           => 'endless-aisles:' . $environment,
			'approval_generation'    => $approval_generation,
			'matching_settings_hash' => $settings_hash,
			'items'                  => $manifest_items,
		);
		if ( $controlled ) {
			$manifest['creation_mode']       = 'controlled_qa';
			$manifest['source_completed_at'] = (string) $dry_run['completed_at'];
		}
		return array(
			'manifest'               => $manifest,
			'hash'                   => self::hash( $manifest ),
			'matching_settings_hash' => $settings_hash,
		);
	}

	public static function qa_source_fresh( string $timestamp ): bool {
		$stamp = 1 === preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $timestamp ) ? strtotime( $timestamp . ' UTC' ) : false;
		$now   = strtotime( current_time( 'mysql', true ) . ' UTC' );
		return '' !== $timestamp && false !== $stamp && false !== $now && $stamp <= $now && $stamp >= $now - self::MAX_QA_SOURCE_AGE;
	}

	/** @param array<string,mixed> $manifest */
	public function verify( array $manifest, string $hash ): bool {
		return 64 === strlen( $hash ) && hash_equals( self::hash( $manifest ), $hash );
	}

	/** @param mixed $value */
	public static function hash( mixed $value ): string {
		$canonical = self::canonicalize( $value );
		$json      = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Approval data could not be canonicalized.' );
		}
		return hash( 'sha256', $json );
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::canonicalize( $child );
		}
		return $value;
	}
}
