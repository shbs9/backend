<?php
/**
 * Plugin Name: Aggressive Home Page Cookie Removal
 * Description: Nukes all tracking cookies on the home page only.
 */

defined( 'ABSPATH' ) || exit;

// Run as early as possible — before WordPress sends anything
add_action( 'template_redirect', 'ahpcr_nuke_cookies', 1 );

function ahpcr_nuke_cookies() {

    // Home page only, non-logged-in only
    if ( ! is_front_page() && ! is_home() ) return;
    if ( is_user_logged_in() ) return;
    if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'POST' ) return;

    // Cookies to kill — add any others you spot
    $kill = [ '_sbp', '_fbp', '_ga', '_gid', '_gat' ];

    $host    = $_SERVER['HTTP_HOST'] ?? '';
    $domains = [
        '.' . $host,
        $host,
        'express.conves.io',
        '.' . 'express.conves.io',
    ];

    // 1. Unset from PHP superglobal so WP never reads them
    foreach ( $kill as $name ) {
        unset( $_COOKIE[ $name ] );
    }

    // 2. Remove ALL existing Vary and Set-Cookie headers PHP knows about
    header_remove( 'Set-Cookie' );
    header_remove( 'Vary' );
    header_remove( 'Expires' );
    header_remove( 'Pragma' );

    // 3. Re-add only Vary: Accept-Encoding
    header( 'Vary: Accept-Encoding', true );

    // 4. Blast expired Set-Cookie for every cookie × every domain combo
    foreach ( $kill as $name ) {
        foreach ( $domains as $domain ) {
            header(
                sprintf(
                    'Set-Cookie: %s=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/; domain=%s; secure; SameSite=Lax',
                    $name,
                    $domain
                ),
                false // false = append, not replace
            );
        }
    }

    // 5. Aggressive cache headers
    header( 'Cache-Control: public, max-age=3600, s-maxage=3600, must-revalidate', true );
    header( 'Pragma: public', true );
}

// Second pass at send_headers in case something re-adds cookies after template_redirect
add_action( 'send_headers', 'ahpcr_send_headers_pass', PHP_INT_MAX );

function ahpcr_send_headers_pass() {
    if ( ! is_front_page() && ! is_home() ) return;
    if ( is_user_logged_in() ) return;

    $kill = [ '_sbp', '_fbp', '_ga', '_gid', '_gat' ];
    $host = $_SERVER['HTTP_HOST'] ?? '';

    $domains = [
        '.' . $host,
        $host,
        'express.conves.io',
        '.' . 'express.conves.io',
    ];

    header_remove( 'Set-Cookie' );
    header_remove( 'Vary' );
    header_remove( 'Expires' );

    header( 'Vary: Accept-Encoding', true );
    header( 'Cache-Control: public, max-age=3600, s-maxage=3600, must-revalidate', true );

    foreach ( $kill as $name ) {
        foreach ( $domains as $domain ) {
            header(
                sprintf(
                    'Set-Cookie: %s=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/; domain=%s; secure; SameSite=Lax',
                    $name,
                    $domain
                ),
                false
            );
        }
    }
}

// Third pass: output buffer to catch anything added after headers were sent
add_action( 'init', 'ahpcr_start_buffer', 0 );

function ahpcr_start_buffer() {
    if ( is_user_logged_in() ) return;
    ob_start( 'ahpcr_ob_callback' );
}

function ahpcr_ob_callback( $buffer ) {
    // Only act on home page (ob fires before is_front_page() is reliable,
    // so we check REQUEST_URI instead)
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if ( $uri !== '/' && rtrim( $uri, '/' ) !== '' ) {
        return $buffer;
    }

    $kill = [ '_sbp', '_fbp', '_ga', '_gid', '_gat' ];

    // Scan and rebuild the header list, dropping tracking Set-Cookie lines
    foreach ( headers_list() as $header ) {
        if ( stripos( $header, 'set-cookie:' ) === 0 ) {
            foreach ( $kill as $name ) {
                if ( stripos( $header, "set-cookie: {$name}=" ) === 0 ) {
                    // This header is a tracking cookie — skip re-adding
                    // (header_remove removes ALL Set-Cookie at once, already done above)
                    break;
                }
            }
        }
    }

    return $buffer;
}
