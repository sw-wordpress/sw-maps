<?php
/**
 * REST: /providers, /providers/{id}, /providers/{id}/test.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\Core\Domain\Provider;
use SWMaps\Core\Domain\ProviderType;
use SWMaps\WordPress\Capabilities;

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

	/** Connection test: fetches tile 0/0/0 server side (never follows private network targets). */
	public function test( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$provider = $this->services()->providers()->get( $this->id( $request ) );
				if ( ProviderType::Pmtiles === $provider->type ) {
					return $this->test_pmtiles( $provider );
				}
				$url    = str_replace( array( '{z}', '{x}', '{y}', '{-y}', '{s}' ), array( '0', '0', '0', '0', 'a' ), $provider->resolvedUrlTemplate() );
				$secret = $this->secret_value( $provider );
				if ( null !== $secret ) {
					$url = str_replace( '{secret}', rawurlencode( $secret ), $url );
				}
				$response = wp_safe_remote_get(
					$url,
					array(
						'timeout'     => 5,
						'redirection' => 2,
						'user-agent'  => 'SW-Maps/' . SWMAPS_VERSION . '; ' . home_url(),
					)
				);
				if ( is_wp_error( $response ) ) {
					return new \WP_REST_Response(
						array(
							'ok'      => false,
							'status'  => 0,
							'message' => __( 'The tile server is not reachable.', 'sw-maps' ),
						)
					);
				}
				$status = (int) wp_remote_retrieve_response_code( $response );
				$type   = wp_remote_retrieve_header( $response, 'content-type' );
				$ok     = 200 === $status && is_string( $type ) && str_starts_with( strtolower( $type ), 'image/' );
				return new \WP_REST_Response(
					array(
						'ok'      => $ok,
						'status'  => $status,
						'message' => $ok ? __( 'Connection successful.', 'sw-maps' ) : __( 'The tile server did not return a tile.', 'sw-maps' ),
					)
				);
			}
		);
	}

	/**
	 * A PMTiles archive starts with the magic bytes "PMTiles" followed by the version (3); a range request reads them.
	 *
	 * @param Provider $provider PMTiles provider.
	 */
	private function test_pmtiles( Provider $provider ): \WP_REST_Response {
		$response = wp_safe_remote_get(
			$provider->urlTemplate,
			array(
				'timeout'             => 5,
				'redirection'         => 2,
				'limit_response_size' => 64, // A host that ignores Range must not stream the whole archive.
				'headers'             => array( 'Range' => 'bytes=0-7' ),
				'user-agent'          => 'SW-Maps/' . SWMAPS_VERSION . '; ' . home_url(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'status'  => 0,
					'message' => __( 'The tile server is not reachable.', 'sw-maps' ),
				)
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$ok     = in_array( $status, array( 200, 206 ), true ) && str_starts_with( $body, 'PMTiles' ) && 3 === ord( $body[7] ?? "\0" );
		return new \WP_REST_Response(
			array(
				'ok'      => $ok,
				'status'  => $status,
				'message' => $ok ? __( 'Connection successful.', 'sw-maps' ) : __( 'The file is not a PMTiles archive (version 3).', 'sw-maps' ),
			)
		);
	}

	private function secret_value( Provider $provider ): ?string {
		$name = $provider->secretConstant;
		// Defence in depth for rows written before the prefix rule: never resolve constants outside the plugin's namespace.
		if ( null === $name || ! str_starts_with( $name, Provider::SECRET_PREFIX ) || ! defined( $name ) ) {
			return null;
		}
		$value = constant( $name );
		return is_string( $value ) ? $value : null;
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
