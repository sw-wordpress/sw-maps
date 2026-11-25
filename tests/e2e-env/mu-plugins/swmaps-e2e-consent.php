<?php
/**
 * E2E only: simulates an active WP Consent API. Requests with ?swmap_consent_api=1 behave as if a consent plugin is
 * active (the PHP function exists, so maps are gated); the decision comes from localStorage "swmap_consent".
 */

if ( isset( $_GET['swmap_consent_api'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
	function wp_has_consent( $category ) { // phpcs:ignore
		return false;
	}
	add_action(
		'wp_head',
		static function () {
			echo '<script>window.wp_has_consent=function(c){try{return localStorage.getItem("swmap_consent")===c;}catch(e){return false;}};</script>';
		},
		1
	);
}
