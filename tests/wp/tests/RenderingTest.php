<?php
/**
 * Shortcode, PHP API, block, inline JSON encoding, drafts, assets, consent and privacy text.
 *
 * @package sw-maps
 */

use SWMaps\WordPress\Integration\Assets;
use SWMaps\WordPress\Integration\Block;
use SWMaps\WordPress\Integration\Consent;
use SWMaps\WordPress\Integration\Privacy;
use SWMaps\WordPress\Integration\Renderer;
use SWMaps\WordPress\Services;

require_once __DIR__ . '/SwMapsTestCase.php';

final class RenderingTest extends SwMapsTestCase {

	private const XSS = '</script><img src=x onerror=alert(1)><script>alert(2)</script>"\'&';

	public function set_up(): void {
		parent::set_up();
		Renderer::reset_counter();
		wp_dequeue_script( Assets::HANDLE );
		wp_deregister_script( Assets::HANDLE );
		wp_dequeue_style( Assets::HANDLE );
		wp_deregister_style( Assets::HANDLE );
		SWMaps\WordPress\Integration\Shortcode::register();
	}

	/** @return array{0:int,1:int} map id and layer id */
	private function published_map( string $title = 'Map', array $feature_props = array() ): array {
		$this->as_role( 'administrator' );
		$map   = Services::instance()->maps()->create( array( 'title' => $title ), get_current_user_id() );
		$layer = Services::instance()->layers()->create( $map->id, array( 'title' => 'L' ) );
		Services::instance()->features()->create(
			$layer->id,
			array(
				'geometry'   => array( 'type' => 'Point', 'coordinates' => array( 8.4, 49.0 ) ),
				'properties' => $feature_props + array( 'title' => 'Rathaus', 'address' => 'Markt 1', 'link' => 'https://example.org/a?b=1&c=2' ),
			)
		);
		Services::instance()->maps()->update( $map->id, array( 'status' => 'published' ) );
		wp_set_current_user( 0 );
		return array( $map->id, $layer->id );
	}

	private function inline_json( string $html ): array {
		$this->assertSame( 1, preg_match( '#<script type="application/json" class="swmap__data">(.*?)</script>#s', $html, $m ), 'inline JSON present' );
		$data = json_decode( $m[1], true );
		$this->assertIsArray( $data, 'valid JSON' );
		return $data;
	}

	public function test_published_map_renders_container_json_and_server_side_list(): void {
		list( $id ) = $this->published_map( 'Rathaus Karte' );
		$html       = do_shortcode( '[sw_map id="' . $id . '"]' );
		$this->assertStringContainsString( 'class="swmap"', $html );
		$this->assertStringContainsString( 'data-swmap="' . $id . '"', $html );
		$this->assertStringContainsString( 'aria-label="Rathaus Karte"', $html );
		$this->assertStringContainsString( '<ul class="swmap__list"', $html );
		$this->assertStringContainsString( 'Rathaus', $html );
		$this->assertStringContainsString( 'Markt 1', $html );
		$data = $this->inline_json( $html );
		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', $data['provider']['urlTemplate'] );
		$this->assertEquals( array( 8.4, 49.0 ), $data['layers'][0]['features'][0]['geometry']['coordinates'] );
		$this->assertArrayHasKey( 'strings', $data );
	}

	public function test_map_works_without_additional_data_request(): void {
		list( $id ) = $this->published_map();
		$data       = $this->inline_json( swmap_render_map( $id ) );
		$this->assertNotEmpty( $data['layers'][0]['features'], 'features are inline' );
		$this->assertSame( $data['layers'][0]['features'][0]['properties']['title'], 'Rathaus' );
	}

