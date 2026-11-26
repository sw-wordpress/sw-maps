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
		return array(
			'type'        => 'Point',
			'coordinates' => array( $lon, $lat ),
		);
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
			array( 'POST', '/providers' ),
			array( 'GET', '/providers/1' ),
			array( 'POST', '/providers/1/test' ),
			array( 'GET', '/settings' ),
			array( 'PATCH', '/settings' ),
			array( 'GET', '/status' ),
		);
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', '/providers' )->get_status() );
		foreach ( $routes as list( $method, $route ) ) {
			$this->assertSame( 401, $this->rest( $method, $route, array() )->get_status(), "anonymous $method $route" );
		}
		$this->as_role( 'subscriber' );
		$this->assertSame( 403, $this->rest( 'GET', '/providers' )->get_status() );
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
		$providers = $this->rest( 'GET', '/providers' );
		$this->assertSame( 200, $providers->get_status(), 'editors read the basemap configuration' );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $providers->get_data() ) );
		$this->assertSame( array( 'id', 'type', 'name', 'public_config', 'enabled' ), array_keys( $providers->get_data()[0] ) );
		$this->assertSame( 403, $this->rest( 'POST', '/providers', array( 'name' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/providers/1' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/settings' )->get_status() );
		$this->assertFalse( current_user_can( 'swmap_manage_imports' ) );
	}

	public function test_map_crud_and_defaults_from_settings(): void {
		$this->as_role( 'administrator' );
		$created = $this->rest(
			'POST',
			'/maps',
			array(
				'title'  => 'Wandern',
				'status' => 'published',
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$map = $created->get_data();
		$this->assertSame( 'draft', $map['status'], 'new maps always start as draft' );
		$this->assertSame( 'wandern', $map['slug'] );
		$this->assertSame( 400, $map['settings']['height'] );
		$this->assertFalse( $map['settings']['scrollZoom'] );
		$this->assertArrayHasKey( 'Location', $created->get_headers() );

		$patched = $this->rest(
			'PATCH',
			"/maps/{$map['id']}",
			array(
				'title'    => 'Neu',
				'viewport' => array(
					'zoom'   => 9,
					'center' => array( 9.1, 48.7 ),
				),
				'settings' => array( 'height' => 650 ),
			)
		)->get_data();
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
		$bad   = $this->rest(
			'POST',
			"/layers/{$layer['id']}/features",
			array(
				'geometry' => array(
					'type'        => 'Point',
					'coordinates' => array( 999, 0 ),
				),
			)
		);
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
		$r = $this->rest(
			'GET',
			'/maps',
			null,
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
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
		$layer = $this->rest(
			'POST',
			"/maps/{$id}/layers",
			array(
				'title' => 'Orte',
				'style' => array( 'color' => '#336699' ),
			)
		)->get_data();
		$feat  = $this->rest(
			'POST',
			"/layers/{$layer['id']}/features",
			array(
				'geometry'   => $this->point(),
				'properties' => array(
					'title' => 'Rathaus',
					'link'  => 'https://example.org',
				),
			)
		);
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

	public function test_admin_app_payload_with_null_external_id_is_accepted(): void {
		$id    = $this->create_map();
		$layer = $this->rest(
			'POST',
			"/maps/{$id}/layers",
			array(
				'title'      => 'L',
				'status'     => 'published',
				'sort_order' => 0,
				'visible'    => true,
				'style'      => array(),
			)
		);
		$this->assertSame( 201, $layer->get_status() );
		$feature = $this->rest(
			'POST',
			'/layers/' . $layer->get_data()['id'] . '/features',
			array(
				'geometry'    => $this->point(),
				'properties'  => array(
					'title'       => 'A',
					'description' => '',
					'address'     => '',
					'image_id'    => null,
					'link'        => null,
					'icon'        => 'builtin:pin',
				),
				'style'       => array(),
				'external_id' => null,
			)
		);
		$this->assertSame( 201, $feature->get_status() );
	}

	public function test_duplicate_slug_on_the_unique_index_is_a_conflict_not_a_server_error(): void {
		$id    = $this->create_map( 'Taken' );
		$taken = Services::instance()->maps()->get( $id );
		$copy  = new SWMaps\Core\Domain\MapEntity( 0, $taken->slug, 'Other', '', $taken->status, $taken->viewport, $taken->settings, 1, $taken->createdAt, $taken->updatedAt );
		$this->expectException( SWMaps\Core\Exception\ConflictException::class );
		Services::instance()->map_repository()->save( $copy );
	}

	public function test_parallel_creation_with_the_same_title_never_fails(): void {
		$this->as_role( 'administrator' );
		$slugs = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$response = $this->rest( 'POST', '/maps', array( 'title' => 'Same title' ) );
			$this->assertSame( 201, $response->get_status() );
			$slugs[] = $response->get_data()['slug'];
		}
		$this->assertCount( 4, array_unique( $slugs ) );
	}

	public function test_feature_responses_include_resolved_media_urls_for_the_editor(): void {
		$id    = $this->create_map();
		$layer = $this->rest( 'POST', "/maps/{$id}/layers", array( 'title' => 'L' ) )->get_data();
		$png   = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$feat  = $this->rest(
			'POST',
			"/layers/{$layer['id']}/features",
			array(
				'geometry'   => $this->point(),
				'properties' => array(
					'image_id' => $png,
					'icon'     => 'media:' . $png,
				),
			)
		)->get_data();
		$this->assertStringContainsString( 'canola', (string) $feat['properties']['icon_url'] );
		$this->assertStringContainsString( 'canola', (string) $feat['properties']['image_url'] );
		$list = $this->rest( 'GET', "/layers/{$layer['id']}/features" )->get_data();
		$this->assertStringContainsString( 'canola', (string) $list[0]['properties']['icon_url'] );
		$one = $this->rest( 'GET', "/features/{$feat['id']}" )->get_data();
		$this->assertStringContainsString( 'canola', (string) $one['properties']['image_url'] );
		$updated = $this->rest( 'PATCH', "/features/{$feat['id']}", array( 'properties' => array( 'title' => 'T' ) ) )->get_data();
		$this->assertStringContainsString( 'canola', (string) $updated['properties']['icon_url'] );
		$this->assertSame( 'builtin:pin', $this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point() ) )->get_data()['properties']['icon'] );
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
				'public_config'   => array(
					'urlTemplate' => 'https://tiles.example.org/{z}/{x}/{y}.png',
					'attribution' => '© Own',
				),
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

		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/providers',
				array(
					'name'          => 'Bad',
					'public_config' => array(
						'urlTemplate' => 'javascript:x',
						'attribution' => 'x',
					),
				)
			)->get_status()
		);
		$this->assertSame( 400, $this->rest( 'PATCH', "/providers/{$data['id']}", array( 'secret_constant' => 'lowercase' ) )->get_status() );
		$this->assertSame( 204, $this->rest( 'DELETE', "/providers/{$data['id']}" )->get_status() );
	}

	public function test_core_credentials_can_never_be_used_as_provider_secrets(): void {
		$this->as_role( 'administrator' );
		foreach ( array( 'DB_PASSWORD', 'AUTH_KEY', 'ABSPATH' ) as $constant ) {
			$response = $this->rest(
				'POST',
				'/providers',
				array(
					'name'            => 'Leak',
					'secret_constant' => $constant,
					'public_config'   => array(
						'urlTemplate' => 'https://attacker.example/{z}/{x}/{y}?v={secret}',
						'attribution' => 'x',
					),
				)
			);
			$this->assertSame( 400, $response->get_status(), $constant );
		}
		// A row written before the rule existed is still never resolved by the connection test.
		global $wpdb;
		// A public IP literal passes wp_safe_remote_get()'s URL validation without DNS, so the request reaches the filter.
		$created = $this->rest(
			'POST',
			'/providers',
			array(
				'name'          => 'Legacy',
				'public_config' => array(
					'urlTemplate' => 'https://93.184.216.34/{z}/{x}/{y}?v={secret}',
					'attribution' => 'x',
				),
			)
		)->get_data();
		$wpdb->update( SWMaps\WordPress\Persistence\Schema::table( 'providers' ), array( 'secret_constant' => 'DB_PASSWORD' ), array( 'id' => $created['id'] ) );
		$requested = array();
		$spy       = static function ( $pre, array $args, string $url ) use ( &$requested ) {
			unset( $pre, $args );
			$requested[] = $url;
			return new WP_Error( 'http_request_failed', 'blocked' );
		};
		add_filter( 'pre_http_request', $spy, 10, 3 );
		$this->rest( 'POST', "/providers/{$created['id']}/test" );
		remove_filter( 'pre_http_request', $spy, 10 );
		$this->assertCount( 1, $requested );
		$this->assertStringNotContainsString( DB_PASSWORD, $requested[0] );
		$this->assertFalse( $this->rest( 'GET', "/providers/{$created['id']}" )->get_data()['secret_defined'] );
	}

	public function test_provider_connection_test_reports_success_and_failure(): void {
		$this->as_role( 'administrator' );
		$id   = Services::instance()->providers()->list()[0]->id;
		$mock = static function ( $pre, array $args, string $url ) {
			unset( $pre, $args );
			if ( str_contains( $url, 'tile.openstreetmap.org/0/0/0.png' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'image/png' ),
					'body'     => 'x',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}
			return new WP_Error( 'http_request_failed', 'down' );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		$ok = $this->rest( 'POST', "/providers/{$id}/test" )->get_data();
		$this->assertTrue( $ok['ok'] );
		$broken = $this->rest(
			'POST',
			'/providers',
			array(
				'name'          => 'Broken',
				'public_config' => array(
					'urlTemplate' => 'https://broken.example.org/{z}/{x}/{y}.png',
					'attribution' => 'x',
				),
			)
		)->get_data();
		$fail   = $this->rest( 'POST', "/providers/{$broken['id']}/test" )->get_data();
		$this->assertFalse( $fail['ok'] );
		$this->assertSame( 0, $fail['status'] );
		remove_filter( 'pre_http_request', $mock, 10 );

		$html = static fn () => array(
			'headers'  => array( 'content-type' => 'text/html' ),
			'body'     => '<html>',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
		add_filter( 'pre_http_request', $html );
		$this->assertFalse( $this->rest( 'POST', "/providers/{$id}/test" )->get_data()['ok'] );
		remove_filter( 'pre_http_request', $html );
	}

	public function test_settings_and_status(): void {
		$this->as_role( 'administrator' );
		$this->assertFalse( $this->rest( 'GET', '/settings' )->get_data()['delete_data_on_uninstall'] );
		$updated = $this->rest(
			'PATCH',
			'/settings',
			array(
				'delete_data_on_uninstall' => true,
				'default_height'           => 500,
				'unknown'                  => 1,
			)
		)->get_data();
		$this->assertTrue( $updated['delete_data_on_uninstall'] );
		$this->assertSame( 500, $updated['default_height'] );
		$this->assertArrayNotHasKey( 'unknown', $updated );
		foreach ( array( array( 'default_height' => 5 ), array( 'default_height' => '500' ), array( 'click_to_load' => 'yes' ) ) as $bad ) {
			$this->assertSame( 400, $this->rest( 'PATCH', '/settings', $bad )->get_status() );
		}
		$this->assertSame( \SWMaps\WordPress\Persistence\Schema::CURRENT, $this->rest( 'GET', '/status' )->get_data()['db_version'] );
		delete_option( \SWMaps\WordPress\Settings::OPTION );
	}
	public function test_imports_validate_execute_and_reject(): void {
		$map_id  = $this->create_map();
		$geojson = (string) wp_json_encode(
			array(
				'type'     => 'FeatureCollection',
				'features' => array(
					array(
						'type'       => 'Feature',
						'properties' => array(
							'name' => 'A',
							'id'   => 'a1',
						),
						'geometry'   => $this->point(),
					),
					array(
						'type'       => 'Feature',
						'properties' => array( 'name' => 'Track' ),
						'geometry'   => array(
							'type'        => 'LineString',
							'coordinates' => array( array( 8, 49 ), array( 8.1, 49.1 ) ),
						),
					),
				),
			)
		);
		$base    = array(
			'map_id'      => $map_id,
			'format'      => 'geojson',
			'content'     => $geojson,
			'layer_title' => 'Imported',
		);

		$dry = $this->rest( 'POST', '/imports', $base + array( 'dry_run' => true ) );
		$this->assertSame( 200, $dry->get_status() );
		$this->assertFalse( $dry->get_data()['imported'] );
		$this->assertSame( array(), $this->rest( 'GET', "/maps/{$map_id}/layers" )->get_data() );

		$done = $this->rest( 'POST', '/imports', $base );
		$this->assertSame( 200, $done->get_status() );
		$report = $done->get_data();
		$this->assertTrue( $report['imported'] );
		$this->assertSame( 2, $report['created'] );
		$features = $this->rest( 'GET', "/layers/{$report['layer_id']}/features" )->get_data();
		$this->assertCount( 2, $features );

		// Upsert by external id updates in place.
		$again = $this->rest(
			'POST',
			'/imports',
			array(
				'map_id'   => $map_id,
				'mode'     => 'upsert',
				'layer_id' => $report['layer_id'],
			) + $base
		);
		$this->assertSame( array( 1, 1 ), array( $again->get_data()['updated'], $again->get_data()['created'] ) );

		// Hostile XML is rejected as a whole, nothing is written.
		$xxe = '<?xml version="1.0"?><!DOCTYPE gpx [<!ENTITY x SYSTEM "file:///etc/passwd">]><gpx><wpt lat="1" lon="1"><name>&x;</name></wpt></gpx>';
		$bad = $this->rest(
			'POST',
			'/imports',
			array(
				'map_id'  => $map_id,
				'format'  => 'gpx',
				'content' => $xxe,
			)
		);
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 'forbidden', $bad->as_error()->get_error_data()['reason'] );
		$this->assertCount( 1, $this->rest( 'GET', "/maps/{$map_id}/layers" )->get_data() );

		// Replacing needs the explicit confirmation.
		$replace = array(
			'map_id'   => $map_id,
			'mode'     => 'replace',
			'layer_id' => $report['layer_id'],
		) + $base;
		$this->assertSame( 400, $this->rest( 'POST', '/imports', $replace )->get_status() );
		$this->assertSame( 200, $this->rest( 'POST', '/imports', $replace + array( 'confirm_replace' => true ) )->get_status() );

		$this->assertSame(
			404,
			$this->rest(
				'POST',
				'/imports',
				array(
					'map_id'  => 999999,
					'format'  => 'geojson',
					'content' => $geojson,
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/imports',
				array(
					'format'  => 'geojson',
					'content' => $geojson,
				)
			)->get_status()
		);

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'POST', '/imports', $base )->get_status() );
		$this->as_role( 'editor' );
		$this->assertSame( 403, $this->rest( 'POST', '/imports', $base )->get_status(), 'editors lack swmap_manage_imports' );
	}

	public function test_categories_crud_assignment_and_public_payload(): void {
		$map_id = $this->create_map();
		$layer  = $this->rest( 'POST', "/maps/{$map_id}/layers", array( 'title' => 'L' ) )->get_data();
		$cat    = $this->rest(
			'POST',
			'/categories',
			array(
				'title' => 'Huts',
				'style' => array(
					'color' => '#00aa00',
					'icon'  => 'builtin:home',
				),
			)
		);
		$this->assertSame( 201, $cat->get_status() );
		$cat_id = $cat->get_data()['id'];
		$this->assertSame( 'huts', $cat->get_data()['slug'] );
		$unused = $this->rest( 'POST', '/categories', array( 'title' => 'Unused' ) )->get_data();

		$feature = $this->rest(
			'POST',
			"/layers/{$layer['id']}/features",
			array(
				'geometry'   => $this->point(),
				'properties' => array( 'title' => 'A' ),
				'categories' => array( $cat_id ),
			)
		);
		$this->assertSame( 201, $feature->get_status() );
		$this->assertSame( array( $cat_id ), $feature->get_data()['categories'] );
		$fid = $feature->get_data()['id'];
		$this->assertSame( array( $cat_id ), $this->rest( 'GET', "/features/{$fid}" )->get_data()['categories'] );
		$listed = $this->rest( 'GET', "/layers/{$layer['id']}/features" )->get_data();
		$this->assertSame( array( $cat_id ), $listed[0]['categories'] );
		$this->assertSame( 400, $this->rest( 'PATCH', "/features/{$fid}", array( 'categories' => array( 99999 ) ) )->get_status() );
		$this->assertSame( array( $cat_id ), $this->rest( 'PATCH', "/features/{$fid}", array( 'properties' => array( 'title' => 'B' ) ) )->get_data()['categories'] );

		// The public payload contains only categories that are in use.
		$this->rest( 'PATCH', "/maps/{$map_id}", array( 'status' => 'published' ) );
		$payload = \SWMaps\WordPress\Integration\MapPayload::build( \SWMaps\WordPress\Services::instance()->maps()->get( $map_id ) );
		$this->assertCount( 1, $payload['categories'] );
		$this->assertSame( 'Huts', $payload['categories'][0]['title'] );
		$this->assertSame( array( $cat_id ), $payload['layers'][0]['features'][0]['categories'] );
		$html = \SWMaps\WordPress\Integration\Renderer::render( $map_id );
		$this->assertStringContainsString( 'data-categories="' . $cat_id . '"', $html );
		$this->assertStringContainsString( 'data-search="b"', $html );

		$copy_id    = $this->rest( 'POST', "/maps/{$map_id}/duplicate" )->get_data()['id'];
		$copy_layer = $this->rest( 'GET', "/maps/{$copy_id}/layers" )->get_data()[0];
		$this->assertSame( array( $cat_id ), $this->rest( 'GET', "/layers/{$copy_layer['id']}/features" )->get_data()[0]['categories'], 'duplicating a map keeps the category assignments' );

		$patched = $this->rest( 'PATCH', "/categories/{$cat_id}", array( 'title' => 'Mountain huts' ) );
		$this->assertSame( 'Mountain huts', $patched->get_data()['title'] );
		$this->assertCount( 2, $this->rest( 'GET', '/categories' )->get_data() );
		$this->assertSame( 204, $this->rest( 'DELETE', "/categories/{$cat_id}" )->get_status() );
		$this->assertSame( array(), $this->rest( 'GET', "/features/{$fid}" )->get_data()['categories'] );
		$this->assertSame( 404, $this->rest( 'DELETE', "/categories/{$cat_id}" )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/categories', array() )->get_status() );
		$this->assertSame( $unused['id'], $this->rest( 'GET', '/categories' )->get_data()[0]['id'] );

		// Editors have both capabilities; subscribers have neither.
		$this->as_role( 'editor' );
		$this->assertSame( 200, $this->rest( 'GET', '/categories' )->get_status() );
		$this->assertSame( 201, $this->rest( 'POST', '/categories', array( 'title' => 'x' ) )->get_status() );
		$this->as_role( 'subscriber' );
		$this->assertSame( 403, $this->rest( 'GET', '/categories' )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/categories', array( 'title' => 'x' ) )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', '/categories' )->get_status() );
	}

	public function test_large_tracks_get_a_simplified_display_variant_but_stay_canonical(): void {
		$map_id = $this->create_map();
		$layer  = $this->rest( 'POST', "/maps/{$map_id}/layers", array( 'title' => 'Tracks' ) )->get_data();
		$points = array();
		for ( $i = 0; $i < 800; $i++ ) {
			$points[] = array( 8 + $i * 0.0001, 49 + ( 0 === $i % 2 ? 0.0 : 0.000001 ) );
		}
		$created = $this->rest(
			'POST',
			"/layers/{$layer['id']}/features",
			array(
				'geometry'   => array(
					'type'        => 'LineString',
					'coordinates' => $points,
				),
				'properties' => array(
					'title'  => 'Long',
					'fields' => array(
						array(
							'label' => 'Length',
							'value' => '8 km',
						),
					),
				),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame(
			array(
				array(
					'label' => 'Length',
					'value' => '8 km',
				),
			),
			$created->get_data()['properties']['fields']
		);
		$small = $this->rest(
			'POST',
			"/layers/{$layer['id']}/features",
			array(
				'geometry' => array(
					'type'        => 'LineString',
					'coordinates' => array_slice( $points, 0, 10 ),
				),
			)
		)->get_data();
		$this->rest( 'PATCH', "/maps/{$map_id}", array( 'status' => 'published' ) );

		$payload   = \SWMaps\WordPress\Integration\MapPayload::build( \SWMaps\WordPress\Services::instance()->maps()->get( $map_id ) );
		$long_out  = $payload['layers'][0]['features'][0];
		$small_out = $payload['layers'][0]['features'][1];
		$this->assertTrue( $long_out['simplified'] );
		$this->assertLessThan( 50, count( $long_out['geometry']['coordinates'] ) );
		$this->assertSame( $points[0], $long_out['geometry']['coordinates'][0] );
		$this->assertSame( $points[799], end( $long_out['geometry']['coordinates'] ) );
		$this->assertArrayNotHasKey( 'simplified', $small_out );
		$this->assertCount( 10, $small_out['geometry']['coordinates'] );

		// The stored (canonical) geometry is untouched.
		$stored = $this->rest( 'GET', "/features/{$created->get_data()['id']}" )->get_data();
		$this->assertCount( 800, $stored['geometry']['coordinates'] );

		// Both thresholds are filterable; 0 disables the display variant.
		add_filter( 'swmap_simplify_min_points', '__return_zero' );
		$off = \SWMaps\WordPress\Integration\MapPayload::build( \SWMaps\WordPress\Services::instance()->maps()->get( $map_id ) );
		$this->assertCount( 800, $off['layers'][0]['features'][0]['geometry']['coordinates'] );
		$this->assertArrayNotHasKey( 'simplified', $off['layers'][0]['features'][0] );
		remove_filter( 'swmap_simplify_min_points', '__return_zero' );
		$this->assertSame( $small['id'], $small_out['id'] );
	}

	public function test_public_features_endpoint_serves_the_visible_area_of_published_ajax_maps_only(): void {
		$map_id = $this->create_map();
		$this->rest( 'PATCH', "/maps/{$map_id}", array( 'settings' => array( 'ajax' => true ) ) );
		$layer = $this->rest( 'POST', "/maps/{$map_id}/layers", array( 'title' => 'L' ) )->get_data();
		$draft = $this->rest(
			'POST',
			"/maps/{$map_id}/layers",
			array(
				'title'  => 'Draft',
				'status' => 'draft',
			)
		)->get_data();
		$cat   = $this->rest( 'POST', '/categories', array( 'title' => 'Huts' ) )->get_data();
		$in    = $this->rest(
			'POST',
			"/layers/{$layer['id']}/features",
			array(
				'geometry'   => $this->point( 8.4, 49.0 ),
				'categories' => array( $cat['id'] ),
			)
		)->get_data();
		$this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point( 8.5, 49.1 ) ) );
		$this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point( 2.0, 10.0 ) ) );
		$this->rest(
			'POST',
			"/layers/{$layer['id']}/features",
			array(
				'geometry' => array(
					'type'        => 'LineString',
					'coordinates' => array( array( 7, 48 ), array( 9, 50 ) ),
				),
			)
		);
		$this->rest( 'POST', "/layers/{$draft['id']}/features", array( 'geometry' => $this->point( 8.4, 49.0 ) ) );
		$route = "/public/maps/{$map_id}/features";
		$query = array( 'bbox' => '8,48.9,9,49.5' );

		// Not published yet: indistinguishable from "does not exist".
		wp_set_current_user( 0 );
		$this->assertSame( 404, $this->rest( 'GET', $route, null, $query )->get_status() );
		$this->as_role( 'administrator' );
		$this->rest( 'PATCH', "/maps/{$map_id}", array( 'status' => 'published' ) );
		wp_set_current_user( 0 );

		$all = $this->rest( 'GET', $route, null, $query );
		$this->assertSame( 200, $all->get_status(), 'anonymous visitors may read published maps: ' . wp_json_encode( $all->get_data() ) );
		$data = $all->get_data();
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 1, $data['total_pages'] );
		$this->assertSame( $layer['id'], $data['layers'][0]['id'] );
		$this->assertCount( 3, $data['layers'][0]['features'], 'two markers and the crossing line, no draft layer' );
		$this->assertStringContainsString( 'max-age', (string) $all->get_headers()['Cache-Control'] );

		$filtered = $this->rest( 'GET', $route, null, $query + array( 'categories' => (string) $cat['id'] ) )->get_data();
		$this->assertSame( array( $in['id'] ), array_column( $filtered['layers'][0]['features'], 'id' ) );
		$paged = $this->rest(
			'GET',
			$route,
			null,
			$query + array(
				'per_page' => 2,
				'page'     => 2,
			)
		)->get_data();
		$this->assertSame( array( 3, 2, 1 ), array( $paged['total'], $paged['total_pages'], count( $paged['layers'][0]['features'] ) ) );
		$this->assertSame( 400, $this->rest( 'GET', $route, null, array( 'bbox' => 'a,b,c,d' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'GET', $route, null, array( 'bbox' => '8,50,9,48' ) )->get_status(), 'min latitude must not exceed max' );
		$this->as_role( 'administrator' );
		$this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point( 179.5, 0.0 ), 'properties' => array( 'title' => 'East' ) ) );
		$this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point( -179.5, 0.0 ), 'properties' => array( 'title' => 'West' ) ) );
		$this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point( 0.0, 0.0 ), 'properties' => array( 'title' => 'Middle' ) ) );
		wp_set_current_user( 0 );
		$wrapped = $this->rest( 'GET', $route, null, array( 'bbox' => '170,-10,-170,10' ) );
		$this->assertSame( 200, $wrapped->get_status(), 'an area across the antimeridian is valid' );
		$titles = array_column( array_column( $wrapped->get_data()['layers'][0]['features'], 'properties' ), 'title' );
		sort( $titles );
		$this->assertSame( array( 'East', 'West' ), $titles, 'both sides of the dateline, nothing from the middle' );
		$this->assertSame( 2, $wrapped->get_data()['total'] );
		$this->assertSame( 400, $this->rest( 'GET', $route, null, array( 'bbox' => '8,48,9' ) )->get_status() );
		$this->assertSame(
			400,
			$this->rest(
				'GET',
				$route,
				null,
				array(
					'bbox'     => '8,48,9,50',
					'per_page' => 5000,
				)
			)->get_status()
		);
		$this->assertSame( 400, $this->rest( 'GET', $route, null )->get_status() );
		$this->assertSame( 400, $this->rest( 'GET', $route, null, array( 'bbox' => '-200,0,10,10' ) )->get_status() );

		// The inline payload carries no features in this mode, but the endpoint and all categories.
		$payload = \SWMaps\WordPress\Integration\MapPayload::build( \SWMaps\WordPress\Services::instance()->maps()->get( $map_id ) );
		$this->assertStringContainsString( $route, $payload['ajaxUrl'] );
		$this->assertSame( array(), $payload['layers'][0]['features'] );
		$this->assertSame( 'Huts', $payload['categories'][0]['title'] );
		$preview = \SWMaps\WordPress\Integration\MapPayload::build( \SWMaps\WordPress\Services::instance()->maps()->get( $map_id ), true );
		$this->assertNull( $preview['ajaxUrl'], 'draft previews are always inline' );
		$this->assertNotEmpty( $preview['layers'][0]['features'] );
		$this->assertFalse( $payload['viewport']['autoBounds'], 'AJAX maps use the configured start view' );

		// Switching the mode off closes the endpoint again.
		$this->as_role( 'administrator' );
		$this->rest( 'PATCH', "/maps/{$map_id}", array( 'settings' => array( 'ajax' => false ) ) );
		wp_set_current_user( 0 );
		$this->assertSame( 404, $this->rest( 'GET', $route, null, $query )->get_status() );
	}

	public function test_pmtiles_provider_connection_test_reads_the_archive_header(): void {
		$this->as_role( 'administrator' );
		$created = $this->rest(
			'POST',
			'/providers',
			array(
				'type'          => 'pmtiles',
				'name'          => 'Archive',
				'public_config' => array(
					'urlTemplate' => 'https://files.example.org/world.pmtiles',
					'attribution' => '© Protomaps',
					'maxZoom'     => 12,
				),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'pmtiles', $created->get_data()['type'] );
		$this->assertSame( array( 'files.example.org' ), $created->get_data()['external_requests'] );
		$id = $created->get_data()['id'];
		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/providers',
				array(
					'type'          => 'pmtiles',
					'name'          => 'Bad',
					'public_config' => array(
						'urlTemplate' => 'https://files.example.org/{z}/{x}/{y}.png',
						'attribution' => 'x',
					),
				)
			)->get_status()
		);

		$seen = array();
		$mock = static function ( $pre, $args ) use ( &$seen ) {
			$seen                        = $args['headers'];
			$GLOBALS['swmap_test_limit'] = $args['limit_response_size'] ?? null;
			return array(
				'headers'  => array( 'content-type' => 'application/octet-stream' ),
				'body'     => "PMTiles\x03" . str_repeat( "\0", 8 ),
				'response' => array(
					'code'    => 206,
					'message' => 'Partial Content',
				),
			);
		};
		add_filter( 'pre_http_request', $mock, 10, 2 );
		$ok = $this->rest( 'POST', "/providers/{$id}/test" )->get_data();
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'bytes=0-7', $seen['Range'], 'only the header is requested' );
		$this->assertLessThanOrEqual( 64, $GLOBALS['swmap_test_limit'], 'the response size is capped' );
		remove_filter( 'pre_http_request', $mock, 10 );

		foreach ( array( "PMTiles\x02", '<html>', '' ) as $body ) {
			$bad = static fn () => array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
			add_filter( 'pre_http_request', $bad );
			$this->assertFalse( $this->rest( 'POST', "/providers/{$id}/test" )->get_data()['ok'], 'rejects ' . wp_json_encode( $body ) );
			remove_filter( 'pre_http_request', $bad );
		}
		$down = static fn () => new WP_Error( 'http_request_failed', 'down' );
		add_filter( 'pre_http_request', $down );
		$this->assertSame( 0, $this->rest( 'POST', "/providers/{$id}/test" )->get_data()['status'] );
		remove_filter( 'pre_http_request', $down );
	}

	public function test_export_and_package_import_roundtrip_with_permissions(): void {
		$map_id = $this->create_map( 'Export me' );
		$layer  = $this->rest( 'POST', "/maps/{$map_id}/layers", array( 'title' => 'Orte' ) )->get_data();
		$cat    = $this->rest( 'POST', '/categories', array( 'title' => 'Huts' ) )->get_data();
		$this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point( 8.4, 49.0 ), 'properties' => array( 'title' => 'Hut' ), 'categories' => array( $cat['id'] ), 'external_id' => 'h1' ) );

		$json = $this->rest( 'GET', "/maps/{$map_id}/export" );
		$this->assertSame( 200, $json->get_status() );
		$this->assertSame( 'application/json', $json->get_data()['mime_type'] );
		$package = json_decode( $json->get_data()['content'], true );
		$this->assertSame( 'sw-maps-package', $package['format'] );
		$this->assertStringNotContainsString( 'secret', $json->get_data()['content'] );
		$geo = $this->rest( 'GET', "/maps/{$map_id}/export", null, array( 'format' => 'geojson', 'layer_id' => $layer['id'] ) )->get_data();
		$this->assertCount( 1, json_decode( $geo['content'], true )['features'] );
		$csv = $this->rest( 'GET', "/maps/{$map_id}/export", null, array( 'format' => 'csv' ) )->get_data();
		$this->assertStringContainsString( 'Hut', $csv['content'] );
		$this->assertSame( 400, $this->rest( 'GET', "/maps/{$map_id}/export", null, array( 'format' => 'xml' ) )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', '/maps/999999/export' )->get_status() );
		$all = $this->rest( 'GET', '/export' );
		$this->assertSame( 200, $all->get_status() );
		$this->assertStringContainsString( 'Export me', $all->get_data()['content'] );

		$imported = $this->rest( 'POST', '/imports/package', array( 'content' => $json->get_data()['content'] ) );
		$this->assertSame( 201, $imported->get_status() );
		$new_id = $imported->get_data()['maps'][0]['id'];
		$this->assertNotSame( $map_id, $new_id );
		$this->assertSame( 'draft', $this->rest( 'GET', "/maps/{$new_id}" )->get_data()['status'] );
		$new_layer = $this->rest( 'GET', "/maps/{$new_id}/layers" )->get_data()[0];
		$features  = $this->rest( 'GET', "/layers/{$new_layer['id']}/features" )->get_data();
		$this->assertSame( array( $cat['id'] ), $features[0]['categories'], 'the existing category is matched by slug' );
		$this->assertSame( 0, $imported->get_data()['categories_created'] );

		$this->assertSame( 422, $this->rest( 'POST', '/imports/package', array( 'content' => '{"format":"x"}' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/imports/package', array() )->get_status() );

		$this->as_role( 'editor' );
		$this->assertSame( 403, $this->rest( 'GET', '/export' )->get_status(), 'exporting every map needs the system capability' );
		wp_set_current_user( 0 );
		foreach ( array( array( 'GET', "/maps/{$map_id}/export" ), array( 'GET', '/export' ), array( 'POST', '/imports/package' ) ) as list( $method, $route ) ) {
			$this->assertSame( 401, $this->rest( $method, $route, array() )->get_status(), "anonymous $method $route" );
		}
		$this->as_role( 'subscriber' );
		$this->assertSame( 403, $this->rest( 'GET', "/maps/{$map_id}/export" )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/imports/package', array( 'content' => '{}' ) )->get_status() );
	}

	public function test_server_side_requests_refuse_private_targets_unless_the_host_is_allowlisted(): void {
		$this->as_role( 'administrator' );
		$calls = 0;
		$mock  = static function () use ( &$calls ) {
			++$calls;
			return array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => 'x',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};
		add_filter( 'pre_http_request', $mock );
		$private = array(
			'http://127.0.0.1/{z}/{x}/{y}.png',
			'http://169.254.169.254/{z}/{x}/{y}.png',
			'http://[::1]/{z}/{x}/{y}.png',
			'http://10.0.0.7/{z}/{x}/{y}.png',
			'http://2130706433/{z}/{x}/{y}.png',
		);
		foreach ( $private as $url ) {
			$created = $this->rest( 'POST', '/providers', array( 'name' => 'P', 'public_config' => array( 'urlTemplate' => $url, 'attribution' => 'x' ) ) );
			$result  = $this->rest( 'POST', "/providers/{$created->get_data()['id']}/test" )->get_data();
			$this->assertFalse( $result['ok'], $url );
			$this->assertSame( 'The address is not allowed for server requests.', $result['message'], $url );
		}
		$this->assertSame( 0, $calls, 'no request left the server' );

		// A redirect to the metadata service is refused before it is requested.
		remove_filter( 'pre_http_request', $mock );
		$hops   = array();
		$redir  = static function ( $pre, $args, $url ) use ( &$hops ) {
			$hops[] = $url;
			return array(
				'headers'  => array( 'location' => 'http://169.254.169.254/latest/meta-data/' ),
				'body'     => '',
				'response' => array( 'code' => 302, 'message' => 'Found' ),
			);
		};
		add_filter( 'pre_http_request', $redir, 10, 3 );
		$public = $this->rest( 'POST', '/providers', array( 'name' => 'Pub', 'public_config' => array( 'urlTemplate' => 'https://tiles.example.org/{z}/{x}/{y}.png', 'attribution' => 'x' ) ) )->get_data();
		$this->assertSame( 'The address is not allowed for server requests.', $this->rest( 'POST', "/providers/{$public['id']}/test" )->get_data()['message'] );
		$this->assertCount( 1, $hops );
		remove_filter( 'pre_http_request', $redir, 10 );

		// The administrator allowlist lets one private host through.
		$this->rest( 'PATCH', '/settings', array( 'allowed_hosts' => array( 'tiles.lan' ) ) );
		add_filter( 'swmap_resolve_host', static fn ( $a, $host ) => 'tiles.lan' === $host ? array( '10.0.0.9' ) : $a, 20, 2 );
		add_filter( 'pre_http_request', $mock );
		$lan = $this->rest( 'POST', '/providers', array( 'name' => 'Lan', 'public_config' => array( 'urlTemplate' => 'http://tiles.lan/{z}/{x}/{y}.png', 'attribution' => 'x' ) ) )->get_data();
		$this->assertTrue( $this->rest( 'POST', "/providers/{$lan['id']}/test" )->get_data()['ok'] );
		$other = $this->rest( 'POST', '/providers', array( 'name' => 'Other', 'public_config' => array( 'urlTemplate' => 'http://10.0.0.7/{z}/{x}/{y}.png', 'attribution' => 'x' ) ) )->get_data();
		$this->assertFalse( $this->rest( 'POST', "/providers/{$other['id']}/test" )->get_data()['ok'] );
		remove_filter( 'pre_http_request', $mock );

		// Allowlist validation.
		foreach ( array( array( 'http://x/' ), array( 'a b' ), 'x', array_fill( 0, 21, 'h.lan' ), array( 5 ) ) as $bad ) {
			$this->assertSame( 400, $this->rest( 'PATCH', '/settings', array( 'allowed_hosts' => $bad ) )->get_status() );
		}
		$this->assertSame( array( 'a.lan', 'b.lan' ), $this->rest( 'PATCH', '/settings', array( 'allowed_hosts' => array( 'A.lan', 'b.lan', 'a.lan' ) ) )->get_data()['allowed_hosts'] );
		delete_option( \SWMaps\WordPress\Settings::OPTION );
	}
}
