<?php
/**
 * REST: POST /geocoding/search and /geocoding/reverse. Only for users who edit maps; the server side key never
 * leaves the server.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\Core\Application\Input;
use SWMaps\Core\Exception\ValidationException;
use SWMaps\WordPress\Capabilities;

final class GeocodingController extends BaseController {

	public function register(): void {
		$cap = $this->can( Capabilities::EDIT_MAPS );
		register_rest_route(
			self::NAMESPACE,
			'/geocoding/search',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/geocoding/reverse',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reverse' ),
				'permission_callback' => $cap,
			)
		);
	}

	public function search( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$query   = Input::string( $this->body( $request ), 'query', true, 200 ) ?? '';
				$results = $this->services()->geocoder()->search( $query, 5 );
				return new \WP_REST_Response( array( 'results' => array_map( static fn ( $r ): array => $r->toArray(), $results ) ) );
			}
		);
	}

	public function reverse( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$body = $this->body( $request );
				foreach ( array( 'lon', 'lat' ) as $key ) {
					if ( ! isset( $body[ $key ] ) || ! is_numeric( $body[ $key ] ) ) {
						throw ValidationException::field( $key, 'Must be a number.' );
					}
				}
				$result = $this->services()->geocoder()->reverse( (float) $body['lon'], (float) $body['lat'] );
				return new \WP_REST_Response( array( 'result' => null === $result ? null : $result->toArray() ) );
			}
		);
	}
}
