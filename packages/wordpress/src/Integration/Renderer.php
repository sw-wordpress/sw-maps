<?php
/**
 * Server side rendering of a map: container with safely encoded inline JSON and a server rendered location list.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Integration;

use SWMaps\Core\Domain\MapEntity;
use SWMaps\WordPress\Access;
use SWMaps\WordPress\Services;

final class Renderer {

	private static int $counter = 0;

	/**
	 * Renders a map. Drafts are only returned to users who may edit the map; everything else is an empty string.
	 *
	 * @param int                  $map_id  Map ID.
	 * @param array<string, mixed> $options Display options (height, aspect_ratio, lazy, click_to_load, list).
	 */
	public static function render( int $map_id, array $options = array() ): string {
		try {
			$map = Services::instance()->map_repository()->find( $map_id );
		} catch ( \Throwable ) {
			return '';
		}
		if ( null === $map ) {
			return '';
		}
		$preview = ! $map->isPublished();
		if ( $preview && ! Access::can_preview_map( $map->id ) ) {
			return '';
		}
		/**
		 * Filters the display options of a map.
		 *
		 * @param array<string, mixed> $options Options.
		 * @param int                  $map_id  Map ID.
		 */
		$options = (array) apply_filters( 'swmap_render_options', $options, $map->id );
		$payload = MapPayload::build( $map, $preview );
		$payload = self::apply_overrides( $payload, $options );
		Assets::enqueue();
		++self::$counter;
		return self::markup( $map, $payload, $options, $preview );
	}

	/**
	 * @param array<string, mixed> $payload Public payload.
	 * @param array<string, mixed> $options Options.
	 * @return array<string, mixed>
	 */
	private static function apply_overrides( array $payload, array $options ): array {
		if ( isset( $options['click_to_load'] ) ) {
			$payload['settings']['clickToLoad'] = (bool) $options['click_to_load'];
		}
		if ( isset( $options['list'] ) ) {
			$payload['settings']['showList'] = (bool) $options['list'];
		}
		return $payload;
	}

	/**
	 * Hex encoding of <, >, &, ' and " makes it impossible to break out of the script element or an attribute.
	 *
	 * @param array<string, mixed> $payload Public payload.
	 */
	public static function encode_json( array $payload ): string {
		$json = wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '{}' : $json;
	}

	/**
	 * @param MapEntity            $map     The map.
	 * @param array<string, mixed> $payload Public payload.
	 * @param array<string, mixed> $options Options.
	 * @param bool                 $preview Whether this is a draft preview.
	 */
	private static function markup( MapEntity $map, array $payload, array $options, bool $preview ): string {
		$settings = $map->settings;
		$height   = self::css_length( $options['height'] ?? null ) ?? $settings->height . 'px';
		$ratio    = $options['aspect_ratio'] ?? $settings->aspectRatio;
		$ratio    = is_string( $ratio ) && 1 === preg_match( '/^[1-9][0-9]{0,2}:[1-9][0-9]{0,2}$/', $ratio ) ? $ratio : null;
		$style    = '--swmap-height:' . $height . ';';
		if ( null !== $ratio ) {
			$style .= '--swmap-aspect:' . str_replace( ':', ' / ', $ratio ) . ';';
		}
		$lazy = ! isset( $options['lazy'] ) || (bool) $options['lazy'];
		$show = (bool) $payload['settings']['showList'];

		$html  = sprintf(
			'<div class="swmap" id="swmap-%1$d" data-swmap="%2$d" data-lazy="%3$s"%4$s style="%5$s">',
			self::$counter,
			$map->id,
			$lazy ? '1' : '0',
			null !== $ratio ? ' data-aspect="1"' : '',
			esc_attr( $style )
		);
		$html .= '<div class="swmap__stage" role="region" aria-label="' . esc_attr( $map->title ) . '"></div>';
		if ( $preview ) {
			$html .= '<p class="swmap__draft">' . esc_html__( 'Draft preview: this map is not published.', 'sw-maps' ) . '</p>';
		}
		if ( $show ) {
			$html .= self::list( $payload );
		}
		$html .= '<script type="application/json" class="swmap__data">' . self::encode_json( $payload ) . '</script>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * The location list is the accessible access path and works without JavaScript.
	 *
	 * @param array<string, mixed> $payload Public payload.
	 */
	private static function list( array $payload ): string {
		$items = '';
		foreach ( $payload['layers'] as $layer ) {
			foreach ( $layer['features'] as $feature ) {
				$p      = $feature['properties'];
				$title  = '' !== $p['title'] ? $p['title'] : __( 'Location', 'sw-maps' );
				$search = mb_strtolower( trim( $title . ' ' . $p['address'] . ' ' . $p['description'] ) );
				$items .= '<li data-feature-id="' . (int) $feature['id'] . '" data-categories="' . esc_attr( implode( ',', array_map( 'intval', $feature['categories'] ) ) ) . '" data-search="' . esc_attr( $search ) . '"><span class="swmap__item-title">' . esc_html( $title ) . '</span>';
				if ( '' !== $p['address'] ) {
					$items .= '<span class="swmap__item-address">' . esc_html( $p['address'] ) . '</span>';
				}
				if ( is_string( $p['link'] ) && '' !== $p['link'] ) {
					$items .= ' <a href="' . esc_url( $p['link'] ) . '" rel="noopener noreferrer">' . esc_html__( 'Open link', 'sw-maps' ) . '</a>';
				}
				$items .= ' <button type="button" class="swmap__show" hidden data-feature-id="' . (int) $feature['id'] . '">' . esc_html__( 'Show on map', 'sw-maps' ) . '</button></li>';
			}
		}
		return '' === $items ? '' : '<ul class="swmap__list" aria-label="' . esc_attr__( 'Locations', 'sw-maps' ) . '">' . $items . '</ul>';
	}

	private static function css_length( mixed $value ): ?string {
		return is_string( $value ) && 1 === preg_match( '/^(?:[1-9][0-9]{1,4}(?:px|vh|rem|em|%))$/', $value ) ? $value : null;
	}

	public static function reset_counter(): void {
		self::$counter = 0;
	}
}
