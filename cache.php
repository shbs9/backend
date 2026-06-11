<?php
/**
 * Plugin Name: WC Cookie Cache Rules
 * Description: Removes cookies that prevent cache HITs and restores cacheable
 *              headers that WooCommerce/WordPress wrongly set to no-cache on
 *              the home page for guest visitors.
 * Version:     2.0.0
 *
 * Must-Use plugin — drop into /wp-content/mu-plugins/
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cookies to strip so they never cause a cache MISS or a Vary split.
 *
 *  _fbp  – Meta Pixel Browser ID
 *  _fbc  – Meta Pixel Click ID
 *  sbc   – Stape Conversion Tracking
 *  _sbp  – Stape Browser Pixel (the one your server is setting itself)
 *  wc_auth_business_type – WooCommerce custom auth / business-type session
 */
const WCCR_IGNORED_COOKIES = [
    '_fbp',
    '_fbc',
    'sbc',
    '_sbp',
    'wc_auth_business_type',
];

// ── 1. Strip cookies from superglobal ASAP ───────────────────────────────────
// Runs at priority 1 so WooCommerce and every other plugin see clean $_COOKIE.
add_action( 'plugins_loaded', 'wccr_unset_cookies', 1 );

function wccr_unset_cookies(): void {
    foreach ( WCCR_IGNORED_COOKIES as $name ) {
        unset( $_COOKIE[ $name ] );
    }
}

// ── 2. Restore cacheable headers on home page for guest visitors ─────────────
// WooCommerce calls wc_nocache_headers() which sets no-store/no-cache.
// We undo that for guests on the front page so the Atomic CDN can cache it.
add_action( 'send_headers', 'wccr_restore_cache_headers', 99 );

function wccr_restore_cache_headers(): void {
    // Only fix the home/front page.
    if ( ! is_front_page() && ! is_home() ) {
        return;
    }

    // Never touch headers for logged-in users or users with a real WC session.
    if ( wccr_visitor_has_real_session() ) {
        return;
    }

    // Override the no-cache headers WooCommerce set.
    header_remove( 'Pragma' );
    header_remove( 'Expires' );

    // Allow the CDN / reverse proxy to cache for 1 hour.
    // The browser itself revalidates every time (s-maxage is CDN-only).
    header( 'Cache-Control: public, max-age=0, s-maxage=3600, must-revalidate', true );

    // Stop Vary: Cookie from splitting the cache on every tracking cookie.
    // We keep Vary: Accept-Encoding which is safe.
    header( 'Vary: Accept-Encoding', true );
}

// ── 3. Tell WooCommerce the home page is safe to cache ──────────────────────
// WC checks this filter before forcing no-cache headers.
add_filter( 'woocommerce_cache_excluded_uris', 'wccr_exclude_home_from_wc_nocache' );

function wccr_exclude_home_from_wc_nocache( array $uris ): array {
    // Add the home page to WC's own exclusion list so it skips wc_nocache_headers().
    $uris[] = '^/$';
    return $uris;
}

// ── 4. WP Rocket / LiteSpeed / W3TC filter support ──────────────────────────
add_filter( 'rocket_cache_ignored_parameters', 'wccr_remove_from_vary_list' );
add_filter( 'litespeed_vary_cookies',          'wccr_remove_from_vary_list' );
add_filter( 'w3tc_reject_cookies',             'wccr_remove_from_vary_list' );

function wccr_remove_from_vary_list( array $list ): array {
    return array_diff( $list, WCCR_IGNORED_COOKIES );
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Returns true only when the visitor has a real WooCommerce session
 * (items in cart, logged-in user, checkout in progress, etc.).
 * In those cases we must NOT override the no-cache headers.
 */
function wccr_visitor_has_real_session(): bool {
    // Logged-in WordPress user.
    if ( is_user_logged_in() ) {
        return true;
    }

    // Active WooCommerce cart cookie.
    if ( isset( $_COOKIE['woocommerce_cart_hash'] ) && ! empty( $_COOKIE['woocommerce_cart_hash'] ) ) {
        return true;
    }

    // WooCommerce session cookie (prefixed with wp_woocommerce_session_).
    foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
        if ( str_starts_with( $cookie_name, 'wp_woocommerce_session_' ) ) {
            return true;
        }
    }

    return false;
}
