<?php
/**
 * Slot based rate limit stored in a transient (site wide; with an object cache it is shared between processes).
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Network;

use SWMaps\Core\Geocoding\RateLimiterInterface;

final class TransientRateLimiter implements RateLimiterInterface {

	/**
	 * @param string $key Limiter key.
	 * @param int    $intervalSeconds Minimum seconds between two slots (0 = unlimited).
	 */
	public function acquire( string $key, int $intervalSeconds ): bool {
		if ( $intervalSeconds <= 0 ) {
			return true;
		}
		$name = 'swmap_rl_' . substr( md5( $key ), 0, 12 );
		if ( false !== get_transient( $name ) ) {
			return false;
		}
		set_transient( $name, 1, $intervalSeconds );
		return true;
	}
}
