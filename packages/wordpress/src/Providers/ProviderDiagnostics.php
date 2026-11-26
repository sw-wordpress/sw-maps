<?php
/**
 * Reachability checks of providers (connection test and system diagnostics). Requests go through the guarded HTTP
 * client: public networks only unless the host is on the administrator allowlist.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Providers;

use SWMaps\Core\Domain\Provider;
use SWMaps\Core\Domain\ProviderType;
use SWMaps\Core\Network\UnsafeUrlException;
use SWMaps\WordPress\Services;

final class ProviderDiagnostics {

	/**
	 * Checks one provider: tile 0/0/0 for tile servers, the archive header for PMTiles.
	 *
	 * @param Provider $provider Provider.
	 * @return array{ok: bool, status: int, message: string}
	 */
	public static function check( Provider $provider ): array {
		return ProviderType::Pmtiles === $provider->type ? self::check_archive( $provider ) : self::check_tiles( $provider );
	}

	/**
	 * Checks every enabled provider (an explicit admin action: it contacts the providers).
	 *
	 * @return list<array{id: int, name: string, type: string, host: string, ok: bool, status: int, message: string}>
	 */
	public static function check_enabled(): array {
		$out = array();
		foreach ( Services::instance()->providers()->list() as $provider ) {
			if ( ! $provider->enabled ) {
				continue;
			}
			$hosts = $provider->externalHosts();
			$out[] = array(
				'id'   => $provider->id,
				'name' => $provider->name,
				'type' => $provider->type->value,
				'host' => $hosts[0] ?? '',
			) + self::check( $provider );
		}
		return $out;
	}

	/**
	 * @param Provider $provider Tile server provider.
	 * @return array{ok: bool, status: int, message: string}
	 */
	private static function check_tiles( Provider $provider ): array {
		$url    = str_replace( array( '{z}', '{x}', '{y}', '{-y}', '{s}' ), array( '0', '0', '0', '0', 'a' ), $provider->resolvedUrlTemplate() );
		$secret = self::secret_value( $provider );
		if ( null !== $secret ) {
			$url = str_replace( '{secret}', rawurlencode( $secret ), $url );
		}
		try {
			$response = Services::instance()->http_client()->get( $url, array(), 65536 );
		} catch ( \Throwable $e ) {
			return self::failure( $e );
		}
		$type = $response->header( 'content-type' );
		$ok   = 200 === $response->status && is_string( $type ) && str_starts_with( strtolower( $type ), 'image/' );
		return array(
			'ok'      => $ok,
			'status'  => $response->status,
			'message' => $ok ? __( 'Connection successful.', 'sw-maps' ) : __( 'The tile server did not return a tile.', 'sw-maps' ),
		);
	}

	/**
	 * A PMTiles archive starts with the magic bytes "PMTiles" followed by the version (3); a range request reads them.
	 *
	 * @param Provider $provider PMTiles provider.
	 * @return array{ok: bool, status: int, message: string}
	 */
	private static function check_archive( Provider $provider ): array {
		try {
			$response = Services::instance()->http_client()->get( $provider->urlTemplate, array( 'Range' => 'bytes=0-7' ), 64 ); // A host that ignores Range must not stream the whole archive.
		} catch ( \Throwable $e ) {
			return self::failure( $e );
		}
		$body = $response->body;
		$ok   = in_array( $response->status, array( 200, 206 ), true ) && str_starts_with( $body, 'PMTiles' ) && 3 === ord( $body[7] ?? "\0" );
		return array(
			'ok'      => $ok,
			'status'  => $response->status,
			'message' => $ok ? __( 'Connection successful.', 'sw-maps' ) : __( 'The file is not a PMTiles archive (version 3).', 'sw-maps' ),
		);
	}

	/**
	 * @param \Throwable $e Error.
	 * @return array{ok: bool, status: int, message: string}
	 */
	private static function failure( \Throwable $e ): array {
		return array(
			'ok'      => false,
			'status'  => 0,
			'message' => $e instanceof UnsafeUrlException ? __( 'The address is not allowed for server requests.', 'sw-maps' ) : __( 'The tile server is not reachable.', 'sw-maps' ),
		);
	}

	/**
	 * Defence in depth for rows written before the prefix rule: never resolve constants outside the plugin's namespace.
	 *
	 * @param Provider $provider Provider.
	 */
	private static function secret_value( Provider $provider ): ?string {
		$name = $provider->secretConstant;
		if ( null === $name || ! str_starts_with( $name, Provider::SECRET_PREFIX ) || ! defined( $name ) ) {
			return null;
		}
		$value = constant( $name );
		return is_string( $value ) ? $value : null;
	}
}
