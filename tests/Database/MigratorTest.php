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
		self::assertSame( 16, $calls );
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

	public function test_existing_non_innodb_table_is_converted_before_version_advances(): void {
		$wpdb            = new MigrationWpdb( '', '', 'wp_ideaxperts_ea_dry_runs' );
		$GLOBALS['wpdb'] = $wpdb;
		self::assertTrue( ( new Migrator( static function ( string $sql ): void {} ) )->migrate() );
		self::assertContains( 'ALTER TABLE wp_ideaxperts_ea_dry_runs ENGINE=InnoDB', $wpdb->queries );
		self::assertSame( Schema::VERSION, get_option( 'ideaxperts_ea_schema_version' ) );
	}

	public function test_engine_conversion_or_verification_failure_does_not_advance_version(): void {
		$wpdb            = new MigrationWpdb( '', '', 'wp_ideaxperts_ea_dry_runs', true );
		$GLOBALS['wpdb'] = $wpdb;
		self::assertFalse( ( new Migrator( static function ( string $sql ): void {} ) )->migrate() );
		self::assertArrayNotHasKey( 'ideaxperts_ea_schema_version', $GLOBALS['ea_test_options'] );

		$GLOBALS['ea_test_options'] = array();
		$wpdb                       = new MigrationWpdb( '', '', 'wp_ideaxperts_ea_dry_runs', false, false );
		$GLOBALS['wpdb']            = $wpdb;
		self::assertFalse( ( new Migrator( static function ( string $sql ): void {} ) )->migrate() );
		self::assertArrayNotHasKey( 'ideaxperts_ea_schema_version', $GLOBALS['ea_test_options'] );
	}

	public function test_missing_outbox_index_does_not_confirm_schema(): void {
		$wpdb            = new MigrationWpdb( '', '', '', false, true, 'logical_action' );
		$GLOBALS['wpdb'] = $wpdb;

		self::assertFalse( ( new Migrator( static function ( string $sql ): void {} ) )->migrate() );
		self::assertArrayNotHasKey( 'ideaxperts_ea_schema_version', $GLOBALS['ea_test_options'] );
	}

	public function test_maybe_migrate_repairs_current_version_even_when_integrity_marker_matches(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_version']   = Schema::VERSION;
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_integrity'] = Schema::VERSION;
		$wpdb            = new MigrationWpdb( 'wp_ideaxperts_ea_logs' );
		$GLOBALS['wpdb'] = $wpdb;
		$calls           = 0;
		( new Migrator(
			static function ( string $sql ) use ( &$calls ): void {
				++$calls;
			}
		) )->maybe_migrate();
		self::assertSame( 8, $calls );
		self::assertArrayNotHasKey( 'ideaxperts_ea_schema_integrity', $GLOBALS['ea_test_options'] );
	}

	public function test_matching_marker_with_valid_schema_skips_dbdelta_and_alter(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_version']   = Schema::VERSION;
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_integrity'] = Schema::VERSION;
		$wpdb            = new MigrationWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$calls           = 0;
		( new Migrator(
			static function ( string $sql ) use ( &$calls ): void {
				++$calls;
			}
		) )->maybe_migrate();
		self::assertSame( 0, $calls );
		self::assertSame( array(), $wpdb->queries );
		self::assertSame( Schema::VERSION, get_option( 'ideaxperts_ea_schema_integrity' ) );
	}

	/** @dataProvider damagedCurrentSchemas */
	public function test_matching_marker_does_not_hide_missing_physical_integrity( string $missing_table, string $missing_column, string $missing_index ): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_version']   = Schema::VERSION;
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_integrity'] = Schema::VERSION;
		$GLOBALS['wpdb'] = new MigrationWpdb( $missing_table, $missing_column, '', false, true, $missing_index );
		$calls           = 0;
		( new Migrator(
			static function ( string $sql ) use ( &$calls ): void {
				++$calls;
			}
		) )->maybe_migrate();

		self::assertSame( 8, $calls );
		self::assertArrayNotHasKey( 'ideaxperts_ea_schema_integrity', $GLOBALS['ea_test_options'] );
	}

	/** @return array<string,array{string,string,string}> */
	public static function damagedCurrentSchemas(): array {
		return array(
			'missing table'  => array( 'wp_ideaxperts_ea_logs', '', '' ),
			'missing column' => array( '', 'execution_token', '' ),
			'missing index'  => array( '', '', 'logical_action' ),
		);
	}

	public function test_matching_marker_repairs_wrong_engine_and_restores_markers(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_version']   = Schema::VERSION;
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_integrity'] = Schema::VERSION;
		$wpdb            = new MigrationWpdb( '', '', 'wp_ideaxperts_ea_dry_run_actions' );
		$GLOBALS['wpdb'] = $wpdb;
		$calls           = 0;
		( new Migrator(
			static function ( string $sql ) use ( &$calls ): void {
				++$calls;
			}
		) )->maybe_migrate();

		self::assertSame( 8, $calls );
		self::assertContains( 'ALTER TABLE wp_ideaxperts_ea_dry_run_actions ENGINE=InnoDB', $wpdb->queries );
		self::assertSame( Schema::VERSION, get_option( 'ideaxperts_ea_schema_version' ) );
		self::assertSame( Schema::VERSION, get_option( 'ideaxperts_ea_schema_integrity' ) );
	}

	/** @dataProvider requiredTableColumns */
	public function test_matching_marker_rejects_a_missing_required_column_from_every_table( string $table, string $column ): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_version']   = Schema::VERSION;
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_integrity'] = Schema::VERSION;
		$GLOBALS['wpdb'] = new MigrationWpdb( '', $column, '', false, true, '', $table );
		$calls           = 0;
		( new Migrator(
			static function ( string $sql ) use ( &$calls ): void {
				++$calls;
			}
		) )->maybe_migrate();

		self::assertSame( 8, $calls );
		self::assertArrayNotHasKey( 'ideaxperts_ea_schema_integrity', $GLOBALS['ea_test_options'] );
	}

	/** @return array<string,array{string,string}> */
	public static function requiredTableColumns(): array {
		return array(
			'mappings'          => array( 'wp_ideaxperts_ea_mappings', 'ea_product_id' ),
			'sync runs'         => array( 'wp_ideaxperts_ea_sync_runs', 'processed_count' ),
			'logs'              => array( 'wp_ideaxperts_ea_logs', 'context' ),
			'orders'            => array( 'wp_ideaxperts_ea_order_submissions', 'idempotency_key' ),
			'dry runs'          => array( 'wp_ideaxperts_ea_dry_runs', 'current_api_page' ),
			'dry-run items'     => array( 'wp_ideaxperts_ea_dry_run_items', 'review_flags' ),
			'store identifiers' => array( 'wp_ideaxperts_ea_store_identifiers', 'identifier_source' ),
			'actions'           => array( 'wp_ideaxperts_ea_dry_run_actions', 'dispatch_token' ),
		);
	}

	/** @dataProvider malformedIndexes */
	public function test_matching_marker_rejects_missing_or_malformed_indexes( string $missing, string $table, string $malformed, array $columns, ?bool $unique ): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_version']   = Schema::VERSION;
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_integrity'] = Schema::VERSION;
		$GLOBALS['wpdb'] = new MigrationWpdb( '', '', '', false, true, $missing, '', $table, $malformed, $columns, $unique );
		$calls           = 0;
		( new Migrator(
			static function ( string $sql ) use ( &$calls ): void {
				++$calls;
			}
		) )->maybe_migrate();

		self::assertSame( 8, $calls );
		self::assertArrayNotHasKey( 'ideaxperts_ea_schema_integrity', $GLOBALS['ea_test_options'] );
	}

	/** @return array<string,array{string,string,string,list<string>,bool|null}> */
	public static function malformedIndexes(): array {
		return array(
			'missing primary'    => array( 'PRIMARY', 'wp_ideaxperts_ea_logs', '', array(), null ),
			'missing unique'     => array( 'run_option', 'wp_ideaxperts_ea_dry_run_items', '', array(), null ),
			'missing normal'     => array( 'created_at', 'wp_ideaxperts_ea_logs', '', array(), null ),
			'wrong column order' => array( '', '', 'run_option', array( 'ea_product_id', 'run_id', 'ea_option_id' ), true ),
			'wrong uniqueness'   => array( '', '', 'intent_token', array( 'intent_token' ), false ),
		);
	}

	/** @dataProvider oldVersions */
	public function test_old_versions_run_upgrade( string $version ): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_version'] = $version;
		$GLOBALS['wpdb'] = new MigrationWpdb();
		$calls           = 0;
		( new Migrator(
			static function ( string $sql ) use ( &$calls ): void {
				++$calls;
			}
		) )->maybe_migrate();
		self::assertSame( 8, $calls );
		self::assertSame( Schema::VERSION, get_option( 'ideaxperts_ea_schema_version' ) );
	}

	/** @return array<string,array{string}> */
	public static function oldVersions(): array {
		return array(
			'one'     => array( '1.0.0' ),
			'two'     => array( '2.0.0' ),
			'two-one' => array( '2.1.0' ),
			'two-two' => array( '2.2.0' ),
		);
	}
}