	public function test_xss_payloads_cannot_break_out_of_json_or_html(): void {
		list( $id ) = $this->published_map( 'T', array( 'title' => 'x' ) );
		global $wpdb;
		// Importers may store raw text; simulate worst case directly in the database, bypassing the sanitising service.
		$wpdb->update( SWMaps\WordPress\Persistence\Schema::table( 'features' ), array( 'properties_json' => wp_json_encode( array( 'title' => self::XSS, 'description' => self::XSS, 'address' => self::XSS, 'link' => 'javascript:alert(1)', 'icon' => 'builtin:pin' ) ) ), array( 'layer_id' => Services::instance()->layer_repository()->forMap( $id )[0]->id ) );
		$wpdb->update( SWMaps\WordPress\Persistence\Schema::table( 'maps' ), array( 'title' => self::XSS ), array( 'id' => $id ) );
		$html = swmap_render_map( $id );
		$this->assertStringNotContainsString( '</script><img', $html );
		$this->assertSame( 1, substr_count( $html, '<script' ), 'only the JSON script element exists' );
		$this->assertSame( 1, substr_count( $html, '</script>' ) );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$json = $this->inline_json( $html );
		$this->assertSame( self::XSS, $json['title'], 'the data survives unchanged for textContent rendering' );
		$this->assertStringContainsString( '<', $html );
		$this->assertStringContainsString( '&lt;/script&gt;', $html, 'list text is escaped' );
		$dom = new DOMDocument();
		@$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$this->assertSame( 0, $dom->getElementsByTagName( 'img' )->length );
		$this->assertSame( 1, $dom->getElementsByTagName( 'script' )->length );
	}

	public function test_drafts_are_never_public_but_previewable_by_editors(): void {
		$this->as_role( 'administrator' );
		$map = Services::instance()->maps()->create( array( 'title' => 'Secret draft' ), get_current_user_id() );
		wp_set_current_user( 0 );
		$this->assertSame( '', swmap_render_map( $map->id ) );
		$this->assertSame( '', do_shortcode( '[sw_map id="' . $map->id . '"]' ) );
		$this->as_role( 'subscriber' );
		$this->assertSame( '', swmap_render_map( $map->id ) );
		$this->as_role( 'editor' );
		$html = swmap_render_map( $map->id );
		$this->assertStringContainsString( 'swmap__draft', $html );
		$this->assertStringContainsString( 'Secret draft', $html );
	}

	public function test_unpublished_layers_and_features_are_not_in_public_output(): void {
		list( $id, $layer_id ) = $this->published_map();
		$this->as_role( 'administrator' );
		$hidden = Services::instance()->layers()->create( $id, array( 'title' => 'Hidden', 'status' => 'draft' ) );
		Services::instance()->features()->create( $hidden->id, array( 'geometry' => array( 'type' => 'Point', 'coordinates' => array( 1, 1 ) ), 'properties' => array( 'title' => 'Private place' ) ) );
		$invisible = Services::instance()->layers()->create( $id, array( 'title' => 'Invisible', 'visible' => false ) );
		Services::instance()->features()->create( $invisible->id, array( 'geometry' => array( 'type' => 'Point', 'coordinates' => array( 2, 2 ) ), 'properties' => array( 'title' => 'Invisible place' ) ) );
		wp_set_current_user( 0 );
		$html = swmap_render_map( $id );
		$this->assertStringNotContainsString( 'Private place', $html );
		$this->assertStringNotContainsString( 'Invisible place', $html );
		$this->assertCount( 1, $this->inline_json( $html )['layers'] );
		$this->assertSame( $layer_id, $this->inline_json( $html )['layers'][0]['id'] );
	}

	public function test_public_payload_has_no_private_fields(): void {
		list( $id ) = $this->published_map();
		$json       = (string) wp_json_encode( $this->inline_json( swmap_render_map( $id ) ) );
		foreach ( array( 'created_by', 'secret_constant', 'external_id', 'user', 'updated_at' ) as $forbidden ) {
			$this->assertStringNotContainsString( '"' . $forbidden . '"', $json, $forbidden );
		}
	}

