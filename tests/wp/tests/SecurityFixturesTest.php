<?php
/**
 * Security fixtures (M3-10): oversized and invalid geometries, hostile import files (wrong type, binary, SVG, script
 * content), and path protection (nothing is written to disk by REST imports and exports).
 *
 * @package sw-maps
 */

require_once __DIR__ . '/SwMapsTestCase.php';

final class SecurityFixturesTest extends SwMapsTestCase {

	/** @return array<string, array{string, mixed}> */
	public static function bad_geometries(): array {
		$ring = array( array( 0, 0 ), array( 1, 0 ), array( 1, 1 ), array( 0, 0 ) );
		return array(
			'longitude too large'      => array( 'Point', array( 181, 0 ) ),
			'latitude beyond mercator' => array( 'Point', array( 0, 89 ) ),
			'string coordinates'       => array( 'Point', array( '8', '49' ) ),
			'null coordinate'          => array( 'Point', array( null, 49 ) ),
			'one value'                => array( 'Point', array( 8 ) ),
			'four values'              => array( 'Point', array( 1, 2, 3, 4 ) ),
			'nested list for point'    => array( 'Point', array( array( 1, 2 ) ) ),
			'line with one point'      => array( 'LineString', array( array( 1, 1 ) ) ),
			'line with object'         => array( 'LineString', array( array( 'x' => 1, 'y' => 2 ), array( 1, 2 ) ) ),
			'unclosed ring'            => array( 'Polygon', array( array( array( 0, 0 ), array( 1, 0 ), array( 1, 1 ), array( 0, 1 ) ) ) ),
			'ring too short'           => array( 'Polygon', array( array( array( 0, 0 ), array( 1, 0 ), array( 0, 0 ) ) ) ),
			'polygon without rings'    => array( 'Polygon', array() ),
			'multipolygon wrong depth' => array( 'MultiPolygon', $ring ),
			'unknown type'             => array( 'Circle', array( 1, 2 ) ),
			'geometry collection'      => array( 'GeometryCollection', array() ),
		);
	}

