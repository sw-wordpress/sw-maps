<?php
/**
 * WP-CLI commands: status, maps list, import (all formats and modes), export, exit codes and confirmations.
 *
 * @package sw-maps
 */

use SWMaps\WordPress\Cli\Command;
use SWMaps\WordPress\Cli\MapsCommand;
use SWMaps\WordPress\Cli\Registrar;

require_once __DIR__ . '/SwMapsTestCase.php';
require_once __DIR__ . '/WpCliStub.php';

final class CliTest extends SwMapsTestCase {

	private string $dir;

	public function set_up(): void {
		parent::set_up();
		WP_CLI::$lines = array();
		$this->dir     = sys_get_temp_dir() . '/swmaps-cli-' . wp_generate_password( 8, false );
		mkdir( $this->dir );
	}

	public function tear_down(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	/** @return array<string, mixed> */
	private function point(): array {
		return array(
			'type'        => 'Point',
			'coordinates' => array( 8.4, 49.0 ),
		);
	}

	private function file( string $name, string $content ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $content );
		return $path;
	}

	/** Runs a callable and returns the exit code of a WP_CLI::error (0 when it finished normally). */
	private function exit_code( callable $call ): int {
		try {
			$call();
		} catch ( SwMapsCliExit $e ) {
			return $e->getCode();
		}
		return 0;
	}

	public function test_status_prints_a_table_or_json(): void {
		( new Command() )->status( array(), array() );
		$table = json_decode( WP_CLI::$lines[0], true );
		$this->assertSame( 'table', $table['format'] );
		$this->assertContains( 'plugin_version', array_column( $table['items'], 'key' ) );
		WP_CLI::$lines = array();
		( new Command() )->status( array(), array( 'format' => 'json' ) );
		$this->assertSame( Schema_CURRENT(), json_decode( WP_CLI::$lines[0], true )['db_expected'] );
	}

	public function test_maps_list_filters_and_formats(): void {
		$a = $this->create_map( 'Alpha' );
		$b = $this->create_map( 'Beta' );
		$this->rest( 'PATCH', "/maps/{$b}", array( 'status' => 'published' ) );
		( new MapsCommand() )->list_( array(), array() );
		$this->assertCount( 2, json_decode( WP_CLI::$lines[0], true )['items'] );
		WP_CLI::$lines = array();
		( new MapsCommand() )->list_( array(), array( 'status' => 'published', 'format' => 'ids' ) );
		$this->assertSame( array( (string) $b ), WP_CLI::$lines );
		WP_CLI::$lines = array();
		( new MapsCommand() )->list_( array(), array( 'search' => 'Alp', 'format' => 'json' ) );
		$this->assertSame( array( $a ), array_column( json_decode( WP_CLI::$lines[0], true )['items'], 'id' ) );
	}

