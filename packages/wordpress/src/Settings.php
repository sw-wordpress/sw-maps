<?php
/**
 * Plugin settings stored in one option. Unknown keys are dropped, values are validated on write.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress;

final class Settings {

	public const OPTION = 'swmap_settings';

	/** @return array{delete_data_on_uninstall: bool, default_height: int, default_scroll_zoom: bool, max_features_per_layer: int, click_to_load: bool, allowed_hosts: list<string>, geocoder_url: string, geocoder_contact: string} */
	public static function defaults(): array {
		return array(
			'delete_data_on_uninstall' => false,
			'default_height'           => 400,
			'default_scroll_zoom'      => false,
			'max_features_per_layer'   => 5000,
			'click_to_load'            => false,
			'allowed_hosts'            => array(),
			'geocoder_url'             => 'https://nominatim.openstreetmap.org',
			'geocoder_contact'         => '',
		);
	}

	/** @return array{delete_data_on_uninstall: bool, default_height: int, default_scroll_zoom: bool, max_features_per_layer: int, click_to_load: bool, allowed_hosts: list<string>, geocoder_url: string, geocoder_contact: string} */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return self::normalise( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * @param array<mixed> $patch
	 * @return array{delete_data_on_uninstall: bool, default_height: int, default_scroll_zoom: bool, max_features_per_layer: int, click_to_load: bool, allowed_hosts: list<string>, geocoder_url: string, geocoder_contact: string}
	 * @throws \InvalidArgumentException When a value is out of range.
	 */
	public static function update( array $patch ): array {
		$merged = self::normalise( array_merge( self::get(), $patch ), true );
		update_option( self::OPTION, $merged, false );
		return $merged;
	}

	/**
	 * @param array<mixed> $in
	 * @return array{delete_data_on_uninstall: bool, default_height: int, default_scroll_zoom: bool, max_features_per_layer: int, click_to_load: bool, allowed_hosts: list<string>, geocoder_url: string, geocoder_contact: string}
	 * @throws \InvalidArgumentException When $strict is set and a value is invalid.
	 */
	private static function normalise( array $in, bool $strict = false ): array {
		$out = self::defaults();
		foreach ( array( 'delete_data_on_uninstall', 'default_scroll_zoom', 'click_to_load' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				if ( ! is_bool( $in[ $key ] ) ) {
					if ( $strict ) {
						throw new \InvalidArgumentException( $key );
					}
					continue;
				}
				$out[ $key ] = $in[ $key ];
			}
		}
		foreach ( array(
			'default_height'         => array( 120, 2000 ),
			'max_features_per_layer' => array( 1, 50000 ),
		) as $key => $range ) {
			if ( isset( $in[ $key ] ) ) {
				if ( ! is_int( $in[ $key ] ) || $in[ $key ] < $range[0] || $in[ $key ] > $range[1] ) {
					if ( $strict ) {
						throw new \InvalidArgumentException( $key );
					}
					continue;
				}
				$out[ $key ] = $in[ $key ];
			}
		}
		if ( isset( $in['allowed_hosts'] ) ) {
			$hosts = self::hosts( $in['allowed_hosts'] );
			if ( null === $hosts ) {
				if ( $strict ) {
					throw new \InvalidArgumentException( 'allowed_hosts' );
				}
			} else {
				$out['allowed_hosts'] = $hosts;
			}
		}
		if ( isset( $in['geocoder_url'] ) ) {
			if ( is_string( $in['geocoder_url'] ) && ( '' === $in['geocoder_url'] || ( strlen( $in['geocoder_url'] ) <= 500 && 1 === preg_match( '#^https?://[^\s"<>\\\\{}?\#]+$#i', $in['geocoder_url'] ) ) ) ) {
				$out['geocoder_url'] = rtrim( $in['geocoder_url'], '/' );
			} elseif ( $strict ) {
				throw new \InvalidArgumentException( 'geocoder_url' );
			}
		}
		if ( isset( $in['geocoder_contact'] ) ) {
			if ( is_string( $in['geocoder_contact'] ) && strlen( $in['geocoder_contact'] ) <= 200 && 1 !== preg_match( '/[\x00-\x1F\x7F<>]/', $in['geocoder_contact'] ) ) {
				$out['geocoder_contact'] = trim( $in['geocoder_contact'] );
			} elseif ( $strict ) {
				throw new \InvalidArgumentException( 'geocoder_contact' );
			}
		}
		return $out;
	}

	/**
	 * Host names only (no scheme, path or port), at most 20.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>|null Null when invalid.
	 */
	private static function hosts( mixed $value ): ?array {
		if ( ! is_array( $value ) || count( $value ) > 20 ) {
			return null;
		}
		$out = array();
		foreach ( $value as $host ) {
			if ( ! is_string( $host ) || 1 !== preg_match( '/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/i', trim( $host ) ) ) {
				return null;
			}
			$out[] = strtolower( trim( $host ) );
		}
		return array_values( array_unique( $out ) );
	}
}
