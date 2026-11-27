<?php
/**
 * Minimal stand-in for WP-CLI so the command classes can be tested inside the WordPress test suite.
 *
 * @package sw-maps
 */

namespace {

	if ( ! class_exists( 'WP_CLI' ) ) {

		/** Raised instead of exiting. */
		class SwMapsCliExit extends Exception {
		}

		// phpcs:ignore Generic.Classes.DuplicateClassName.Found
		class WP_CLI {

			/** @var string[] */
			public static array $lines = array();

			public static function line( string $message = '' ): void {
				self::$lines[] = $message;
			}

			public static function success( string $message ): void {
				self::$lines[] = 'Success: ' . $message;
			}

			/** @param bool|int $exit Exit code. */
			public static function error( string $message, $exit = true ): void {
				throw new SwMapsCliExit( $message, is_int( $exit ) ? $exit : 1 );
			}

			/** @param array<string, mixed> $assoc_args Options. */
			public static function confirm( string $question, array $assoc_args = array() ): void {
				if ( ! isset( $assoc_args['yes'] ) ) {
					throw new SwMapsCliExit( 'Aborted: ' . $question, 1 );
				}
			}

			public static function add_command( string $name, string $class ): void {
				unset( $name, $class );
			}
		}
	}
}

namespace WP_CLI\Utils {

	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		/**
		 * @param string                           $format Format.
		 * @param array<int, array<string, mixed>> $items Rows.
		 * @param array<int, string>               $fields Columns.
		 */
		function format_items( string $format, array $items, array $fields ): void {
			\WP_CLI::$lines[] = wp_json_encode(
				array(
					'format' => $format,
					'fields' => $fields,
					'items'  => $items,
				)
			);
		}
	}
}
