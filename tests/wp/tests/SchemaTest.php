<?php
/**
 * @package sw-maps
 */

use SWMaps\WordPress\Persistence\Schema;
use SWMaps\WordPress\SystemStatus;

require_once __DIR__ . '/SwMapsTestCase.php';

final class SchemaTest extends SwMapsTestCase {

	public function test_all_tables_exist_after_migration(): void {
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$name = $wpdb->prefix . $table;
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ), $name );
		}
		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::OPTION ) );
	}

	public function test_migrate_is_idempotent_and_seeds_openstreetmap_once(): void {
		Schema::migrate();
		Schema::migrate();
		$providers = \SWMaps\WordPress\Services::instance()->providers()->list();
		$this->assertCount( 1, $providers );
		$this->assertSame( 'OpenStreetMap', $providers[0]->name );
		$this->assertTrue( $providers[0]->enabled );
	}

	public function test_rerunning_migration_one_keeps_data(): void {
		$map_id = $this->create_map( 'Keep me' );
		$this->assertTrue( Schema::migration_1() );
		$this->assertSame( 'Keep me', \SWMaps\WordPress\Services::instance()->maps()->get( $map_id )->title );
		$this->assertCount( 1, \SWMaps\WordPress\Services::instance()->providers()->list() );
	}

	public function test_old_version_triggers_upgrade_on_boot(): void {
		update_option( Schema::OPTION, 0 );
		\SWMaps\WordPress\Plugin::instance()->maybe_upgrade();
		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::OPTION ) );
	}

	public function test_unique_indexes(): void {
		global $wpdb;
		$map_id   = $this->create_map();
		$services = \SWMaps\WordPress\Services::instance();
		$layer    = $services->layers()->create( $map_id, array( 'title' => 'L' ) );
		$geo      = array(
			'type'        => 'Point',
			'coordinates' => array( 1, 1 ),
		);
		$services->features()->create(
			$layer->id,
			array(
				'geometry'    => $geo,
				'external_id' => 'a',
			)
		);
		$wpdb->suppress_errors( true );
		try {
			$services->features()->create(
				$layer->id,
				array(
					'geometry'    => $geo,
					'external_id' => 'a',
				)
			);
			$this->fail( 'duplicate external id accepted' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'feature', $e->getMessage() );
		}
		$wpdb->suppress_errors( false );
		// NULL external ids may repeat.
		$services->features()->create( $layer->id, array( 'geometry' => $geo ) );
		$services->features()->create( $layer->id, array( 'geometry' => $geo ) );
		$this->assertSame( 3, $services->feature_repository()->count( $layer->id ) );
	}

	public function test_system_status_report(): void {
		$report = SystemStatus::report();
		$this->assertSame( Schema::CURRENT, $report['db_version'] );
		$this->assertCount( 6, $report['tables'] );
		foreach ( $report['tables'] as $table ) {
			$this->assertTrue( $table['exists'] );
		}
	}

	public function test_drop_issues_drop_for_every_table(): void {
		// The WP test suite rewrites DROP TABLE to temporary tables, so the issued statements are captured instead.
		$queries = array();
		$capture = static function ( string $query ) use ( &$queries ): string {
			if ( 0 === strpos( $query, 'DROP TABLE' ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $capture, 1 );
		Schema::drop();
		remove_filter( 'query', $capture, 1 );
		$this->assertCount( 6, $queries );
		$this->assertFalse( get_option( Schema::OPTION ) );
		Schema::migrate();
	}
}
