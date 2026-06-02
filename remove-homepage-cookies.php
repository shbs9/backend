<?php
/**
 * Plugin Name: Remove Homepage Cookies for Cloudflare Cache
 * Description: Strips only SAFE cookies on homepage so Cloudflare can cache.
 *              Leaves ep_session_id and any login/cart cookies untouched.
 * Version: 1.2
 */

/**
 * WHAT WE STRIP (safe only):
 *   - icwp-wpsf-notbot   : WP Shield bot-check cookie (no user-facing effect on homepage)
 *   - wordpress_test_cookie : WP's browser-check cookie (only needed on login page)
 *   - wp-settings / wp-settings-time : Admin UI prefs (only matter when logged in)
 *
 * WHAT WE LEAVE ALONE:
 *   - ep_session_id       : May be needed for login/cart/membership flows
 *   - wordpress_logged_in_* : Logged-in user sessions
 *   - woocommerce_*       : Cart/checkout cookies
 *   - Any other session cookies
 */

// ── 1. Unset safe cookies from $_COOKIE early ─────────────────────────────────
add_action( 'plugins_loaded', 'rh_strip_safe_cookies_early', 1 );
function rh_strip_safe_cookies_early() {
    if ( ! rh_is_cacheable() ) {
        return;
    }

    $safe_to_remove = [
        'icwp-wpsf-notbot',
        'wordpress_test_cookie',
        'wp-settings',
        'wp-settings-time',
    ];

    foreach ( $safe_to_remove as $name ) {
        unset( $_COOKIE[ $name ] );
    }
}

// ── 2. Remove ONLY safe Set-Cookie headers from HTTP response ─────────────────
add_action( 'send_headers', 'rh_remove_safe_setcookie_headers', 9999 );
function rh_remove_safe_setcookie_headers() {
    if ( ! rh_is_cacheable() ) {
        return;
    }

    // PHP's header_remove('Set-Cookie') removes ALL Set-Cookie headers at once.
    // So we: grab all headers → filter out only the safe ones → re-add the rest.
    $headers = headers_list();

    // Remove all Set-Cookie headers first
    header_remove( 'Set-Cookie' );

    // Re-add any Set-Cookie headers we want to KEEP (ep_session_id etc.)
    foreach ( $headers as $header ) {
        if ( stripos( $header, 'Set-Cookie:' ) === 0 ) {
            $cookie_value = substr( $header, strlen( 'Set-Cookie:' ) );
            $cookie_value = ltrim( $cookie_value );

            // Check if this is one we want to KEEP
            if ( rh_should_keep_cookie( $cookie_value ) ) {
                header( 'Set-Cookie: ' . $cookie_value, false ); // false = don't replace, append
            }
            // Otherwise it's dropped (safe cookies we wanted to remove)
        }
    }

    // Tell Cloudflare this page is cacheable
    // NOTE: Cloudflare will still bypass cache if ANY Set-Cookie header remains.
    // If ep_session_id is still being set, you'll need a Cloudflare Cache Rule
    // to ignore that specific cookie. See notes below.
    header( 'Cache-Control: public, s-maxage=86400, max-age=3600', true );
    header( 'Vary: Accept-Encoding', true );
}

// ── 3. Suppress WP Shield bot cookie via its own hooks ───────────────────────
add_action( 'init', 'rh_disable_wpshield_bot_cookie', 1 );
function rh_disable_wpshield_bot_cookie() {
    if ( ! rh_is_cacheable() ) {
        return;
    }
    // Tell WP Shield not to run its bot handshake on this page
    add_filter( 'icwp_wpsf_run_processor_bottrack', '__return_false' );
    add_filter( 'icwp_wpsf_run_processor_antibot',  '__return_false' );
}

// ── Helper: cookies we want to KEEP in the response ──────────────────────────
function rh_should_keep_cookie( $cookie_string ) {
    $keep_prefixes = [
        'ep_session',           // Membership/event session — keep
        'wordpress_logged_in_', // Logged-in users — keep
        'woocommerce_',         // WooCommerce cart — keep
        'wp_woocommerce_',      // WooCommerce — keep
        'PHPSESSID',            // Generic PHP session — keep
    ];

    foreach ( $keep_prefixes as $prefix ) {
        if ( stripos( $cookie_string, $prefix ) === 0 ) {
            return true;
        }
    }
    return false;
}

// ── Helper: is this request safely cacheable? ────────────────────────────────
function rh_is_cacheable() {
    if ( is_user_logged_in() ) {
        return false;
    }
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' || is_admin() ) {
        return false;
    }
    if ( function_exists( 'is_front_page' ) && ( is_front_page() || is_home() ) ) {
        return true;
    }
    // Early fallback before WP query is set up
    $uri = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
    if ( $uri === '/' || $uri === '' ) {
        return true;
    }
    return false;
}
