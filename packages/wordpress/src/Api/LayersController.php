<?php
/**
 * REST: /maps/{id}/layers, /layers/{id}.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\WordPress\Access;
use SWMaps\WordPress\Capabilities;

final class LayersController extends BaseController {

	public function register(): void {
		$map_check   = fn ( \WP_REST_Request $r ): bool => Access::can_edit_map( $this->id( $r ) );
		$layer_check = fn ( \WP_REST_Request $r ): bool => $this->may_edit_layer( $this->id( $r ) );
		register_rest_route(
			self::NAMESPACE,
			'/maps/(?P<id>\d+)/layers',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_LAYERS, $map_check ),
					'args'                => $this->id_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_LAYERS, $map_check ),
					'args'                => $this->id_args(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/layers/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_LAYERS, $layer_check ),
					'args'                => $this->id_args(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_LAYERS, $layer_check ),
					'args'                => $this->id_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_LAYERS, $layer_check ),
					'args'                => $this->id_args(),
				),
			)
		);
	}

	/** Resolves layer to map; unknown layers pass (the handler answers 404 to users with the capability). */
	private function may_edit_layer( int $layer_id ): bool {
		$layer = $this->services()->layer_repository()->find( $layer_id );
		return null === $layer || Access::can_edit_map( $layer->mapId );
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$items = array_map( static fn ( $l ): array => $l->toArray(), $this->services()->layers()->forMap( $this->id( $request ) ) );
				return new \WP_REST_Response( $items );
			}
		);
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->services()->layers()->create( $this->id( $request ), $this->body( $request ) )->toArray(), 201 ) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->services()->layers()->get( $this->id( $request ) )->toArray() ) );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->services()->layers()->update( $this->id( $request ), $this->body( $request ) )->toArray() ) );
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$this->services()->layers()->delete( $this->id( $request ) );
				return new \WP_REST_Response( null, 204 );
			}
		);
	}
}
