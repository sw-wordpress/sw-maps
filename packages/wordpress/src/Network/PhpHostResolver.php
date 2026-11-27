<?php
/**
 * Resolves host names with the system resolver (A and AAAA). The `swmap_resolve_host` filter can replace the answer
 * (tests, special DNS setups).
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Network;

use SWMaps\Core\Network\HostResolverInterface;

final class PhpHostResolver implements HostResolverInterface {

	/**
	 * @param string $host Host name.
	 * @return list<string>
	 */
	public function resolve( string $host ): array {
		/**
		 * Overrides DNS resolution: return a list of IP addresses, or null for the system resolver.
		 *
		 * @param list<string>|null $addresses Addresses or null.
		 * @param string            $host Host name.
		 */
		$override = apply_filters( 'swmap_resolve_host', null, $host );
		if ( is_array( $override ) ) {
			return array_values( array_filter( array_map( 'strval', $override ) ) );
		}
		$out = array();
		$v4  = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			array_push( $out, ...$v4 );
		}
		$v6 = function_exists( 'dns_get_record' ) ? dns_get_record( $host, DNS_AAAA ) : false;
		if ( is_array( $v6 ) ) {
			foreach ( $v6 as $record ) {
				if ( is_string( $record['ipv6'] ?? null ) ) {
					$out[] = $record['ipv6'];
				}
			}
		}
		return $out;
	}
}
