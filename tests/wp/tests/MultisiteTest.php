<?php
/**
 * Multisite isolation and per-site installation (run with phpunit-ms.xml.dist).
 *
 * @package sw-maps
 * @group ms
 */

use SWMaps\WordPress\Capabilities;
use SWMaps\WordPress\Lifecycle;
use SWMaps\WordPress\Persistence\Schema;
use SWMaps\WordPress\Services;

require_once __DIR__ . '/SwMapsTestCase.php';

final class MultisiteTest extends SwMapsTestCase {

	public function set_up(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		parent::set_up();
	}

	public function test_network_activation_installs_every_site_with_its_own_prefix(): void {
		global $wpdb;
		$second = self::factory()->blog->create();
		Lifecycle::activate( true );
		switch_to_blog( $second );
		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::OPTION ) );
		$this->assertStringContainsString( '_' . $second . '_', $wpdb->prefix );
		$this->assertTrue( Schema::table_exists( 'swmap_maps' ) );
		restore_current_blog();
	}

	public function test_data_does_not_leak_between_sites(): void {
		$second = self::factory()->blog->create();
		switch_to_blog( $second );
		Schema::migrate();
		Capabilities::grant();
		Services::reset();
		$this->as_role( 'administrator' );
		$other_id = (int) $this->rest( 'POST', '/maps', array( 'title' => 'Second site' ) )->get_data()['id'];
		restore_current_blog();
		Services::reset();

		$this->create_map( 'First site' );
		$this->assertSame( array( 'First site' ), array_column( $this->rest( 'GET', '/maps' )->get_data(), 'title' ) );
		$this->assertSame( 404, $this->rest( 'GET', '/maps/' . ( $other_id + 1000 ) )->get_status() );
		$this->assertNotSame( 'Second site', Services::instance()->maps()->list( '', null, 1, 20 )['items'][0]->title );
	}

	public function test_new_site_is_installed_only_when_plugin_is_network_active(): void {
		$plain = self::factory()->blog->create();
		switch_to_blog( $plain );
		$this->assertFalse( get_option( Schema::OPTION ), 'not network active: installed lazily on first use' );
		restore_current_blog();

		update_site_option( 'active_sitewide_plugins', array( plugin_basename( SWMAPS_FILE ) => time() ) );
		$network = self::factory()->blog->create();
		switch_to_blog( $network );
		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::EDIT_MAPS ) );
		restore_current_blog();
		delete_site_option( 'active_sitewide_plugins' );
	}

	public function test_super_admin_passes_capability_checks(): void {
		$id = self::factory()->user->create();
		grant_super_admin( $id );
		wp_set_current_user( $id );
		$this->assertTrue( Capabilities::can( Capabilities::MANAGE_SYSTEM ) );
	}
}
