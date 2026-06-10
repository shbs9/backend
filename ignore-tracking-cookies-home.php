<?php
/**
 * Plugin Name: Strip Edge Tracking Cookies
 * Description: Overwrites _sbp/_fbp with expired Set-Cookie headers so CDN stops seeing them.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'send_headers', 'stcc_kill_tracking_cookies', PHP_INT_MAX );

function stcc_kill_tracking_cookies() {
    if ( is_user_logged_in() || is_admin() ) return;
    if ( defined('REST_REQUEST') && REST_REQUEST ) return;
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) return;

    $host = $_SERVER['HTTP_HOST'] ?? '';

    // Overwrite with expired cookies — forces browser + CDN to drop them
    $cookies = [
        [ 'name' => '_sbp', 'domain' => '.' . $host ],
        [ 'name' => '_fbp', 'domain' => 'express.conves.io' ],
    ];

    foreach ( $cookies as $c ) {
        header(
            sprintf(
                'Set-Cookie: %s=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/; domain=%s; secure; SameSite=Lax',
                $c['name'],
                $c['domain']
            ),
            false // false = don't replace existing Set-Cookie headers, ADD this one
        );
    }

    // Tell CDN to cache publicly
    header( 'Cache-Control: public, max-age=3600, s-maxage=3600', true );
}