final class MigrationWpdb {
	public string $prefix         = 'wp_';
	public string $last_error     = '';
	private string $prepared_like = '';
	/** @var list<string> */
	private array $prepared_values = array();
	private string $last_query     = '';
	/** @var list<string> */
	public array $queries   = array();
	private bool $converted = false;

	public function __construct(
		private readonly string $missing_table = '',
		private readonly string $missing_column = '',
		private readonly string $wrong_engine_table = '',
		private readonly bool $conversion_fails = false,
		private readonly bool $conversion_sticks = true,
		private readonly string $missing_index = '',
		private readonly string $missing_column_table = '',
		private readonly string $missing_index_table = '',
		private readonly string $malformed_index = '',
		private readonly array $malformed_columns = array(),
		private readonly ?bool $malformed_unique = null
	) {}
	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4'; }
	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' ); }
	public function prepare( string $query, string ...$values ): string {
		$this->prepared_values = $values;
		$value                 = $values[0] ?? '';
		$this->prepared_like   = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $value );
		return $query;
	}
	public function get_var( string $query ): ?string {
		$this->last_query = $query;
		$needle           = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $this->prepared_like );
		if ( str_contains( $query, 'information_schema.TABLES' ) ) {
			return $this->wrong_engine_table === $needle && ( ! $this->converted || ! $this->conversion_sticks ) ? 'MyISAM' : 'InnoDB';
		}
		if ( str_contains( $query, 'information_schema.STATISTICS' ) ) {
			$index = $this->prepared_values[1] ?? '';
			return $this->missing_index === $index ? null : $index;
		}
		if ( str_contains( $query, 'SHOW COLUMNS' ) ) {
			return $this->missing_column === $needle ? null : $needle;
		}
		return $this->missing_table === $needle ? null : $needle;
	}
	/** @return list<array<string,string>> */
	public function get_results( string $query, mixed $output = null ): array {
		if ( str_contains( $query, 'information_schema.TABLES' ) ) {
			$rows = array();
			foreach ( Schema::table_names( $this->prefix ) as $table ) {
				if ( $this->missing_table === $table ) {
					continue;
				}
				$rows[] = array(
					'TABLE_NAME' => $table,
					'ENGINE'     => $this->wrong_engine_table === $table && ( ! $this->converted || ! $this->conversion_sticks ) ? 'MyISAM' : 'InnoDB',
				);
			}
			return $rows;
		}
		if ( str_contains( $query, 'information_schema.COLUMNS' ) ) {
			$rows = array();
			foreach ( Schema::required_columns( $this->prefix ) as $table => $columns ) {
				foreach ( $columns as $column ) {
					if ( $this->missing_column !== $column || ( '' !== $this->missing_column_table && $this->missing_column_table !== $table ) ) {
						$rows[] = array(
							'TABLE_NAME'  => $table,
							'COLUMN_NAME' => $column,
						);
					}
				}
			}
			return $rows;
		}
		$rows = array();
		foreach ( Schema::required_indexes( $this->prefix ) as $table => $indexes ) {
			foreach ( $indexes as $index => $definition ) {
				if ( $this->missing_index === $index && ( '' === $this->missing_index_table || $this->missing_index_table === $table ) ) {
					continue;
				}
				$columns = $this->malformed_index === $index ? $this->malformed_columns : $definition['columns'];
				$unique  = $this->malformed_index === $index && null !== $this->malformed_unique ? $this->malformed_unique : $definition['unique'];
				foreach ( $columns as $offset => $column ) {
					$rows[] = array(
						'TABLE_NAME'   => $table,
						'INDEX_NAME'   => $index,
						'NON_UNIQUE'   => $unique ? '0' : '1',
						'SEQ_IN_INDEX' => (string) ( $offset + 1 ),
						'COLUMN_NAME'  => $column,
					);
				}
			}
		}
		return $rows;
	}
	public function query( string $query ): int|false {
		$this->last_query = $query;
		$this->queries[]  = $query;
		if ( str_contains( $query, 'ALTER TABLE ' . $this->wrong_engine_table ) ) {
			if ( $this->conversion_fails ) {
				return false;
			}
			$this->converted = true;
		}
		return 0;
	}
}
