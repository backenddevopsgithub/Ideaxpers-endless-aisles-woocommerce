<?php
namespace IdeaXperts\EndlessAisles\ProductMapping;

defined( 'ABSPATH' ) || exit;

final class UpcNormalizer {
	public static function normalize( string $upc ): string {
		return preg_replace( '/[^0-9]/', '', $upc ) ?? '';
	}
}
