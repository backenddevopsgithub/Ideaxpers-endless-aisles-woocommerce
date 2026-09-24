<?php
namespace IdeaXperts\EndlessAisles\Tests\Database;

use IdeaXperts\EndlessAisles\Database\Migrator;
use IdeaXperts\EndlessAisles\Database\Schema;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ea_test_options'] = array();
	}

	public function test_repeated_schema_creation_is_idempotent_and_records_version(): void {
		$wpdb            = new MigrationWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$calls           = 0;
		$migrator        = new Migrator(
			static function ( string $sql ) use ( &$calls ): void {
				++$calls;
			}
		);

		self::assertTrue( $migrator->migrate() );
		self::assertTrue( $migrator->migrate() );
		self::assertSame( 8, $calls );
		self::assertSame( Schema::VERSION, $GLOBALS['ea_test_options']['ideaxperts_ea_schema_version'] );
	}

	public function test_failed_table_verification_does_not_advance_schema_version(): void {
		$wpdb            = new MigrationWpdb( 'wp_ideaxperts_ea_logs' );
		$GLOBALS['wpdb'] = $wpdb;
		$migrator        = new Migrator( static function ( string $sql ): void {} );

		self::assertFalse( $migrator->migrate() );
		self::assertArrayNotHasKey( 'ideaxperts_ea_schema_version', $GLOBALS['ea_test_options'] );
	}
}

final class MigrationWpdb {
	public string $prefix          = 'wp_';
	public string $last_error      = '';
	private string $prepared_table = '';

	public function __construct( private readonly string $missing_table = '' ) {}
	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4'; }
	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' ); }
	public function prepare( string $query, string $table ): string {
		$this->prepared_table = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $table );
		return $query; }
	public function get_var( string $query ): ?string {
		return $this->missing_table === $this->prepared_table ? null : $this->prepared_table; }
}
