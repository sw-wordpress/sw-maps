<?php
/**
 * The wpdb repository for maps. All SQL goes through prepare() or wpdb helper methods with formats.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Persistence;

use SWMaps\Core\Contracts\MapRepositoryInterface;
use SWMaps\Core\Domain\MapEntity;
use SWMaps\Core\Domain\MapSettings;
use SWMaps\Core\Domain\MapStatus;
use SWMaps\Core\Domain\Viewport;

final class WpdbMaps implements MapRepositoryInterface {

	public function find( int $id ): ?MapEntity {
		global $wpdb;
		$table = Schema::table( 'maps' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function findBySlug( string $slug ): ?MapEntity {
		global $wpdb;
		$table = Schema::table( 'maps' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function search( string $search, ?string $status, int $page, int $perPage ): array {
		global $wpdb;
		$table  = Schema::table( 'maps' );
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$status = $status ?? '';
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE (%s = '' OR title LIKE %s OR slug LIKE %s) AND (%s = '' OR status = %s)", $search, $like, $like, $status, $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE (%s = '' OR title LIKE %s OR slug LIKE %s) AND (%s = '' OR status = %s) ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $search, $like, $like, $status, $status, $perPage, ( $page - 1 ) * $perPage ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[] = $this->hydrate( $row );
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	public function save( MapEntity $map ): MapEntity {
		global $wpdb;
		$data    = array(
			'slug'          => $map->slug,
			'title'         => $map->title,
			'description'   => $map->description,
			'status'        => $map->status->value,
			'viewport_json' => Codec::encode( $map->viewport->toArray() ),
			'settings_json' => Codec::encode( $map->settings->toArray() ),
			'created_by'    => $map->createdBy,
			'created_at'    => Codec::date( $map->createdAt ),
			'updated_at'    => Codec::date( $map->updatedAt ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
		$table   = Schema::table( 'maps' );
		if ( 0 === $map->id ) {
			if ( false === $wpdb->insert( $table, $data, $formats ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				throw new \RuntimeException( 'Could not store the map.' );
			}
			return $map->withId( (int) $wpdb->insert_id );
		}
		unset( $data['created_by'], $data['created_at'] );
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		if ( false === $wpdb->update( $table, $data, array( 'id' => $map->id ), $formats, array( '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			throw new \RuntimeException( 'Could not update the map.' );
		}
		return $map;
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'maps' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function slugExists( string $slug, int $exceptId = 0 ): bool {
		global $wpdb;
		$table = Schema::table( 'maps' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s AND id <> %d", $slug, $exceptId ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param array<string, mixed> $row */
	private function hydrate( array $row ): MapEntity {
		return new MapEntity(
			(int) $row['id'],
			(string) $row['slug'],
			(string) $row['title'],
			(string) $row['description'],
			MapStatus::tryFrom( (string) $row['status'] ) ?? MapStatus::Draft,
			Viewport::fromArray( Codec::decode( $row['viewport_json'] ) ),
			MapSettings::fromArray( Codec::decode( $row['settings_json'] ) ),
			(int) $row['created_by'],
			Codec::parse_date( $row['created_at'] ),
			Codec::parse_date( $row['updated_at'] )
		);
	}
}
