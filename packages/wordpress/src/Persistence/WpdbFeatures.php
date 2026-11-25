<?php
/**
 * The wpdb repository for features. The canonical geometry is stored as GeoJSON together with its bounding box.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Persistence;

use SWMaps\Core\Contracts\FeatureRepositoryInterface;
use SWMaps\Core\Domain\Feature;
use SWMaps\Core\Domain\FeatureProperties;
use SWMaps\Core\Domain\Geometry;

final class WpdbFeatures implements FeatureRepositoryInterface {

	public function find( int $id ): ?Feature {
		global $wpdb;
		$table = Schema::table( 'features' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function page( int $layerId, int $page, int $perPage ): array {
		global $wpdb;
		$table = Schema::table( 'features' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE layer_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", $layerId, $perPage, ( $page - 1 ) * $perPage ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->hydrate_all( $rows );
	}

	public function forLayers( array $layerIds ): array {
		global $wpdb;
		if ( array() === $layerIds ) {
			return array();
		}
		$table        = Schema::table( 'features' );
		$placeholders = implode( ',', array_fill( 0, count( $layerIds ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE layer_id IN ({$placeholders}) ORDER BY layer_id ASC, id ASC", ...array_map( 'intval', $layerIds ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $this->hydrate_all( $rows );
	}

	public function count( int $layerId ): int {
		global $wpdb;
		$table = Schema::table( 'features' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE layer_id = %d", $layerId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function countForMap( int $mapId ): int {
		global $wpdb;
		$features = Schema::table( 'features' );
		$layers   = Schema::table( 'layers' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$features} f INNER JOIN {$layers} l ON l.id = f.layer_id WHERE l.map_id = %d", $mapId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function save( Feature $feature ): Feature {
		global $wpdb;
		$bounds = $feature->bounds();
		$data   = array(
			'layer_id'        => $feature->layerId,
			'external_id'     => $feature->externalId,
			'geometry_type'   => $feature->geometry->type->value,
			'geometry_json'   => Codec::encode( $feature->geometry->toArray() ),
			'bbox_min_lon'    => $bounds->minLongitude,
			'bbox_min_lat'    => $bounds->minLatitude,
			'bbox_max_lon'    => $bounds->maxLongitude,
			'bbox_max_lat'    => $bounds->maxLatitude,
			'properties_json' => Codec::encode( $feature->properties->toArray() ),
			'style_json'      => Codec::encode( $feature->style ),
			'updated_at'      => Codec::date( $feature->updatedAt ),
		);
		$fmt    = array( '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s' );
		$table  = Schema::table( 'features' );
		if ( 0 === $feature->id ) {
			if ( false === $wpdb->insert( $table, $data, $fmt ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				throw new \RuntimeException( 'Could not store the feature.' );
			}
			return $feature->withId( (int) $wpdb->insert_id );
		}
		unset( $data['layer_id'], $data['external_id'] );
		if ( false === $wpdb->update( $table, $data, array( 'id' => $feature->id ), array( '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s' ), array( '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			throw new \RuntimeException( 'Could not update the feature.' );
		}
		return $feature;
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'feature_categories' ), array( 'feature_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( 'features' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function deleteForLayer( int $layerId ): void {
		global $wpdb;
		$features = Schema::table( 'features' );
		$links    = Schema::table( 'feature_categories' );
		$wpdb->query( $wpdb->prepare( "DELETE fc FROM {$links} fc INNER JOIN {$features} f ON f.id = fc.feature_id WHERE f.layer_id = %d", $layerId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $features, array( 'layer_id' => $layerId ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function deleteForMap( int $mapId ): void {
		global $wpdb;
		$features = Schema::table( 'features' );
		$links    = Schema::table( 'feature_categories' );
		$layers   = Schema::table( 'layers' );
		$wpdb->query( $wpdb->prepare( "DELETE fc FROM {$links} fc INNER JOIN {$features} f ON f.id = fc.feature_id INNER JOIN {$layers} l ON l.id = f.layer_id WHERE l.map_id = %d", $mapId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE f FROM {$features} f INNER JOIN {$layers} l ON l.id = f.layer_id WHERE l.map_id = %d", $mapId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param mixed $rows
	 * @return list<Feature>
	 */
	private function hydrate_all( mixed $rows ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = $this->hydrate( $row );
		}
		return $out;
	}

	/** @param array<string, mixed> $row */
	private function hydrate( array $row ): Feature {
		$geometry = Geometry::fromArray( Codec::decode( $row['geometry_json'] ) );
		return new Feature(
			(int) $row['id'],
			(int) $row['layer_id'],
			null === $row['external_id'] ? null : (string) $row['external_id'],
			$geometry,
			FeatureProperties::fromArray( Codec::decode( $row['properties_json'] ) ),
			Codec::decode( $row['style_json'] ),
			Codec::parse_date( $row['updated_at'] )
		);
	}
}
