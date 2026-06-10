<?php
/**
 * Plugin Name: Strip Tracking Cookies for Cache
 * Description: Removes _sbp / _fbp cookies on non-logged-in, cacheable pages
 *              so the A8C / atomic CDN layer can actually cache responses.
 * Version:     1.0.0
 * Author:      Custom MU-Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * 1. Remove tracking cookies from the *incoming* request so WordPress/PHP
 *    never sees them as a reason to bypass cache.
 *
 * Runs as early as possible — before any plugin or theme touches $_COOKIE.
 */
add_action( 'init', 'stcc_strip_incoming_tracking_cookies', 0 );
function stcc_strip_incoming_tracking_cookies() {
    if ( is_user_logged_in() ) {
        return; // never touch cookies for logged-in users
    }

    $tracking = [ '_sbp', '_fbp' ];

    foreach ( $tracking as $name ) {
        if ( isset( $_COOKIE[ $name ] ) ) {
            unset( $_COOKIE[ $name ] );
        }
    }
}

/**
 * 2. Expire / unset those cookies in the *outgoing* response headers so the
 *    browser stops sending them back, breaking the cache-miss loop.
 *
 *    We also set correct Cache-Control headers so the CDN knows it CAN cache.
 */
add_action( 'send_headers', 'stcc_fix_response_headers', 99 );
function stcc_fix_response_headers() {
    if ( is_user_logged_in() || is_admin() ) {
        return;
    }

    // Only touch the front page / static pages — skip REST, feeds, search, etc.
    if ( ! stcc_is_cacheable_request() ) {
        return;
    }

    $tracking = [ '_sbp', '_fbp' ];
    $host     = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';

    foreach ( $tracking as $name ) {
        // Expire the cookie immediately in the browser
        setcookie(
            $name,
            '',
            [
                'expires'  => time() - YEAR_IN_SECONDS,
                'path'     => '/',
                'domain'   => '.' . $host,   // covers subdomain variants
                'secure'   => true,
                'httponly'  => false,
                'samesite' => 'Lax',
            ]
        );
        // Also kill the broader domain variant (_fbp uses express.conves.io)
        setcookie(
            $name,
            '',
            [
                'expires'  => time() - YEAR_IN_SECONDS,
                'path'     => '/',
                'secure'   => true,
                'httponly'  => false,
                'samesite' => 'Lax',
            ]
        );
    }

    // Tell the CDN this response is publicly cacheable
    // Adjust max-age to taste (3600 = 1 hour).
    if ( ! headers_sent() ) {
        header( 'Cache-Control: public, max-age=3600, s-maxage=3600', true );
    }
}

/**
 * 3. Remove Set-Cookie headers for tracking cookies right before output.
 *    This is the nuclear option that guarantees they never reach the CDN.
 *
 *    Uses output buffering to intercept PHP's header stack on hosts where
 *    header_remove() alone is unreliable.
 */
add_action( 'template_redirect', 'stcc_intercept_output', 0 );
function stcc_intercept_output() {
    if ( is_user_logged_in() || is_admin() || ! stcc_is_cacheable_request() ) {
        return;
    }
    ob_start( 'stcc_output_callback' );
}

function stcc_output_callback( $buffer ) {
    // Strip Set-Cookie headers for tracking names via PHP header list
    $tracking = [ '_sbp', '_fbp' ];
    foreach ( headers_list() as $header ) {
        foreach ( $tracking as $name ) {
            if ( stripos( $header, 'Set-Cookie: ' . $name . '=' ) === 0 ||
                 stripos( $header, 'set-cookie: ' . $name . '=' ) === 0 ) {
                header_remove( 'Set-Cookie' ); // removes ALL Set-Cookie headers once
                break 2;                        // then we re-add the ones we want below
            }
        }
    }
    return $buffer;
}

/**
 * Helper — decides whether the current request should be treated as cacheable.
 */
function stcc_is_cacheable_request() {
    // Skip REST API, feeds, search, previews
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return false;
    }
    if ( isset( $_GET['preview'] ) || isset( $_GET['s'] ) ) {
        return false;
    }
    // POST requests are never cacheable
    if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
        return false;
    }
    return true;
}
