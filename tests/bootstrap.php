<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'AUTH_KEY', 'unit-test-auth-key-with-sufficient-entropy-0001' );
define( 'SECURE_AUTH_KEY', 'unit-test-secure-auth-key-with-entropy-0002' );
define( 'LOGGED_IN_KEY', 'unit-test-logged-in-key-with-entropy-0003' );
define( 'NONCE_KEY', 'unit-test-nonce-key-with-sufficient-entropy-0004' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['ea_test_options']       = array();
$GLOBALS['ea_scheduled']          = false;
$GLOBALS['ea_schedule_calls']     = 0;
$GLOBALS['ea_schedule_interval']  = 0;
$GLOBALS['ea_unschedule_calls']   = 0;
$GLOBALS['ea_unschedule_log']     = array();
$GLOBALS['ea_action_queue']       = array();
$GLOBALS['ea_enqueue_failure']    = false;
$GLOBALS['ea_unschedule_failure'] = false;
$GLOBALS['ea_add_option_failure'] = false;
$GLOBALS['ea_now']                = '2026-09-24 12:00:00';
$GLOBALS['ea_wc_products']        = array();
$GLOBALS['ea_wc_product_map']     = array();
$GLOBALS['ea_wc_writes']          = array();
$GLOBALS['ea_wc_max_pages']       = 1;
$GLOBALS['ea_wc_pages']           = array();
$GLOBALS['ea_wc_reads']           = array();
$GLOBALS['ea_remote_response']    = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{}',
);
$GLOBALS['ea_remote_requests']    = array();

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
	if ( ! empty( $GLOBALS['ea_add_option_failure'] ) ) {
		return false;
	}
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
	return (string) ( $GLOBALS['ea_now'] ?? '2026-09-24 12:00:00' ); }
function delete_option( string $key ): bool {
	unset( $GLOBALS['ea_test_options'][ $key ] );
	return true; }
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
	$GLOBALS['ea_scheduled']         = true;
	$GLOBALS['ea_schedule_interval'] = $interval;
	return 123;
}
function as_enqueue_async_action( string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int {
	if ( ! empty( $GLOBALS['ea_enqueue_failure'] ) ) {
		return 0;
	}
	$queue = is_array( $GLOBALS['ea_action_queue'] ) ? $GLOBALS['ea_action_queue'] : array();
	if ( $unique ) {
		foreach ( $queue as $action ) {
			if ( $action['hook'] === $hook && $action['args'] === $args && $action['group'] === $group ) {
				return (int) ( $action['id'] ?? 0 );
			}
		}
	}
	$id                           = ( count( $queue ) + 1 ) * 17;
	$GLOBALS['ea_action_queue'][] = array(
		'id'       => $id,
		'status'   => 'pending',
		'hook'     => $hook,
		'args'     => $args,
		'group'    => $group,
		'priority' => $priority,
	);
	if ( isset( $GLOBALS['ea_after_enqueue'] ) && is_callable( $GLOBALS['ea_after_enqueue'] ) ) {
		( $GLOBALS['ea_after_enqueue'] )( $GLOBALS['ea_action_queue'][ array_key_last( $GLOBALS['ea_action_queue'] ) ] );
	}
	return $id;
}
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
	++$GLOBALS['ea_unschedule_calls'];
	$GLOBALS['ea_unschedule_log'][] = array(
		'hook'  => $hook,
		'args'  => $args,
		'group' => $group,
	);
	$queue                          = is_array( $GLOBALS['ea_action_queue'] ) ? $GLOBALS['ea_action_queue'] : array();
	if ( array() === $args && '' !== $hook && '' === $group ) {
		$GLOBALS['ea_action_queue'] = array_values(
			array_filter(
				$queue,
				static fn( array $action ): bool => $action['hook'] !== $hook
			)
		);
	} else {
		$GLOBALS['ea_action_queue'] = array_values(
			array_filter(
				$queue,
				static function ( array $action ) use ( $hook, $args, $group ): bool {
					return $action['hook'] !== $hook || $action['args'] !== $args || ( '' !== $group && $action['group'] !== $group );
				}
			)
		);
	}
	if ( 'ideaxperts_ea_inventory_sync' === $hook ) {
		$GLOBALS['ea_scheduled'] = false;
	}
}
function as_unschedule_action( string $hook, array $args = array(), string $group = '' ): int {
	if ( ! empty( $GLOBALS['ea_unschedule_failure'] ) ) {
		return 0;
	}
	$queue = is_array( $GLOBALS['ea_action_queue'] ) ? $GLOBALS['ea_action_queue'] : array();
	foreach ( $queue as $index => $action ) {
		if ( $action['hook'] === $hook && $action['args'] === $args && ( '' === $group || $action['group'] === $group ) ) {
			unset( $GLOBALS['ea_action_queue'][ $index ] );
			$GLOBALS['ea_action_queue'] = array_values( $GLOBALS['ea_action_queue'] );
			return $index + 1;
		}
	}
	return 0;
}
function as_get_scheduled_actions( array $args = array(), string $return_format = 'OBJECT' ): array {
	$hook     = (string) ( $args['hook'] ?? '' );
	$group    = (string) ( $args['group'] ?? '' );
	$expected = isset( $args['args'] ) && is_array( $args['args'] ) ? $args['args'] : null;
	$statuses = $args['status'] ?? array();
	$statuses = is_array( $statuses ) ? $statuses : array( $statuses );
	$found    = array();
	foreach ( is_array( $GLOBALS['ea_action_queue'] ) ? $GLOBALS['ea_action_queue'] : array() as $action ) {
		$status = (string) ( $action['status'] ?? 'pending' );
		if ( ( '' === $hook || $action['hook'] === $hook ) && ( null === $expected || $action['args'] === $expected ) && ( '' === $group || $action['group'] === $group ) && ( array() === $statuses || in_array( $status, $statuses, true ) ) ) {
			$found[] = new class($action) {
				/** @param array<string,mixed> $action */
				public function __construct( private array $action ) {}
				public function get_id(): int {
					return (int) ( $this->action['id'] ?? 0 );
				}
				public function get_status(): string {
					return (string) ( $this->action['status'] ?? 'pending' );
				}
				public function get_hook(): string {
					return (string) ( $this->action['hook'] ?? '' );
				}
				public function get_group(): string {
					return (string) ( $this->action['group'] ?? '' );
				}
				/** @return array<mixed> */
				public function get_args(): array {
					return is_array( $this->action['args'] ?? null ) ? $this->action['args'] : array();
				}
			};
		}
	}
	return array_slice( $found, 0, max( 1, (int) ( $args['per_page'] ?? count( $found ) ?: 1 ) ) );
}

