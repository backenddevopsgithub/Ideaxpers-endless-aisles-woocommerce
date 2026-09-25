<?php
namespace IdeaXperts\EndlessAisles\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const VERSION = '2.1.0';

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
		);
	}

	/** @return array<string,list<string>> */
	public static function required_columns( string $prefix ): array {
		return array(
			$prefix . 'ideaxperts_ea_dry_runs'      => array( 'last_heartbeat_at' ),
			$prefix . 'ideaxperts_ea_dry_run_items' => array( 'review_flags' ),
		);
	}

	/** @return array<string,string> */
	public static function definitions( string $prefix, string $charset_collate = '' ): array {
		$collation = '' !== $charset_collate ? ' ' . $charset_collate : '';
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
		);
	}
}
