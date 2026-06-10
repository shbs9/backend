<?php
/**
 * Plugin Name: Strip Edge Tracking Cookies
 * Description: Removes Vary:Cookie and kills _sbp/_fbp so Atomic CDN can cache.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'send_headers', 'stcc_kill_tracking_cookies', PHP_INT_MAX );

function stcc_kill_tracking_cookies() {
    if ( is_user_logged_in() || is_admin() ) return;
    if ( defined('REST_REQUEST') && REST_REQUEST ) return;
    if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'POST' ) return;

    $host = $_SERVER['HTTP_HOST'] ?? '';

    // 1. Remove Vary: Cookie — this alone prevents CDN caching
    header_remove( 'Vary' );
    header( 'Vary: Accept-Encoding', true ); // keep only encoding vary

    // 2. Expire the tracking cookies so browser stops sending them
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
            false
        );
    }

    // 3. Tell CDN to cache publicly
    header( 'Cache-Control: public, max-age=3600, s-maxage=3600', true );

    // 4. Remove the WordPress "never cache" expires header
    header_remove( 'Expires' );
}
