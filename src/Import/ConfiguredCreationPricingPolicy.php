<?php
namespace IdeaXperts\EndlessAisles\Import;

defined( 'ABSPATH' ) || exit;

/** Disabled unless a merchant supplies an explicit server-side direct-source approval. */
final class ConfiguredCreationPricingPolicy implements CreationPricingPolicyInterface {

	/** @param array<string,mixed> $configuration Nonsecret, business-approved configuration. */
	public function __construct( private readonly array $configuration ) {}

	public function policy_id(): string {
		return 'ea-approved-direct-source';
	}

	public function policy_version(): string {
		return '1';
	}

	/** @return array<string,mixed> */
	public function configuration(): array {
		$config = $this->configuration;
		ksort( $config, SORT_STRING );
		return $config;
	}

	public function configuration_hash(): string {
		return ApprovalManifest::hash( $this->configuration );
	}

	/** @param array<string,mixed> $vendor */
	public function failure_code( array $vendor ): string {
		$config = $this->configuration;
		if ( ! is_string( $config['approval_reference'] ?? null ) || '' === trim( $config['approval_reference'] ) || 'direct_source' !== ( $config['rule'] ?? '' ) || ! in_array( $config['source_field'] ?? '', array( 'retail_price', 'wholesale_price', 'map_price' ), true ) || ! is_int( $config['decimal_places'] ?? null ) || $config['decimal_places'] < 0 || $config['decimal_places'] > 4 || ! is_int( $config['max_age_seconds'] ?? null ) || $config['max_age_seconds'] < 1 ) {
			return 'pricing_configuration_invalid';
		}
		if ( ! is_string( $config['currency'] ?? null ) || 1 !== preg_match( '/\A[A-Z]{3}\z/', $config['currency'] ) || get_option( 'woocommerce_currency', '' ) !== $config['currency'] || (int) get_option( 'woocommerce_price_num_decimals', 2 ) !== $config['decimal_places'] ) {
			return 'pricing_currency_unsupported';
		}
		$observed = $vendor['source_observed_at'] ?? '';
		$stamp    = is_string( $observed ) && 1 === preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $observed ) ? strtotime( $observed . ' UTC' ) : false;
		$now      = strtotime( current_time( 'mysql', true ) . ' UTC' );
		if ( false === $stamp || false === $now || $now < $stamp || $stamp < $now - $config['max_age_seconds'] ) {
			return 'pricing_source_stale';
		}
		$price = $vendor[ $config['source_field'] ] ?? null;
		if ( null === $price || '' === $price ) {
			return 'pricing_source_missing';
		}
		if ( ! is_string( $price ) || ! self::valid_decimal( $price, $config['decimal_places'] ) ) {
			return 'pricing_source_invalid';
		}
		foreach ( array( 'minimum_price', 'maximum_price' ) as $bound ) {
			if ( ! isset( $config[ $bound ] ) ) {
				continue;
			}
			if ( ! is_string( $config[ $bound ] ) || ! self::valid_decimal( $config[ $bound ], $config['decimal_places'] ) ) {
				return 'pricing_configuration_invalid';
			}
			$comparison = strcmp( self::comparable( $price ), self::comparable( $config[ $bound ] ) );
			if ( ( 'minimum_price' === $bound && $comparison < 0 ) || ( 'maximum_price' === $bound && $comparison > 0 ) ) {
				return 'pricing_out_of_bounds';
			}
		}
		return '';
	}

	/** @param array<string,mixed> $vendor */
	public function regular_price( array $vendor ): ?string {
		if ( '' !== $this->failure_code( $vendor ) ) {
			return null;
		}
		$parts   = explode( '.', (string) $vendor[ $this->configuration['source_field'] ] );
		$integer = ltrim( $parts[0], '0' );
		return ( '' === $integer ? '0' : $integer ) . ( $this->configuration['decimal_places'] > 0 ? '.' . str_pad( $parts[1] ?? '', $this->configuration['decimal_places'], '0' ) : '' );
	}

	public static function valid_decimal( string $price, int $decimals = 4 ): bool {
		return 1 === preg_match( '/\A[0-9]{1,8}(?:\.[0-9]{1,4})?\z/', $price ) && strspn( $price, '0.' ) !== strlen( $price ) && strlen( explode( '.', $price )[1] ?? '' ) <= $decimals;
	}

	private static function comparable( string $price ): string {
		$parts = explode( '.', $price );
		return str_pad( $parts[0], 8, '0', STR_PAD_LEFT ) . str_pad( $parts[1] ?? '', 4, '0' );
	}
}
