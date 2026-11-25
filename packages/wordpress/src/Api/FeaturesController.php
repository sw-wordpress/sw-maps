<?php
/**
 * REST: /layers/{id}/features, /features/{id}.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\Core\Application\FeatureService;
use SWMaps\WordPress\Access;
use SWMaps\WordPress\Capabilities;
use SWMaps\WordPress\Settings;

final class FeaturesController extends BaseController {

	public function register(): void {
		$layer_check   = fn ( \WP_REST_Request $r ): bool => $this->may_edit_layer( $this->id( $r ) );
		$feature_check = fn ( \WP_REST_Request $r ): bool => $this->may_edit_feature( $this->id( $r ) );
		register_rest_route(
			self::NAMESPACE,
			'/layers/(?P<id>\d+)/features',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS, $layer_check ),
					'args'                => $this->id_args() + $this->paging_args( FeatureService::MAX_PER_PAGE, 100 ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS, $layer_check ),
					'args'                => $this->id_args(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/features/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS, $feature_check ),
					'args'                => $this->id_args(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS, $feature_check ),
					'args'                => $this->id_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS, $feature_check ),
					'args'                => $this->id_args(),
				),
			)
		);
	}

	private function may_edit_layer( int $layer_id ): bool {
		$layer = $this->services()->layer_repository()->find( $layer_id );
		return null === $layer || Access::can_edit_map( $layer->mapId );
	}

	private function may_edit_feature( int $feature_id ): bool {
		$feature = $this->services()->feature_repository()->find( $feature_id );
		return null === $feature || $this->may_edit_layer( $feature->layerId );
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$per_page = (int) $request->get_param( 'per_page' );
				$result   = $this->services()->features()->page( $this->id( $request ), (int) $request->get_param( 'page' ), $per_page );
				return $this->paged( array_map( static fn ( $f ): array => $f->toArray(), $result['items'] ), $result['total'], $per_page );
			}
		);
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$layer_id = $this->id( $request );
				$this->services()->layers()->get( $layer_id );
				if ( $this->services()->feature_repository()->count( $layer_id ) >= Settings::get()['max_features_per_layer'] ) {
					return new \WP_Error( 'swmap_limit_exceeded', __( 'The feature limit of this layer is reached.', 'sw-maps' ), array( 'status' => 413 ) );
				}
				return new \WP_REST_Response( $this->services()->features()->create( $layer_id, $this->body( $request ) )->toArray(), 201 );
			}
		);
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->services()->features()->get( $this->id( $request ) )->toArray() ) );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->services()->features()->update( $this->id( $request ), $this->body( $request ) )->toArray() ) );
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$this->services()->features()->delete( $this->id( $request ) );
				return new \WP_REST_Response( null, 204 );
			}
		);
	}
}
