<?php
/**
 * Activation, deactivation and uninstall. Deactivation keeps all data; uninstall deletes data only on explicit opt-in.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress;

use SWMaps\WordPress\Persistence\Schema;

final class Lifecycle {

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $blog_id ) { // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_number
				switch_to_blog( (int) $blog_id );
				self::install_current_blog();
				restore_current_blog();
			}
			return;
		}
		self::install_current_blog();
	}

	public static function deactivate(): void {
		// Data is intentionally kept.
		flush_rewrite_rules( false );
	}

	public static function install_current_blog(): void {
		Schema::migrate();
		Capabilities::grant();
	}

	/** Creates the tables of a site that is added while the plugin is network active. */
	public static function on_new_site( \WP_Site $site ): void {
		if ( ! is_plugin_active_for_network( plugin_basename( SWMAPS_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install_current_blog();
		restore_current_blog();
	}

	public static function uninstall(): void {
		if ( is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $blog_id ) { // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_number
				switch_to_blog( (int) $blog_id );
				self::uninstall_current_blog();
				restore_current_blog();
			}
			return;
		}
		self::uninstall_current_blog();
	}

	private static function uninstall_current_blog(): void {
		if ( true !== Settings::get()['delete_data_on_uninstall'] ) {
			return;
		}
		Schema::drop();
		delete_option( Settings::OPTION );
		Capabilities::revoke();
	}
}
