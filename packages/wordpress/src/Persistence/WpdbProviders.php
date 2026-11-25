<?php
/**
 * The wpdb repository for map providers. Only the NAME of a secret constant is persisted (ADR 0004).
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Persistence;

use SWMaps\Core\Contracts\ProviderRepositoryInterface;
use SWMaps\Core\Domain\Provider;
use SWMaps\Core\Domain\ProviderType;

final class WpdbProviders implements ProviderRepositoryInterface {

	public function find( int $id ): ?Provider {
		global $wpdb;
		$table = Schema::table( 'providers' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function all(): array {
		global $wpdb;
		$table = Schema::table( 'providers' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = $this->hydrate( $row );
		}
		return $out;
	}

	public function defaultProvider(): ?Provider {
		global $wpdb;
		$table = Schema::table( 'providers' );
		$row   = $wpdb->get_row( "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY id ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function save( Provider $provider ): Provider {
		global $wpdb;
		$data  = array(
			'type'               => $provider->type->value,
			'name'               => $provider->name,
			'public_config_json' => Codec::encode( $provider->toArray()['public_config'] ),
			'secret_constant'    => $provider->secretConstant,
			'enabled'            => $provider->enabled ? 1 : 0,
		);
		$fmt   = array( '%s', '%s', '%s', '%s', '%d' );
		$table = Schema::table( 'providers' );
		if ( 0 === $provider->id ) {
			if ( false === $wpdb->insert( $table, $data, $fmt ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				throw new \RuntimeException( 'Could not store the provider.' );
			}
			return $this->with_id( $provider, (int) $wpdb->insert_id );
		}
		if ( false === $wpdb->update( $table, $data, array( 'id' => $provider->id ), $fmt, array( '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			throw new \RuntimeException( 'Could not update the provider.' );
		}
		return $provider;
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'providers' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function with_id( Provider $p, int $id ): Provider {
		return new Provider( $id, $p->type, $p->name, $p->urlTemplate, $p->attribution, $p->minZoom, $p->maxZoom, $p->tileSize, $p->publicKey, $p->secretConstant, $p->enabled );
	}

	/** @param array<string, mixed> $row */
	private function hydrate( array $row ): Provider {
		$config = Codec::decode( $row['public_config_json'] );
		$int    = static fn ( string $key, int $fallback ): int => isset( $config[ $key ] ) && is_numeric( $config[ $key ] ) ? (int) $config[ $key ] : $fallback;
		$text   = static fn ( string $key ): string => isset( $config[ $key ] ) && is_string( $config[ $key ] ) ? $config[ $key ] : '';
		return new Provider(
			(int) $row['id'],
			ProviderType::tryFrom( (string) $row['type'] ) ?? ProviderType::Xyz,
			(string) $row['name'],
			$text( 'urlTemplate' ),
			$text( 'attribution' ),
			$int( 'minZoom', 0 ),
			$int( 'maxZoom', 19 ),
			$int( 'tileSize', 256 ),
			$text( 'publicKey' ),
			null === $row['secret_constant'] ? null : (string) $row['secret_constant'],
			(bool) $row['enabled']
		);
	}
}
