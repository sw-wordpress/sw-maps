<?php
/**
 * Activation, deactivation and uninstall behaviour.
 *
 * @package sw-maps
 */

use SWMaps\WordPress\Capabilities;
use SWMaps\WordPress\Lifecycle;
use SWMaps\WordPress\Persistence\Schema;
use SWMaps\WordPress\Settings;

require_once __DIR__ . '/SwMapsTestCase.php';

final class LifecycleTest extends SwMapsTestCase {

	public function test_activation_grants_documented_capabilities(): void {
		Capabilities::revoke();
		Lifecycle::activate();
		foreach ( Capabilities::role_map() as $role => $caps ) {
			foreach ( $caps as $cap ) {
				$this->assertTrue( get_role( $role )->has_cap( $cap ), "$role $cap" );
			}
		}
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::MANAGE_SYSTEM ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( Capabilities::EDIT_MAPS ) );
	}

	public function test_deactivation_keeps_data_and_capabilities(): void {
		$id = $this->create_map( 'Stay' );
		Lifecycle::deactivate();
		$this->assertSame( 'Stay', \SWMaps\WordPress\Services::instance()->maps()->get( $id )->title );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::EDIT_MAPS ) );
	}

	public function test_uninstall_without_opt_in_keeps_everything(): void {
		$id = $this->create_map( 'Stay' );
		Lifecycle::uninstall();
		$this->assertSame( 'Stay', \SWMaps\WordPress\Services::instance()->maps()->get( $id )->title );
		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::EDIT_MAPS ) );
	}

	public function test_uninstall_with_opt_in_removes_tables_options_and_capabilities(): void {
		Settings::update( array( 'delete_data_on_uninstall' => true ) );
		$drops = array();
		$spy   = static function ( string $q ) use ( &$drops ): string {
			if ( 0 === strpos( $q, 'DROP TABLE' ) ) {
				$drops[] = $q;
			}
			return $q;
		};
		add_filter( 'query', $spy, 1 );
		Lifecycle::uninstall();
		remove_filter( 'query', $spy, 1 );
		$this->assertCount( 6, $drops );
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Schema::OPTION ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::EDIT_MAPS ) );
		Schema::migrate();
		Capabilities::grant();
	}

	public function test_uninstall_file_requires_wordpress_constant(): void {
		$this->assertFileExists( SWMAPS_DIR . 'uninstall.php' );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit", (string) file_get_contents( SWMAPS_DIR . 'uninstall.php' ) );
	}

	public function test_tables_follow_the_prefix_convention_backup_tools_select_by(): void {
		global $wpdb;
		// SW BackupIt (and most tools) take "all tables with the WordPress prefix": every plugin table must match that rule.
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'swmap_' ) . '%' ) );
		$expected = array_map( static fn ( string $t ): string => $wpdb->prefix . $t, Schema::tables() );
		sort( $found );
		sort( $expected );
		$this->assertSame( $expected, $found, 'the plugin owns exactly its prefixed tables' );
		foreach ( $found as $table ) {
			$this->assertStringStartsWith( $wpdb->prefix, $table );
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			$this->assertSame( 'InnoDB', $engine, "$table is transactional (consistent dumps)" );
		}
		$this->assertCount( 6, $found );
	}

	public function test_plugin_options_share_one_prefix_and_no_files_are_created(): void {
		global $wpdb;
		$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'swmap\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertContains( 'swmap_db_version', $options );
		foreach ( $options as $name ) {
			$this->assertMatchesRegularExpression( '/^swmap_[a-z_0-9]+$/', $name );
		}
		$uploads = wp_upload_dir()['basedir'] . '/sw-maps';
		$this->assertDirectoryDoesNotExist( $uploads, 'the plugin keeps no files of its own (nothing besides the database to back up)' );
	}
}
