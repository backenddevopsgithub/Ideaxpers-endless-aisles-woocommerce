<?php
namespace IdeaXperts\EndlessAisles\Tests\Scheduling;

use IdeaXperts\EndlessAisles\Core\Deactivator;
use IdeaXperts\EndlessAisles\Database\SyncRunRepository;
use IdeaXperts\EndlessAisles\Logging\DatabaseLogger;
use IdeaXperts\EndlessAisles\Scheduling\InventoryScheduler;
use IdeaXperts\EndlessAisles\Settings\SettingsRepository;
use IdeaXperts\EndlessAisles\Settings\SettingsValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InventorySchedulerTest extends TestCase {
	private SchedulerWpdb $wpdb;
	private SettingsRepository $settings;

	protected function setUp(): void {
		$GLOBALS['ea_scheduled']          = false;
		$GLOBALS['ea_schedule_calls']     = 0;
		$GLOBALS['ea_schedule_interval']  = 0;
		$GLOBALS['ea_unschedule_calls']   = 0;
		$GLOBALS['ea_unschedule_log']     = array();
		$GLOBALS['ea_action_queue']       = array();
		$GLOBALS['ea_add_option_failure'] = false;
		$GLOBALS['ea_unschedule_failure'] = false;
		$GLOBALS['ea_remote_requests']    = array();
		$GLOBALS['ea_test_options']       = array(
			'ideaxperts_ea_settings' => array(
				'enabled'              => 'no',
				'environment'          => 'qa',
				'production_confirmed' => 'no',
				'log_level'            => 'critical',
			),
		);
		$this->wpdb                       = new SchedulerWpdb();
		$GLOBALS['wpdb']                  = $this->wpdb;
		$this->settings                   = new SettingsRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	#[DataProvider( 'unconfiguredStates' )]
	public function test_disabled_or_unconfigured_state_has_no_action_or_database_row( array $settings, ?string $token_environment ): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings'] = array_merge( $GLOBALS['ea_test_options']['ideaxperts_ea_settings'], $settings );
		if ( null !== $token_environment ) {
			$this->settings->replace_token( $token_environment, 'configured-token' );
		}
		$scheduler = $this->scheduler();

		$scheduler->ensure_scheduled();
		$scheduler->handle();

		self::assertFalse( $GLOBALS['ea_scheduled'] );
		self::assertSame( 0, $GLOBALS['ea_schedule_calls'] );
		self::assertSame( array(), $this->wpdb->rows );
		self::assertSame( array(), $GLOBALS['ea_remote_requests'] );
	}

	/** @return array<string,array{array<string,string>,string|null}> */
	public static function unconfiguredStates(): array {
		return array(
			'disabled integration'   => array(
				array(
					'enabled'     => 'no',
					'environment' => 'qa',
				),
				'qa',
			),
			'missing credential'     => array(
				array(
					'enabled'     => 'yes',
					'environment' => 'qa',
				),
				null,
			),
			'unconfirmed production' => array(
				array(
					'enabled'              => 'yes',
					'environment'          => 'production',
					'production_confirmed' => 'no',
				),
				'production',
			),
			'invalid environment'    => array(
				array(
					'enabled'     => 'yes',
					'environment' => 'invalid',
				),
				null,
			),
		);
	}

	#[DataProvider( 'configuredStates' )]
	public function test_valid_configuration_has_exactly_one_action_and_records_expected_run( string $environment, string $confirmation ): void {
		$GLOBALS['ea_test_options']['ideaxperts_ea_settings'] = array_merge(
			$GLOBALS['ea_test_options']['ideaxperts_ea_settings'],
			array(
				'enabled'              => 'yes',
				'environment'          => $environment,
				'production_confirmed' => $confirmation,
			)
		);
		$this->settings->replace_token( $environment, 'configured-token' );
		$scheduler = $this->scheduler();

		$scheduler->ensure_scheduled();
		$scheduler->ensure_scheduled();
		$scheduler->handle();

		self::assertTrue( $GLOBALS['ea_scheduled'] );
		self::assertSame( 1, $GLOBALS['ea_schedule_calls'] );
		self::assertSame( 30 * MINUTE_IN_SECONDS, $GLOBALS['ea_schedule_interval'] );
		self::assertCount( 1, $this->wpdb->rows );
		self::assertSame( 'not_implemented', $this->wpdb->rows[0]['status'] );
		self::assertCount( 1, $this->wpdb->queries );
		self::assertStringContainsString( 'status = %s', $this->wpdb->queries[0] );
		self::assertStringContainsString( 'not_implemented', $this->wpdb->queries[0] );
		self::assertStringNotContainsString( 'failed', $this->wpdb->queries[0] );
	}

	/** @return array<string,array{string,string}> */
	public static function configuredStates(): array {
		return array(
			'QA'                   => array( 'qa', 'no' ),
			'confirmed Production' => array( 'production', SettingsValidator::PRODUCTION_CONFIRMED ),
		);
	}

	public function test_enable_disable_transitions_and_repeated_saves_reconcile_without_duplicates(): void {
		$this->settings->replace_token( 'qa', 'configured-token' );
		$scheduler = $this->scheduler();

		$this->settings->save(
			array(
				'enabled'     => '1',
				'environment' => 'qa',
			)
		);
		$scheduler->ensure_scheduled();
		$this->settings->save(
			array(
				'enabled'     => '1',
				'environment' => 'qa',
			)
		);
		$scheduler->ensure_scheduled();
		self::assertSame( 1, $GLOBALS['ea_schedule_calls'] );
		self::assertTrue( $GLOBALS['ea_scheduled'] );

		$this->settings->save( array( 'environment' => 'qa' ) );
		$scheduler->ensure_scheduled();
		self::assertFalse( $GLOBALS['ea_scheduled'] );
		self::assertSame( 1, $GLOBALS['ea_unschedule_calls'] );
		self::assertSame( array(), $this->wpdb->rows );
	}

	public function test_deactivation_removes_only_plugin_owned_inventory_actions(): void {
		$GLOBALS['ea_scheduled']           = true;
		$GLOBALS['ea_unrelated_scheduled'] = true;

		Deactivator::deactivate();

		self::assertFalse( $GLOBALS['ea_scheduled'] );
		self::assertTrue( $GLOBALS['ea_unrelated_scheduled'] );
		self::assertSame( 1, $GLOBALS['ea_unschedule_calls'] );
	}

	public function test_deactivation_unschedules_dry_run_jobs_with_nonempty_arguments(): void {
		$GLOBALS['ea_action_queue'] = array(
			array(
				'hook'  => 'ideaxperts_ea_dry_run_store_batch',
				'args'  => array( 9, 2 ),
				'group' => 'ideaxperts-endless-aisles',
			),
			array(
				'hook'  => 'ideaxperts_ea_dry_run_catalog_page',
				'args'  => array( 9, 4 ),
				'group' => 'ideaxperts-endless-aisles',
			),
		);

		Deactivator::deactivate();

		self::assertCount( 2, $GLOBALS['ea_action_queue'] );
		self::assertSame( 'ideaxperts_ea_inventory_sync', $GLOBALS['ea_unschedule_log'][0]['hook'] ?? '' );
	}

	private function scheduler(): InventoryScheduler {
		return new InventoryScheduler( $this->settings, new DatabaseLogger(), new SyncRunRepository() );
	}
}

final class SchedulerWpdb {
	public string $prefix     = 'wp_';
	public string $options    = 'wp_options';
	public int $rows_affected = 0;
	/** @var list<array<string,mixed>> */
	public array $rows = array();
	/** @var list<string> */
	public array $queries = array();

	public function insert( string $table, array $data, array $formats ): bool {
		$this->rows[] = $data;
		return true;
	}

	public function prepare( string $query, mixed ...$args ): string {
		return $query . ' -- ' . implode( ', ', array_map( 'strval', $args ) );
	}

	public function query( string $query ): int {
		$this->queries[] = $query;
		return 0;
	}

	/** @return list<array<string,mixed>> */
	public function get_results( string $query, mixed $output = null ): array {
		return array();
	}

	public function get_var( string $query ): mixed {
		return null;
	}
}
