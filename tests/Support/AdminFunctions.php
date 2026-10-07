<?php
// WordPress admin function doubles; redirects terminate handler execution in tests.
final class EaAdminRedirect extends Exception {}
final class EaAdminDenied extends Exception {}
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $value ): string { return esc_html( $value ); }
function esc_url( string $value ): string { return esc_html( $value ); }
function esc_html__( string $value, string $domain = '' ): string { return esc_html( $value ); }
function wp_unslash( mixed $value ): mixed { return is_string( $value ) ? stripslashes( $value ) : $value; }
function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . $path; }
function current_user_can( string $capability ): bool { return $GLOBALS['ea_admin_allowed'] ?? true; }
function get_current_user_id(): int { return 9; }
function check_admin_referer( string $action ): void {
    $GLOBALS['ea_nonce_actions'][] = $action;
    if ( ! ( $GLOBALS['ea_nonce_valid'] ?? true ) ) { throw new EaAdminDenied( 'Invalid nonce' ); }
}
function wp_die( string $message ): never { throw new EaAdminDenied( $message ); }
function wp_safe_redirect( string $url ): void { throw new EaAdminRedirect( $url ); }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function wp_nonce_field( string $action ): void { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $action ) . '">'; }
function submit_button( string $label, string $type = 'primary', string $name = 'submit', bool $wrap = true, array $attrs = array() ): void {
    echo '<button type="submit" name="' . esc_attr( $name ) . '"' . ( isset( $attrs['disabled'] ) ? ' disabled="disabled"' : '' ) . '>' . esc_html( $label ) . '</button>';
}
function wp_count_posts( string $type ): object { return (object) array( 'publish' => 2, 'draft' => 1 ); }
function wp_create_nonce( string $action ): string { return 'test-nonce-' . $action; }
function wp_verify_nonce( string $nonce, string $action ): int|false { return $GLOBALS['ea_nonce_valid'] ?? true ? ( hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false ) : false; }
function load_plugin_textdomain( mixed ...$args ): bool { return true; }
function plugin_basename( string $path ): string { return basename( $path ); }
