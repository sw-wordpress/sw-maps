<?php

/**
 * PHPStan stubs for WP-CLI (only present under `wp`; never loaded at runtime).
 *
 * @package sw-maps
 */

// phpcs:disable

namespace {
    class WP_CLI
    {
        /** @param callable|object|string $callable @param array<string, mixed> $args */
        public static function add_command($name, $callable, $args = [])
        {
        }

        public static function line(string $message = ''): void
        {
        }

        public static function success(string $message): void
        {
        }

        /** @param bool|int $exit */
        public static function error(string $message, $exit = true): never
        {
            exit(1);
        }

        /** @param array<string, mixed> $assoc_args */
        public static function confirm(string $question, array $assoc_args = []): void
        {
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
    }
}
