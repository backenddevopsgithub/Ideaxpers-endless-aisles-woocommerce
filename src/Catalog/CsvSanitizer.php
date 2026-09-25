<?php
namespace IdeaXperts\EndlessAisles\Catalog;

defined( 'ABSPATH' ) || exit;

final class CsvSanitizer {
	public static function cell( mixed $value ): string {
		$original = (string) $value;
		$inspect  = preg_replace( '/^[\s\x00-\x1F\x7F]+/u', '', $original ) ?? $original;
		return 1 === preg_match( '/^[=+\-@]/', $inspect ) ? "'" . $original : $original;
	}
}
