<?php
/**
 * Shortcode [sw_map id=""] and the PHP API swmap_render_map().
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Integration;

final class Shortcode {

	public const TAG = 'sw_map';

	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'handle' ) );
		Assets::register();
	}

	/**
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function handle( $atts ): string {
		$atts    = shortcode_atts(
			array(
				'id'            => '0',
				'height'        => '',
				'aspect_ratio'  => '',
				'lazy'          => '',
				'click_to_load' => '',
				'list'          => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		$options = array();
		foreach ( array( 'height', 'aspect_ratio' ) as $key ) {
			if ( '' !== $atts[ $key ] ) {
				$options[ $key ] = $atts[ $key ];
			}
		}
		foreach ( array( 'lazy', 'click_to_load', 'list' ) as $key ) {
			if ( '' !== $atts[ $key ] ) {
				$options[ $key ] = filter_var( $atts[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		return Renderer::render( absint( $atts['id'] ), $options );
	}
}
