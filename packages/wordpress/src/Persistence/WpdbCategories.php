<?php
/**
 * The wpdb repository for categories and their feature assignments.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Persistence;

use SWMaps\Core\Contracts\CategoryRepositoryInterface;
use SWMaps\Core\Domain\Category;
use SWMaps\Core\Exception\ConflictException;

final class WpdbCategories implements CategoryRepositoryInterface {

	public function find( int $id ): ?Category {
		global $wpdb;
		$table = Schema::table( 'categories' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function all(): array {
		global $wpdb;
		$table = Schema::table( 'categories' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY title ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = $this->hydrate( $row );
		}
		return $out;
	}

	/**
	 * @param int[] $ids Category ids.
	 * @return list<int>
	 */
	public function existing( array $ids ): array {
		global $wpdb;
		if ( array() === $ids ) {
			return array();
		}
		$table        = Schema::table( 'categories' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$found        = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id IN ({$placeholders})", ...array_map( 'intval', $ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return array_values( array_map( 'intval', is_array( $found ) ? $found : array() ) );
	}

	public function slugExists( string $slug, ?int $exceptId = null ): bool {
		global $wpdb;
		$table = Schema::table( 'categories' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s AND id <> %d", $slug, $exceptId ?? 0 ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function save( Category $category ): Category {
		global $wpdb;
		$data  = array(
			'slug'       => $category->slug,
			'title'      => $category->title,
			'style_json' => Codec::encode( $category->style ),
		);
		$table = Schema::table( 'categories' );
		$quiet = $wpdb->suppress_errors( true );
		try {
			if ( 0 === $category->id ) {
				$ok = $wpdb->insert( $table, $data, array( '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				if ( false === $ok ) {
					throw new ConflictException( 'Slug already in use.' );
				}
				return $category->withId( (int) $wpdb->insert_id );
			}
			if ( false === $wpdb->update( $table, $data, array( 'id' => $category->id ), array( '%s', '%s', '%s' ), array( '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				throw new ConflictException( 'Slug already in use.' );
			}
			return $category;
		} finally {
			$wpdb->suppress_errors( $quiet );
		}
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'feature_categories' ), array( 'category_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( 'categories' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): Category {
		return new Category( (int) $row['id'], (string) $row['slug'], (string) $row['title'], Codec::decode( $row['style_json'] ) );
	}
}
