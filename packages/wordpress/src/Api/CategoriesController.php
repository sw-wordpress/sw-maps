<?php
/**
 * REST: /categories. Everyone who edits maps may read and assign categories; managing them needs the layer capability.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\WordPress\Capabilities;

final class CategoriesController extends BaseController {

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/categories',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_LAYERS ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/categories/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_LAYERS ),
					'args'                => $this->id_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_LAYERS ),
					'args'                => $this->id_args(),
				),
			)
		);
	}

	public function index(): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( array_map( static fn ( $c ): array => $c->toArray(), $this->services()->categories()->all() ) ) );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->services()->categories()->create( $this->body( $request ) )->toArray(), 201 ) );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->services()->categories()->update( $this->id( $request ), $this->body( $request ) )->toArray() ) );
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$this->services()->categories()->delete( $this->id( $request ) );
				return new \WP_REST_Response( null, 204 );
			}
		);
	}
}
