<?php
/**
 * Base class: clean tables per test (the plugin's own transactions end WP's test transaction, so rows are wiped explicitly).
 *
 * @package sw-maps
 */

use SWMaps\WordPress\Capabilities;
use SWMaps\WordPress\Persistence\Schema;
use SWMaps\WordPress\Services;

abstract class SwMapsTestCase extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Services::reset();
		Schema::migrate();
		Capabilities::grant();
		$this->wipe();
		wp_set_current_user( 0 );
		// Tests never depend on real DNS: *.example.org and *.example.com are public hosts, everything else keeps its fate.
		add_filter(
			'swmap_resolve_host',
			static fn ( $addresses, $host ) => preg_match( '/(^|\.)example\.(org|com)$/', (string) $host ) ? array( '93.184.216.34' ) : $addresses,
			10,
			2
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'swmap_resolve_host' );
		$this->wipe();
		remove_all_filters( 'swmap_can_edit_map' );
		parent::tear_down();
	}

	protected function wipe(): void {
		global $wpdb;
		foreach ( array( 'maps', 'layers', 'features', 'categories', 'feature_categories' ) as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) ); // phpcs:ignore
		}
	}

	protected function as_role( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @param array<string, mixed>      $params
	 */
	protected function rest( string $method, string $route, ?array $body = null, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/sw-maps/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		$request->set_query_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	protected function create_map( string $title = 'Test map' ): int {
		$this->as_role( 'administrator' );
		$response = $this->rest( 'POST', '/maps', array( 'title' => $title ) );
		$this->assertSame( 201, $response->get_status() );
		return (int) $response->get_data()['id'];
	}
}
