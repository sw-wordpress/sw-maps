<?php
/**
 * Plugin Name:       SW Maps
 * Plugin URI:        https://github.com/sw-wordpress/sw-maps
 * Description:       Interactive maps with markers, lines and areas. OpenStreetMap without API keys, self-hosted tiles possible, no telemetry.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Stefan Warnat
 * Author URI:        https://stefanwarnat.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sw-maps
 * Domain Path:       /languages
 *
 * @package sw-maps
 */

defined( 'ABSPATH' ) || exit;

define( 'SWMAPS_VERSION', '0.1.0' );
define( 'SWMAPS_FILE', __FILE__ );
define( 'SWMAPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWMAPS_URL', plugin_dir_url( __FILE__ ) );
define( 'SWMAPS_MIN_PHP', '8.2' );

if ( version_compare( PHP_VERSION, SWMAPS_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'SW Maps requires PHP %1$s or newer. You are running PHP %2$s.', 'sw-maps' ),
						SWMAPS_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

// SWMAPS_AUTOLOADER may be defined by the test environment to supply its own dependency set.
$swmap_autoload = defined( 'SWMAPS_AUTOLOADER' ) ? SWMAPS_AUTOLOADER : SWMAPS_DIR . 'vendor-prefixed/autoload.php';
if ( ! defined( 'SWMAPS_AUTOLOADER' ) && ! is_readable( $swmap_autoload ) ) {
	// Source checkouts use Composer; release packages ship isolated dependencies (or none).
	$swmap_autoload = SWMAPS_DIR . 'vendor/autoload.php';
}
if ( ! is_readable( $swmap_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'SW Maps: the vendor directory is missing. Install the release package or run "composer install".', 'sw-maps' )
			);
		}
	);
	return;
}
$swmap_loader = require $swmap_autoload;
$swmap_loader->addPsr4( 'SWMaps\\Core\\', SWMAPS_DIR . 'packages/core/src/' );
$swmap_loader->addPsr4( 'SWMaps\\WordPress\\', SWMAPS_DIR . 'packages/wordpress/src/' );
unset( $swmap_autoload, $swmap_loader );

register_activation_hook( __FILE__, array( \SWMaps\WordPress\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SWMaps\WordPress\Lifecycle::class, 'deactivate' ) );

\SWMaps\WordPress\Plugin::instance()->boot();
