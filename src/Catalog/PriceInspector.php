<?php
namespace IdeaXperts\EndlessAisles\Catalog;

defined( 'ABSPATH' ) || exit;

final class PriceInspector {
	/** @param array<string,mixed> $option
	 *  @return list<string>
	 */
	public static function warnings( array $option ): array {
		$warnings  = array();
		$retail    = self::number( $option, 'price', true, $warnings );
		$wholesale = self::number( $option, 'wholesale', true, $warnings );
		$map       = self::number( $option, 'minimum_advertised_price', false, $warnings );
		self::number( $option, 'msrp', false, $warnings );
		if ( null !== $retail && null !== $wholesale && $retail < $wholesale ) {
			$warnings[] = 'retail_below_wholesale';
		}
		if ( null !== $retail && null !== $map && $retail < $map ) {
			$warnings[] = 'retail_below_map';
		}
		return array_values( array_unique( $warnings ) );
	}

	/** @param array<string,mixed> $option
	 *  @param list<string> $warnings
	 */
	private static function number( array $option, string $field, bool $required, array &$warnings ): ?float {
		if ( ! array_key_exists( $field, $option ) || null === $option[ $field ] || '' === $option[ $field ] ) {
			if ( $required ) {
				$warnings[] = 'missing_' . $field;
			}
			return null;
		}
		if ( ! is_int( $option[ $field ] ) && ! is_float( $option[ $field ] ) && ! ( is_string( $option[ $field ] ) && is_numeric( $option[ $field ] ) ) ) {
			$warnings[] = 'invalid_' . $field;
			return null;
		}
		$value = (float) $option[ $field ];
		if ( $value <= 0 ) {
			$warnings[] = 'non_positive_' . $field;
		}
		return $value;
	}
}
