<?php
namespace IdeaXperts\EndlessAisles\Database;

use Closure;

defined( 'ABSPATH' ) || exit;

final class Migrator {
	private const VERSION_OPTION   = 'ideaxperts_ea_schema_version';
	private const INTEGRITY_OPTION = 'ideaxperts_ea_schema_integrity';

	/** @param (Closure(string):mixed)|null $db_delta Database migration runner used by tests. */
	public function __construct( private readonly ?Closure $db_delta = null ) {}

	public function maybe_migrate(): void {
		if ( Schema::VERSION === get_option( self::VERSION_OPTION, '' ) ) {
			if ( $this->verify_integrity() ) {
				update_option( self::INTEGRITY_OPTION, Schema::VERSION, false );
				return;
			}
			delete_option( self::INTEGRITY_OPTION );
		}
		$this->migrate();
	}

	public function migrate(): bool {
		global $wpdb;
		$previous = (string) get_option( self::VERSION_OPTION, '' );
		if ( '' !== $previous && version_compare( $previous, '3.0.0', '<' ) && ! $this->prepare_three_upgrade() ) {
			delete_option( self::INTEGRITY_OPTION );
			return false;
		}
		if ( null === $this->db_delta ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$runner = $this->db_delta ?? static fn( string $sql ): mixed => dbDelta( $sql );
		foreach ( Schema::definitions( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
			$runner( $sql );
			if ( isset( $wpdb->last_error ) && '' !== $wpdb->last_error ) {
				return false;
			}
		}
		foreach ( Schema::table_names( $wpdb->prefix ) as $table ) {
			$engine = $this->table_engine( $table );
			if ( null !== $engine && 'innodb' !== strtolower( $engine ) ) {
				$result = $wpdb->query( "ALTER TABLE {$table} ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internally constructed schema table name.
				if ( false === $result ) {
					delete_option( self::INTEGRITY_OPTION );
					return false;
				}
			}
		}
		if ( ! $this->verify_integrity() ) {
			delete_option( self::INTEGRITY_OPTION );
			return false;
		}
		update_option( self::VERSION_OPTION, Schema::VERSION, false );
		update_option( self::INTEGRITY_OPTION, Schema::VERSION, false );
		return true;
	}

	/**
	 * Milestone 2 catalog reads were QA-only. Explicitly normalize its populated
	 * rows before dbDelta sees the changed mapping index. DDL may auto-commit, so
	 * every step is idempotent and version advancement remains fail-closed.
	 */
	private function prepare_three_upgrade(): bool {
		global $wpdb;
		$prefix   = $wpdb->prefix;
		$mappings = $prefix . 'ideaxperts_ea_mappings';
		$columns  = array(
			array( $mappings, 'source_scope', "varchar(191) NOT NULL DEFAULT 'legacy'" ),
			array( $mappings, 'environment', "varchar(16) NOT NULL DEFAULT 'qa'" ),
			array( $prefix . 'ideaxperts_ea_dry_runs', 'source_scope', "varchar(191) NOT NULL DEFAULT 'endless-aisles:qa'" ),
			array( $prefix . 'ideaxperts_ea_dry_run_items', 'source_scope', "varchar(191) NOT NULL DEFAULT 'endless-aisles:qa'" ),
			array( $prefix . 'ideaxperts_ea_dry_run_items', 'environment', "varchar(16) NOT NULL DEFAULT 'qa'" ),
			array( $prefix . 'ideaxperts_ea_dry_run_actions', 'source_scope', "varchar(191) NOT NULL DEFAULT 'local'" ),
			array( $prefix . 'ideaxperts_ea_dry_run_actions', 'environment', "varchar(16) NOT NULL DEFAULT 'local'" ),
		);
		foreach ( $columns as $column_definition ) {
			list( $table, $column, $definition ) = $column_definition;
			if ( ! $this->column_exists( $table, $column ) && false === $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal schema identifiers and fixed definitions.
				return false;
			}
		}
		$queries = array(
			"UPDATE {$mappings} SET source_scope = 'endless-aisles:qa', environment = 'qa' WHERE source_scope IS NULL OR source_scope IN ('','legacy')",
			"UPDATE {$prefix}ideaxperts_ea_dry_runs SET source_scope = CASE WHEN environment = 'local' THEN 'local' ELSE 'endless-aisles:qa' END, environment = CASE WHEN environment = 'local' THEN 'local' ELSE 'qa' END WHERE source_scope IS NULL OR source_scope IN ('','legacy','endless-aisles:qa')",
			"UPDATE {$prefix}ideaxperts_ea_dry_run_items i INNER JOIN {$prefix}ideaxperts_ea_dry_runs r ON r.id = i.run_id SET i.source_scope = r.source_scope, i.environment = r.environment WHERE i.source_scope <> r.source_scope OR i.environment <> r.environment OR i.source_scope IS NULL OR i.environment IS NULL",
			"UPDATE {$prefix}ideaxperts_ea_dry_run_actions a INNER JOIN {$prefix}ideaxperts_ea_dry_runs r ON r.id = a.run_id SET a.source_scope = r.source_scope, a.environment = r.environment WHERE a.run_id > 0 AND (a.source_scope <> r.source_scope OR a.environment <> r.environment OR a.source_scope IS NULL OR a.environment IS NULL)",
			"UPDATE {$prefix}ideaxperts_ea_dry_run_actions SET source_scope = 'local', environment = 'local' WHERE run_id = 0 AND (source_scope <> 'local' OR environment <> 'local' OR source_scope IS NULL OR environment IS NULL)",
		);
		foreach ( $queries as $query ) {
			if ( false === $wpdb->query( $query ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed migration SQL.
				return false;
			}
		}
		$duplicates = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT source_scope,ea_product_id,ea_option_id FROM {$mappings} GROUP BY source_scope,ea_product_id,ea_option_id HAVING COUNT(*) > 1) duplicate_mappings" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $duplicates > 0 || '' !== $this->database_error() ) {
			return false;
		}
		$index = $this->index_columns( $mappings, 'ea_identity' );
		$want  = array( 'source_scope', 'ea_product_id', 'ea_option_id' );
		if ( $index !== $want ) {
			if ( array() !== $index && false === $wpdb->query( "ALTER TABLE {$mappings} DROP INDEX ea_identity" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return false;
			}
			if ( false === $wpdb->query( "ALTER TABLE {$mappings} ADD UNIQUE KEY ea_identity (source_scope,ea_product_id,ea_option_id)" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return false;
			}
		}
		$invalid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$mappings} WHERE NOT ((source_scope = 'endless-aisles:qa' AND environment = 'qa') OR (source_scope = 'endless-aisles:production' AND environment = 'production')) OR source_scope IS NULL OR environment IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 0 === $invalid && '' === $this->database_error();
	}

	private function column_exists( string $table, string $column ): bool {
		global $wpdb;
		$this->clear_database_error();
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $table, $column ) );
		return $column === (string) $found && '' === $this->database_error();
	}

	/** @return list<string> */
	private function index_columns( string $table, string $index ): array {
		global $wpdb;
		$this->clear_database_error();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX', $table, $index ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $this->database_error() ) {
			return array();
		}
		return array_values( array_map( static fn( array $row ): string => (string) $row['COLUMN_NAME'], $rows ) );
	}

