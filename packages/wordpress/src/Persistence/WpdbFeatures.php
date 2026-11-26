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
		$table   = Schema::table( 'features' );
		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$feature = is_array( $row ) ? $this->hydrate( $row ) : null;
		return null === $feature ? null : $this->attach_categories( array( $feature ) )[0];
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

	/**
	 * @param int      $layerId Layer ID.
	 * @param string[] $externalIds External IDs.
	 * @return array<string, Feature>
	 */
	public function byExternalIds( int $layerId, array $externalIds ): array {
		global $wpdb;
		if ( array() === $externalIds ) {
			return array();
		}
		$table = Schema::table( 'features' );
		$out   = array();
		foreach ( array_chunk( $externalIds, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE layer_id = %d AND external_id IN ({$placeholders})", $layerId, ...$chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			foreach ( $this->hydrate_all( $rows ) as $feature ) {
				if ( null !== $feature->externalId ) {
					$out[ $feature->externalId ] = $feature;
				}
			}
		}
		return $out;
	}

	/**
	 * @param int[]   $layerIds Layer ids.
	 * @param float[] $bounds minLon, minLat, maxLon, maxLat.
	 * @param int[]   $categoryIds Category filter (any).
	 * @param int     $page Page.
	 * @param int     $perPage Page size.
	 * @return array{items: list<Feature>, total: int}
	 */
	public function inBounds( array $layerIds, array $bounds, array $categoryIds, int $page, int $perPage ): array {
		global $wpdb;
		if ( array() === $layerIds ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}
		$table    = Schema::table( 'features' );
		$links    = Schema::table( 'feature_categories' );
		$layer_in = implode( ',', array_fill( 0, count( $layerIds ), '%d' ) );
		$where    = "f.layer_id IN ({$layer_in}) AND f.bbox_max_lon >= %f AND f.bbox_min_lon <= %f AND f.bbox_max_lat >= %f AND f.bbox_min_lat <= %f";
		$args     = array_merge( array_map( 'intval', $layerIds ), array( $bounds[0], $bounds[2], $bounds[1], $bounds[3] ) );
		if ( array() !== $categoryIds ) {
			$cat_in = implode( ',', array_fill( 0, count( $categoryIds ), '%d' ) );
			$where .= " AND EXISTS (SELECT 1 FROM {$links} fc WHERE fc.feature_id = f.id AND fc.category_id IN ({$cat_in}))";
			$args   = array_merge( $args, array_map( 'intval', $categoryIds ) );
		}
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} f WHERE {$where}", ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT f.* FROM {$table} f WHERE {$where} ORDER BY f.layer_id ASC, f.id ASC LIMIT %d OFFSET %d", ...array_merge( $args, array( $perPage, ( $page - 1 ) * $perPage ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return array(
			'items' => $this->hydrate_all( $rows ),
			'total' => $total,
		);
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
			$saved = $feature->withId( (int) $wpdb->insert_id );
			$this->sync_categories( $saved );
			return $saved;
		}
		unset( $data['layer_id'], $data['external_id'] );
		if ( false === $wpdb->update( $table, $data, array( 'id' => $feature->id ), array( '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s' ), array( '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			throw new \RuntimeException( 'Could not update the feature.' );
		}
		$this->sync_categories( $feature );
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
			$feature = $this->hydrate( $row );
			if ( null !== $feature ) {
				$out[] = $feature;
			}
		}
		return $this->attach_categories( $out );
	}

	/**
	 * Loads the category assignments of all given features with one query.
	 *
	 * @param Feature[] $features Features.
	 * @return list<Feature>
	 */
	private function attach_categories( array $features ): array {
		global $wpdb;
		if ( array() === $features ) {
			return $features;
		}
		$ids          = array_map( static fn ( Feature $f ): int => $f->id, $features );
		$table        = Schema::table( 'feature_categories' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT feature_id, category_id FROM {$table} WHERE feature_id IN ({$placeholders}) ORDER BY category_id ASC", ...$ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$by_feature   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by_feature[ (int) $row['feature_id'] ][] = (int) $row['category_id'];
		}
		return array_values( array_map( static fn ( Feature $f ): Feature => $f->with( null, null, null, null, $by_feature[ $f->id ] ?? array() ), $features ) );
	}

	/**
	 * Replaces the assignments of a feature.
	 *
	 * @param Feature $feature Saved feature (with id).
	 */
	private function sync_categories( Feature $feature ): void {
		global $wpdb;
		$table = Schema::table( 'feature_categories' );
		$wpdb->delete( $table, array( 'feature_id' => $feature->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $feature->categoryIds as $category_id ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				array(
					'feature_id'  => $feature->id,
					'category_id' => $category_id,
				),
				array( '%d', '%d' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
	}

	/**
	 * Rows with a broken geometry are skipped (and logged) instead of breaking the whole page; invalid property
	 * fields fall back to their defaults.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): ?Feature {
		try {
			$geometry = Geometry::fromArray( Codec::decode( $row['geometry_json'] ) );
		} catch ( \Throwable ) {
			error_log( sprintf( 'sw-maps: feature %d has an invalid geometry and was skipped.', (int) $row['id'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}
		return new Feature(
			(int) $row['id'],
			(int) $row['layer_id'],
			null === $row['external_id'] ? null : (string) $row['external_id'],
			$geometry,
			FeatureProperties::lenient( Codec::decode( $row['properties_json'] ) ),
			Codec::decode( $row['style_json'] ),
			Codec::parse_date( $row['updated_at'] )
		);
	}
}
