<?php
namespace IdeaXperts\EndlessAisles\Tests\Catalog;

use IdeaXperts\EndlessAisles\Catalog\CsvSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvSanitizerTest extends TestCase {
	#[DataProvider( 'dangerousValues' )]
	public function test_formula_prefixes_are_neutralized( string $value ): void {
		self::assertSame( "'" . $value, CsvSanitizer::cell( $value ) );
	}

	public static function dangerousValues(): array {
		return array(
			array( '=1+1' ),
			array( '+SUM(A:A)' ),
			array( '-2+3' ),
			array( '@cmd' ),
			array( '=cmd' ),
		);
	}

	public function test_leading_whitespace_and_control_prefixes_are_preserved_and_neutralized(): void {
		self::assertSame( "'=cmd", CsvSanitizer::cell( '=cmd' ) );
		self::assertSame( "' =cmd", CsvSanitizer::cell( ' =cmd' ) );
		self::assertSame( "'\t=1+1", CsvSanitizer::cell( "\t=1+1" ) );
		self::assertSame( "'\r@SUM()", CsvSanitizer::cell( "\r@SUM()" ) );
		self::assertSame( "'\n=HYPERLINK()", CsvSanitizer::cell( "\n=HYPERLINK()" ) );
		self::assertSame( "'\x1B=HYPERLINK()", CsvSanitizer::cell( "\x1B=HYPERLINK()" ) );
		self::assertSame( ' normal', CsvSanitizer::cell( ' normal' ) );
		self::assertSame( "\tplain", CsvSanitizer::cell( "\tplain" ) );
		self::assertSame( 'safe', CsvSanitizer::cell( 'safe' ) );
	}
}
