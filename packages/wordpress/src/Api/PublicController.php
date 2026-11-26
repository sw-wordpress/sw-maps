<?php
/**
 * REST: GET /public/maps/{id}/features. Public, read-only, only published maps and layers; bounded response size.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\WordPress\Integration\MapPayload;

final class PublicController extends BaseController {

	private const MAX_PER_PAGE = 500;

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/public/maps/(?P<id>\d+)/features',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'features' ),
				'permission_callback' => '__return_true', // Public data of published maps only.
				'args'                => $this->id_args() + array(
					'bbox'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'zoom'       => array(
						'type'    => 'number',
						'default' => 14,
					),
					'categories' => array(
						'type'    => 'string',
						'default' => '',
					),
				) + $this->paging_args( self::MAX_PER_PAGE, 200 ),
			)
		);
	}

	public function features( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$bounds = $this->bounds( (string) $request->get_param( 'bbox' ) );
				if ( null === $bounds ) {
					return new \WP_Error( 'swmap_invalid_param', __( 'Invalid input.', 'sw-maps' ), array( 'status' => 400 ) );
				}
				$zoom       = max( 0.0, min( 24.0, (float) $request->get_param( 'zoom' ) ) );
				$categories = array();
				foreach ( explode( ',', (string) $request->get_param( 'categories' ) ) as $part ) {
					if ( ctype_digit( trim( $part ) ) && (int) $part > 0 ) {
						$categories[] = (int) $part;
					}
				}
				$per_page = min( self::MAX_PER_PAGE, max( 1, (int) $request->get_param( 'per_page' ) ) );
				$page     = max( 1, (int) $request->get_param( 'page' ) );
				$result   = $this->services()->public_features()->featuresInArea( $this->id( $request ), $bounds, array_values( array_unique( $categories ) ), $page, $per_page );
				if ( null === $result ) {
					return new \WP_Error( 'swmap_not_found', __( 'Not found.', 'sw-maps' ), array( 'status' => 404 ) );
				}
				$out = array();
				foreach ( $result['layers'] as $layer_id => $features ) {
					$out[] = array(
						'id'       => $layer_id,
						'features' => array_map( static fn ( $f ): array => MapPayload::feature( $f, $zoom ), $features ),
					);
				}
				$response = new \WP_REST_Response(
					array(
						'layers'      => $out,
						'total'       => $result['total'],
						'page'        => $page,
						'total_pages' => max( 1, (int) ceil( $result['total'] / $per_page ) ),
					)
				);
				$response->header( 'Cache-Control', 'public, max-age=30' );
				return $response;
			}
		);
	}

	/**
	 * Parses "minLon,minLat,maxLon,maxLat" (WGS84).
	 *
	 * @param string $bbox Raw parameter.
	 * @return array{0: float, 1: float, 2: float, 3: float}|null
	 */
	private function bounds( string $bbox ): ?array {
		$parts = explode( ',', $bbox );
		if ( 4 !== count( $parts ) ) {
			return null;
		}
		foreach ( $parts as $part ) {
			if ( ! is_numeric( trim( $part ) ) ) {
				return null;
			}
		}
		list( $min_lon, $min_lat, $max_lon, $max_lat ) = array_map( 'floatval', $parts );
		// min_lon > max_lon is valid: the area crosses the antimeridian (two ranges).
		if ( $min_lat > $max_lat || $min_lon < -180 || $min_lon > 180 || $max_lon < -180 || $max_lon > 180 || $min_lat < -90 || $max_lat > 90 ) {
			return null;
		}
		return array( $min_lon, $min_lat, $max_lon, $max_lat );
	}
}
