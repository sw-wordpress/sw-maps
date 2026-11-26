<?php
/**
 * WP-CLI: wp sw-maps status | import | export.
 *
 * Exit codes: 0 success, 1 operational failure (not found, file unreadable, write failed), 2 the input or file was
 * rejected (invalid arguments, limits, validation). Machine readable output with --format=json.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Cli;

use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;
use SWMaps\Core\Import\ImportException;
use SWMaps\WordPress\Services;
use SWMaps\WordPress\SystemStatus;

/**
 * Manage SW Maps from the command line.
 */
final class Command {

	private const MAX_FILE_BYTES = 52428800;

	/**
	 * Shows the plugin status (version, schema, tables, environment, settings).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sw-maps status --format=json
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );
		$report = SystemStatus::report();
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $report ) );
			return;
		}
		$rows = array();
		foreach ( $report as $key => $value ) {
			if ( 'tables' === $key || 'settings' === $key || 'limits' === $key ) {
				continue;
			}
			$rows[] = array(
				'key'   => $key,
				'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value,
			);
		}
		foreach ( $report['limits'] as $name => $limit ) {
			$rows[] = array(
				'key'   => 'limit ' . $name,
				'value' => (string) $limit,
			);
		}
		foreach ( $report['tables'] as $table ) {
			$rows[] = array(
				'key'   => 'table ' . $table['name'],
				'value' => $table['exists'] ? 'ok' : 'missing',
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * Imports a file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path of the file (GeoJSON, GPX, CSV or a plugin file).
	 *
	 * [--format=<format>]
	 * : File format; guessed from the extension when omitted (a .json file with a "format" key is a plugin file).
	 * ---
	 * options:
	 *   - geojson
	 *   - gpx
	 *   - csv
	 *   - package
	 * ---
	 *
	 * [--map=<id>]
	 * : Target map (not needed for plugin files, which create new draft maps).
	 *
	 * [--mode=<mode>]
	 * : What to do with the locations.
	 * ---
	 * default: new_layer
	 * options:
	 *   - new_layer
	 *   - append
	 *   - upsert
	 *   - replace
	 * ---
	 *
	 * [--layer=<id>]
	 * : Target layer for append, upsert and replace.
	 *
	 * [--layer-title=<title>]
	 * : Title of the new layer.
	 *
	 * [--delimiter=<char>]
	 * : CSV delimiter (, ; | or "tab").
	 *
	 * [--decimal=<char>]
	 * : CSV decimal separator (. or ,).
	 *
	 * [--dry-run]
	 * : Validate and report without writing.
	 *
	 * [--skip-invalid]
	 * : Import the valid entries and skip the others.
	 *
	 * [--yes]
	 * : Do not ask for confirmation when replacing a layer.
	 *
	 * [--output=<format>]
	 * : Report format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sw-maps import trail.gpx --map=3 --user=1
	 *     wp sw-maps import backup.json --output=json
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function import( array $args, array $assoc_args ): void {
		$path    = $args[0] ?? '';
		$content = $this->read( $path );
		$format  = $assoc_args['format'] ?? $this->guess( $path, $content );
		try {
			if ( 'package' === $format ) {
				$report = Services::instance()->package_importer()->import( $content, get_current_user_id() );
				$this->report( $report, $assoc_args, sprintf( '%d map(s) imported as drafts.', count( $report['maps'] ) ) );
				return;
			}
			if ( ! isset( $assoc_args['map'] ) || ! ctype_digit( (string) $assoc_args['map'] ) ) {
				\WP_CLI::error( 'Use --map=<id> to say which map receives the locations.', 2 );
			}
			$mode  = $assoc_args['mode'] ?? 'new_layer';
			$dry   = isset( $assoc_args['dry-run'] );
			$input = array(
				'format'       => $format,
				'content'      => $content,
				'mode'         => $mode,
				'dry_run'      => $dry,
				'skip_invalid' => isset( $assoc_args['skip-invalid'] ),
			);
			if ( 'replace' === $mode && ! $dry ) {
				\WP_CLI::confirm( 'This deletes the current locations of the layer. Continue?', $assoc_args );
				$input['confirm_replace'] = true;
			}
			if ( isset( $assoc_args['layer'] ) ) {
				$input['layer_id'] = (int) $assoc_args['layer'];
			}
			if ( isset( $assoc_args['layer-title'] ) ) {
				$input['layer_title'] = $assoc_args['layer-title'];
			}
			$options = array();
			if ( isset( $assoc_args['delimiter'] ) ) {
				$options['delimiter'] = 'tab' === $assoc_args['delimiter'] ? "\t" : $assoc_args['delimiter'];
			}
			if ( isset( $assoc_args['decimal'] ) ) {
				$options['decimal'] = $assoc_args['decimal'];
			}
			if ( array() !== $options ) {
				$input['options'] = $options;
			}
			$report = Services::instance()->imports()->run( (int) $assoc_args['map'], $input );
			$line   = $dry
				? sprintf( 'Checked: %d new, %d updated, %d invalid (nothing written).', $report['created'], $report['updated'], $report['error_count'] )
				: sprintf( 'Imported: %d new, %d updated, %d skipped.', $report['created'], $report['updated'], $report['skipped'] );
			$this->report( $report, $assoc_args, $line );
		} catch ( ImportException $e ) {
			\WP_CLI::error( 'The file was rejected (' . $e->reason . '): ' . $e->getMessage(), 2 );
		} catch ( ValidationException $e ) {
			\WP_CLI::error( 'Invalid input: ' . implode( ' ', $e->errors() ), 2 );
		} catch ( NotFoundException ) {
			\WP_CLI::error( 'Map or layer not found.', 1 );
		}
	}

	/**
	 * Exports a map (or all maps) to a file or to standard output.
	 *
	 * ## OPTIONS
	 *
	 * [<map-id>]
	 * : The map to export.
	 *
	 * [--all]
	 * : Export all maps as one plugin file.
	 *
	 * [--format=<format>]
	 * : Export format (json = plugin file).
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - geojson
	 *   - csv
	 * ---
	 *
	 * [--layer=<id>]
	 * : Only this layer (geojson, csv).
	 *
	 * [--output=<file>]
	 * : Write to this file instead of standard output.
	 *
	 * [--yes]
	 * : Overwrite an existing output file without asking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sw-maps export 3 --format=geojson --output=map3.geojson
	 *     wp sw-maps export --all > backup.json
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function export( array $args, array $assoc_args ): void {
		$exporter = Services::instance()->exporter();
		$format   = $assoc_args['format'] ?? 'json';
		try {
			if ( isset( $assoc_args['all'] ) ) {
				$file = $exporter->package();
			} else {
				if ( ! isset( $args[0] ) || ! ctype_digit( $args[0] ) ) {
					\WP_CLI::error( 'Give a map id or use --all.', 2 );
				}
				$id       = (int) $args[0];
				$layer_id = isset( $assoc_args['layer'] ) ? (int) $assoc_args['layer'] : null;
				$file     = match ( $format ) {
					'geojson' => $exporter->geojson( $id, $layer_id ),
					'csv'     => $exporter->csv( $id, $layer_id ),
					'json'    => $exporter->package( array( $id ) ),
					default   => \WP_CLI::error( 'Unknown format.', 2 ),
				};
			}
		} catch ( NotFoundException ) {
			\WP_CLI::error( 'Map or layer not found.', 1 );
		}
		if ( ! isset( $assoc_args['output'] ) ) {
			\WP_CLI::line( $file['content'] );
			return;
		}
		$target = (string) $assoc_args['output'];
		if ( '' === $target || is_dir( $target ) || ! is_dir( dirname( $target ) ) || ! is_writable( dirname( $target ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			\WP_CLI::error( 'The output path is not writable.', 1 );
		}
		if ( file_exists( $target ) ) {
			\WP_CLI::confirm( sprintf( '%s exists. Overwrite?', $target ), $assoc_args );
		}
		if ( false === file_put_contents( $target, $file['content'], LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			\WP_CLI::error( 'Writing the file failed.', 1 );
		}
		\WP_CLI::success( sprintf( 'Exported to %s (%d bytes).', $target, strlen( $file['content'] ) ) );
	}

	/**
	 * Reads an import file: a regular readable file within the size limit.
	 *
	 * @param string $path File path.
	 */
	private function read( string $path ): string {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			\WP_CLI::error( 'The file does not exist or is not readable.', 1 );
		}
		if ( (int) filesize( $path ) > self::MAX_FILE_BYTES ) {
			\WP_CLI::error( 'The file is too large.', 2 );
		}
		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $content ? '' : $content;
	}

	/**
	 * Format of a file from its extension (and, for JSON, whether it is a plugin file).
	 *
	 * @param string $path File path.
	 * @param string $content File content.
	 */
	private function guess( string $path, string $content ): string {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'gpx' === $ext ) {
			return 'gpx';
		}
		if ( in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			return 'csv';
		}
		return str_contains( substr( $content, 0, 200 ), 'sw-maps-package' ) ? 'package' : 'geojson';
	}

	/**
	 * Prints the report as text or JSON.
	 *
	 * @param array<string, mixed>  $report Report.
	 * @param array<string, string> $assoc_args Options.
	 * @param string                $line Text summary.
	 */
	private function report( array $report, array $assoc_args, string $line ): void {
		if ( 'json' === ( $assoc_args['output'] ?? 'text' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $report ) );
			return;
		}
		\WP_CLI::success( $line );
	}
}
