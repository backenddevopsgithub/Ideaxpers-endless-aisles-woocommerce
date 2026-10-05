<?php
namespace IdeaXperts\EndlessAisles\Import;

use IdeaXperts\EndlessAisles\ProductMapping\UpcNormalizer;

defined( 'ABSPATH' ) || exit;

/** No Production price is selected until an approved policy is supplied in code. */
final class SimpleProductProjection {
	public const FIELD_POLICY = 'simple-draft-fields-v1';
	public function __construct( private readonly ?CreationPricingPolicyInterface $pricing = null ) {}

	/**
	 * @param array<string,mixed> $vendor Hash-verified persisted snapshot.
	 * @return array<string,string>
	 */
	public function build( array $vendor ): array {
		$flags = $vendor['review_flags'] ?? array();
		if ( 'new_product_candidate' !== ( $vendor['classification'] ?? '' ) || ! is_array( $flags ) || array() !== $flags || 1 !== (int) ( $vendor['purchasable'] ?? 0 ) || 0 !== (int) ( $vendor['discontinued'] ?? 1 ) || '' === (string) ( $vendor['ea_product_id'] ?? '' ) || '' === (string) ( $vendor['ea_option_id'] ?? '' ) ) {
			throw new \RuntimeException( 'creation_ineligible' );
		}
		$upc = (string) ( $vendor['normalized_upc'] ?? '' );
		if ( '' === $upc || ! UpcNormalizer::inspect( $upc )['valid'] || UpcNormalizer::normalize( $upc ) !== $upc ) {
			throw new \RuntimeException( 'creation_invalid_upc' );
		}
		$title = sanitize_text_field( $this->text( $vendor['vendor_title'] ?? '', 500 ) );
		if ( '' === $title ) {
			throw new \RuntimeException( 'creation_title_missing' );
		}
		$description = wp_kses_post( $this->text( $vendor['vendor_option_description'] ?? '', 16000 ) );
		$price       = $this->pricing ? $this->pricing->regular_price( $vendor ) : null;
		if ( null !== $price && ( 1 !== preg_match( '/\A[0-9]{1,8}(?:\.[0-9]{1,4})?\z/', $price ) || (float) $price <= 0 ) ) {
			throw new \RuntimeException( 'creation_invalid_price' );
		}
		return array(
			'title'         => $title,
			'description'   => $description,
			'upc'           => $upc,
			'regular_price' => $price ?? '',
			'failure_code'  => null === $price ? 'pricing_policy_missing' : '',
		);
	}

	/**
	 * @param array<string,mixed> $vendor
	 * @param array<string,string> $desired
	 * @return array<string,string|bool>
	 */
	public function binding( array $vendor, string $environment, array $desired ): array {
		$id      = $this->pricing ? $this->pricing->policy_id() : 'missing';
		$version = $this->pricing ? $this->pricing->policy_version() : 'missing';
		$config  = $this->pricing ? $this->pricing->configuration_hash() : hash( 'sha256', 'missing' );
		if ( ! in_array( $environment, array( 'qa', 'production' ), true ) || 1 !== preg_match( '/\A[a-zA-Z0-9_.:-]{1,128}\z/', $id ) || 1 !== preg_match( '/\A[a-zA-Z0-9_.:-]{1,128}\z/', $version ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $config ) ) {
			throw new \RuntimeException( 'creation_policy_invalid' );
		}
		$fields = array_merge(
			$desired,
			array(
				'product_type'        => 'simple',
				'status'              => 'draft',
				'correlation_version' => '3c-v1',
			)
		);
		return array(
			'environment'      => $environment,
			'field_policy'     => self::FIELD_POLICY,
			'pricing_present'  => null !== $this->pricing,
			'pricing_id'       => $id,
			'pricing_version'  => $version,
			'pricing_config'   => $config,
			'vendor_hash'      => ApprovalManifest::hash( $vendor ),
			'desired_hash'     => ApprovalManifest::hash( $fields ),
			'title_hash'       => hash( 'sha256', $desired['title'] ),
			'description_hash' => hash( 'sha256', $desired['description'] ),
			'normalized_upc'   => $desired['upc'],
			'regular_price'    => $desired['regular_price'],
			'failure_code'     => $desired['failure_code'],
		);
	}

	private function text( mixed $value, int $limit ): string {
		if ( ! is_string( $value ) || strlen( $value ) > $limit || 1 !== preg_match( '//u', $value ) ) {
			throw new \RuntimeException( 'creation_invalid_content' );
		}
		return $value;
	}
}
