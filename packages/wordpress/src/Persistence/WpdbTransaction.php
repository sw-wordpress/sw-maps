<?php
/**
 * Database transaction around a callback (InnoDB). Nested calls join the outer transaction.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Persistence;

use SWMaps\Core\Contracts\TransactionInterface;

final class WpdbTransaction implements TransactionInterface {

	private int $depth = 0;

	public function run( callable $work ): mixed {
		global $wpdb;
		if ( $this->depth > 0 ) {
			return $work();
		}
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		++$this->depth;
		try {
			$result = $work();
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			throw $e;
		} finally {
			--$this->depth;
		}
	}
}
