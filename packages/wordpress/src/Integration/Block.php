<?php
/**
 * Dynamic Gutenberg block sw-maps/map (rendered on the server, editor preview via ServerSideRender).
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Integration;

use SWMaps\WordPress\Access;
use SWMaps\WordPress\Capabilities;
use SWMaps\WordPress\Services;

final class Block {

	public const NAME = 'sw-maps/map';

	/** Upper bound of 100 map pages for the block selector (10,000 maps). */
	private const MAX_SELECTOR_PAGES = 100;

	public static function register(): void {
		register_block_type(
			SWMAPS_DIR . 'blocks/map',
			array(
				'title'           => __( 'SW Map', 'sw-maps' ),
				'description'     => __( 'Displays a map created with SW Maps.', 'sw-maps' ),
				'render_callback' => array( self::class, 'render' ),
			)
		);
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_data' ) );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		$id = isset( $attributes['mapId'] ) ? absint( $attributes['mapId'] ) : 0;
		if ( 0 === $id ) {
			return '';
		}
		$options = array();
		if ( isset( $attributes['height'] ) && is_string( $attributes['height'] ) && '' !== $attributes['height'] ) {
			$options['height'] = $attributes['height'];
		}
		foreach ( array(
			'clickToLoad' => 'click_to_load',
			'showList'    => 'list',
		) as $attribute => $option ) {
			if ( isset( $attributes[ $attribute ] ) && is_bool( $attributes[ $attribute ] ) ) {
				$options[ $option ] = $attributes[ $attribute ];
			}
		}
		return Renderer::render( $id, $options );
	}

	/** Supplies the map list for the block's selector (only to users who may edit maps). */
	public static function editor_data(): void {
		if ( ! current_user_can( Capabilities::EDIT_MAPS ) ) {
			return;
		}
		$maps     = array();
		$page     = 1;
		$per_page = 100;
		// All pages (bounded): the selector must offer every map the user may edit, also older ones.
		do {
			$result = Services::instance()->maps()->list( '', null, $page, $per_page );
			foreach ( $result['items'] as $map ) {
				if ( ! Access::can_edit_map( $map->id ) ) {
					continue; // Same object-level rule as the REST list: no metadata of maps the user may not edit.
				}
				$maps[] = array(
					'value' => $map->id,
					'label' => $map->title . ( $map->isPublished() ? '' : ' (' . __( 'Draft', 'sw-maps' ) . ')' ),
				);
			}
			++$page;
		} while ( $page <= self::MAX_SELECTOR_PAGES && ( $page - 1 ) * $per_page < $result['total'] );
		wp_add_inline_script( 'sw-maps-map-editor-script', 'window.swmapBlockMaps = ' . wp_json_encode( $maps ) . ';', 'before' );
	}
}
