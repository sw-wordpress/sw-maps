<?php
/**
 * Privacy policy text suggestion (no claim of blanket GDPR compliance).
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Integration;

use SWMaps\WordPress\Services;

final class Privacy {

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'add_policy_content' ) );
	}

	public static function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'SW Maps', wp_kses_post( self::policy_text() ) );
	}

	public static function policy_text(): string {
		$hosts = array();
		foreach ( Services::instance()->providers()->list() as $provider ) {
			if ( $provider->enabled ) {
				$hosts = array_merge( $hosts, $provider->externalHosts() );
			}
		}
		$hosts = array_values( array_unique( $hosts ) );
		$text  = '<h2>' . esc_html__( 'Maps', 'sw-maps' ) . '</h2>';
		$text .= '<p class="privacy-policy-tutorial">' . esc_html__( 'This text is a suggestion. Check it against your configuration and have it reviewed.', 'sw-maps' ) . '</p>';
		$text .= '<p>' . esc_html__( 'We display interactive maps. When a map is loaded, your browser requests map tiles from the map provider. The provider receives your IP address and technical request data. SW Maps itself sets no cookies and sends no data to third parties other than the configured map provider.', 'sw-maps' ) . '</p>';
		if ( array() !== $hosts ) {
			/* translators: %s: comma separated list of tile server host names */
			$text .= '<p>' . esc_html( sprintf( __( 'Currently configured tile servers: %s.', 'sw-maps' ), implode( ', ', $hosts ) ) ) . '</p>';
		}
		$text .= '<p>' . esc_html__( 'Maps are loaded only after you consent or after you click "Load map", depending on the site configuration.', 'sw-maps' ) . '</p>';
		return $text;
	}
}
