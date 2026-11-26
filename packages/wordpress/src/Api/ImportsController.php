<?php
/**
 * REST: POST /imports (validate with dry_run, or execute).
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\WordPress\Access;
use SWMaps\WordPress\Capabilities;

final class ImportsController extends BaseController {

	public function register(): void {
		$map_check = static function ( \WP_REST_Request $r ): bool {
			$map_id = $r->get_param( 'map_id' );
			return is_int( $map_id ) && Access::can_edit_map( $map_id );
		};
		register_rest_route(
			self::NAMESPACE,
			'/imports',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $this->can( Capabilities::MANAGE_IMPORTS, $map_check ),
				'args'                => array(
					'map_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$body   = $this->body( $request );
				$map_id = (int) $request->get_param( 'map_id' );
				return new \WP_REST_Response( $this->services()->imports()->run( $map_id, $body ) );
			}
		);
	}
}
