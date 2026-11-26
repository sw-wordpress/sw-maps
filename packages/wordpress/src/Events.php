<?php
/**
 * Domain events mapped to WordPress actions. Listener failures are logged and never abort the operation.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress;

use SWMaps\Core\Contracts\EventsInterface;

final class Events implements EventsInterface {

	public function mapPublished( int $mapId ): void {
		$this->fire( 'swmap_map_published', $mapId );
	}

	public function layerUpdated( int $layerId, int $mapId ): void {
		$this->fire( 'swmap_layer_updated', $layerId, $mapId );
	}

	/**
	 * @param non-empty-string $hook Action name.
	 * @param int              ...$args Action arguments.
	 */
	private function fire( string $hook, int ...$args ): void {
		try {
			do_action( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Callers pass swmap_* hook names only.
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'sw-maps: listener of %s failed: %s', $hook, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
