<?php
/**
 * Plugin Name: WC Cookie Cache Rules
 * Description: Strips Set-Cookie response headers for tracking cookies and
 *              restores cacheable headers so the Atomic CDN gets a HIT.
 * Version:     3.0.0
 *
 * Must-Use plugin — drop into /wp-content/mu-plugins/
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cookies whose Set-Cookie response header must be removed so the CDN
 * is not forced into a MISS.
 *
 * Rule: if the server sends Set-Cookie for any of these, the Atomic CDN
 * (and most CDNs) will refuse to cache the response entirely.
 */
const WCCR_STRIP_SETCOOKIE = [
    '_sbp',   // Stape Browser Pixel  ← the current blocker
    '_fbp',   // Meta Pixel Browser ID
    '_fbc',   // Meta Pixel Click ID
    'sbc',    // Stape Conversion Tracking
];

/**
 * Cookies to remove from the incoming $_COOKIE superglobal so WordPress /
 * WooCommerce never sees them and doesn't vary output on them.
 */
const WCCR_IGNORED_COOKIES = [
    '_sbp',
    '_fbp',
    '_fbc',
    'sbc',
    'wc_auth_business_type',
];

// ── 1. Remove Set-Cookie headers for tracking cookies before output ──────────
// Must run as late as possible on send_headers so nothing re-adds them after.
add_action( 'send_headers', 'wccr_strip_setcookie_headers', 999 );
// Also hook shutdown as a safety net for anything that sets cookies late.
add_action( 'shutdown',     'wccr_strip_setcookie_headers', 0   );

function wccr_strip_setcookie_headers(): void {
    // Only act on the front page for guest visitors.
    if ( wccr_visitor_has_real_session() ) {
        return;
    }

    $existing = headers_list();
    // Remove ALL Set-Cookie headers first.
    header_remove( 'Set-Cookie' );

    // Re-add only the ones that are NOT in our strip list.
    foreach ( $existing as $header ) {
        if ( stripos( $header, 'Set-Cookie:' ) !== 0 ) {
            continue; // not a Set-Cookie header, skip
        }

        // Extract the cookie name (first segment before = in the value).
        $value      = trim( substr( $header, strlen( 'Set-Cookie:' ) ) );
        $cookie_name = strtok( $value, '=' );

        if ( ! in_array( trim( $cookie_name ), WCCR_STRIP_SETCOOKIE, true ) ) {
            // Safe to keep — re-add it.
            header( $header, false );
        }
        // Otherwise: silently dropped.
    }
}

// ── 2. Strip tracking cookies from incoming request superglobal ──────────────
add_action( 'plugins_loaded', 'wccr_unset_request_cookies', 1 );

function wccr_unset_request_cookies(): void {
    foreach ( WCCR_IGNORED_COOKIES as $name ) {
        unset( $_COOKIE[ $name ] );
    }
}

// ── 3. Restore cacheable response headers (undo WooCommerce no-cache) ────────
add_action( 'send_headers', 'wccr_restore_cache_headers', 99 );

function wccr_restore_cache_headers(): void {
    if ( ! is_front_page() && ! is_home() ) {
        return;
    }

    if ( wccr_visitor_has_real_session() ) {
        return;
    }

    header_remove( 'Pragma' );
    header_remove( 'Expires' );
    header( 'Cache-Control: public, max-age=0, s-maxage=3600, must-revalidate', true );
    header( 'Vary: Accept-Encoding', true );
}

// ── 4. Tell WooCommerce home page is safe to cache ───────────────────────────
add_filter( 'woocommerce_cache_excluded_uris', function( array $uris ): array {
    $uris[] = '^/$';
    return $uris;
} );

// ── 5. Cache plugin filter support (WP Rocket / LiteSpeed / W3TC) ────────────
add_filter( 'rocket_cache_ignored_parameters', 'wccr_remove_from_vary_list' );
add_filter( 'litespeed_vary_cookies',          'wccr_remove_from_vary_list' );
add_filter( 'w3tc_reject_cookies',             'wccr_remove_from_vary_list' );

function wccr_remove_from_vary_list( array $list ): array {
    return array_diff( $list, WCCR_IGNORED_COOKIES );
}

// ── Helper ───────────────────────────────────────────────────────────────────

function wccr_visitor_has_real_session(): bool {
    if ( is_user_logged_in() ) {
        return true;
    }
    if ( ! empty( $_COOKIE['woocommerce_cart_hash'] ) ) {
        return true;
    }
    foreach ( array_keys( $_COOKIE ) as $name ) {
        if ( str_starts_with( $name, 'wp_woocommerce_session_' ) ) {
            return true;
        }
    }
    return false;
}
