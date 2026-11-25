<?php
/**
 * Uninstall handler. Data is only removed when the option "delete data on uninstall" was explicitly enabled.
 *
 * @package sw-maps
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$swmap_autoload = defined( 'SWMAPS_AUTOLOADER' ) ? SWMAPS_AUTOLOADER : __DIR__ . '/vendor-prefixed/autoload.php';
if ( ! defined( 'SWMAPS_AUTOLOADER' ) && ! is_readable( $swmap_autoload ) ) {
	$swmap_autoload = __DIR__ . '/vendor/autoload.php';
}
$swmap_loader = require $swmap_autoload;
$swmap_loader->addPsr4( 'SWMaps\\Core\\', __DIR__ . '/packages/core/src/' );
$swmap_loader->addPsr4( 'SWMaps\\WordPress\\', __DIR__ . '/packages/wordpress/src/' );
unset( $swmap_autoload, $swmap_loader );

\SWMaps\WordPress\Lifecycle::uninstall();
