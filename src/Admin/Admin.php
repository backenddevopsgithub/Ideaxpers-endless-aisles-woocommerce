<?php
namespace IdeaXperts\EndlessAisles\Admin;

use IdeaXperts\EndlessAisles\API\ConnectionTester;
use IdeaXperts\EndlessAisles\Database\Schema;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Logging\Redactor;
use IdeaXperts\EndlessAisles\Scheduling\InventoryScheduler;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Admin {
	private const CAPABILITY = 'manage_woocommerce';

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly DatabaseLogger $logger,
		private readonly InventoryScheduler $scheduler,
		private readonly ConnectionTester $connection_tester
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_ideaxperts_ea_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_ideaxperts_ea_test_connection', array( $this, 'test_connection' ) );
	}

	public function test_connection(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to test Endless Aisles settings.', 'ideaxperts-endless-aisles' ) );
		}
		check_admin_referer( 'ideaxperts_ea_test_connection' );
		$result = $this->connection_tester->test();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'ideaxperts-endless-aisles',
					'connection' => $result->status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Endless Aisles', 'ideaxperts-endless-aisles' ),
			__( 'Endless Aisles', 'ideaxperts-endless-aisles' ),
			self::CAPABILITY,
			'ideaxperts-endless-aisles',
			array( $this, 'render' )
		);
	}

	public function save_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Endless Aisles settings.', 'ideaxperts-endless-aisles' ) );
		}
		check_admin_referer( 'ideaxperts_ea_save_settings' );
		$raw    = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result = 'saved';
		try {
			$this->settings->save( $raw );
			InventoryScheduler::unschedule_all();
			$this->scheduler->ensure_scheduled();
		} catch ( RuntimeException $exception ) {
			$result = 'encryption_error';
			$this->logger->log( 'error', 'Settings could not be saved because credential encryption failed.', array( 'error' => $exception->getMessage() ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'ideaxperts-endless-aisles',
					'updated' => $result,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! in_array( $tab, array( 'settings', 'status', 'logs' ), true ) ) {
			$tab = 'settings';
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'IdeaXperts Endless Aisles', 'ideaxperts-endless-aisles' ) . '</h1>';
		$this->render_notices();
		$this->render_tabs( $tab );
		if ( 'status' === $tab ) {
			$this->render_status();
		} elseif ( 'logs' === $tab ) {
			$this->render_logs();
		} else {
			$this->render_settings();
		}
		echo '</div>';
	}

	private function render_notices(): void {
		if ( isset( $_GET['updated'] ) ) {
			$updated = sanitize_key( wp_unslash( $_GET['updated'] ) );
			if ( 'saved' === $updated ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Endless Aisles settings saved.', 'ideaxperts-endless-aisles' ) . '</p></div>';
			} elseif ( 'encryption_error' === $updated ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Settings were not saved because secure credential encryption is unavailable. Verify WordPress salts and OpenSSL support.', 'ideaxperts-endless-aisles' ) . '</p></div>';
			}
		}
		if ( isset( $_GET['connection'] ) ) {
			$status  = sanitize_key( wp_unslash( $_GET['connection'] ) );
			$success = 'connected' === $status;
			$message = $success ? __( 'Authenticated read-only connection succeeded.', 'ideaxperts-endless-aisles' ) : __( 'Connection test did not succeed. Check the selected credential, production safeguard, and logs.', 'ideaxperts-endless-aisles' );
			echo '<div class="notice ' . ( $success ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	private function render_tabs( string $current ): void {
		$tabs = array(
			'settings' => __( 'Settings', 'ideaxperts-endless-aisles' ),
			'status'   => __( 'Status', 'ideaxperts-endless-aisles' ),
			'logs'     => __( 'Logs', 'ideaxperts-endless-aisles' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page' => 'ideaxperts-endless-aisles',
					'tab'  => $slug,
				),
				admin_url( 'admin.php' )
			);
			echo '<a class="nav-tab ' . ( $current === $slug ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	private function render_settings(): void {
		$settings = $this->settings->all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ideaxperts_ea_save_settings">';
		wp_nonce_field( 'ideaxperts_ea_save_settings' );
		echo '<table class="form-table" role="presentation">';
		$this->row( __( 'Enable integration', 'ideaxperts-endless-aisles' ), '<label><input type="checkbox" name="settings[enabled]" value="1" ' . checked( 'yes', $settings['enabled'], false ) . '> ' . esc_html__( 'Enable scheduled integration tasks', 'ideaxperts-endless-aisles' ) . '</label>' );
		$environment = '<select name="settings[environment]"><option value="qa" ' . selected( 'qa', $settings['environment'], false ) . '>' . esc_html__( 'QA', 'ideaxperts-endless-aisles' ) . '</option><option value="production" ' . selected( 'production', $settings['environment'], false ) . '>' . esc_html__( 'Production', 'ideaxperts-endless-aisles' ) . '</option></select>';
		$this->row( __( 'Environment', 'ideaxperts-endless-aisles' ), $environment );
		$this->row( __( 'Production safeguard', 'ideaxperts-endless-aisles' ), '<label><input type="checkbox" name="settings[production_confirmed]" value="1" ' . checked( SettingsValidator::PRODUCTION_CONFIRMED, $settings['production_confirmed'], false ) . '> ' . esc_html__( 'I explicitly authorize requests to the Production environment.', 'ideaxperts-endless-aisles' ) . '</label><p class="description">' . esc_html__( 'Selecting Production alone does not enable Production requests.', 'ideaxperts-endless-aisles' ) . '</p>' );
		$this->token_row( 'qa', __( 'QA API token', 'ideaxperts-endless-aisles' ) );
		$this->token_row( 'production', __( 'Production API token', 'ideaxperts-endless-aisles' ) );
		$this->row( __( 'Inventory sync interval', 'ideaxperts-endless-aisles' ), '<input type="hidden" name="settings[inventory_interval]" value="30"><strong>' . esc_html__( 'Every 30 minutes', 'ideaxperts-endless-aisles' ) . '</strong>' );
		$options = '';
		foreach ( array( 'debug', 'info', 'warning', 'error', 'critical' ) as $level ) {
			$options .= '<option value="' . esc_attr( $level ) . '" ' . selected( $level, $settings['log_level'], false ) . '>' . esc_html( ucfirst( $level ) ) . '</option>';
		}
		$this->row( __( 'Logging level', 'ideaxperts-endless-aisles' ), '<select name="settings[log_level]">' . $options . '</select>' );
		$this->row( __( 'Alert email', 'ideaxperts-endless-aisles' ), '<input type="email" class="regular-text" name="settings[alert_email]" value="' . esc_attr( (string) $settings['alert_email'] ) . '">' );
		$this->row( __( 'New product status', 'ideaxperts-endless-aisles' ), '<input type="hidden" name="settings[import_status]" value="draft"><strong>' . esc_html__( 'Draft', 'ideaxperts-endless-aisles' ) . '</strong>' );
		echo '</table>';
		submit_button();
		echo '</form>';
		echo '<hr><h2>' . esc_html__( 'Connection test', 'ideaxperts-endless-aisles' ) . '</h2><p>' . esc_html__( 'Sends one authenticated GET request for one product. Returned product data is not stored.', 'ideaxperts-endless-aisles' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ideaxperts_ea_test_connection">';
		wp_nonce_field( 'ideaxperts_ea_test_connection' );
		submit_button( __( 'Test connection', 'ideaxperts-endless-aisles' ), 'secondary' );
		echo '</form>';
	}

	private function token_row( string $environment, string $label ): void {
		$configured = $this->settings->token_configured( $environment );
		$field      = '<input type="password" class="regular-text" name="settings[' . esc_attr( $environment ) . '_token]" value="" autocomplete="new-password">';
		$field     .= '<p class="description">' . ( $configured ? esc_html__( 'A token is configured. Leave blank to keep it, or enter a replacement.', 'ideaxperts-endless-aisles' ) : esc_html__( 'No token is configured.', 'ideaxperts-endless-aisles' ) ) . '</p>';
		$this->row( $label, $field );
	}

	private function render_status(): void {
		global $wpdb;
		$sync_table   = $wpdb->prefix . 'ideaxperts_ea_sync_runs';
		$logs_table   = $wpdb->prefix . 'ideaxperts_ea_logs';
		$latest       = $wpdb->get_row( "SELECT status, message, created_at FROM {$sync_table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest_error = $wpdb->get_var( "SELECT message FROM {$logs_table} WHERE level IN ('error','critical') ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$environment  = (string) $this->settings->get( 'environment', 'qa' );
		$rows         = array(
			__( 'Plugin version', 'ideaxperts-endless-aisles' ) => IDEAXPERTS_EA_VERSION,
			__( 'WooCommerce', 'ideaxperts-endless-aisles' ) => defined( 'WC_VERSION' ) ? sprintf( /* translators: %s: WooCommerce version. */ __( 'Available (%s)', 'ideaxperts-endless-aisles' ), WC_VERSION ) : __( 'Unavailable', 'ideaxperts-endless-aisles' ),
			__( 'Environment', 'ideaxperts-endless-aisles' ) => strtoupper( $environment ),
			__( 'Credential configured', 'ideaxperts-endless-aisles' ) => $this->settings->token_configured( $environment ) ? __( 'Yes', 'ideaxperts-endless-aisles' ) : __( 'No', 'ideaxperts-endless-aisles' ),
			__( 'Scheduled action', 'ideaxperts-endless-aisles' ) => $this->scheduler->is_scheduled() ? __( 'Scheduled', 'ideaxperts-endless-aisles' ) : __( 'Not scheduled', 'ideaxperts-endless-aisles' ),
			__( 'Database schema', 'ideaxperts-endless-aisles' ) => (string) get_option( 'ideaxperts_ea_schema_version', __( 'Not installed', 'ideaxperts-endless-aisles' ) ),
			__( 'Latest synchronization', 'ideaxperts-endless-aisles' ) => $latest ? (string) $latest->status . ' — ' . (string) $latest->created_at : __( 'No runs recorded', 'ideaxperts-endless-aisles' ),
			__( 'Latest error', 'ideaxperts-endless-aisles' ) => $latest_error ? (string) Redactor::redact( $latest_error ) : __( 'None', 'ideaxperts-endless-aisles' ),
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_logs(): void {
		echo '<p>' . esc_html__( 'Recent redacted integration logs. Retention is bounded to the latest 2,000 entries.', 'ideaxperts-endless-aisles' ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'ideaxperts-endless-aisles' ) . '</th><th>' . esc_html__( 'Level', 'ideaxperts-endless-aisles' ) . '</th><th>' . esc_html__( 'Message', 'ideaxperts-endless-aisles' ) . '</th><th>' . esc_html__( 'Context', 'ideaxperts-endless-aisles' ) . '</th></tr></thead><tbody>';
		foreach ( $this->logger->recent() as $log ) {
			echo '<tr><td>' . esc_html( $log->created_at ) . '</td><td>' . esc_html( $log->level ) . '</td><td>' . esc_html( $log->message ) . '</td><td><code>' . esc_html( $log->context ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function row( string $label, string $field ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . wp_kses(
			$field,
			array(
				'input'  => array(
					'type'         => true,
					'name'         => true,
					'value'        => true,
					'class'        => true,
					'checked'      => true,
					'min'          => true,
					'max'          => true,
					'autocomplete' => true,
				),
				'select' => array( 'name' => true ),
				'option' => array(
					'value'    => true,
					'selected' => true,
				),
				'label'  => array(),
				'strong' => array(),
				'p'      => array( 'class' => true ),
			)
		) . '</td></tr>';
	}
}
