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
		$schema_ok = (int) get_option( Schema::OPTION, 0 ) === Schema::CURRENT && array() === array_filter( $tables, static fn ( array $t ): bool => ! $t['exists'] );
		$settings  = Settings::get();
		return array(
			'plugin_version' => SWMAPS_VERSION,
			'schema_ok'      => $schema_ok,
			'limits'         => array(
				'max_features_per_layer' => $settings['max_features_per_layer'],
				'import_max_bytes'       => ( new \SWMaps\Core\Import\ImportLimits() )->maxBytes,
				'import_max_coords'      => ( new \SWMaps\Core\Import\ImportLimits() )->maxCoordinates,
				'public_page_size'       => \SWMaps\Core\Application\PublicFeatureService::MAX_PER_PAGE,
				'php_memory_limit'       => (string) ini_get( 'memory_limit' ),
				'php_max_execution_time' => (int) ini_get( 'max_execution_time' ),
				'php_post_max_size'      => (string) ini_get( 'post_max_size' ),
			),
			'db_version'     => (int) get_option( Schema::OPTION, 0 ),
			'db_expected'    => Schema::CURRENT,
			'tables'         => $tables,
			'php_version'    => PHP_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'db_server'      => $wpdb->db_server_info(),
			'multisite'      => is_multisite(),
			'environment'    => wp_get_environment_type(),
			'consent_api'    => function_exists( 'wp_has_consent' ),
			'settings'       => $settings,
		);
	}
}
