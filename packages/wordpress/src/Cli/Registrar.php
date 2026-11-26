<?php
/**
 * Registers the WP-CLI commands (only when WP-CLI is running).
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Cli;

final class Registrar {

	/**
	 * Command name => handler class (the parent command must be registered first).
	 *
	 * @return array<string, class-string>
	 */
	public static function commands(): array {
		return array(
			'sw-maps'      => Command::class,
			'sw-maps maps' => MapsCommand::class,
		);
	}

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		foreach ( self::commands() as $name => $handler ) {
			\WP_CLI::add_command( $name, $handler );
		}
	}
}
