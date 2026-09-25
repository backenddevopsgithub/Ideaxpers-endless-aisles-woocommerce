<?php
namespace IdeaXperts\EndlessAisles\Core;

use IdeaXperts\EndlessAisles\Database\Migrator;

defined( 'ABSPATH' ) || exit;

final class Activator {
	public static function activate(): void {
		( new Migrator() )->migrate();
		if ( false === get_option( 'ideaxperts_ea_settings', false ) ) {
			add_option(
				'ideaxperts_ea_settings',
				array(
					'enabled'              => 'no',
					'environment'          => 'qa',
					'production_confirmed' => 'no',
					'inventory_interval'   => 30,
					'log_level'            => 'warning',
					'alert_email'          => get_option( 'admin_email', '' ),
					'import_status'        => 'draft',
					'use_global_unique_id' => 'yes',
					'upc_meta_keys'        => array(),
					'allow_sku_upc_match'  => 'no',
				),
				'',
				false
			);
		}
	}
}
