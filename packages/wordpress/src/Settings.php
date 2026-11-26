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

	/** @return array{delete_data_on_uninstall: bool, default_height: int, default_scroll_zoom: bool, max_features_per_layer: int, click_to_load: bool} */
	public static function defaults(): array {
		return array(
			'delete_data_on_uninstall' => false,
			'default_height'           => 400,
			'default_scroll_zoom'      => false,
			'max_features_per_layer'   => 5000,
			'click_to_load'            => false,
		);
	}

	/** @return array{delete_data_on_uninstall: bool, default_height: int, default_scroll_zoom: bool, max_features_per_layer: int, click_to_load: bool} */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return self::normalise( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * @param array<mixed> $patch
	 * @return array{delete_data_on_uninstall: bool, default_height: int, default_scroll_zoom: bool, max_features_per_layer: int, click_to_load: bool}
	 * @throws \InvalidArgumentException When a value is out of range.
	 */
	public static function update( array $patch ): array {
		$merged = self::normalise( array_merge( self::get(), $patch ), true );
		update_option( self::OPTION, $merged, false );
		return $merged;
	}

	/**
	 * @param array<mixed> $in
	 * @return array{delete_data_on_uninstall: bool, default_height: int, default_scroll_zoom: bool, max_features_per_layer: int, click_to_load: bool}
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
		return $out;
	}
}