	/** @dataProvider bad_geometries */
	public function test_invalid_geometries_are_rejected_with_400_and_store_nothing( string $type, mixed $coordinates ): void {
		$map_id = $this->create_map();
		$layer  = $this->rest( 'POST', "/maps/{$map_id}/layers", array( 'title' => 'L' ) )->get_data();
		$result = $this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => array( 'type' => $type, 'coordinates' => $coordinates ) ) );
		$this->assertSame( 400, $result->get_status(), "$type " . wp_json_encode( $coordinates ) );
		$this->assertSame( array(), $this->rest( 'GET', "/layers/{$layer['id']}/features" )->get_data() );
	}

	public function test_oversized_and_deeply_nested_geometries_are_rejected(): void {
		$map_id = $this->create_map();
		$layer  = $this->rest( 'POST', "/maps/{$map_id}/layers", array( 'title' => 'L' ) )->get_data();
		$points = array();
		for ( $i = 0; $i < 100001; $i++ ) {
			$points[] = array( ( $i % 3600 ) / 20 - 90, ( $i % 1700 ) / 20 - 42 );
		}
		$big = $this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => array( 'type' => 'LineString', 'coordinates' => $points ) ) );
		$this->assertSame( 400, $big->get_status(), 'more than 100000 coordinates' );
		$deep = array( 1, 2 );
		for ( $i = 0; $i < 60; $i++ ) {
			$deep = array( $deep );
		}
		$nested = $this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => array( 'type' => 'MultiPolygon', 'coordinates' => $deep ) ) );
		$this->assertSame( 400, $nested->get_status() );
		$this->assertSame( array(), $this->rest( 'GET', "/layers/{$layer['id']}/features" )->get_data() );
		// Oversized text fields.
		$text = $this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => array( 'type' => 'Point', 'coordinates' => array( 1, 1 ) ), 'properties' => array( 'description' => str_repeat( 'x', 2001 ) ) ) );
		$this->assertSame( 400, $text->get_status() );
	}

	/** @return array<string, array{string, string}> */
	public static function hostile_files(): array {
		return array(
			'png as geojson'     => array( 'geojson', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" ),
			'zip as gpx'         => array( 'gpx', "PK\x03\x04\x14\0\0\0\x08\0" ),
			'binary as csv'      => array( 'csv', "\0\x01\x02\x03\xFF\xFE" ),
			'svg as geojson'     => array( 'geojson', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ),
			'svg as gpx'         => array( 'gpx', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ),
			'html as gpx'        => array( 'gpx', '<html><body><img src=x onerror=alert(1)></body></html>' ),
			'php as geojson'     => array( 'geojson', '<?php system($_GET["c"]); ?>' ),
			'php as csv'         => array( 'csv', '<?php system($_GET["c"]); ?>' ),
			'json array as gpx'  => array( 'gpx', '{"type":"FeatureCollection","features":[]}' ),
			'empty file'         => array( 'geojson', '' ),
			'xxe parameter'      => array( 'gpx', '<?xml version="1.0"?><!DOCTYPE gpx [<!ENTITY % x SYSTEM "http://127.0.0.1/evil.dtd"> %x;]><gpx/>' ),
			'billion laughs'     => array( 'gpx', '<?xml version="1.0"?><!DOCTYPE l [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]><gpx>&b;</gpx>' ),
		);
	}

	/** @dataProvider hostile_files */
	public function test_hostile_import_files_are_refused_without_server_errors_or_writes( string $format, string $content ): void {
		$map_id = $this->create_map();
		$before = $this->upload_files();
		$result = $this->rest( 'POST', '/imports', array( 'map_id' => $map_id, 'format' => $format, 'content' => $content ) );
		$this->assertContains( $result->get_status(), array( 400, 422 ), "$format: " . substr( bin2hex( $content ), 0, 24 ) );
		$this->assertSame( array(), $this->rest( 'GET', "/maps/{$map_id}/layers" )->get_data(), 'nothing was created' );
		$this->assertSame( $before, $this->upload_files(), 'nothing was written to disk' );
	}

	public function test_package_import_refuses_wrong_files(): void {
		$this->create_map();
		foreach ( array( "\x89PNG\r\n", '<?php echo 1;', '{"format":"sw-maps-package","version":1,"maps":[{"title":"x","layers":[{"title":"L","features":[{"geometry":{"type":"Point","coordinates":[999,1]}}]}]}]}', str_repeat( '[', 5000 ) ) as $content ) {
			$this->assertSame( 422, $this->rest( 'POST', '/imports/package', array( 'content' => $content ) )->get_status(), substr( $content, 0, 12 ) );
		}
		$this->assertSame( 1, (int) $this->rest( 'GET', '/maps' )->get_headers()['X-WP-Total'], 'no map was created by the rejected packages' );
	}

	public function test_script_content_in_imported_text_is_stored_as_plain_text_and_escaped_on_output(): void {
		$map_id  = $this->create_map();
		$payload = '<script>alert(1)</script><img src=x onerror=alert(2)>';
		$geojson = wp_json_encode( array( 'type' => 'FeatureCollection', 'features' => array( array( 'type' => 'Feature', 'properties' => array( 'name' => $payload, 'desc' => $payload, 'url' => 'javascript:alert(3)' ), 'geometry' => array( 'type' => 'Point', 'coordinates' => array( 8, 49 ) ) ) ) ) );
		$report  = $this->rest( 'POST', '/imports', array( 'map_id' => $map_id, 'format' => 'geojson', 'content' => $geojson ) )->get_data();
		$feature = $this->rest( 'GET', "/layers/{$report['layer_id']}/features" )->get_data()[0];
		$this->assertStringNotContainsString( '<', $feature['properties']['title'] );
		$this->assertNull( $feature['properties']['link'], 'javascript: links are dropped' );
		$this->rest( 'PATCH', "/maps/{$map_id}", array( 'status' => 'published' ) );
		$html = \SWMaps\WordPress\Integration\Renderer::render( $map_id );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( 'javascript:alert(3)', $html );
	}

	public function test_media_references_to_non_images_and_other_types_resolve_to_nothing(): void {
		foreach ( array( 'text/plain', 'application/x-httpd-php', 'image/svg+xml', 'application/pdf' ) as $mime ) {
			$id = self::factory()->attachment->create(
				array(
					'post_mime_type' => $mime,
					'post_title'     => 'x',
				)
			);
			$this->assertNull( \SWMaps\WordPress\Integration\MapPayload::attachment_url( $id, 'medium' ), "$mime is no allowed image type" );
		}
		$post = self::factory()->post->create();
		$this->assertNull( \SWMaps\WordPress\Integration\MapPayload::attachment_url( $post, 'medium' ), 'a post is no attachment' );
		$this->assertNull( \SWMaps\WordPress\Integration\MapPayload::attachment_url( null, 'medium' ) );
		$this->assertNull( \SWMaps\WordPress\Integration\MapPayload::attachment_url( 999999, 'medium' ) );
	}

	public function test_exports_and_imports_never_write_to_disk(): void {
		$map_id = $this->create_map( 'Disk' );
		$layer  = $this->rest( 'POST', "/maps/{$map_id}/layers", array( 'title' => 'L' ) )->get_data();
		$this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => array( 'type' => 'Point', 'coordinates' => array( 1, 1 ) ) ) );
		$before = $this->upload_files();
		foreach ( array( 'json', 'geojson', 'csv' ) as $format ) {
			$this->assertSame( 200, $this->rest( 'GET', "/maps/{$map_id}/export", null, array( 'format' => $format ) )->get_status() );
		}
		$package = $this->rest( 'GET', "/maps/{$map_id}/export" )->get_data()['content'];
		$this->assertSame( 201, $this->rest( 'POST', '/imports/package', array( 'content' => $package ) )->get_status() );
		$this->assertSame( $before, $this->upload_files() );
	}

	public function test_export_filenames_are_safe_for_downloads(): void {
		$map_id = $this->create_map( '../../etc/passwd "; <script>' );
		$file   = $this->rest( 'GET', "/maps/{$map_id}/export" )->get_data();
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+-\d{8}\.json$/', $file['filename'] );
	}

	/** @return string[] */
	private function upload_files(): array {
		$dir   = wp_upload_dir()['basedir'];
		$files = array();
		if ( is_dir( $dir ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				$files[] = (string) $file;
			}
		}
		sort( $files );
		return $files;
	}
}