	public function test_import_formats_modes_and_exit_codes(): void {
		$map_id  = $this->create_map( 'Target' );
		$geojson = $this->file( 'places.geojson', wp_json_encode( array( 'type' => 'FeatureCollection', 'features' => array( array( 'type' => 'Feature', 'properties' => array( 'name' => 'A', 'id' => 'a1' ), 'geometry' => array( 'type' => 'Point', 'coordinates' => array( 8, 49 ) ) ) ) ) ) );
		$cmd     = new Command();
		$cmd->import( array( $geojson ), array( 'map' => (string) $map_id, 'dry-run' => true ) );
		$this->assertStringContainsString( 'nothing written', WP_CLI::$lines[0] );
		$this->assertSame( array(), $this->rest( 'GET', "/maps/{$map_id}/layers" )->get_data() );
		WP_CLI::$lines = array();
		$cmd->import( array( $geojson ), array( 'map' => (string) $map_id, 'layer-title' => 'CLI' ) );
		$this->assertStringContainsString( 'Imported: 1 new', WP_CLI::$lines[0] );
		$layer = $this->rest( 'GET', "/maps/{$map_id}/layers" )->get_data()[0];
		$this->assertSame( 'CLI', $layer['title'] );

		WP_CLI::$lines = array();
		$cmd->import( array( $geojson ), array( 'map' => (string) $map_id, 'mode' => 'upsert', 'layer' => (string) $layer['id'], 'output' => 'json' ) );
		$this->assertSame( 1, json_decode( WP_CLI::$lines[0], true )['updated'] );

		$gpx = $this->file( 'trail.gpx', '<gpx><wpt lat="49" lon="8"><name>W</name></wpt></gpx>' );
		$cmd->import( array( $gpx ), array( 'map' => (string) $map_id ) );
		$csv = $this->file( 'k.csv', "name;lat;lon\nK;49,5;8,5\n" );
		WP_CLI::$lines = array();
		$cmd->import( array( $csv ), array( 'map' => (string) $map_id, 'delimiter' => ';', 'decimal' => ',' ) );
		$this->assertStringContainsString( 'Imported: 1 new', WP_CLI::$lines[0] );
		$tab = $this->file( 't.txt', "name\tlat\tlon\nT\t49.5\t8.5\n" );
		WP_CLI::$lines = array();
		$cmd->import( array( $tab ), array( 'map' => (string) $map_id, 'delimiter' => 'tab' ) );
		$this->assertStringContainsString( 'Imported: 1 new', WP_CLI::$lines[0] );

		// Exit codes: 2 for rejected input, 1 for operational problems.
		$this->assertSame( 2, $this->exit_code( fn () => $cmd->import( array( $geojson ), array() ) ), 'a map is required' );
		$this->assertSame( 2, $this->exit_code( fn () => $cmd->import( array( $this->file( 'x.gpx', '<!DOCTYPE x [<!ENTITY a "b">]><gpx/>' ) ), array( 'map' => (string) $map_id ) ) ) );
		$this->assertSame( 2, $this->exit_code( fn () => $cmd->import( array( $geojson ), array( 'map' => (string) $map_id, 'mode' => 'zzz' ) ) ) );
		$this->assertSame( 1, $this->exit_code( fn () => $cmd->import( array( $this->dir . '/missing.geojson' ), array( 'map' => (string) $map_id ) ) ) );
		$this->assertSame( 1, $this->exit_code( fn () => $cmd->import( array( $geojson ), array( 'map' => '999999' ) ) ) );
		$this->assertSame( 1, $this->exit_code( fn () => $cmd->import( array( '' ), array() ) ) );
		$invalid = $this->file( 'bad.geojson', wp_json_encode( array( 'type' => 'FeatureCollection', 'features' => array( array( 'type' => 'Feature', 'properties' => array(), 'geometry' => array( 'type' => 'Point', 'coordinates' => array( 999, 1 ) ) ) ) ) ) );
		$this->assertSame( 2, $this->exit_code( fn () => $cmd->import( array( $invalid ), array( 'map' => (string) $map_id ) ) ), 'invalid entries reject the import' );
	}

