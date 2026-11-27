<?php
/**
 * REST: /providers, /providers/{id}, /providers/{id}/test.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\Core\Domain\Provider;
use SWMaps\WordPress\Capabilities;
use SWMaps\WordPress\Providers\ProviderDiagnostics;

final class ProvidersController extends BaseController {

	public function register(): void {
		$cap = $this->can( Capabilities::MANAGE_SYSTEM );
		register_rest_route(
			self::NAMESPACE,
			'/providers',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					// Map editors need the basemap configuration to see the map; management data stays restricted.
					'permission_callback' => $this->can( Capabilities::EDIT_MAPS ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $cap,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/providers/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $cap,
					'args'                => $this->id_args(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $cap,
					'args'                => $this->id_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $cap,
					'args'                => $this->id_args(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/providers/(?P<id>\d+)/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $cap,
				'args'                => $this->id_args(),
			)
		);
	}

	public function index(): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( array_map( array( $this, 'present' ), $this->services()->providers()->list() ) ) );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->present( $this->services()->providers()->create( $this->body( $request ) ) ), 201 ) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->present( $this->services()->providers()->get( $this->id( $request ) ) ) ) );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( $this->present( $this->services()->providers()->update( $this->id( $request ), $this->body( $request ) ) ) ) );
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$this->services()->providers()->delete( $this->id( $request ) );
				return new \WP_REST_Response( null, 204 );
			}
		);
	}

	/** Connection test (see ProviderDiagnostics). */
	public function test( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => new \WP_REST_Response( ProviderDiagnostics::check( $this->services()->providers()->get( $this->id( $request ) ) ) ) );
	}

	/** @return array<string, mixed> */
	private function present( Provider $provider ): array {
		if ( ! current_user_can( Capabilities::MANAGE_SYSTEM ) ) {
			$data = $provider->toArray();
			return array(
				'id'            => $data['id'],
				'type'          => $data['type'],
				'name'          => $data['name'],
				'public_config' => $data['public_config'],
				'enabled'       => $data['enabled'],
			);
		}
		return $provider->toArray() + array(
			'secret_defined'    => null !== $provider->secretConstant && str_starts_with( $provider->secretConstant, Provider::SECRET_PREFIX ) && defined( $provider->secretConstant ),
			'external_requests' => $provider->externalHosts(),
		);
	}
}
