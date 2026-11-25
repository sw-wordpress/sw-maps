<?php
/**
 * System status report: schema and environment diagnostics.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress;

use SWMaps\WordPress\Persistence\Schema;

final class SystemStatus {

	/**
	 * @return array<string, mixed>
	 */
	public static function report(): array {
		global $wpdb;
		$tables = array();
		foreach ( Schema::tables() as $table ) {
			$name     = $wpdb->prefix . $table;
			$exists   = Schema::table_exists( $table );
			$tables[] = array(
				'name'   => $name,
				'exists' => $exists,
			);
		}
		return array(
			'plugin_version' => SWMAPS_VERSION,
			'db_version'     => (int) get_option( Schema::OPTION, 0 ),
			'db_expected'    => Schema::CURRENT,
			'tables'         => $tables,
			'php_version'    => PHP_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'db_server'      => $wpdb->db_server_info(),
			'multisite'      => is_multisite(),
			'environment'    => wp_get_environment_type(),
			'consent_api'    => function_exists( 'wp_has_consent' ),
			'settings'       => Settings::get(),
		);
	}
}
