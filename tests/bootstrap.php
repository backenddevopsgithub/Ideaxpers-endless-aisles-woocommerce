<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'AUTH_KEY', 'unit-test-auth-key-with-sufficient-entropy-0001' );
define( 'SECURE_AUTH_KEY', 'unit-test-secure-auth-key-with-entropy-0002' );
define( 'LOGGED_IN_KEY', 'unit-test-logged-in-key-with-entropy-0003' );
define( 'NONCE_KEY', 'unit-test-nonce-key-with-sufficient-entropy-0004' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['ea_test_options']    = array();
$GLOBALS['ea_scheduled']       = false;
$GLOBALS['ea_schedule_calls']  = 0;
$GLOBALS['ea_schedule_interval'] = 0;
$GLOBALS['ea_remote_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{}',
);
$GLOBALS['ea_remote_requests'] = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private readonly string $code, private readonly string $message = '' ) {}
		public function get_error_code(): string {
			return $this->code; }
		public function get_error_message(): string {
			return $this->message; }
	}
}

function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' ); }
function sanitize_email( string $value ): string {
	return filter_var( $value, FILTER_SANITIZE_EMAIL ) ?: ''; }
function absint( mixed $value ): int {
	return abs( (int) $value ); }
function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['ea_test_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = false ): bool {
	$GLOBALS['ea_test_options'][ $key ] = $value;
	return true; }
function add_option( string $key, mixed $value, string $deprecated = '', bool $autoload = true ): bool {
	if ( array_key_exists( $key, $GLOBALS['ea_test_options'] ) ) {
		return false;
	} $GLOBALS['ea_test_options'][ $key ] = $value;
	return true; }
function wp_parse_args( array $args, array $defaults = array() ): array {
	return array_merge( $defaults, $args ); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return $value; }
function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ); }
function __( string $value, string $domain = 'default' ): string {
	return $value; }
function current_time( string $type, bool $gmt = false ): string {
	return '2026-09-24 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
	return json_encode( $value, $flags ); }
function wp_safe_remote_request( string $url, array $args ): array|WP_Error {
	$GLOBALS['ea_remote_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	return $GLOBALS['ea_remote_response']; }
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' ); }
function wp_remote_retrieve_header( array $response, string $header ): string {
	$headers = $response['headers'] ?? array();
	if ( ! is_array( $headers ) ) {
		return '';
	}
	foreach ( $headers as $name => $value ) {
		if ( strtolower( (string) $name ) === strtolower( $header ) ) {
			return (string) $value;
		}
	}
	return '';
}
function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ): int|false {
	return $GLOBALS['ea_scheduled'] ? 123 : false; }
function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '', bool $unique = false ): int {
	++$GLOBALS['ea_schedule_calls'];
	$GLOBALS['ea_scheduled']        = true;
	$GLOBALS['ea_schedule_interval'] = $interval;
	return 123;
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
