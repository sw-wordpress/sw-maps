<?php
/**
 * Plugin bootstrap: registers hooks. Nothing heavy happens on load.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress;

use SWMaps\WordPress\Api\BaseController;
use SWMaps\WordPress\Persistence\Schema;

final class Plugin {

	private static ?self $instance = null;
	private bool $booted           = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		add_action( 'wp_initialize_site', array( Lifecycle::class, 'on_new_site' ) );
		add_action( 'init', array( Integration\Shortcode::class, 'register' ) );
		add_action( 'init', array( Integration\Block::class, 'register' ) );
		Integration\Privacy::register();
		Integration\Consent::register();
		if ( is_admin() ) {
			( new Admin\AdminPage() )->register();
		}
	}

	/** Runs pending migrations when the stored schema is older than the code (cheap option comparison). */
	public function maybe_upgrade(): void {
		if ( (int) get_option( Schema::OPTION, 0 ) < Schema::CURRENT ) {
			Lifecycle::install_current_blog();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'sw-maps', false, dirname( plugin_basename( SWMAPS_FILE ) ) . '/languages' );
	}

	public function register_rest(): void {
		foreach ( $this->controllers() as $controller ) {
			$controller->register();
		}
	}

	/** @return list<BaseController> */
	private function controllers(): array {
		return array(
			new Api\MapsController(),
			new Api\LayersController(),
			new Api\ImportsController(),
			new Api\FeaturesController(),
			new Api\ProvidersController(),
			new Api\SettingsController(),
		);
	}
}
