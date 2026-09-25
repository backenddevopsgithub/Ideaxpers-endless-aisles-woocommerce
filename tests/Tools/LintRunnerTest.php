<?php
declare(strict_types=1);

namespace IdeaXperts\EndlessAisles\Tests\Tools;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname( __DIR__, 2 ) . '/tools/lint.php';

final class LintRunnerTest extends TestCase {
	private string $fixture_root;

	protected function setUp(): void {
		$this->fixture_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ideaxperts-ea-lint-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->fixture_root . '/src/cache', 0777, true ) );
		self::assertTrue( mkdir( $this->fixture_root . '/tests/generated', 0777, true ) );
		self::assertTrue( mkdir( $this->fixture_root . '/tools/vendor', 0777, true ) );

		$this->writeFixture( 'ideaxperts-endless-aisles.php' );
		$this->writeFixture( 'src/Zeta.php' );
		$this->writeFixture( 'src/Alpha.php' );
		$this->writeFixture( 'tests/Example.php' );
		$this->writeFixture( 'tools/lint.php' );
		$this->writeFixture( 'src/cache/Cached.php' );
		$this->writeFixture( 'tests/generated/Generated.php' );
		$this->writeFixture( 'tools/vendor/Dependency.php' );
		$this->writeFixture( 'src/Proxy.generated.php' );
		file_put_contents( $this->fixture_root . '/src/README.txt', 'not PHP' );
	}

	protected function tearDown(): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->fixture_root, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $this->fixture_root );
	}

	public function test_collects_sorted_php_files_and_excludes_non_source_output(): void {
		$files = array_map(
			fn( string $file ): string => str_replace( '\\', '/', substr( $file, strlen( $this->fixture_root ) + 1 ) ),
			\ideaxperts_ea_lint_collect_files( $this->fixture_root )
		);

		self::assertSame(
			array(
				'ideaxperts-endless-aisles.php',
				'src/Alpha.php',
				'src/Zeta.php',
				'tests/Example.php',
				'tools/lint.php',
			),
			$files
		);
		self::assertSame( 1, count( array_keys( $files, 'tools/lint.php', true ) ) );
	}

	private function writeFixture( string $relative_path ): void {
		$path      = $this->fixture_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) ) {
			self::assertTrue( mkdir( $directory, 0777, true ) );
		}
		self::assertNotFalse( file_put_contents( $path, "<?php\n" ) );
	}
}
