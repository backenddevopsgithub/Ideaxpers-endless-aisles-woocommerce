<?php
namespace IdeaXperts\EndlessAisles\Import;

defined( 'ABSPATH' ) || exit;

/** Disabled unless a merchant supplies an explicit server-side direct-source and MAP approval. */
final class ConfiguredCreationPricingPolicy implements CreationPricingPolicyInterface {

	/** @param array<string,mixed> $configuration Nonsecret, business-approved configuration. */
	public function __construct( private readonly array $configuration ) {}

	public function policy_id(): string {
		return 'ea-approved-creation-pricing';
	}

	public function policy_version(): string {
		return '2';
	}

	/** @return array<string,mixed> */
	public function configuration(): array {
		$config = array_intersect_key( $this->configuration, array_flip( array( 'approval_reference', 'rule', 'source_field', 'currency', 'decimal_places', 'max_age_seconds', 'map_rule', 'zero_map_rule', 'markup_value', 'minimum_price', 'maximum_price' ) ) );
		ksort( $config, SORT_STRING );
		return $config;
	}

	public function configuration_hash(): string {
		return ApprovalManifest::hash( $this->configuration() );
	}

	/** @param array<string,mixed> $vendor */
	public function failure_code( array $vendor ): string {
		$config = $this->configuration;
		if ( ! is_string( $config['approval_reference'] ?? null ) || '' === trim( $config['approval_reference'] ) || strlen( $config['approval_reference'] ) > 128 || ! in_array( $config['rule'] ?? '', array( 'direct_source', 'percentage_markup', 'fixed_markup' ), true ) || ! in_array( $config['source_field'] ?? '', array( 'retail_price', 'wholesale_price', 'map_price' ), true ) || ! is_int( $config['decimal_places'] ?? null ) || $config['decimal_places'] < 0 || $config['decimal_places'] > 4 || ! is_int( $config['max_age_seconds'] ?? null ) || $config['max_age_seconds'] < 1 ) {
			return 'pricing_configuration_invalid';
		}
		if ( ! is_string( $config['currency'] ?? null ) || 1 !== preg_match( '/\A[A-Z]{3}\z/', $config['currency'] ) || get_option( 'woocommerce_currency', '' ) !== $config['currency'] || (int) get_option( 'woocommerce_price_num_decimals', 2 ) !== $config['decimal_places'] ) {
			return 'pricing_currency_unsupported';
		}
		if ( ! in_array( $config['map_rule'] ?? '', array( 'draft_below_map', 'ignore_map' ), true ) || ! in_array( $config['zero_map_rule'] ?? '', array( 'no_restriction', 'block' ), true ) ) {
			return 'pricing_configuration_invalid';
		}
		if ( 'direct_source' !== $config['rule'] && ( ! is_string( $config['markup_value'] ?? null ) || ! self::valid_nonnegative_decimal( $config['markup_value'] ) ) ) {
			return 'pricing_configuration_invalid';
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
		if ( 'draft_below_map' === $config['map_rule'] ) {
			$map = $vendor['map_price'] ?? null;
			if ( ! is_string( $map ) || ! preg_match( '/\A[0-9]{1,8}(?:\.[0-9]{1,4})?\z/', $map ) || strlen( explode( '.', $map )[1] ?? '' ) > $config['decimal_places'] ) {
				return 'pricing_map_invalid';
			}
			if ( strspn( $map, '0.' ) === strlen( $map ) ) {
				if ( 'block' === $config['zero_map_rule'] ) {
					return 'pricing_zero_map_review';
				}
			}
		}
		$resolved = $this->resolved_unchecked( $price );
		if ( null === $resolved ) {
			return 'pricing_configuration_invalid';
		}
		foreach ( array( 'minimum_price', 'maximum_price' ) as $bound ) {
			if ( ! isset( $config[ $bound ] ) ) {
				continue;
			}
			if ( ! is_string( $config[ $bound ] ) || ! self::valid_decimal( $config[ $bound ], $config['decimal_places'] ) ) {
				return 'pricing_configuration_invalid';
			}
			$comparison = strcmp( self::comparable( $resolved ), self::comparable( $config[ $bound ] ) );
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
		return $this->resolved_unchecked( (string) $vendor[ $this->configuration['source_field'] ] );
	}

	/** @param array<string,mixed> $vendor */
	public function map_decision( array $vendor ): string {
		if ( 'ignore_map' === ( $this->configuration['map_rule'] ?? '' ) ) {
			return 'not_evaluated';
		}
		$map = $vendor['map_price'] ?? null;
		if ( ! is_string( $map ) || ! preg_match( '/\A[0-9]{1,8}(?:\.[0-9]{1,4})?\z/', $map ) ) {
			return 'invalid';
		}
		if ( strspn( $map, '0.' ) === strlen( $map ) ) {
			return 'no_restriction';
		}
		$price = $vendor[ $this->configuration['source_field'] ?? '' ] ?? null;
		return is_string( $price ) && strcmp( self::comparable( $price ), self::comparable( $map ) ) < 0 ? 'below_map' : 'compliant';
	}

	public static function valid_decimal( string $price, int $decimals = 4 ): bool {
		return 1 === preg_match( '/\A[0-9]{1,8}(?:\.[0-9]{1,4})?\z/', $price ) && strspn( $price, '0.' ) !== strlen( $price ) && strlen( explode( '.', $price )[1] ?? '' ) <= $decimals;
	}

	private static function comparable( string $price ): string {
		$parts = explode( '.', $price );
		return str_pad( $parts[0], 8, '0', STR_PAD_LEFT ) . str_pad( $parts[1] ?? '', 4, '0' );
	}

	private static function valid_nonnegative_decimal( string $value ): bool {
		return 1 === preg_match( '/\A[0-9]{1,8}(?:\.[0-9]{1,4})?\z/', $value );
	}

	private function resolved_unchecked( string $source ): ?string {
		$decimals = (int) $this->configuration['decimal_places'];
		$factor   = 10 ** $decimals;
		$parts    = explode( '.', $source );
		$minor    = (int) $parts[0] * $factor + (int) str_pad( substr( $parts[1] ?? '', 0, $decimals ), $decimals, '0' );
		$rule     = (string) $this->configuration['rule'];
		if ( 'percentage_markup' === $rule ) {
			$markup = (float) $this->configuration['markup_value'];
			$minor  = (int) round( $minor * ( 1 + $markup / 100 ), 0, PHP_ROUND_HALF_UP );
		} elseif ( 'fixed_markup' === $rule ) {
			$value  = explode( '.', (string) $this->configuration['markup_value'] );
			$minor += (int) $value[0] * $factor + (int) str_pad( substr( $value[1] ?? '', 0, $decimals ), $decimals, '0' );
		}
		if ( $minor < 1 ) {
			return null;
		}
		return intdiv( $minor, $factor ) . ( $decimals > 0 ? '.' . str_pad( (string) ( $minor % $factor ), $decimals, '0', STR_PAD_LEFT ) : '' );
	}
}
