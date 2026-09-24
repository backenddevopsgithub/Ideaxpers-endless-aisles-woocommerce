<?php
namespace IdeaXperts\EndlessAisles\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const VERSION = '1.0.0';

	/** @return list<string> */
	public static function table_names( string $prefix ): array {
		return array(
			$prefix . 'ideaxperts_ea_mappings',
			$prefix . 'ideaxperts_ea_sync_runs',
			$prefix . 'ideaxperts_ea_logs',
			$prefix . 'ideaxperts_ea_order_submissions',
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
		);
	}
}
