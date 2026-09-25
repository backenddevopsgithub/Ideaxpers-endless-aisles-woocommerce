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
		self::assertSame( 14, $calls );
		self::assertSame( Schema::VERSION, $GLOBALS['ea_test_options']['ideaxperts_ea_schema_version'] );
	}

	public function test_failed_table_verification_does_not_advance_schema_version(): void {
		$wpdb            = new MigrationWpdb( 'wp_ideaxperts_ea_logs' );
		$GLOBALS['wpdb'] = $wpdb;
		$migrator        = new Migrator( static function ( string $sql ): void {} );

		self::assertFalse( $migrator->migrate() );
		self::assertArrayNotHasKey( 'ideaxperts_ea_schema_version', $GLOBALS['ea_test_options'] );
	}

	public function test_failed_column_verification_does_not_advance_schema_version(): void {
		$wpdb            = new MigrationWpdb( '', 'last_heartbeat_at' );
		$GLOBALS['wpdb'] = $wpdb;
		$migrator        = new Migrator( static function ( string $sql ): void {} );

		self::assertFalse( $migrator->migrate() );
		self::assertArrayNotHasKey( 'ideaxperts_ea_schema_version', $GLOBALS['ea_test_options'] );
	}
}

final class MigrationWpdb {
	public string $prefix          = 'wp_';
	public string $last_error      = '';
	private string $prepared_like  = '';
	private string $last_query     = '';

	public function __construct( private readonly string $missing_table = '', private readonly string $missing_column = '' ) {}
	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4'; }
	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' ); }
	public function prepare( string $query, string $value ): string {
		$this->prepared_like = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $value );
		return $query;
	}
	public function get_var( string $query ): ?string {
		$this->last_query = $query;
		$needle           = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $this->prepared_like );
		if ( str_contains( $query, 'SHOW COLUMNS' ) ) {
			return $this->missing_column === $needle ? null : $needle;
		}
		return $this->missing_table === $needle ? null : $needle;
	}
}
