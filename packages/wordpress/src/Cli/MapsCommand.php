<?php
/**
 * WP-CLI: wp sw-maps maps list.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Cli;

use SWMaps\WordPress\Services;

/**
 * Work with maps.
 */
final class MapsCommand {

	/**
	 * Lists maps.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only maps with this status.
	 * ---
	 * options:
	 *   - draft
	 *   - published
	 * ---
	 *
	 * [--search=<text>]
	 * : Search in the title.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sw-maps maps list --status=published --format=ids
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function list_( array $args, array $assoc_args ): void {
		unset( $args );
		$service = Services::instance()->maps();
		$rows    = array();
		for ( $page = 1;; ++$page ) {
			$result = $service->list( (string) ( $assoc_args['search'] ?? '' ), $assoc_args['status'] ?? null, $page, 100 );
			foreach ( $result['items'] as $map ) {
				$counts = $service->counts( $map->id );
				$rows[] = array(
					'id'       => $map->id,
					'title'    => $map->title,
					'slug'     => $map->slug,
					'status'   => $map->status->value,
					'layers'   => $counts['layers'],
					'features' => $counts['features'],
				);
			}
			if ( $page * 100 >= $result['total'] || array() === $result['items'] ) {
				break;
			}
		}
		$format = $assoc_args['format'] ?? 'table';
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_column( $rows, 'id' ) ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'title', 'slug', 'status', 'layers', 'features' ) );
	}
}
