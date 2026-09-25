<?php
namespace IdeaXperts\EndlessAisles\Database;

use Closure;

defined( 'ABSPATH' ) || exit;

final class Migrator {
	private const VERSION_OPTION = 'ideaxperts_ea_schema_version';

	/** @param (Closure(string):mixed)|null $db_delta Database migration runner used by tests. */
	public function __construct( private readonly ?Closure $db_delta = null ) {}

	public function maybe_migrate(): void {
		if ( Schema::VERSION !== get_option( self::VERSION_OPTION, '' ) ) {
			$this->migrate();
		}
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
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ( isset( $wpdb->last_error ) && '' !== $wpdb->last_error ) || $table !== $found ) {
				return false;
			}
		}
		foreach ( Schema::required_columns( $wpdb->prefix ) as $table => $columns ) {
			foreach ( $columns as $column ) {
				$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $wpdb->esc_like( $column ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come only from Schema::required_columns().
				if ( ( isset( $wpdb->last_error ) && '' !== $wpdb->last_error ) || $column !== $found ) {
					return false;
				}
			}
		}
		update_option( self::VERSION_OPTION, Schema::VERSION, false );
		return true;
	}
}
