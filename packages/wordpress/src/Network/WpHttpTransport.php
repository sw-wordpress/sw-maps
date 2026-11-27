<?php
/**
 * A WordPress HTTP GET pinned to an address validated by the guarded client.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Network;

use SWMaps\Core\Network\HttpException;
use SWMaps\Core\Network\HttpResponse;
use SWMaps\Core\Network\HttpTransportInterface;
use SWMaps\Core\Network\UrlGuard;
use WpOrg\Requests\Transport\Curl;

final class WpHttpTransport implements HttpTransportInterface {

	/**
	 * @param string                $url URL (already checked by the guard).
	 * @param array<string, string> $headers Request headers.
	 * @param int                   $timeoutSeconds Timeout.
	 * @param int                   $maxBytes Maximum response size.
	 * @param string[]              $addresses Validated IP addresses.
	 * @phpstan-param non-empty-list<string> $addresses
	 * @throws HttpException When the request fails.
	 */
	public function get( string $url, array $headers, int $timeoutSeconds, int $maxBytes, array $addresses ): HttpResponse {
		$args                          = array(
			'timeout'             => $timeoutSeconds,
			'redirection'         => 0,
			'limit_response_size' => $maxBytes,
			'headers'             => $headers,
			'user-agent'          => $headers['User-Agent'] ?? 'SW-Maps/' . SWMAPS_VERSION . '; ' . home_url(),
		);
		$marker                        = new \stdClass();
		$args['swmap_checked_request'] = $marker;
		$pin                           = $this->pin( $url, $addresses[0], $marker );
		$origin                        = self::origin( $url );
		/** @param array<string, mixed> $options Requests options. */
		$transport = static function ( string $request_url, mixed $headers, mixed $data, mixed $method, array &$options ) use ( $origin ): void {
			if ( self::origin( $request_url ) === $origin ) {
				$options['transport']        = Curl::class;
				$options['follow_redirects'] = false;
			}
		};
		add_action( 'requests-requests.before_request', $transport, PHP_INT_MAX, 5 );
		add_action( 'http_api_curl', $pin, PHP_INT_MAX, 2 );
		try {
			$response = wp_remote_get( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $pin, PHP_INT_MAX );
			remove_action( 'requests-requests.before_request', $transport, PHP_INT_MAX );
		}
		if ( is_wp_error( $response ) ) {
			throw new HttpException( $response->get_error_message() );
		}
		$out = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}
		return new HttpResponse( (int) wp_remote_retrieve_response_code( $response ), $out, wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Keeps the URL hostname for TLS verification and the Host header while pinning its connection.
	 *
	 * @param string    $url Checked URL.
	 * @param string    $address Checked IP address.
	 * @param \stdClass $marker Identifies this HTTP request.
	 * @return \Closure(\CurlHandle, array<mixed>): void
	 * @throws HttpException When cURL is unavailable.
	 */
	private function pin( string $url, string $address, \stdClass $marker ): \Closure {
		if ( '' === $url ) {
			throw new HttpException( 'A URL is required for a guarded HTTP request.' );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			throw new HttpException( 'The cURL PHP extension is required for guarded HTTP requests.' );
		}
		[, $host, $port] = self::origin( $url );
		return static function ( \CurlHandle $handle, array $args ) use ( $url, $host, $port, $address, $marker ): void {
			if ( ( $args['swmap_checked_request'] ?? null ) !== $marker ) {
				return;
			}
			$options = array(
				CURLOPT_URL            => $url,
				CURLOPT_PROXY          => '',
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_SSL_VERIFYPEER => true,
			);
			if ( ! UrlGuard::isIpLiteral( $host ) ) {
				$target                     = str_contains( $address, ':' ) ? '[' . $address . ']' : $address;
				$options[ CURLOPT_RESOLVE ] = array( $host . ':' . $port . ':' . $target );
			}
			if ( ! curl_setopt_array( $handle, $options ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- The WordPress HTTP API has no option to pin DNS.
				throw new HttpException( 'The HTTP connection could not be pinned.' );
			}
		};
	}

	/**
	 * @param string $url Request URL.
	 * @return array{string, string, int} Lower-case scheme and host, and effective port.
	 */
	private static function origin( string $url ): array {
		$parts  = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$scheme = strtolower( $parts['scheme'] ?? '' );
		return array(
			$scheme,
			strtolower( trim( $parts['host'] ?? '', '[]' ) ),
			$parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ),
		);
	}
}