function wp_cache_delete( string $key, string $group = '' ): bool {
	return true;
}
function update_post_meta( mixed ...$args ): void {
	$GLOBALS['ea_wc_writes'][] = 'update_post_meta';
}
function delete_post_meta( mixed ...$args ): void {
	$GLOBALS['ea_wc_writes'][] = 'delete_post_meta';
}
function wp_insert_post( mixed ...$args ): int {
	$GLOBALS['ea_wc_writes'][] = 'wp_insert_post';
	return 0;
}
function wp_update_post( mixed ...$args ): int {
	$GLOBALS['ea_wc_writes'][] = 'wp_update_post';
	return 0;
}
function wc_update_product_stock( mixed ...$args ): int {
	$GLOBALS['ea_wc_writes'][] = 'wc_update_product_stock';
	return 0;
}

function wc_get_products( array $args ): object {
	$GLOBALS['ea_wc_reads'][] = array( 'wc_get_products', $args );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	$pages    = is_array( $GLOBALS['ea_wc_pages'] ?? null ) ? $GLOBALS['ea_wc_pages'] : array();
	$products = array_key_exists( $page, $pages ) && is_array( $pages[ $page ] ) ? $pages[ $page ] : ( is_array( $GLOBALS['ea_wc_products'] ) ? $GLOBALS['ea_wc_products'] : array() );
	$types    = array_map( 'strval', (array) ( $args['type'] ?? array() ) );
	if ( array() !== $types ) {
		$products = array_values( array_filter( $products, static fn( object $product ): bool => method_exists( $product, 'get_type' ) && in_array( (string) $product->get_type(), $types, true ) ) );
	}
	return (object) array(
		'products'      => $products,
		'max_num_pages' => array() !== $pages ? count( $pages ) : (int) ( $GLOBALS['ea_wc_max_pages'] ?? 1 ),
	);
}
function wc_get_product_types(): array {
	return array( 'simple' => 'Simple', 'variable' => 'Variable' );
}
function wc_get_product_statuses(): array {
	return array( 'publish' => 'Published' );
}
function wc_get_product( int $product_id ): object|false {
	$GLOBALS['ea_wc_reads'][] = array( 'wc_get_product', $product_id );
	return $GLOBALS['ea_wc_product_map'][ $product_id ] ?? false;
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
