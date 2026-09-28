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
