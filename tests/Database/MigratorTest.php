<?php
namespace IdeaXperts\EndlessAisles\Tests\Database;

use IdeaXperts\EndlessAisles\Catalog\MatchClassifier;
use IdeaXperts\EndlessAisles\Database\Migrator;
use IdeaXperts\EndlessAisles\Database\Schema;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test fixture globals and database fake intentionally share this file.
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
		self::assertSame( 30, $calls );
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
		self::assertSame( 15, $calls );
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

		self::assertSame( 15, $calls );
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

		self::assertSame( 15, $calls );
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

		self::assertSame( 15, $calls );
		self::assertArrayNotHasKey( 'ideaxperts_ea_schema_integrity', $GLOBALS['ea_test_options'] );
	}

	/** @return array<string,array{string,string}> */
	public static function requiredTableColumns(): array {
		return array(
			'mappings'           => array( 'wp_ideaxperts_ea_mappings', 'ea_product_id' ),
			'sync runs'          => array( 'wp_ideaxperts_ea_sync_runs', 'processed_count' ),
			'logs'               => array( 'wp_ideaxperts_ea_logs', 'context' ),
			'orders'             => array( 'wp_ideaxperts_ea_order_submissions', 'idempotency_key' ),
			'dry runs'           => array( 'wp_ideaxperts_ea_dry_runs', 'current_api_page' ),
			'dry-run items'      => array( 'wp_ideaxperts_ea_dry_run_items', 'review_flags' ),
			'store identifiers'  => array( 'wp_ideaxperts_ea_store_identifiers', 'identifier_source' ),
			'actions'            => array( 'wp_ideaxperts_ea_dry_run_actions', 'dispatch_token' ),
			'import runs'        => array( 'wp_ideaxperts_ea_import_runs', 'manifest_hash' ),
			'import items'       => array( 'wp_ideaxperts_ea_import_items', 'operation_uuid' ),
			'catalog identities' => array( 'wp_ideaxperts_ea_catalog_identities', 'identity_key' ),
			'UPC reservations'   => array( 'wp_ideaxperts_ea_store_identifier_reservations', 'identifier_key' ),
			'import actions'     => array( 'wp_ideaxperts_ea_import_actions', 'source_scope' ),
			'import events'      => array( 'wp_ideaxperts_ea_import_events', 'before_hash' ),
			'vendor snapshots'   => array( 'wp_ideaxperts_ea_vendor_snapshots', 'payload_hash' ),
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

		self::assertSame( 15, $calls );
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
			'import unique'      => array( 'store_identifier', 'wp_ideaxperts_ea_store_identifier_reservations', '', array(), null ),
			'import order'       => array( '', '', 'dispatch_lease', array( 'dispatch_lease_expires_at', 'status', 'id' ), false ),
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
		self::assertSame( 15, $calls );
		self::assertSame( Schema::VERSION, get_option( 'ideaxperts_ea_schema_version' ) );
	}

	/** @return array<string,array{string}> */
	public static function oldVersions(): array {
		return array(
			'one'       => array( '1.0.0' ),
			'two'       => array( '2.0.0' ),
			'two-one'   => array( '2.1.0' ),
			'two-two'   => array( '2.2.0' ),
			'two-three' => array( '2.3.0' ),
		);
	}

	public function test_populated_two_three_upgrade_backfills_rows_replaces_index_and_is_idempotent(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_version'] = '2.3.0';
		$wpdb = new MigrationWpdb();
		$wpdb->enable_legacy_upgrade_fixture();
		$GLOBALS['wpdb'] = $wpdb;
		$migrator        = new Migrator( static function ( string $sql ): void {} );
		self::assertTrue( $migrator->migrate() );
		self::assertSame( 'endless-aisles:qa', $wpdb->legacy_mappings[0]['source_scope'] );
		self::assertSame( 'qa', $wpdb->legacy_mappings[0]['environment'] );
		self::assertSame( 'endless-aisles:production', $wpdb->legacy_mappings[2]['source_scope'] );
		self::assertSame( 'production', $wpdb->legacy_mappings[2]['environment'] );
		self::assertSame( 'endless-aisles:qa', $wpdb->legacy_items[0]['source_scope'] );
		self::assertSame( 'endless-aisles:qa', $wpdb->legacy_actions[0]['source_scope'] );
		$classification = ( new MatchClassifier() )->classify(
			array(
				'ea_product_id' => 'p1',
				'id'            => 'o1',
				'upc'           => '001234567890',
			),
			array( $wpdb->legacy_mappings[0] ),
			array(),
			array(),
			false,
			false
		);
		self::assertSame( 'already_linked', $classification['classification'] );
		self::assertContains( 'ALTER TABLE wp_ideaxperts_ea_mappings DROP INDEX ea_identity', $wpdb->queries );
		self::assertContains( 'ALTER TABLE wp_ideaxperts_ea_mappings ADD UNIQUE KEY ea_identity (source_scope,ea_product_id,ea_option_id)', $wpdb->queries );
		self::assertTrue( $migrator->migrate() );
	}

	public function test_populated_upgrade_fails_closed_on_duplicate_or_backfill_failure(): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_version'] = '2.3.0';
		$duplicate = new MigrationWpdb();
		$duplicate->enable_legacy_upgrade_fixture( true );
		$GLOBALS['wpdb'] = $duplicate;
		self::assertFalse( ( new Migrator( static function ( string $sql ): void {} ) )->migrate() );
		self::assertSame( '2.3.0', get_option( 'ideaxperts_ea_schema_version' ) );

		$GLOBALS['ea_test_options']['ideaxperts_ea_schema_version'] = '2.3.0';
		$failed = new MigrationWpdb();
		$failed->enable_legacy_upgrade_fixture();
		$failed->upgrade_failure_contains = 'UPDATE wp_ideaxperts_ea_mappings';
		$GLOBALS['wpdb']                  = $failed;
		self::assertFalse( ( new Migrator( static function ( string $sql ): void {} ) )->migrate() );
		self::assertSame( '2.3.0', get_option( 'ideaxperts_ea_schema_version' ) );
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
	public array $queries                   = array();
	private bool $converted                 = false;
	public array $legacy_mappings           = array();
	public array $legacy_runs               = array();
	public array $legacy_items              = array();
	public array $legacy_actions            = array();
	public string $upgrade_failure_contains = '';
	private bool $legacy_upgrade            = false;
	private bool $legacy_index_replaced     = false;

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
	public function enable_legacy_upgrade_fixture( bool $duplicate = false ): void {
		$this->legacy_upgrade  = true;
		$this->legacy_mappings = array(
			array(
				'id'            => 1,
				'ea_product_id' => 'p1',
				'ea_option_id'  => 'o1',
			),
			array(
				'id'            => 2,
				'ea_product_id' => $duplicate ? 'p1' : 'p2',
				'ea_option_id'  => $duplicate ? 'o1' : 'o2',
				'source_scope'  => 'endless-aisles:qa',
				'environment'   => 'qa',
			),
			array(
				'id'            => 3,
				'ea_product_id' => 'p3',
				'ea_option_id'  => 'o3',
				'source_scope'  => 'endless-aisles:production',
				'environment'   => 'production',
			),
		);
		$this->legacy_runs     = array(
			array(
				'id'          => 10,
				'environment' => 'qa',
			),
		);
		$this->legacy_items    = array(
			array(
				'id'     => 20,
				'run_id' => 10,
			),
		);
		$this->legacy_actions  = array(
			array(
				'id'     => 30,
				'run_id' => 10,
			),
		);
	}
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
		if ( $this->legacy_upgrade && str_contains( $query, 'duplicate_mappings' ) ) {
			$seen = array();
			foreach ( $this->legacy_mappings as $row ) {
				$key = (string) ( $row['source_scope'] ?? '' ) . '|' . $row['ea_product_id'] . '|' . $row['ea_option_id'];
				if ( isset( $seen[ $key ] ) ) {
					return '1';
				}
				$seen[ $key ] = true;
			}
			return '0';
		}
		if ( $this->legacy_upgrade && str_contains( $query, 'FROM wp_ideaxperts_ea_mappings WHERE' ) && ! str_contains( $query, 'duplicate_mappings' ) ) {
			foreach ( $this->legacy_mappings as $row ) {
				$scope       = (string) ( $row['source_scope'] ?? '' );
				$environment = (string) ( $row['environment'] ?? '' );
				if ( ! ( 'endless-aisles:qa' === $scope && 'qa' === $environment ) && ! ( 'endless-aisles:production' === $scope && 'production' === $environment ) ) {
					return '1';
				}
			}
			return '0';
		}
		if ( $this->legacy_upgrade && str_contains( $query, 'information_schema.COLUMNS' ) ) {
			return $this->prepared_values[1] ?? '';
		}
		$needle = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $this->prepared_like );
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
		if ( $this->legacy_upgrade && str_starts_with( $query, 'SELECT COLUMN_NAME FROM information_schema.STATISTICS' ) ) {
			$columns = $this->legacy_index_replaced ? array( 'source_scope', 'ea_product_id', 'ea_option_id' ) : array( 'ea_product_id', 'ea_option_id' );
			return array_map( static fn( string $column ): array => array( 'COLUMN_NAME' => $column ), $columns );
		}
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
		if ( '' !== $this->upgrade_failure_contains && str_contains( $query, $this->upgrade_failure_contains ) ) {
			$this->last_error = 'Injected upgrade failure.';
			return false;
		}
		if ( $this->legacy_upgrade && str_starts_with( $query, 'UPDATE wp_ideaxperts_ea_mappings' ) ) {
			foreach ( $this->legacy_mappings as &$row ) {
				if ( ! isset( $row['source_scope'] ) || in_array( $row['source_scope'], array( '', 'legacy' ), true ) ) {
					$row['source_scope'] = 'endless-aisles:qa';
					$row['environment']  = 'qa';
				}
			}
			unset( $row );
			return 1;
		}
		if ( $this->legacy_upgrade && str_contains( $query, 'UPDATE wp_ideaxperts_ea_dry_runs' ) ) {
			foreach ( $this->legacy_runs as &$row ) {
				$row['source_scope'] = 'qa' === $row['environment'] ? 'endless-aisles:qa' : 'local';
			}
			unset( $row );
			return 1;
		}
		if ( $this->legacy_upgrade && str_contains( $query, 'UPDATE wp_ideaxperts_ea_dry_run_items' ) ) {
			foreach ( $this->legacy_items as &$row ) {
				$row['source_scope'] = 'endless-aisles:qa';
				$row['environment']  = 'qa';
			}
			unset( $row );
			return 1;
		}
		if ( $this->legacy_upgrade && str_contains( $query, 'UPDATE wp_ideaxperts_ea_dry_run_actions a' ) ) {
			foreach ( $this->legacy_actions as &$row ) {
				$row['source_scope'] = 'endless-aisles:qa';
				$row['environment']  = 'qa';
			}
			unset( $row );
			return 1;
		}
		if ( $this->legacy_upgrade && str_contains( $query, 'ADD UNIQUE KEY ea_identity' ) ) {
			$this->legacy_index_replaced = true;
			return 1;
		}
		if ( str_contains( $query, 'ALTER TABLE ' . $this->wrong_engine_table ) ) {
			if ( $this->conversion_fails ) {
				return false;
			}
			$this->converted = true;
		}
		return 0;
	}
}
