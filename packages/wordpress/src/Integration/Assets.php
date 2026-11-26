<?php
/**
 * Frontend assets: one shared bundle, enqueued only when a map is rendered.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Integration;

final class Assets {

	public const HANDLE = 'swmaps-viewer';

	public static function register(): void {
		$deps = array();
		if ( wp_script_is( Consent::SCRIPT_HANDLE, 'registered' ) ) {
			$deps[] = Consent::SCRIPT_HANDLE;
		}
		wp_register_script( self::HANDLE, SWMAPS_URL . 'assets/frontend/swmaps-viewer.js', $deps, SWMAPS_VERSION, array( 'in_footer' => true ) );
		wp_register_style( self::HANDLE, SWMAPS_URL . 'assets/frontend/swmaps-viewer.css', array(), SWMAPS_VERSION );
	}

	/** Enqueues the bundle (idempotent: several maps share it). */
	public static function enqueue(): void {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			self::register();
		}
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
	}
}
