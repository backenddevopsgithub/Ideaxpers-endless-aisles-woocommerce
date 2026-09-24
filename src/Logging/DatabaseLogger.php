<?php
namespace IdeaXperts\EndlessAisles\Logging;

defined( 'ABSPATH' ) || exit;

final class DatabaseLogger {
	private const MAX_ROWS = 2000;
	private const LEVELS   = array(
		'debug'    => 10,
		'info'     => 20,
		'warning'  => 30,
		'error'    => 40,
		'critical' => 50,
	);

	/** @param array<string,mixed> $context */
	public function log( string $level, string $message, array $context = array() ): void {
		$level    = isset( self::LEVELS[ $level ] ) ? $level : 'info';
		$settings = get_option( 'ideaxperts_ea_settings', array() );
		$minimum  = is_array( $settings ) ? (string) ( $settings['log_level'] ?? 'warning' ) : 'warning';
		if ( self::LEVELS[ $level ] < ( self::LEVELS[ $minimum ] ?? 30 ) ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'ideaxperts_ea_logs',
			array(
				'level'      => $level,
				'message'    => (string) Redactor::redact( $message ),
				'context'    => wp_json_encode( Redactor::redact( $context ) ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		$this->prune();
	}

	/** @return list<object> */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		$limit = min( 100, max( 1, $limit ) );
		$table = $wpdb->prefix . 'ideaxperts_ea_logs';
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, level, message, context, created_at FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function prune(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ideaxperts_ea_logs';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$table} ORDER BY id DESC LIMIT %d) retained)", self::MAX_ROWS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
