<?php
/**
 * REST: /maps, /maps/{id}, /maps/{id}/duplicate.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\Core\Domain\MapEntity;
use SWMaps\WordPress\Access;
use SWMaps\WordPress\Capabilities;
use SWMaps\WordPress\Settings;

final class MapsController extends BaseController {

	public function register(): void {
		$map_check = fn ( \WP_REST_Request $r ): bool => Access::can_edit_map( $this->id( $r ) );
		register_rest_route(
			self::NAMESPACE,
			'/maps',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS ),
					'args'                => $this->paging_args( 100, 20 ) + array(
						'search' => array(
							'type'    => 'string',
							'default' => '',
						),
						'status' => array(
							'type' => 'string',
							'enum' => array( 'draft', 'published' ),
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/maps/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS, $map_check ),
					'args'                => $this->id_args(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS, $map_check ),
					'args'                => $this->id_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS, $map_check ),
					'args'                => $this->id_args(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/maps/(?P<id>\d+)/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate' ),
				'permission_callback' => $this->can( Capabilities::EDIT_MAPS, $map_check ),
				'args'                => $this->id_args(),
			)
		);
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$status   = $request->get_param( 'status' );
				$per_page = (int) $request->get_param( 'per_page' );
				$result   = $this->services()->maps()->list( (string) $request->get_param( 'search' ), is_string( $status ) ? $status : null, (int) $request->get_param( 'page' ), $per_page );
				$items    = array();
				foreach ( $result['items'] as $map ) {
					if ( Access::can_edit_map( $map->id ) ) {
						$items[] = $this->present( $map );
					}
				}
				return $this->paged( $items, $result['total'], $per_page );
			}
		);
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$input = $this->body( $request );
				if ( isset( $input['status'] ) ) {
					unset( $input['status'] ); // New maps always start as draft; publishing is a separate capability.
				}
				$defaults          = Settings::get();
				$settings          = is_array( $input['settings'] ?? null ) ? $input['settings'] : array();
				$input['settings'] = $settings + array(
					'height'      => $defaults['default_height'],
					'scrollZoom'  => $defaults['default_scroll_zoom'],
					'clickToLoad' => $defaults['click_to_load'],
				);
				$map               = $this->services()->maps()->create( $input, get_current_user_id() );
				$response          = new \WP_REST_Response( $this->present( $map ), 201 );
				$response->header( 'Location', rest_url( self::NAMESPACE . '/maps/' . $map->id ) );
				return $response;
			}
		);
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->present( $this->services()->maps()->get( $this->id( $request ) ) ) ) );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$id    = $this->id( $request );
				$patch = $this->body( $request );
				$map   = $this->services()->maps()->get( $id );
				if ( isset( $patch['status'] ) && $patch['status'] !== $map->status->value && ! current_user_can( Capabilities::PUBLISH_MAPS ) ) {
					return new \WP_Error( 'rest_forbidden', __( 'You are not allowed to publish maps.', 'sw-maps' ), array( 'status' => 403 ) );
				}
				$result = $this->services()->maps()->update( $id, $patch );
				return new \WP_REST_Response( $this->present( $result['map'] ) );
			}
		);
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$this->services()->maps()->delete( $this->id( $request ) );
				return new \WP_REST_Response( null, 204 );
			}
		);
	}

	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->present( $this->services()->maps()->duplicate( $this->id( $request ), get_current_user_id() ) ), 201 ) );
	}

	/** @return array<string, mixed> */
	private function present( MapEntity $map ): array {
		$counts = $this->services()->maps()->counts( $map->id );
		return $map->toArray() + array(
			'layer_count'   => $counts['layers'],
			'feature_count' => $counts['features'],
		);
	}
}
