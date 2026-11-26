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
use SWMaps\Core\Geo\Simplifier;
use SWMaps\WordPress\Services;

final class MapPayload {

	/**
	 * @param MapEntity $map            The map.
	 * @param bool      $include_drafts Whether unpublished layers are included (preview for users who may edit the map).
	 * @return array<string, mixed>
	 */
	public static function build( MapEntity $map, bool $include_drafts = false ): array {
		$services = Services::instance();
		$ajax     = $map->settings->ajax && ! $include_drafts;
		$layers   = array();
		$layer_by = array();
		foreach ( $services->public_features()->visibleLayers( $map->id, $include_drafts ) as $layer ) {
			$layer_by[ $layer->id ] = count( $layers );
			$layers[]               = array(
				'id'       => $layer->id,
				'title'    => $layer->title,
				'style'    => (object) $layer->style,
				'features' => array(),
			);
		}
		if ( array() !== $layer_by && ! $ajax ) {
			foreach ( $services->feature_repository()->forLayers( array_keys( $layer_by ) ) as $feature ) {
				$layers[ $layer_by[ $feature->layerId ] ]['features'][] = self::feature( $feature );
			}
		}
		$provider = self::provider( $map );
		return array(
			'id'              => $map->id,
			'title'           => $map->title,
			// Without inline features there is nothing to fit to: the configured start view applies.
			'viewport'        => $ajax ? array_merge( $map->viewport->toArray(), array( 'autoBounds' => false ) ) : $map->viewport->toArray(),
			'settings'        => $map->settings->toArray(),
			'provider'        => null === $provider ? null : $provider->toPublicArray(),
			'layers'          => $layers,
			'categories'      => $ajax ? self::all_categories() : self::categories( $layers ),
			'ajaxUrl'         => $ajax ? rest_url( 'sw-maps/v1/public/maps/' . $map->id . '/features' ) : null,
			'consentCategory' => Consent::category(),
			'strings'         => self::strings(),
		);
	}

	/**
	 * Display variant of a large track or area: the canonical geometry is never changed, only the public payload
	 * carries the simplified copy (Douglas-Peucker, end points kept). Small geometries and points stay as they are.
	 *
	 * @return array{0: array<string, mixed>, 1: bool} Geometry array and whether it was simplified.
	 */
	private static function display_geometry( Feature $feature, ?float $zoom = null ): array {
		$geometry = $feature->geometry;
		/**
		 * Minimum number of coordinates before a geometry is simplified for display (0 disables simplification).
		 *
		 * @param int $min_points Default 500.
		 */
		$min_points = (int) apply_filters( 'swmap_simplify_min_points', 500 );
		if ( $min_points <= 0 || \SWMaps\Core\Domain\GeometryType::Point === $geometry->type || \SWMaps\Core\Domain\GeometryType::MultiPoint === $geometry->type ) {
			return array( $geometry->toArray(), false );
		}
		if ( count( $geometry->flatten() ) < $min_points ) {
			return array( $geometry->toArray(), false );
		}
		/**
		 * Simplification tolerance in degrees (default: about one pixel at zoom 14, roughly 10 m).
		 *
		 * @param float      $tolerance Tolerance.
	 * @param float|null $zoom Zoom of the request (AJAX mode) or null.
		 */
		$tolerance = (float) apply_filters( 'swmap_simplify_tolerance', Simplifier::toleranceForZoom( $zoom ?? 14 ), $zoom );
		$simple    = ( new Simplifier() )->simplify( $geometry, $tolerance );
		return array( $simple->toArray(), count( $simple->flatten() ) < count( $geometry->flatten() ) );
	}

	/**
	 * @param Feature    $feature Feature.
	 * @param float|null $zoom Zoom the output is for (AJAX mode); null uses the default tolerance.
	 * @return array<string, mixed>
	 */
	public static function feature( Feature $feature, ?float $zoom = null ): array {
		$properties                    = $feature->properties->toArray();
		list( $geometry, $simplified ) = self::display_geometry( $feature, $zoom );
		$out                           = array(
			'id'         => $feature->id,
			'geometry'   => $geometry,
			'properties' => $properties + array(
				'image_url' => self::attachment_url( $feature->properties->imageId, 'medium' ),
				'icon_url'  => str_starts_with( $feature->properties->icon, 'media:' ) ? self::attachment_url( (int) substr( $feature->properties->icon, 6 ), 'thumbnail' ) : null,
			),
			'style'      => (object) $feature->style,
			'categories' => $feature->categoryIds,
		);
		if ( $simplified ) {
			$out['simplified'] = true;
		}
		return $out;
	}

	/**
	 * Every category (AJAX mode: the features are not inline, so the legend lists all categories).
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function all_categories(): array {
		$out = array();
		foreach ( Services::instance()->category_repository()->all() as $category ) {
			$out[] = array(
				'id'    => $category->id,
				'title' => $category->title,
				'style' => (object) $category->style,
			);
		}
		return $out;
	}

	/**
	 * Only the categories that are used by the visible features (an explicit allowlist of fields).
	 *
	 * @param list<array<string, mixed>> $layers Public layers.
	 * @return list<array<string, mixed>>
	 */
	private static function categories( array $layers ): array {
		$used = array();
		foreach ( $layers as $layer ) {
			foreach ( $layer['features'] as $feature ) {
				foreach ( $feature['categories'] as $id ) {
					$used[ (int) $id ] = true;
				}
			}
		}
		$out = array();
		if ( array() === $used ) {
			return $out;
		}
		foreach ( Services::instance()->category_repository()->all() as $category ) {
			if ( isset( $used[ $category->id ] ) ) {
				$out[] = array(
					'id'    => $category->id,
					'title' => $category->title,
					'style' => (object) $category->style,
				);
			}
		}
		return $out;
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
			'search'           => __( 'Search locations', 'sw-maps' ),
			'filterByCategory' => __( 'Filter by category', 'sw-maps' ),
			'noResults'        => __( 'No locations found.', 'sw-maps' ),
		);
	}
}
