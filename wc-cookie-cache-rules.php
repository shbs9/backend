<?php
/**
 * Plugin Name: WC Cookie Cache Rules
 * Description: Strips tracking & auth cookies before the cache layer sees them,
 *              so the home page gets a cache HIT regardless of these cookies.
 * Version:     1.0.0
 *
 * Must-Use plugin — drop into /wp-content/mu-plugins/
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cookies to strip so they never cause a cache MISS.
 *
 *  _fbp  – Meta Pixel Browser ID
 *  _fbc  – Meta Pixel Click ID
 *  sbc   – Stape Conversion Tracking
 *  wc_auth_business_type – WooCommerce custom auth / business-type session
 */
const WCCR_IGNORED_COOKIES = [ '_fbp', '_fbc', 'sbc', 'wc_auth_business_type' ];

// ── WP Rocket ────────────────────────────────────────────────────────────────
add_filter( 'rocket_cache_ignored_parameters', 'wccr_ignore_cookies' );

// ── LiteSpeed Cache ──────────────────────────────────────────────────────────
add_filter( 'litespeed_vary_cookies', 'wccr_ignore_cookies' );

// ── W3 Total Cache ───────────────────────────────────────────────────────────
add_filter( 'w3tc_reject_cookies', 'wccr_ignore_cookies' );

/**
 * Remove our cookies from whatever list the cache plugin uses to decide
 * whether to serve a cached page or not.
 *
 * @param  array $list  Existing list passed by the cache plugin.
 * @return array
 */
function wccr_ignore_cookies( array $list ): array {
    return array_diff( $list, WCCR_IGNORED_COOKIES );
}

// ── Universal fallback: unset from $_COOKIE before any cache reads it ────────
add_action( 'plugins_loaded', 'wccr_unset_cookies_from_superglobal', 1 );

function wccr_unset_cookies_from_superglobal(): void {
    foreach ( WCCR_IGNORED_COOKIES as $name ) {
        unset( $_COOKIE[ $name ] );
    }
}
