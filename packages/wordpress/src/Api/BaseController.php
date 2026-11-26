<?php
/**
 * Shared REST helpers: permission callbacks, error mapping, pagination headers.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\Core\Exception\ConflictException;
use SWMaps\Core\Exception\InvalidGeometryException;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;
use SWMaps\Core\Import\ImportException;
use SWMaps\WordPress\Services;

abstract class BaseController {

	public const NAMESPACE = 'sw-maps/v1';

	abstract public function register(): void;

	protected function services(): Services {
		return Services::instance();
	}

	/**
	 * Permission callback factory: capability plus an optional object check on the request.
	 *
	 * @param string                                  $capability Required capability.
	 * @param (callable(\WP_REST_Request): bool)|null $object_check Optional object level check.
	 * @return callable(\WP_REST_Request): (true|\WP_Error)
	 */
	protected function can( string $capability, ?callable $object_check = null ): callable {
		return static function ( \WP_REST_Request $request ) use ( $capability, $object_check ) {
			if ( current_user_can( $capability ) && ( null === $object_check || $object_check( $request ) ) ) {
				return true;
			}
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to do this.', 'sw-maps' ),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		};
	}

	/**
	 * Runs a handler and converts domain exceptions into WP_Error.
	 *
	 * @param callable(): (\WP_REST_Response|\WP_Error) $handler Handler.
	 */
	protected function run( callable $handler ): \WP_REST_Response|\WP_Error {
		try {
			return $handler();
		} catch ( \Throwable $e ) {
			return $this->to_error( $e );
		}
	}

	protected function to_error( \Throwable $e ): \WP_Error {
		if ( $e instanceof InvalidGeometryException ) {
			return new \WP_Error(
				'swmap_invalid_geometry',
				__( 'The geometry is invalid.', 'sw-maps' ),
				array(
					'status'  => 400,
					'details' => $this->details( $e ),
				)
			);
		}
		if ( $e instanceof ValidationException ) {
			return new \WP_Error(
				'swmap_invalid_param',
				__( 'Invalid input.', 'sw-maps' ),
				array(
					'status'  => 400,
					'details' => $this->details( $e ),
				)
			);
		}
		if ( $e instanceof ImportException ) {
			return new \WP_Error(
				'swmap_import_rejected',
				__( 'The file cannot be imported.', 'sw-maps' ),
				array(
					'status' => 422,
					'reason' => $e->reason,
				)
			);
		}
		if ( $e instanceof NotFoundException ) {
			return new \WP_Error( 'swmap_not_found', __( 'Not found.', 'sw-maps' ), array( 'status' => 404 ) );
		}
		if ( $e instanceof ConflictException ) {
			return new \WP_Error( 'swmap_conflict', __( 'This value is already in use.', 'sw-maps' ), array( 'status' => 409 ) );
		}
		error_log( 'sw-maps: unexpected REST error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return new \WP_Error( 'swmap_internal_error', __( 'Unexpected error.', 'sw-maps' ), array( 'status' => 500 ) );
	}

	/**
	 * @return list<array{field: string, message: string}>
	 */
	private function details( ValidationException $e ): array {
		$out = array();
		foreach ( $e->errors() as $field => $message ) {
			$out[] = array(
				'field'   => (string) $field,
				'message' => $message,
			);
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function body( \WP_REST_Request $request ): array {
		$out = array();
		foreach ( $request->get_json_params() as $key => $value ) {
			$out[ (string) $key ] = $value;
		}
		return $out;
	}

	protected function id( \WP_REST_Request $request ): int {
		return (int) $request->get_param( 'id' );
	}

	/**
	 * @param array<mixed> $items Serialised items.
	 */
	protected function paged( array $items, int $total, int $per_page ): \WP_REST_Response {
		$response = new \WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / max( 1, $per_page ) ) ) );
		return $response;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function id_args(): array {
		return array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function paging_args( int $max_per_page, int $default ): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => $default,
				'minimum' => 1,
				'maximum' => $max_per_page,
			),
		);
	}
}
