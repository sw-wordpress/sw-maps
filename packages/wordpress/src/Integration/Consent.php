<?php
/**
 * WP Consent API integration (server side part). The browser checks the actual decision before loading tiles.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Integration;

final class Consent {

	public const SCRIPT_HANDLE = 'wp-consent-api';

	/**
	 * Consent category that must be granted before external tile requests; null when the Consent API is not active.
	 * Filterable via `swmap_consent_category` (return null to disable gating).
	 */
	public static function category(): ?string {
		if ( ! self::api_active() ) {
			return null;
		}
		/**
		 * Filters the WP Consent API category required for map tiles.
		 *
		 * @param string|null $category Category (functional, preferences, statistics, statistics-anonymous, marketing).
		 */
		$category = apply_filters( 'swmap_consent_category', 'marketing' );
		return is_string( $category ) && in_array( $category, array( 'functional', 'preferences', 'statistics-anonymous', 'statistics', 'marketing' ), true ) ? $category : null;
	}

	/**
	 * Whether a WP Consent API implementation is available. Filterable for environments that provide the consent
	 * decision differently (and for tests).
	 */
	public static function api_active(): bool {
		/**
		 * Filters whether the WP Consent API is considered active.
		 *
		 * @param bool $active True when the `wp_has_consent` function exists.
		 */
		return true === apply_filters( 'swmap_consent_api_active', function_exists( 'wp_has_consent' ) );
	}

	/** Declares the plugin's cookie-free usage for the Consent API plugin list. */
	public static function register(): void {
		add_filter( 'wp_consent_api_registered_' . plugin_basename( SWMAPS_FILE ), '__return_true' );
	}
}
