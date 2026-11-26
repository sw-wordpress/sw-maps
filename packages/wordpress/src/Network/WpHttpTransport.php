<?php
/**
 * One HTTP GET through the WordPress HTTP API without following redirects (the guarded client checks every hop).
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Network;

use SWMaps\Core\Network\HttpException;
use SWMaps\Core\Network\HttpResponse;
use SWMaps\Core\Network\HttpTransportInterface;

final class WpHttpTransport implements HttpTransportInterface {

	/**
	 * @param string                $url URL (already checked by the guard).
	 * @param array<string, string> $headers Request headers.
	 * @param int                   $timeoutSeconds Timeout.
	 * @param int                   $maxBytes Maximum response size.
	 * @throws HttpException When the request fails.
	 */
	public function get( string $url, array $headers, int $timeoutSeconds, int $maxBytes ): HttpResponse {
		$args     = array(
			'timeout'             => $timeoutSeconds,
			'redirection'         => 0,
			'limit_response_size' => $maxBytes,
			'headers'             => $headers,
			'user-agent'          => $headers['User-Agent'] ?? 'SW-Maps/' . SWMAPS_VERSION . '; ' . home_url(),
		);
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new HttpException( $response->get_error_message() );
		}
		$out = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}
		return new HttpResponse( (int) wp_remote_retrieve_response_code( $response ), $out, wp_remote_retrieve_body( $response ) );
	}
}