	public function test_replace_needs_confirmation(): void {
		$map_id  = $this->create_map( 'Replace' );
		$layer   = $this->rest( 'POST', "/maps/{$map_id}/layers", array( 'title' => 'L' ) )->get_data();
		$this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => array( 'type' => 'Point', 'coordinates' => array( 1, 1 ) ) ) );
		$geojson = $this->file( 'p.geojson', wp_json_encode( array( 'type' => 'Point', 'coordinates' => array( 8, 49 ) ) ) );
		$args    = array( 'map' => (string) $map_id, 'mode' => 'replace', 'layer' => (string) $layer['id'] );
		$cmd     = new Command();
		$this->assertSame( 1, $this->exit_code( fn () => $cmd->import( array( $geojson ), $args ) ), 'declined without --yes' );
		$this->assertCount( 1, $this->rest( 'GET', "/layers/{$layer['id']}/features" )->get_data() );
		$cmd->import( array( $geojson ), $args + array( 'dry-run' => true ) );
		$cmd->import( array( $geojson ), $args + array( 'yes' => true ) );
		$this->assertSame( array( 8.0, 49.0 ), $this->rest( 'GET', "/layers/{$layer['id']}/features" )->get_data()[0]['geometry']['coordinates'] );
	}

	public function test_export_to_stdout_and_files_and_package_roundtrip(): void {
		$map_id = $this->create_map( 'Exported' );
		$layer  = $this->rest( 'POST', "/maps/{$map_id}/layers", array( 'title' => 'L' ) )->get_data();
		$this->rest( 'POST', "/layers/{$layer['id']}/features", array( 'geometry' => $this->point(), 'properties' => array( 'title' => 'Hut' ) ) );
		$cmd = new Command();
		$cmd->export( array( (string) $map_id ), array( 'format' => 'geojson' ) );
		$this->assertSame( 'Hut', json_decode( WP_CLI::$lines[0], true )['features'][0]['properties']['title'] );
		WP_CLI::$lines = array();
		$cmd->export( array( (string) $map_id ), array( 'format' => 'csv', 'layer' => (string) $layer['id'] ) );
		$this->assertStringContainsString( 'Hut', WP_CLI::$lines[0] );
		$out = $this->dir . '/map.json';
		WP_CLI::$lines = array();
		$cmd->export( array( (string) $map_id ), array( 'output' => $out ) );
		$this->assertStringContainsString( 'Exported to', WP_CLI::$lines[0] );
		$this->assertSame( 'sw-maps-package', json_decode( (string) file_get_contents( $out ), true )['format'] );
		$this->assertSame( 1, $this->exit_code( fn () => $cmd->export( array( (string) $map_id ), array( 'output' => $out ) ) ), 'overwriting asks first' );
		$cmd->export( array( (string) $map_id ), array( 'output' => $out, 'yes' => true ) );
		$all = $this->dir . '/all.json';
		$cmd->export( array(), array( 'all' => true, 'output' => $all ) );
		$this->assertCount( 1, json_decode( (string) file_get_contents( $all ), true )['maps'] );

		// A package dry-run validates without creating an additional map.
		WP_CLI::$lines = array();
		$cmd->import( array( $out ), array( 'output' => 'json', 'dry-run' => true ) );
		$this->assertSame( array( 'maps' => 1, 'layers' => 1, 'features' => 1, 'categories' => 0 ), json_decode( WP_CLI::$lines[0], true ) );
		$this->assertCount( 1, $this->rest( 'GET', '/maps' )->get_data() );

		// The plugin file imports back as a new draft map (format guessed from the content).
		WP_CLI::$lines = array();
		$cmd->import( array( $out ), array( 'output' => 'json' ) );
		$report = json_decode( WP_CLI::$lines[0], true );
		$this->assertSame( 1, count( $report['maps'] ) );
		$this->assertSame( 'draft', $this->rest( 'GET', "/maps/{$report['maps'][0]['id']}" )->get_data()['status'] );
		WP_CLI::$lines = array();
		$cmd->import( array( $out ), array() );
		$this->assertStringContainsString( '1 map(s) imported as drafts.', WP_CLI::$lines[0] );

		$this->assertSame( 2, $this->exit_code( fn () => $cmd->export( array(), array() ) ) );
		$this->assertSame( 2, $this->exit_code( fn () => $cmd->export( array( 'x' ), array() ) ) );
		$this->assertSame( 2, $this->exit_code( fn () => $cmd->export( array( (string) $map_id ), array( 'format' => 'xml' ) ) ) );
		$this->assertSame( 1, $this->exit_code( fn () => $cmd->export( array( '999999' ), array() ) ) );
		$this->assertSame( 1, $this->exit_code( fn () => $cmd->export( array( (string) $map_id ), array( 'output' => $this->dir . '/missing-dir/x.json' ) ) ) );
		$this->assertSame( 1, $this->exit_code( fn () => $cmd->export( array( (string) $map_id ), array( 'output' => $this->dir ) ) ), 'a directory is no output file' );
	}

	public function test_registrar_lists_the_commands_and_does_nothing_without_wp_cli(): void {
		$this->assertSame( array( 'sw-maps' => Command::class, 'sw-maps maps' => MapsCommand::class ), Registrar::commands() );
		Registrar::register();
		$this->assertTrue( true );
	}
}

/** Current schema version (kept out of the class so the test reads naturally). */
function Schema_CURRENT(): int { // phpcs:ignore
	return \SWMaps\WordPress\Persistence\Schema::CURRENT;
}
