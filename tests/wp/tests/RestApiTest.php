<?php
/**
 * REST API: authorisation, CRUD, validation, pagination, cascade deletes.
 *
 * @package sw-maps
 */

use SWMaps\WordPress\Services;

require_once __DIR__ . '/SwMapsTestCase.php';

final class RestApiTest extends SwMapsTestCase {

	private function point( float $lon = 8.4, float $lat = 49.0 ): array {
		return array( 'type' => 'Point', 'coordinates' => array( $lon, $lat ) );
	}

	public function test_anonymous_gets_401_and_subscriber_403_on_every_route(): void {
		$map_id = $this->create_map();
		$layer  = $this->rest( 'POST', "/maps/{$map_id}/layers", array( 'title' => 'L' ) )->get_data();
		$feat   = $this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point() ) )->get_data();
		$routes = array(
			array( 'GET', '/maps' ),
			array( 'POST', '/maps' ),
			array( 'GET', "/maps/{$map_id}" ),
			array( 'PATCH', "/maps/{$map_id}" ),
			array( 'DELETE', "/maps/{$map_id}" ),
			array( 'POST', "/maps/{$map_id}/duplicate" ),
			array( 'GET', "/maps/{$map_id}/layers" ),
			array( 'POST', "/maps/{$map_id}/layers" ),
			array( 'GET', "/layers/{$layer['id']}" ),
			array( 'PATCH', "/layers/{$layer['id']}" ),
			array( 'DELETE', "/layers/{$layer['id']}" ),
			array( 'GET', "/layers/{$layer['id']}/features" ),
			array( 'POST', "/layers/{$layer['id']}/features" ),
			array( 'GET', "/features/{$feat['id']}" ),
			array( 'PATCH', "/features/{$feat['id']}" ),
			array( 'DELETE', "/features/{$feat['id']}" ),
			array( 'GET', '/providers' ),
			array( 'POST', '/providers' ),
			array( 'GET', '/providers/1' ),
			array( 'POST', '/providers/1/test' ),
			array( 'GET', '/settings' ),
			array( 'PATCH', '/settings' ),
			array( 'GET', '/status' ),
		);
		wp_set_current_user( 0 );
		foreach ( $routes as list( $method, $route ) ) {
			$this->assertSame( 401, $this->rest( $method, $route, array() )->get_status(), "anonymous $method $route" );
		}
		$this->as_role( 'subscriber' );
		foreach ( $routes as list( $method, $route ) ) {
			$this->assertSame( 403, $this->rest( $method, $route, array() )->get_status(), "subscriber $method $route" );
		}
		// Nothing was changed by the rejected calls.
		$this->as_role( 'administrator' );
		$this->assertSame( 200, $this->rest( 'GET', "/features/{$feat['id']}" )->get_status() );
	}

	public function test_editor_can_edit_but_not_manage_system_or_imports(): void {
		$this->as_role( 'editor' );
		$this->assertSame( 201, $this->rest( 'POST', '/maps', array( 'title' => 'E' ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/providers' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/settings' )->get_status() );
		$this->assertFalse( current_user_can( 'swmap_manage_imports' ) );
	}

	public function test_map_crud_and_defaults_from_settings(): void {
		$this->as_role( 'administrator' );
		$created = $this->rest( 'POST', '/maps', array( 'title' => 'Wandern', 'status' => 'published' ) );
		$this->assertSame( 201, $created->get_status() );
		$map = $created->get_data();
		$this->assertSame( 'draft', $map['status'], 'new maps always start as draft' );
		$this->assertSame( 'wandern', $map['slug'] );
		$this->assertSame( 400, $map['settings']['height'] );
		$this->assertFalse( $map['settings']['scrollZoom'] );
		$this->assertArrayHasKey( 'Location', $created->get_headers() );

		$patched = $this->rest( 'PATCH', "/maps/{$map['id']}", array( 'title' => 'Neu', 'viewport' => array( 'zoom' => 9, 'center' => array( 9.1, 48.7 ) ), 'settings' => array( 'height' => 650 ) ) )->get_data();
		$this->assertSame( array( 'Neu', 9.0, 650 ), array( $patched['title'], $patched['viewport']['zoom'], $patched['settings']['height'] ) );
		$this->assertSame( 204, $this->rest( 'DELETE', "/maps/{$map['id']}" )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', "/maps/{$map['id']}" )->get_status() );
	}

	public function test_publishing_requires_publish_capability(): void {
		$id   = $this->create_map();
		$user = $this->as_role( 'editor' );
		get_role( 'editor' )->remove_cap( 'swmap_publish_maps' );
		wp_set_current_user( 0 );
		wp_set_current_user( $user );
		$this->assertSame( 403, $this->rest( 'PATCH', "/maps/{$id}", array( 'status' => 'published' ) )->get_status() );
		$this->assertSame( 200, $this->rest( 'PATCH', "/maps/{$id}", array( 'title' => 'ok' ) )->get_status() );
		get_role( 'editor' )->add_cap( 'swmap_publish_maps' );
		wp_set_current_user( 0 );
		wp_set_current_user( $user );
		$this->assertSame( 200, $this->rest( 'PATCH', "/maps/{$id}", array( 'status' => 'published' ) )->get_status() );
	}

	public function test_publish_fires_action_after_commit(): void {
		$id    = $this->create_map();
		$fired = array();
		add_action(
			'swmap_map_published',
			static function ( int $map_id ) use ( &$fired ): void {
				$fired[] = $map_id;
			}
		);
		$this->rest( 'PATCH', "/maps/{$id}", array( 'status' => 'published' ) );
		$this->rest( 'PATCH', "/maps/{$id}", array( 'status' => 'published' ) );
		$this->assertSame( array( $id ), $fired );
	}

	public function test_throwing_listener_does_not_break_operation(): void {
		$id = $this->create_map();
		add_action(
			'swmap_map_published',
			static function (): void {
				throw new \RuntimeException( 'listener failed' );
			}
		);
		$previous = ini_set( 'error_log', '/dev/null' );
		$status   = $this->rest( 'PATCH', "/maps/{$id}", array( 'status' => 'published' ) )->get_status();
		ini_set( 'error_log', (string) $previous );
		$this->assertSame( 200, $status );
	}

	public function test_validation_errors_use_stable_codes(): void {
		$this->as_role( 'administrator' );
		$r = $this->rest( 'POST', '/maps', array( 'title' => '' ) );
		$this->assertSame( 400, $r->get_status() );
		$this->assertSame( 'swmap_invalid_param', $r->get_data()['code'] );
		$this->assertSame( 'title', $r->get_data()['data']['details'][0]['field'] );

		$id    = $this->create_map();
		$layer = $this->rest( 'POST', "/maps/{$id}/layers", array( 'title' => 'L' ) )->get_data();
		$bad   = $this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => array( 'type' => 'Point', 'coordinates' => array( 999, 0 ) ) ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'swmap_invalid_geometry', $bad->get_data()['code'] );

		$conflict_id = $this->create_map( 'Zwei' );
		$this->assertSame( 409, $this->rest( 'PATCH', "/maps/{$conflict_id}", array( 'slug' => 'test-map' ) )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', '/layers/999999' )->get_status() );
	}

	public function test_pagination_headers_and_limits(): void {
		$this->as_role( 'administrator' );
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->rest( 'POST', '/maps', array( 'title' => "Map $i" ) );
		}
		$r = $this->rest( 'GET', '/maps', null, array( 'per_page' => 2, 'page' => 2 ) );
		$this->assertCount( 1, $r->get_data() );
		$this->assertSame( '3', (string) $r->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', (string) $r->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( 400, $this->rest( 'GET', '/maps', null, array( 'per_page' => 101 ) )->get_status() );
		$this->assertCount( 1, $this->rest( 'GET', '/maps', null, array( 'search' => 'Map 3' ) )->get_data() );
		$this->assertSame( 400, $this->rest( 'GET', '/maps', null, array( 'status' => 'bogus' ) )->get_status() );
	}

	public function test_layers_features_flow_and_cascade(): void {
		global $wpdb;
		$id    = $this->create_map();
		$layer = $this->rest( 'POST', "/maps/{$id}/layers", array( 'title' => 'Orte', 'style' => array( 'color' => '#336699' ) ) )->get_data();
		$feat  = $this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point(), 'properties' => array( 'title' => 'Rathaus', 'link' => 'https://example.org' ) ) );
		$this->assertSame( 201, $feat->get_status() );
		$feature = $feat->get_data();
		$moved   = $this->rest( 'PATCH', "/features/{$feature['id']}", array( 'geometry' => $this->point( 8.5, 49.1 ) ) )->get_data();
		$this->assertSame( array( 8.5, 49.1 ), $moved['geometry']['coordinates'] );
		$this->assertSame( 'Rathaus', $moved['properties']['title'] );

		$list = $this->rest( 'GET', "/layers/{$layer['id']}/features" );
		$this->assertSame( '1', (string) $list->get_headers()['X-WP-Total'] );
		$this->assertSame( 500, rest_get_server()->get_routes()['/sw-maps/v1/layers/(?P<id>\d+)/features'][0]['args']['per_page']['maximum'] );

		$map = $this->rest( 'GET', "/maps/{$id}" )->get_data();
		$this->assertSame( array( 1, 1 ), array( $map['layer_count'], $map['feature_count'] ) );

		$dup = $this->rest( 'POST', "/maps/{$id}/duplicate" );
		$this->assertSame( 201, $dup->get_status() );
		$this->assertSame( 1, $dup->get_data()['feature_count'] );

		$this->rest( 'DELETE', "/maps/{$id}" );
		$this->assertSame( 404, $this->rest( 'GET', "/features/{$feature['id']}" )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', "/layers/{$layer['id']}" )->get_status() );
		$left = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \SWMaps\WordPress\Persistence\Schema::table( 'features' ) ); // phpcs:ignore
		$this->assertSame( 1, $left, 'only the duplicate keeps its feature' );
	}

	public function test_feature_limit_per_layer(): void {
		$id    = $this->create_map();
		$layer = $this->rest( 'POST', "/maps/{$id}/layers", array( 'title' => 'L' ) )->get_data();
		$this->assertSame( 200, $this->rest( 'PATCH', '/settings', array( 'max_features_per_layer' => 1 ) )->get_status() );
		$this->assertSame( 201, $this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point() ) )->get_status() );
		$r = $this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point() ) );
		$this->assertSame( 413, $r->get_status() );
		$this->assertSame( 'swmap_limit_exceeded', $r->get_data()['code'] );
	}

	public function test_object_level_check_filter_blocks_other_maps(): void {
		$own   = $this->create_map( 'Own' );
		$other = $this->create_map( 'Other' );
		$layer = $this->rest( 'POST', "/maps/{$other}/layers", array( 'title' => 'L' ) )->get_data();
		$feat  = $this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point() ) )->get_data();
		add_filter( 'swmap_can_edit_map', static fn ( bool $allowed, int $map_id ): bool => $map_id === $own, 10, 2 );

		$this->assertSame( 200, $this->rest( 'GET', "/maps/{$own}" )->get_status() );
		foreach ( array(
			array( 'GET', "/maps/{$other}" ),
			array( 'PATCH', "/maps/{$other}" ),
			array( 'DELETE', "/maps/{$other}" ),
			array( 'GET', "/maps/{$other}/layers" ),
			array( 'GET', "/layers/{$layer['id']}" ),
			array( 'POST', "/layers/{$layer['id']}/features" ),
			array( 'GET', "/features/{$feat['id']}" ),
			array( 'DELETE', "/features/{$feat['id']}" ),
		) as list( $method, $route ) ) {
			$this->assertSame( 403, $this->rest( $method, $route, array() )->get_status(), "$method $route" );
		}
		$list = $this->rest( 'GET', '/maps' )->get_data();
		$this->assertSame( array( $own ), array_column( $list, 'id' ) );
	}

	public function test_providers_endpoints_hide_secrets_and_list_external_hosts(): void {
		$this->as_role( 'administrator' );
		define( 'SWMAP_TEST_SECRET', 'super-secret-value' );
		$created = $this->rest(
			'POST',
			'/providers',
			array(
				'name'            => 'Own tiles',
				'secret_constant' => 'SWMAP_TEST_SECRET',
				'public_config'   => array( 'urlTemplate' => 'https://tiles.example.org/{z}/{x}/{y}.png', 'attribution' => '© Own' ),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$data = $created->get_data();
		$this->assertTrue( $data['secret_defined'] );
		$this->assertSame( array( 'tiles.example.org' ), $data['external_requests'] );
		$this->assertFalse( $data['enabled'], 'providers start disabled' );
		$this->assertStringNotContainsString( 'super-secret-value', (string) wp_json_encode( $this->rest( 'GET', '/providers' )->get_data() ) );
		global $wpdb;
		$stored = $wpdb->get_var( 'SELECT CONCAT_WS("|", public_config_json, secret_constant) FROM ' . \SWMaps\WordPress\Persistence\Schema::table( 'providers' ) . ' WHERE id = ' . (int) $data['id'] ); // phpcs:ignore
		$this->assertStringNotContainsString( 'super-secret-value', (string) $stored );
		$this->assertStringContainsString( 'SWMAP_TEST_SECRET', (string) $stored );

		$this->assertSame( 400, $this->rest( 'POST', '/providers', array( 'name' => 'Bad', 'public_config' => array( 'urlTemplate' => 'javascript:x', 'attribution' => 'x' ) ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'PATCH', "/providers/{$data['id']}", array( 'secret_constant' => 'lowercase' ) )->get_status() );
		$this->assertSame( 204, $this->rest( 'DELETE', "/providers/{$data['id']}" )->get_status() );
	}

	public function test_provider_connection_test_reports_success_and_failure(): void {
		$this->as_role( 'administrator' );
		$id = Services::instance()->providers()->list()[0]->id;
		$mock = static function ( $pre, array $args, string $url ) {
			unset( $pre, $args );
			if ( str_contains( $url, 'tile.openstreetmap.org/0/0/0.png' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'image/png' ),
					'body'     => 'x',
					'response' => array( 'code' => 200, 'message' => 'OK' ),
				);
			}
			return new WP_Error( 'http_request_failed', 'down' );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		$ok = $this->rest( 'POST', "/providers/{$id}/test" )->get_data();
		$this->assertTrue( $ok['ok'] );
		$broken = $this->rest( 'POST', '/providers', array( 'name' => 'Broken', 'public_config' => array( 'urlTemplate' => 'https://broken.example.org/{z}/{x}/{y}.png', 'attribution' => 'x' ) ) )->get_data();
		$fail = $this->rest( 'POST', "/providers/{$broken['id']}/test" )->get_data();
		$this->assertFalse( $fail['ok'] );
		$this->assertSame( 0, $fail['status'] );
		remove_filter( 'pre_http_request', $mock, 10 );

		$html = static fn () => array(
			'headers'  => array( 'content-type' => 'text/html' ),
			'body'     => '<html>',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
		add_filter( 'pre_http_request', $html );
		$this->assertFalse( $this->rest( 'POST', "/providers/{$id}/test" )->get_data()['ok'] );
		remove_filter( 'pre_http_request', $html );
	}

	public function test_settings_and_status(): void {
		$this->as_role( 'administrator' );
		$this->assertFalse( $this->rest( 'GET', '/settings' )->get_data()['delete_data_on_uninstall'] );
		$updated = $this->rest( 'PATCH', '/settings', array( 'delete_data_on_uninstall' => true, 'default_height' => 500, 'unknown' => 1 ) )->get_data();
		$this->assertTrue( $updated['delete_data_on_uninstall'] );
		$this->assertSame( 500, $updated['default_height'] );
		$this->assertArrayNotHasKey( 'unknown', $updated );
		foreach ( array( array( 'default_height' => 5 ), array( 'default_height' => '500' ), array( 'click_to_load' => 'yes' ) ) as $bad ) {
			$this->assertSame( 400, $this->rest( 'PATCH', '/settings', $bad )->get_status() );
		}
		$this->assertSame( \SWMaps\WordPress\Persistence\Schema::CURRENT, $this->rest( 'GET', '/status' )->get_data()['db_version'] );
		delete_option( \SWMaps\WordPress\Settings::OPTION );
	}
}
