<?php
/**
 * Versioned, multisite aware schema migrations (docs/database.md).
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Persistence;

use SWMaps\Core\Domain\Provider;

final class Schema {

	public const OPTION  = 'swmap_db_version';
	public const CURRENT = 1;

	/** @return list<string> unprefixed table names */
	public static function tables(): array {
		return array( 'swmap_maps', 'swmap_layers', 'swmap_features', 'swmap_categories', 'swmap_feature_categories', 'swmap_providers' );
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'swmap_' . $name;
	}

	/**
	 * Applies all pending migrations for the current blog. Idempotent; stops at the first failure and keeps the old version.
	 *
	 * @return int the schema version after the run
	 */
	public static function migrate(): int {
		$installed = (int) get_option( self::OPTION, 0 );
		if ( $installed >= self::CURRENT ) {
			return $installed;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::migrations() as $version => $migration ) {
			if ( $version <= $installed ) {
				continue;
			}
			if ( ! $migration() ) {
				return $installed;
			}
			update_option( self::OPTION, $version, true );
			$installed = $version;
		}
		return $installed;
	}

	/** @return array<int, callable(): bool> */
	private static function migrations(): array {
		return array(
			1 => array( self::class, 'migration_1' ),
		);
	}

	public static function migration_1(): bool {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'swmap_';

		$statements = array(
			"CREATE TABLE {$p}maps (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(190) NOT NULL,
  title varchar(255) NOT NULL,
  description text NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  viewport_json longtext NOT NULL,
  settings_json longtext NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY status (status)
) {$charset};",
			"CREATE TABLE {$p}layers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  map_id bigint(20) unsigned NOT NULL,
  title varchar(255) NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'features',
  status varchar(20) NOT NULL DEFAULT 'published',
  sort_order int(11) NOT NULL DEFAULT 0,
  visible tinyint(1) NOT NULL DEFAULT 1,
  style_json longtext NOT NULL,
  source_config_json longtext NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY map_order (map_id,sort_order)
) {$charset};",
			"CREATE TABLE {$p}features (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  layer_id bigint(20) unsigned NOT NULL,
  external_id varchar(190) DEFAULT NULL,
  geometry_type varchar(20) NOT NULL,
  geometry_json longtext NOT NULL,
  bbox_min_lon double NOT NULL,
  bbox_min_lat double NOT NULL,
  bbox_max_lon double NOT NULL,
  bbox_max_lat double NOT NULL,
  properties_json longtext NOT NULL,
  style_json longtext NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY layer_external (layer_id,external_id),
  KEY bbox_lon (layer_id,bbox_min_lon,bbox_max_lon),
  KEY bbox_lat (bbox_min_lat,bbox_max_lat)
) {$charset};",
			"CREATE TABLE {$p}categories (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(190) NOT NULL,
  title varchar(255) NOT NULL,
  style_json longtext NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$charset};",
			"CREATE TABLE {$p}feature_categories (
  feature_id bigint(20) unsigned NOT NULL,
  category_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (feature_id,category_id),
  KEY category_id (category_id)
) {$charset};",
			"CREATE TABLE {$p}providers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL,
  name varchar(190) NOT NULL,
  public_config_json longtext NOT NULL,
  secret_constant varchar(64) DEFAULT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) {$charset};",
		);
		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}
		foreach ( self::tables() as $table ) {
			if ( ! self::table_exists( $table ) ) {
				return false;
			}
		}
		self::seed_default_provider();
		return true;
	}

	/**
	 * Checks a table by selecting from it (SHOW TABLES does not list temporary tables).
	 *
	 * @param string $table Unprefixed table name as listed in tables().
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->query( 'SELECT 1 FROM `' . esc_sql( $wpdb->prefix . $table ) . '` LIMIT 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->suppress_errors( $suppress );
		return false !== $result;
	}

	private static function seed_default_provider(): void {
		global $wpdb;
		$table = self::table( 'providers' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count > 0 ) {
			return;
		}
		( new WpdbProviders() )->save( Provider::openStreetMap() );
	}

	/** Drops all plugin tables of the current blog. */
	public static function drop(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		delete_option( self::OPTION );
	}
}