	private function verify_integrity(): bool {
		global $wpdb;
		$tables      = Schema::table_names( $wpdb->prefix );
		$table_slots = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$this->clear_database_error();
		$table_rows    = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$table_slots})", ...$tables ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$table_engines = array();
		foreach ( is_array( $table_rows ) ? $table_rows : array() as $row ) {
			$table_engines[ (string) $row['TABLE_NAME'] ] = strtolower( (string) $row['ENGINE'] );
		}
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) || count( $table_engines ) !== count( $tables ) ) {
			return false;
		}
		foreach ( $tables as $table ) {
			if ( 'innodb' !== ( $table_engines[ $table ] ?? '' ) ) {
				return false;
			}
		}

		$required_columns = Schema::required_columns( $wpdb->prefix );
		$column_tables    = array_keys( $required_columns );
		$column_slots     = implode( ',', array_fill( 0, count( $column_tables ), '%s' ) );
		$this->clear_database_error();
		$column_rows = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$column_slots})", ...$column_tables ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( ! is_array( $column_rows ) || '' !== $this->database_error() ) {
			return false;
		}
		$found_columns = array();
		foreach ( $column_rows as $row ) {
			$found_columns[ (string) $row['TABLE_NAME'] ][ (string) $row['COLUMN_NAME'] ] = true;
		}
		foreach ( $required_columns as $table => $columns ) {
			foreach ( $columns as $column ) {
				if ( empty( $found_columns[ $table ][ $column ] ) ) {
					return false;
				}
			}
		}

		$required_indexes = Schema::required_indexes( $wpdb->prefix );
		$index_tables     = array_keys( $required_indexes );
		$index_slots      = implode( ',', array_fill( 0, count( $index_tables ), '%s' ) );
		$this->clear_database_error();
		$index_rows = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$index_slots}) ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX", ...$index_tables ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( ! is_array( $index_rows ) || '' !== $this->database_error() ) {
			return false;
		}
		$found_indexes = array();
		foreach ( $index_rows as $row ) {
			$table                                       = (string) $row['TABLE_NAME'];
			$index                                       = (string) $row['INDEX_NAME'];
			$found_indexes[ $table ][ $index ]['unique'] = 0 === (int) $row['NON_UNIQUE'];
			$found_indexes[ $table ][ $index ]['columns'][ (int) $row['SEQ_IN_INDEX'] ] = (string) $row['COLUMN_NAME'];
		}
		foreach ( $required_indexes as $table => $indexes ) {
			foreach ( $indexes as $index => $definition ) {
				$found = $found_indexes[ $table ][ $index ] ?? null;
				if ( ! is_array( $found ) ) {
					return false;
				}
				$columns = $found['columns'];
				ksort( $columns );
				if ( (bool) $found['unique'] !== $definition['unique'] || array_values( $columns ) !== $definition['columns'] ) {
					return false;
				}
			}
		}
		return true;
	}

	private function table_engine( string $table ): ?string {
		global $wpdb;
		$this->clear_database_error();
		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
		if ( isset( $wpdb->last_error ) && '' !== $wpdb->last_error ) {
			return null;
		}
		return is_string( $engine ) ? $engine : null;
	}

	private function clear_database_error(): void {
		global $wpdb;
		if ( property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
	}

	private function database_error(): string {
		global $wpdb;
		return (string) ( $wpdb->last_error ?? '' );
	}
}
