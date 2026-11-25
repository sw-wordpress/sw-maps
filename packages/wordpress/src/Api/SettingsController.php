<?php
/**
 * REST: /settings and /status.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Api;

use SWMaps\WordPress\Capabilities;
use SWMaps\WordPress\Settings;
use SWMaps\WordPress\SystemStatus;

final class SettingsController extends BaseController {

	public function register(): void {
		$cap = $this->can( Capabilities::MANAGE_SYSTEM );
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn () => new \WP_REST_Response( Settings::get() ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $cap,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn () => new \WP_REST_Response( SystemStatus::report() ),
				'permission_callback' => $cap,
			)
		);
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( Settings::update( $this->body( $request ) ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error(
				'swmap_invalid_param',
				__( 'Invalid input.', 'sw-maps' ),
				array(
					'status'  => 400,
					'details' => array(
						array(
							'field'   => $e->getMessage(),
							'message' => 'Invalid value.',
						),
					),
				)
			);
		}
	}
}
