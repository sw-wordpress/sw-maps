<?php
/**
 * Atomic rate-limit slots stored in the current site's options table.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Persistence;

use SWMaps\Core\Geocoding\RateLimiterInterface;

final class OptionRateLimiter implements RateLimiterInterface {

	/**
	 * @param string $key Limiter key.
	 * @param int    $intervalSeconds Minimum seconds between two slots (0 = unlimited).
	 */
	public function acquire( string $key, int $intervalSeconds ): bool {
		if ( $intervalSeconds <= 0 ) {
			return true;
		}

		global $wpdb;
		$name = self::option_name( $key );
		// Only the writer that advances an expired deadline acquires the slot.
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE %i SET option_value = UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) + %d
				WHERE option_name = %s AND CAST(option_value AS DECIMAL(20,6)) <= UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))',
				$wpdb->options,
				$intervalSeconds,
				$name
			)
		);
		if ( 0 !== $updated ) {
			return 1 === $updated;
		}

		// The unique option name also makes the first acquisition exclusive across processes.
		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) + %d, 'no')",
				$wpdb->options,
				$name,
				$intervalSeconds
			)
		);
	}

	/** @param string $key Limiter key. */
	public static function clear( string $key ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::option_name( $key ) ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** @param string $key Limiter key. */
	private static function option_name( string $key ): string {
		return 'swmap_rate_limit_' . hash( 'sha256', $key );
	}
}
