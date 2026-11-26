<?php
/**
 * WordPress test suite configuration. Uses ONLY the private throw-away MariaDB instance (bin/test-db.sh),
 * never the development site's database.
 */

define( 'ABSPATH', __DIR__ . '/vendor/johnpbloch/wordpress-core/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'SWMAPS_WP_TEST_DB_NAME' ) ?: 'swmaps_wp_tests' );
define( 'DB_USER', getenv( 'SWMAPS_TEST_DB_USER' ) ?: 'swmaps' );
define( 'DB_PASSWORD', getenv( 'SWMAPS_TEST_DB_PASSWORD' ) ?: 'swmaps' );
define( 'DB_HOST', ( getenv( 'SWMAPS_TEST_DB_HOST' ) ?: '127.0.0.1' ) . ':' . ( getenv( 'SWMAPS_TEST_DB_PORT' ) ?: '33078' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'sw-maps Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

$table_prefix = 'wptests_';
