<?php
/**
 * Benchmarks (M3-07): public payload and rendering for 100 / 1,000 / 10,000 points and large tracks, plus the
 * on-demand endpoint. Skipped unless SWMAPS_BENCH=1; results are written to STDERR as a Markdown table.
 *
 *   SWMAPS_BENCH=1 composer test:wp -- --filter BenchmarkTest
 *
 * @package sw-maps
 */

use SWMaps\Core\Domain\Feature;
use SWMaps\Core\Domain\FeatureProperties;
use SWMaps\Core\Domain\Geometry;
use SWMaps\Core\Domain\GeometryType;
use SWMaps\WordPress\Integration\MapPayload;
use SWMaps\WordPress\Integration\Renderer;
use SWMaps\WordPress\Services;

require_once __DIR__ . '/SwMapsTestCase.php';

final class BenchmarkTest extends SwMapsTestCase {

	public function set_up(): void {
		if ( '1' !== getenv( 'SWMAPS_BENCH' ) ) {
			$this->markTestSkipped( 'Set SWMAPS_BENCH=1 to run the benchmarks.' );
		}
		parent::set_up();
		update_option( \SWMaps\WordPress\Settings::OPTION, array( 'max_features_per_layer' => 50000 ) );
	}

	/** @return array<string, mixed> */
	private function measure( callable $work ): array {
		gc_collect_cycles();
		$before = memory_get_peak_usage( true );
		memory_reset_peak_usage();
		$start  = hrtime( true );
		$result = $work();
		return array(
			'ms'     => round( ( hrtime( true ) - $start ) / 1e6, 1 ),
			'mem_mb' => round( ( memory_get_peak_usage( true ) - 0 ) / 1048576, 1 ),
			'result' => $result,
			'before' => $before,
		);
	}

	private function seed_points( int $count ): int {
		$this->as_role( 'administrator' );
		$map_id = $this->create_map( "Bench $count" );
		$layer  = Services::instance()->layers()->create( $map_id, array( 'title' => 'Points' ) );
		$repo   = Services::instance()->feature_repository();
		$now    = new DateTimeImmutable();
		Services::instance()->maps()->update( $map_id, array( 'status' => 'published' ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$lon = 5 + ( $i % 1000 ) * 0.01;
			$lat = 45 + intdiv( $i, 1000 ) * 0.01 + ( $i % 7 ) * 0.001;
			$repo->save( new Feature( 0, $layer->id, null, Geometry::point( $lon, $lat ), FeatureProperties::fromArray( array( 'title' => "Place $i", 'address' => "Street $i" ) ), array(), $now ) );
		}
		return $map_id;
	}

	/**
	 * A GPS-like track: about 4 m between fixes, slowly changing heading (deterministic).
	 *
	 * @return list<array{0: float, 1: float}>
	 */
	private static function gps_track( int $n ): array {
		mt_srand( 42 );
		$lon     = 8.0;
		$lat     = 49.0;
		$heading = 0.5;
		$coords  = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$heading += ( mt_rand( -100, 100 ) / 100 ) * 0.08;
			$lon     += cos( $heading ) * 0.00004;
			$lat     += sin( $heading ) * 0.00004;
			$coords[] = array( $lon, $lat );
		}
		return $coords;
	}

	public function test_points(): void {
		$rows = array( '| points | payload build | memory | JSON | gzip | render (HTML) | HTML size |', '|---:|---:|---:|---:|---:|---:|---:|' );
		foreach ( array( 100, 1000, 10000 ) as $n ) {
			$map_id = $this->seed_points( $n );
			$map    = Services::instance()->maps()->get( $map_id );
			$built  = $this->measure( fn () => MapPayload::build( $map ) );
			$json   = (string) wp_json_encode( $built['result'] );
			$html   = $this->measure( fn () => Renderer::render( $map_id ) );
			$rows[] = sprintf( '| %d | %s ms | %s MB | %s KB | %s KB | %s ms | %s KB |', $n, $built['ms'], $built['mem_mb'], round( strlen( $json ) / 1024 ), round( strlen( (string) gzencode( $json, 6 ) ) / 1024 ), $html['ms'], round( strlen( (string) $html['result'] ) / 1024 ) );
			$this->assertNotSame( '', (string) $html['result'] );
		}
		fwrite( STDERR, "\n" . implode( "\n", $rows ) . "\n" );
	}

	public function test_large_tracks_and_on_demand_loading(): void {
		$this->as_role( 'administrator' );
		$map_id = $this->create_map( 'Bench tracks' );
		$layer  = Services::instance()->layers()->create( $map_id, array( 'title' => 'Tracks' ) );
		$repo   = Services::instance()->feature_repository();
		$now    = new DateTimeImmutable();
		$rows   = array( '| track points | stored geometry | payload (simplified) | simplified points | build |', '|---:|---:|---:|---:|---:|' );
		foreach ( array( 1000, 10000, 100000 ) as $n ) {
			$coords  = self::gps_track( $n );
			$saved = $repo->save( new Feature( 0, $layer->id, null, Geometry::create( GeometryType::LineString, $coords ), FeatureProperties::empty(), array(), $now ) );
			$feature = $repo->find( $saved->id );
			$built   = $this->measure( fn () => MapPayload::feature( $feature ) );
			$out     = $built['result'];
			$rows[]  = sprintf( '| %d | %s KB | %s KB | %d | %s ms |', $n, round( strlen( (string) wp_json_encode( $feature->geometry->toArray() ) ) / 1024 ), round( strlen( (string) wp_json_encode( $out ) ) / 1024 ), count( $out['geometry']['coordinates'] ), $built['ms'] );
			$repo->delete( $saved->id );
		}
		fwrite( STDERR, "\n" . implode( "\n", $rows ) . "\n" );

		$points = $this->seed_points( 10000 );
		Services::instance()->maps()->update( $points, array( 'settings' => array( 'ajax' => true ) ) );
		$measured = $this->measure( fn () => $this->rest( 'GET', "/public/maps/{$points}/features", null, array( 'bbox' => '5,45,10,46', 'zoom' => 10, 'per_page' => 500 ) ) );
		$data     = $measured['result']->get_data();
		fwrite( STDERR, sprintf( "\n| on-demand request (10,000 points, 500 per page) | %s ms | %s MB | %d of %d locations |\n", $measured['ms'], $measured['mem_mb'], count( $data['layers'][0]['features'] ), $data['total'] ) );
		$this->assertSame( 500, count( $data['layers'][0]['features'] ) );
	}
}
