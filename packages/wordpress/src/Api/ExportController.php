<?php
/**
 * REST: GET /maps/{id}/export, GET /export (all maps), POST /imports/package.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\Core\Exception\ValidationException;
use SWMaps\WordPress\Access;
use SWMaps\WordPress\Capabilities;

final class ExportController extends BaseController {

	private const FORMATS = array( 'geojson', 'csv', 'json' );

	public function register(): void {
		$map_check = fn ( \WP_REST_Request $r ): bool => Access::can_edit_map( $this->id( $r ) );
		register_rest_route(
			self::NAMESPACE,
			'/maps/(?P<id>\d+)/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_map' ),
				'permission_callback' => $this->can( Capabilities::MANAGE_IMPORTS, $map_check ),
				'args'                => $this->id_args() + array(
					'format'   => array(
						'type'    => 'string',
						'enum'    => self::FORMATS,
						'default' => 'json',
					),
					'layer_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_all' ),
				'permission_callback' => $this->can( Capabilities::MANAGE_SYSTEM ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/imports/package',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_package' ),
				'permission_callback' => $this->can( Capabilities::MANAGE_IMPORTS ),
			)
		);
	}

	public function export_map( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$exporter = $this->services()->exporter();
				$id       = $this->id( $request );
				$layer    = $request->get_param( 'layer_id' );
				$layer_id = is_numeric( $layer ) ? (int) $layer : null;
				$format   = (string) $request->get_param( 'format' );
				$file     = match ( $format ) {
					'geojson' => $exporter->geojson( $id, $layer_id ),
					'csv'     => $exporter->csv( $id, $layer_id ),
					default   => $exporter->package( array( $id ) ),
				};
				return new \WP_REST_Response( $file );
			}
		);
	}

	public function export_all(): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->services()->exporter()->package() ) );
	}

	public function import_package( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$body    = $this->body( $request );
				$content = $body['content'] ?? null;
				if ( ! is_string( $content ) ) {
					throw ValidationException::field( 'content', 'The package text is required.' );
				}
				return new \WP_REST_Response( $this->services()->package_importer()->import( $content, get_current_user_id() ), 201 );
			}
		);
	}
}
