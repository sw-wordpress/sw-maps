<?php
/**
 * E2E only.
 *
 * 1. ?swmap_consent_api=1 simulates an active WP Consent API without the real plugin: maps are gated and the decision
 *    comes from localStorage "swmap_consent" (a stub for window.wp_has_consent).
 * 2. With the REAL wp-consent-api plugin active, maps are only gated on requests that opt in (?swmap_real_consent=1),
 *    so the other specs are unaffected by its presence.
 */

// phpcs:disable WordPress.Security.NonceVerification

if ( isset( $_GET['swmap_consent_api'] ) ) {
	add_filter( 'swmap_consent_api_active', '__return_true' );
	// Printed after every other footer script so it also wins when the real consent plugin is active.
	add_action(
		'wp_footer',
		static function () {
			echo '<script>window.wp_has_consent=function(c){try{return localStorage.getItem("swmap_consent")===c;}catch(e){return false;}};</script>';
		},
		999
	);
}

// A consent management plugin declares the consent type; without one the Consent API treats everything as allowed.
if ( isset( $_GET['swmap_real_consent'] ) ) {
	add_filter(
		'wp_get_consent_type',
		static function () {
			return 'optin';
		}
	);
}

add_filter(
	'swmap_consent_category',
	static function ( $category ) {
		if ( isset( $_GET['swmap_consent_api'] ) || isset( $_GET['swmap_real_consent'] ) ) {
			return $category;
		}
		return null;
	},
	99
);
