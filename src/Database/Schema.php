<?php
namespace IdeaXperts\EndlessAisles\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const VERSION = '2.3.0';

	/** @return list<string> */
	public static function table_names( string $prefix ): array {
		return array(
			$prefix . 'ideaxperts_ea_mappings',
			$prefix . 'ideaxperts_ea_sync_runs',
			$prefix . 'ideaxperts_ea_logs',
			$prefix . 'ideaxperts_ea_order_submissions',
			$prefix . 'ideaxperts_ea_dry_runs',
			$prefix . 'ideaxperts_ea_dry_run_items',
			$prefix . 'ideaxperts_ea_store_identifiers',
			$prefix . 'ideaxperts_ea_dry_run_actions',
		);
	}

	/** @return array<string,list<string>> */
	public static function required_columns( string $prefix ): array {
		return array(
			$prefix . 'ideaxperts_ea_mappings'          => array( 'id', 'wc_product_id', 'wc_variation_id', 'ea_product_id', 'ea_option_id', 'upc', 'normalized_upc', 'mapping_status', 'last_synced_at', 'created_at', 'updated_at' ),
			$prefix . 'ideaxperts_ea_sync_runs'         => array( 'id', 'run_type', 'status', 'started_at', 'completed_at', 'processed_count', 'success_count', 'failure_count', 'message', 'created_at', 'updated_at' ),
			$prefix . 'ideaxperts_ea_logs'              => array( 'id', 'level', 'message', 'context', 'created_at' ),
			$prefix . 'ideaxperts_ea_order_submissions' => array( 'id', 'wc_order_id', 'idempotency_key', 'status', 'attempt_count', 'ea_order_id', 'last_error', 'submitted_at', 'created_at', 'updated_at' ),
			$prefix . 'ideaxperts_ea_dry_runs'          => array( 'id', 'status', 'environment', 'started_by', 'started_at', 'updated_at', 'completed_at', 'current_api_page', 'current_store_page', 'products_inspected', 'variations_inspected', 'store_records_inspected', 'match_counters', 'warning_counters', 'error_summary', 'resume_cursor', 'cancellation_at', 'last_heartbeat_at', 'claim_token', 'claim_generation' ),
			$prefix . 'ideaxperts_ea_dry_run_items'     => array( 'id', 'run_id', 'ea_product_id', 'ea_option_id', 'original_upc', 'normalized_upc', 'wc_product_id', 'wc_variation_id', 'classification', 'review_flags', 'review_reason', 'vendor_title', 'vendor_option_description', 'retail_price', 'wholesale_price', 'map_price', 'purchasable', 'discontinued', 'created_at', 'updated_at' ),
			$prefix . 'ideaxperts_ea_store_identifiers' => array( 'id', 'run_id', 'wc_product_id', 'wc_variation_id', 'product_type', 'product_status', 'title', 'identifier_type', 'identifier_source', 'original_identifier', 'normalized_identifier', 'parent_product_id', 'ea_product_id', 'ea_option_id', 'created_at' ),
			$prefix . 'ideaxperts_ea_dry_run_actions'   => array( 'id', 'run_id', 'claim_token', 'claim_generation', 'intent_token', 'action_type', 'hook', 'page_number', 'status', 'action_scheduler_id', 'attempts', 'available_at', 'last_attempt_at', 'dispatch_token', 'dispatch_started_at', 'dispatch_lease_expires_at', 'execution_token', 'started_at', 'lease_expires_at', 'created_at', 'updated_at' ),
		);
	}

	/** @return array<string,array<string,array{unique:bool,columns:list<string>}>> */
	public static function required_indexes( string $prefix ): array {
		return array(
			$prefix . 'ideaxperts_ea_mappings'          => array(
				'PRIMARY'        => array(
					'unique'  => true,
					'columns' => array( 'id' ),
				),
				'ea_identity'    => array(
					'unique'  => true,
					'columns' => array( 'ea_product_id', 'ea_option_id' ),
				),
				'wc_identity'    => array(
					'unique'  => true,
					'columns' => array( 'wc_product_id', 'wc_variation_id' ),
				),
				'normalized_upc' => array(
					'unique'  => false,
					'columns' => array( 'normalized_upc' ),
				),
				'mapping_status' => array(
					'unique'  => false,
					'columns' => array( 'mapping_status' ),
				),
				'last_synced_at' => array(
					'unique'  => false,
					'columns' => array( 'last_synced_at' ),
				),
			),
			$prefix . 'ideaxperts_ea_sync_runs'         => array(
				'PRIMARY'         => array(
					'unique'  => true,
					'columns' => array( 'id' ),
				),
				'run_type_status' => array(
					'unique'  => false,
					'columns' => array( 'run_type', 'status' ),
				),
				'created_at'      => array(
					'unique'  => false,
					'columns' => array( 'created_at' ),
				),
			),
			$prefix . 'ideaxperts_ea_logs'              => array(
				'PRIMARY'    => array(
					'unique'  => true,
					'columns' => array( 'id' ),
				),
				'level'      => array(
					'unique'  => false,
					'columns' => array( 'level' ),
				),
				'created_at' => array(
					'unique'  => false,
					'columns' => array( 'created_at' ),
				),
			),
			$prefix . 'ideaxperts_ea_order_submissions' => array(
				'PRIMARY'         => array(
					'unique'  => true,
					'columns' => array( 'id' ),
				),
				'idempotency_key' => array(
					'unique'  => true,
					'columns' => array( 'idempotency_key' ),
				),
				'wc_order_id'     => array(
					'unique'  => false,
					'columns' => array( 'wc_order_id' ),
				),
				'status'          => array(
					'unique'  => false,
					'columns' => array( 'status' ),
				),
				'created_at'      => array(
					'unique'  => false,
					'columns' => array( 'created_at' ),
				),
			),
			$prefix . 'ideaxperts_ea_dry_runs'          => array(
				'PRIMARY'             => array(
					'unique'  => true,
					'columns' => array( 'id' ),
				),
				'status'              => array(
					'unique'  => false,
					'columns' => array( 'status' ),
				),
				'started_at'          => array(
					'unique'  => false,
					'columns' => array( 'started_at' ),
				),
				'status_completed_at' => array(
					'unique'  => false,
					'columns' => array( 'status', 'completed_at' ),
				),
			),
			$prefix . 'ideaxperts_ea_dry_run_items'     => array(
				'PRIMARY'            => array(
					'unique'  => true,
					'columns' => array( 'id' ),
				),
				'run_option'         => array(
					'unique'  => true,
					'columns' => array( 'run_id', 'ea_product_id', 'ea_option_id' ),
				),
				'run_classification' => array(
					'unique'  => false,
					'columns' => array( 'run_id', 'classification' ),
				),
				'normalized_upc'     => array(
					'unique'  => false,
					'columns' => array( 'normalized_upc' ),
				),
				'wc_identity'        => array(
					'unique'  => false,
					'columns' => array( 'wc_product_id', 'wc_variation_id' ),
				),
			),
			$prefix . 'ideaxperts_ea_store_identifiers' => array(
				'PRIMARY'             => array(
					'unique'  => true,
					'columns' => array( 'id' ),
				),
				'run_source_identity' => array(
					'unique'  => true,
					'columns' => array( 'run_id', 'wc_product_id', 'wc_variation_id', 'identifier_type', 'identifier_source' ),
				),
				'run_normalized'      => array(
					'unique'  => false,
					'columns' => array( 'run_id', 'normalized_identifier' ),
				),
				'run_mapping'         => array(
					'unique'  => false,
					'columns' => array( 'run_id', 'ea_product_id', 'ea_option_id' ),
				),
			),
			$prefix . 'ideaxperts_ea_dry_run_actions'   => array(
				'PRIMARY'          => array(
					'unique'  => true,
					'columns' => array( 'id' ),
				),
				'logical_action'   => array(
					'unique'  => true,
					'columns' => array( 'run_id', 'claim_generation', 'action_type', 'page_number' ),
				),
				'intent_token'     => array(
					'unique'  => true,
					'columns' => array( 'intent_token' ),
				),
				'status_available' => array(
					'unique'  => false,
					'columns' => array( 'status', 'available_at' ),
				),
				'scheduler_id'     => array(
					'unique'  => false,
					'columns' => array( 'action_scheduler_id' ),
				),
				'run_claim'        => array(
					'unique'  => false,
					'columns' => array( 'run_id', 'claim_generation', 'status' ),
				),
				'dispatch_lease'   => array(
					'unique'  => false,
					'columns' => array( 'status', 'dispatch_lease_expires_at', 'id' ),
				),
			),
		);
	}

	/** @return array<string,string> */
	public static function definitions( string $prefix, string $charset_collate = '' ): array {
		$collation = ' ENGINE=InnoDB' . ( '' !== $charset_collate ? ' ' . $charset_collate : '' );
		return array(
			'mappings'          => "CREATE TABLE {$prefix}ideaxperts_ea_mappings (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 wc_product_id bigint(20) unsigned NOT NULL,
 wc_variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
 ea_product_id varchar(191) NOT NULL,
 ea_option_id varchar(191) NOT NULL DEFAULT '',
 upc varchar(64) NOT NULL DEFAULT '',
 normalized_upc varchar(64) NOT NULL DEFAULT '',
 mapping_status varchar(32) NOT NULL DEFAULT 'active',
 last_synced_at datetime NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY ea_identity (ea_product_id,ea_option_id),
 UNIQUE KEY wc_identity (wc_product_id,wc_variation_id),
 KEY normalized_upc (normalized_upc),
 KEY mapping_status (mapping_status),
 KEY last_synced_at (last_synced_at)
){$collation};",
			'sync_runs'         => "CREATE TABLE {$prefix}ideaxperts_ea_sync_runs (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 run_type varchar(32) NOT NULL,
 status varchar(32) NOT NULL DEFAULT 'pending',
 started_at datetime NULL,
 completed_at datetime NULL,
 processed_count bigint(20) unsigned NOT NULL DEFAULT 0,
 success_count bigint(20) unsigned NOT NULL DEFAULT 0,
 failure_count bigint(20) unsigned NOT NULL DEFAULT 0,
 message text NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY run_type_status (run_type,status),
 KEY created_at (created_at)
){$collation};",
			'logs'              => "CREATE TABLE {$prefix}ideaxperts_ea_logs (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 level varchar(16) NOT NULL,
 message text NOT NULL,
 context longtext NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY level (level),
 KEY created_at (created_at)
){$collation};",
			'order_submissions' => "CREATE TABLE {$prefix}ideaxperts_ea_order_submissions (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 wc_order_id bigint(20) unsigned NOT NULL,
 idempotency_key char(64) NOT NULL,
 status varchar(32) NOT NULL DEFAULT 'pending',
 attempt_count int(10) unsigned NOT NULL DEFAULT 0,
 ea_order_id varchar(191) NOT NULL DEFAULT '',
 last_error text NULL,
 submitted_at datetime NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY idempotency_key (idempotency_key),
 KEY wc_order_id (wc_order_id),
 KEY status (status),
 KEY created_at (created_at)
){$collation};",
			'dry_runs'          => "CREATE TABLE {$prefix}ideaxperts_ea_dry_runs (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 status varchar(32) NOT NULL DEFAULT 'pending',
 environment varchar(16) NOT NULL DEFAULT 'qa',
 started_by bigint(20) unsigned NOT NULL,
 started_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 completed_at datetime NULL,
 current_api_page int(10) unsigned NOT NULL DEFAULT 0,
 current_store_page int(10) unsigned NOT NULL DEFAULT 0,
 products_inspected bigint(20) unsigned NOT NULL DEFAULT 0,
 variations_inspected bigint(20) unsigned NOT NULL DEFAULT 0,
 store_records_inspected bigint(20) unsigned NOT NULL DEFAULT 0,
 match_counters longtext NULL,
 warning_counters longtext NULL,
 error_summary text NULL,
 resume_cursor varchar(191) NOT NULL DEFAULT '',
 cancellation_at datetime NULL,
 last_heartbeat_at datetime NULL,
 claim_token varchar(64) NOT NULL DEFAULT '',
 claim_generation bigint(20) unsigned NOT NULL DEFAULT 1,
 PRIMARY KEY  (id),
 KEY status (status),
 KEY started_at (started_at),
 KEY status_completed_at (status,completed_at)
){$collation};",
			'dry_run_items'     => "CREATE TABLE {$prefix}ideaxperts_ea_dry_run_items (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 run_id bigint(20) unsigned NOT NULL,
 ea_product_id varchar(191) NOT NULL,
 ea_option_id varchar(191) NOT NULL,
 original_upc varchar(64) NOT NULL DEFAULT '',
 normalized_upc varchar(64) NOT NULL DEFAULT '',
 wc_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
 wc_variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
 classification varchar(48) NOT NULL,
 review_flags longtext NULL,
 review_reason text NULL,
 vendor_title text NULL,
 vendor_option_description text NULL,
 retail_price varchar(64) NOT NULL DEFAULT '',
 wholesale_price varchar(64) NOT NULL DEFAULT '',
 map_price varchar(64) NOT NULL DEFAULT '',
 purchasable tinyint(1) NOT NULL DEFAULT 0,
 discontinued tinyint(1) NOT NULL DEFAULT 0,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY run_option (run_id,ea_product_id,ea_option_id),
 KEY run_classification (run_id,classification),
 KEY normalized_upc (normalized_upc),
 KEY wc_identity (wc_product_id,wc_variation_id)
){$collation};",
			'store_identifiers' => "CREATE TABLE {$prefix}ideaxperts_ea_store_identifiers (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 run_id bigint(20) unsigned NOT NULL,
 wc_product_id bigint(20) unsigned NOT NULL,
 wc_variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
 product_type varchar(32) NOT NULL DEFAULT '',
 product_status varchar(32) NOT NULL DEFAULT '',
 title text NULL,
 identifier_type varchar(32) NOT NULL,
 identifier_source varchar(191) NOT NULL DEFAULT '',
 original_identifier varchar(191) NOT NULL DEFAULT '',
 normalized_identifier varchar(191) NOT NULL DEFAULT '',
 parent_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
 ea_product_id varchar(191) NOT NULL DEFAULT '',
 ea_option_id varchar(191) NOT NULL DEFAULT '',
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY run_source_identity (run_id,wc_product_id,wc_variation_id,identifier_type,identifier_source),
 KEY run_normalized (run_id,normalized_identifier),
 KEY run_mapping (run_id,ea_product_id,ea_option_id)
){$collation};",
			'dry_run_actions'   => "CREATE TABLE {$prefix}ideaxperts_ea_dry_run_actions (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 run_id bigint(20) unsigned NOT NULL DEFAULT 0,
 claim_token varchar(64) NOT NULL DEFAULT '',
 claim_generation bigint(20) unsigned NOT NULL DEFAULT 0,
 intent_token varchar(64) NOT NULL,
 action_type varchar(32) NOT NULL,
 hook varchar(191) NOT NULL,
 page_number int(10) unsigned NOT NULL DEFAULT 0,
 status varchar(32) NOT NULL DEFAULT 'pending',
 action_scheduler_id bigint(20) unsigned NULL,
 attempts int(10) unsigned NOT NULL DEFAULT 0,
 available_at datetime NOT NULL,
 last_attempt_at datetime NULL,
 dispatch_token varchar(64) NOT NULL DEFAULT '',
 dispatch_started_at datetime NULL,
 dispatch_lease_expires_at datetime NULL,
 execution_token varchar(64) NOT NULL DEFAULT '',
 started_at datetime NULL,
 lease_expires_at datetime NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY logical_action (run_id,claim_generation,action_type,page_number),
 UNIQUE KEY intent_token (intent_token),
 KEY status_available (status,available_at),
 KEY scheduler_id (action_scheduler_id),
 KEY run_claim (run_id,claim_generation,status),
 KEY dispatch_lease (status,dispatch_lease_expires_at,id)
){$collation};",
		);
	}
}
