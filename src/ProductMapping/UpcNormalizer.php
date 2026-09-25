<?php
namespace IdeaXperts\EndlessAisles\ProductMapping;

defined( 'ABSPATH' ) || exit;

final class UpcNormalizer {
	public const SUPPORTED_LENGTHS = array( 8, 12, 13, 14 );

	public static function normalize( string $upc ): string {
		return preg_replace( '/[\s-]+/u', '', trim( $upc ) ) ?? '';
	}

	/** @return array{original:string,normalized:string,valid:bool,reason:string} */
	public static function inspect( mixed $identifier ): array {
		if ( is_int( $identifier ) || is_float( $identifier ) ) {
			return array(
				'original'   => (string) $identifier,
				'normalized' => '',
				'valid'      => false,
				'reason'     => 'numeric_json',
			);
		}
		$original   = is_string( $identifier ) ? $identifier : '';
		$normalized = self::normalize( $original );
		$reason     = '';
		if ( '' === $normalized ) {
			$reason = 'missing';
		} elseif ( 1 !== preg_match( '/^[0-9]+$/', $normalized ) ) {
			$reason = 'non_digit';
		} elseif ( ! in_array( strlen( $normalized ), self::SUPPORTED_LENGTHS, true ) ) {
			$reason = 'unsupported_length';
		}
		return array(
			'original'   => $original,
			'normalized' => $normalized,
			'valid'      => '' === $reason,
			'reason'     => $reason,
		);
	}
}