	public function test_assets_are_enqueued_only_when_a_map_is_rendered(): void {
		list( $id ) = $this->published_map();
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		do_shortcode( '[sw_map id="999999"]' );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ), 'unknown map: nothing enqueued' );
		swmap_render_map( $id );
		swmap_render_map( $id );
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertSame( 1, count( array_keys( wp_scripts()->queue, Assets::HANDLE, true ) ), 'one shared bundle' );
		$this->assertStringContainsString( 'assets/frontend/swmaps-viewer.js', wp_scripts()->registered[ Assets::HANDLE ]->src );
	}

	public function test_multiple_maps_get_unique_ids(): void {
		list( $a ) = $this->published_map( 'A' );
		list( $b ) = $this->published_map( 'B' );
		$html      = swmap_render_map( $a ) . swmap_render_map( $b ) . swmap_render_map( $a );
		preg_match_all( '/id="(swmap-\d+)"/', $html, $m );
		$this->assertSame( 3, count( array_unique( $m[1] ) ) );
	}

	public function test_options_and_overrides_are_validated(): void {
		list( $id ) = $this->published_map();
		$html       = swmap_render_map( $id, array( 'height' => '60vh', 'aspect_ratio' => '16:9', 'lazy' => false, 'click_to_load' => true, 'list' => false ) );
		$this->assertStringContainsString( '--swmap-height:60vh;', $html );
		$this->assertStringContainsString( '--swmap-aspect:16 / 9;', $html );
		$this->assertStringContainsString( 'data-lazy="0"', $html );
		$this->assertStringNotContainsString( 'swmap__list', $html );
		$data = $this->inline_json( $html );
		$this->assertTrue( $data['settings']['clickToLoad'] );
		$bad = swmap_render_map( $id, array( 'height' => 'calc(1px);background:url(x)', 'aspect_ratio' => '1;2' ) );
		$this->assertStringContainsString( '--swmap-height:400px;', $bad );
		$this->assertStringNotContainsString( 'url(x)', $bad );
		$this->assertStringNotContainsString( 'data-aspect', $bad );
	}

	public function test_shortcode_attributes(): void {
		list( $id ) = $this->published_map();
		$html       = do_shortcode( '[sw_map id="' . $id . '" height="500px" lazy="false" click_to_load="1" list="0"]' );
		$this->assertStringContainsString( '--swmap-height:500px;', $html );
		$this->assertStringContainsString( 'data-lazy="0"', $html );
		$this->assertSame( '', do_shortcode( '[sw_map]' ) );
		$this->assertSame( '', do_shortcode( '[sw_map id="abc"]' ) );
	}

	public function test_block_renders_same_output_as_shortcode(): void {
		list( $id ) = $this->published_map();
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) );
		$block  = do_blocks( '<!-- wp:sw-maps/map {"mapId":' . $id . ',"height":"300px","showList":false,"clickToLoad":true} /-->' );
		$this->assertStringContainsString( 'data-swmap="' . $id . '"', $block );
		$this->assertStringContainsString( '--swmap-height:300px;', $block );
		$this->assertStringNotContainsString( 'swmap__list', $block );
		$this->assertSame( '', do_blocks( '<!-- wp:sw-maps/map /-->' ) );
		$same = $this->inline_json( swmap_render_map( $id ) );
		$this->assertSame( $same['layers'], $this->inline_json( do_blocks( '<!-- wp:sw-maps/map {"mapId":' . $id . '} /-->' ) )['layers'] );
	}

	public function test_block_editor_data_only_for_editors(): void {
		$this->published_map( 'For block' );
		wp_register_script( 'sw-maps-map-editor-script', '', array(), '1', true );
		wp_set_current_user( 0 );
		Block::editor_data();
		$this->assertFalse( (bool) wp_scripts()->get_data( 'sw-maps-map-editor-script', 'before' ) );
		$this->as_role( 'editor' );
		Block::editor_data();
		$before = wp_scripts()->get_data( 'sw-maps-map-editor-script', 'before' );
		$this->assertStringContainsString( 'For block', implode( '', (array) $before ) );
	}

	public function test_consent_category_only_when_consent_api_is_active(): void {
		list( $id ) = $this->published_map();
		$this->assertFalse( Consent::api_active() );
		$this->assertNull( $this->inline_json( swmap_render_map( $id ) )['consentCategory'] );
		if ( ! function_exists( 'wp_has_consent' ) ) {
			eval( 'function wp_has_consent( $category ) { return false; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		$this->assertSame( 'marketing', $this->inline_json( swmap_render_map( $id ) )['consentCategory'] );
		add_filter( 'swmap_consent_category', static fn () => 'functional' );
		$this->assertSame( 'functional', Consent::category() );
		add_filter( 'swmap_consent_category', static fn () => 'bogus', 20 );
		$this->assertNull( Consent::category() );
		remove_all_filters( 'swmap_consent_category' );
	}

	public function test_consent_script_dependency_when_registered(): void {
		wp_deregister_script( Assets::HANDLE );
		wp_register_script( Consent::SCRIPT_HANDLE, '', array(), '1', true );
		Assets::register();
		$this->assertContains( Consent::SCRIPT_HANDLE, wp_scripts()->registered[ Assets::HANDLE ]->deps );
	}

	public function test_media_images_must_be_local_raster_attachments(): void {
		$this->as_role( 'administrator' );
		$png = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$svg = self::factory()->attachment->create( array( 'post_mime_type' => 'image/svg+xml', 'post_title' => 'x' ) );
		list( $id, $layer_id ) = $this->published_map( 'Media', array( 'image_id' => $png, 'icon' => 'media:' . $png ) );
		$this->as_role( 'administrator' );
		Services::instance()->features()->create( $layer_id, array( 'geometry' => array( 'type' => 'Point', 'coordinates' => array( 1, 1 ) ), 'properties' => array( 'image_id' => $svg, 'icon' => 'media:' . $svg ) ) );
		wp_set_current_user( 0 );
		$features = $this->inline_json( swmap_render_map( $id ) )['layers'][0]['features'];
		$this->assertStringContainsString( 'canola', (string) $features[0]['properties']['image_url'] );
		$this->assertStringContainsString( 'canola', (string) $features[0]['properties']['icon_url'] );
		$this->assertNull( $features[1]['properties']['image_url'], 'SVG attachments are never used' );
		$this->assertNull( $features[1]['properties']['icon_url'] );
	}

	public function test_privacy_policy_text_names_the_enabled_tile_servers(): void {
		$text = Privacy::policy_text();
		$this->assertStringContainsString( 'tile.openstreetmap.org', $text );
		$this->assertStringContainsString( 'IP', $text );
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' );
		remove_all_actions( 'admin_init' );
		Privacy::register();
		do_action( 'admin_init' );
		$suggested = wp_json_encode( WP_Privacy_Policy_Content::get_suggested_policy_text() );
		$this->assertStringContainsString( 'SW Maps', (string) $suggested );
	}

	public function test_corrupt_stored_rows_do_not_break_rendering(): void {
		global $wpdb;
		list( $id, $layer_id ) = $this->published_map();
		$this->as_role( 'administrator' );
		Services::instance()->features()->create( $layer_id, array( 'geometry' => array( 'type' => 'Point', 'coordinates' => array( 2, 2 ) ), 'properties' => array( 'title' => 'Second' ) ) );
		$features = SWMaps\WordPress\Persistence\Schema::table( 'features' );
		$wpdb->query( "UPDATE {$features} SET geometry_json = '{\"type\":\"Point\",\"coordinates\":[999,0]}' WHERE layer_id = " . (int) $layer_id . ' ORDER BY id ASC LIMIT 1' ); // phpcs:ignore
		$wpdb->update( SWMaps\WordPress\Persistence\Schema::table( 'maps' ), array( 'viewport_json' => '{"zoom":99}', 'settings_json' => 'not json' ), array( 'id' => $id ) );
		wp_set_current_user( 0 );
		$previous = ini_set( 'error_log', '/dev/null' );
		$html     = swmap_render_map( $id );
		ini_set( 'error_log', (string) $previous );
		$data = $this->inline_json( $html );
		$this->assertCount( 1, $data['layers'][0]['features'], 'the broken feature is skipped' );
		$this->assertSame( 'Second', $data['layers'][0]['features'][0]['properties']['title'] );
		$this->assertSame( 400, $data['settings']['height'], 'defaults replace invalid stored settings' );
	}

	public function test_php_api_returns_empty_for_missing_maps(): void {
		$this->assertSame( '', swmap_render_map( 0 ) );
		$this->assertSame( '', swmap_render_map( 123456 ) );
	}

	public function test_render_options_filter(): void {
		list( $id ) = $this->published_map();
		add_filter( 'swmap_render_options', static fn ( array $o ) => $o + array( 'height' => '777px' ) );
		$this->assertStringContainsString( '--swmap-height:777px;', swmap_render_map( $id ) );
		remove_all_filters( 'swmap_render_options' );
	}
}
