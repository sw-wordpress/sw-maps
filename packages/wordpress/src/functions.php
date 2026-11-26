<?php
/**
 * Public PHP API (docs/hooks.md).
 *
 * @package sw-maps
 */

declare(strict_types=1);

if ( ! function_exists( 'swmap_render_map' ) ) {
	/**
	 * Renders a map and returns the HTML. Returns an empty string for unknown maps and for drafts the current user
	 * may not preview.
	 *
	 * @param int                  $map_id  Map ID.
	 * @param array<string, mixed> $options Display options: height, aspect_ratio, lazy, click_to_load, list.
	 */
	function swmap_render_map( int $map_id, array $options = array() ): string {
		return \SWMaps\WordPress\Integration\Renderer::render( $map_id, $options );
	}
}
