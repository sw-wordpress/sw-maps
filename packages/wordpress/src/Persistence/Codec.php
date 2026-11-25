<?php
/**
 * JSON and date helpers shared by the repositories.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Persistence;

final class Codec {

	/**
	 * @param array<mixed> $value
	 */
	public static function encode( array $value ): string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '{}' : $json;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function decode( mixed $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			$out[ (string) $key ] = $value;
		}
		return $out;
	}

	public static function date( \DateTimeImmutable $date ): string {
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	public static function parse_date( mixed $value ): \DateTimeImmutable {
		try {
			return new \DateTimeImmutable( is_string( $value ) ? $value : 'now', new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		}
	}
}
