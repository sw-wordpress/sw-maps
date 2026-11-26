<?php
/**
 * WordPress admin integration: menu page hosting the Vue/Quasar single page application.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Admin;

use SWMaps\WordPress\Capabilities;

final class AdminPage {

	public const SLUG    = 'sw-maps';
	private const HANDLE = 'sw-maps-admin';

	private string $hook = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'module_tag' ), 10, 2 );
	}

	public function add_menu(): void {
		$this->hook = (string) add_menu_page(
			__( 'Maps', 'sw-maps' ),
			__( 'Maps', 'sw-maps' ),
			Capabilities::EDIT_MAPS,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-location-alt',
			30
		);
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::EDIT_MAPS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sw-maps' ), 403 );
		}
		echo '<div class="wrap swmap-wrap">';
		if ( ! is_readable( SWMAPS_DIR . 'assets/admin/app.js' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The SW Maps admin interface has not been built. Install the release package or run "make build".', 'sw-maps' ) . '</p></div>';
		} else {
			echo '<div id="sw-maps-app"></div><noscript>' . esc_html__( 'SW Maps requires JavaScript.', 'sw-maps' ) . '</noscript>';
		}
		echo '</div>';
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== $this->hook || ! is_readable( SWMAPS_DIR . 'assets/admin/app.js' ) ) {
			return;
		}
		wp_enqueue_media();
		$version = (string) filemtime( SWMAPS_DIR . 'assets/admin/app.js' );
		wp_enqueue_style( self::HANDLE, SWMAPS_URL . 'assets/admin/app.css', array(), $version );
		wp_enqueue_script( self::HANDLE, SWMAPS_URL . 'assets/admin/app.js', array(), $version, true );
		wp_add_inline_script( self::HANDLE, 'window.swmapsConfig = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	/**
	 * Configuration contract with the SPA (apps/admin/src/types/config.ts).
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$caps = array();
		foreach ( Capabilities::all() as $cap ) {
			if ( current_user_can( $cap ) ) {
				$caps[] = $cap;
			}
		}
		return array(
			'restRoot'     => esc_url_raw( rest_url( 'sw-maps/v1/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'locale'       => determine_locale(),
			'version'      => SWMAPS_VERSION,
			'adminUrl'     => esc_url_raw( admin_url( 'admin.php?page=' . self::SLUG ) ),
			'siteUrl'      => esc_url_raw( site_url() ),
			'capabilities' => $caps,
		);
	}

	public function module_tag( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		return str_replace( '<script ', '<script type="module" ', $tag );
	}
}
