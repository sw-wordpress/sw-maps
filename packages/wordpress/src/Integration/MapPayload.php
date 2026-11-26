<?php
/**
 * Builds the public view of a map: only published maps and layers, an explicit field allowlist, no private data.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Integration;

use SWMaps\Core\Domain\Feature;
use SWMaps\Core\Domain\MapEntity;
use SWMaps\Core\Domain\Provider;
use SWMaps\WordPress\Services;

final class MapPayload {

	/**
	 * @param MapEntity $map            The map.
	 * @param bool      $include_drafts Whether unpublished layers are included (preview for users who may edit the map).
	 * @return array<string, mixed>
	 */
	public static function build( MapEntity $map, bool $include_drafts = false ): array {
		$services = Services::instance();
		$layers   = array();
		$layer_by = array();
		foreach ( $services->layer_repository()->forMap( $map->id ) as $layer ) {
			if ( ! $layer->visible || ( ! $layer->isPublished() && ! $include_drafts ) ) {
				continue;
			}
			$layer_by[ $layer->id ] = count( $layers );
			$layers[]               = array(
				'id'       => $layer->id,
				'title'    => $layer->title,
				'style'    => (object) $layer->style,
				'features' => array(),
			);
		}
		if ( array() !== $layer_by ) {
			foreach ( $services->feature_repository()->forLayers( array_keys( $layer_by ) ) as $feature ) {
				$layers[ $layer_by[ $feature->layerId ] ]['features'][] = self::feature( $feature );
			}
		}
		$provider = self::provider( $map );
		return array(
			'id'              => $map->id,
			'title'           => $map->title,
			'viewport'        => $map->viewport->toArray(),
			'settings'        => $map->settings->toArray(),
			'provider'        => null === $provider ? null : $provider->toPublicArray(),
			'layers'          => $layers,
			'consentCategory' => Consent::category(),
			'strings'         => self::strings(),
		);
	}

	/** @return array<string, mixed> */
	public static function feature( Feature $feature ): array {
		$properties = $feature->properties->toArray();
		return array(
			'id'         => $feature->id,
			'geometry'   => $feature->geometry->toArray(),
			'properties' => $properties + array(
				'image_url' => self::attachment_url( $feature->properties->imageId, 'medium' ),
				'icon_url'  => str_starts_with( $feature->properties->icon, 'media:' ) ? self::attachment_url( (int) substr( $feature->properties->icon, 6 ), 'thumbnail' ) : null,
			),
			'style'      => (object) $feature->style,
		);
	}

	/**
	 * Local raster images from the media library only (no SVG, no external URLs).
	 */
	public static function attachment_url( ?int $id, string $size ): ?string {
		if ( null === $id || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}
		$mime = get_post_mime_type( $id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, $size );
		return is_array( $src ) ? $src[0] : null;
	}

	private static function provider( MapEntity $map ): ?Provider {
		$repo = Services::instance()->provider_repository();
		if ( null !== $map->settings->providerId ) {
			$chosen = $repo->find( $map->settings->providerId );
			if ( null !== $chosen && $chosen->enabled ) {
				return $chosen;
			}
		}
		return $repo->defaultProvider();
	}

	/** @return array<string, string> */
	public static function strings(): array {
		return array(
			'zoomIn'           => __( 'Zoom in', 'sw-maps' ),
			'zoomOut'          => __( 'Zoom out', 'sw-maps' ),
			'fullscreen'       => __( 'Fullscreen', 'sw-maps' ),
			'exitFullscreen'   => __( 'Exit fullscreen', 'sw-maps' ),
			'loadMap'          => __( 'Load map', 'sw-maps' ),
			'consentRequired'  => __( 'The map is shown after you accept the cookies required for it.', 'sw-maps' ),
			'clickToLoad'      => __( 'Loading the map contacts the map provider and transmits your IP address.', 'sw-maps' ),
			'error'            => __( 'The map could not be displayed.', 'sw-maps' ),
			'attributionLabel' => __( 'Map attribution', 'sw-maps' ),
			'openLink'         => __( 'Open link', 'sw-maps' ),
			'closePopup'       => __( 'Close', 'sw-maps' ),
		);
	}
}
