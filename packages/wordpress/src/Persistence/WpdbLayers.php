<?php
/**
 * The wpdb repository for layers.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Persistence;

use SWMaps\Core\Contracts\LayerRepositoryInterface;
use SWMaps\Core\Domain\Layer;
use SWMaps\Core\Domain\LayerType;
use SWMaps\Core\Domain\MapStatus;

final class WpdbLayers implements LayerRepositoryInterface {

	public function find( int $id ): ?Layer {
		global $wpdb;
		$table = Schema::table( 'layers' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function forMap( int $mapId ): array {
		global $wpdb;
		$table = Schema::table( 'layers' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE map_id = %d ORDER BY sort_order ASC, id ASC", $mapId ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = $this->hydrate( $row );
		}
		return $out;
	}

	public function save( Layer $layer ): Layer {
		global $wpdb;
		$data  = array(
			'map_id'             => $layer->mapId,
			'title'              => $layer->title,
			'type'               => $layer->type->value,
			'status'             => $layer->status->value,
			'sort_order'         => $layer->sortOrder,
			'visible'            => $layer->visible ? 1 : 0,
			'style_json'         => Codec::encode( $layer->style ),
			'source_config_json' => '{}',
			'created_at'         => Codec::date( $layer->createdAt ),
			'updated_at'         => Codec::date( $layer->updatedAt ),
		);
		$fmt   = array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' );
		$table = Schema::table( 'layers' );
		if ( 0 === $layer->id ) {
			if ( false === $wpdb->insert( $table, $data, $fmt ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				throw new \RuntimeException( 'Could not store the layer.' );
			}
			return $layer->withId( (int) $wpdb->insert_id );
		}
		unset( $data['created_at'], $data['source_config_json'] );
		$fmt = array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );
		if ( false === $wpdb->update( $table, $data, array( 'id' => $layer->id ), $fmt, array( '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			throw new \RuntimeException( 'Could not update the layer.' );
		}
		return $layer;
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'layers' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function deleteForMap( int $mapId ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'layers' ), array( 'map_id' => $mapId ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function nextSortOrder( int $mapId ): int {
		global $wpdb;
		$table = Schema::table( 'layers' );
		$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$table} WHERE map_id = %d", $mapId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $max ? 0 : (int) $max + 1;
	}

	/** @param array<string, mixed> $row */
	private function hydrate( array $row ): Layer {
		return new Layer(
			(int) $row['id'],
			(int) $row['map_id'],
			(string) $row['title'],
			LayerType::tryFrom( (string) $row['type'] ) ?? LayerType::Features,
			MapStatus::tryFrom( (string) $row['status'] ) ?? MapStatus::Draft,
			(int) $row['sort_order'],
			(bool) $row['visible'],
			Codec::decode( $row['style_json'] ),
			Codec::parse_date( $row['created_at'] ),
			Codec::parse_date( $row['updated_at'] )
		);
	}
}
