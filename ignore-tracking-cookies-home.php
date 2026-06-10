<?php
/**
 * Plugin Name: Ignore Tracking Cookies on Homepage
 * Description: Allows cache HIT on homepage by ignoring tracking cookies.
 */

if ( is_admin() ) {
    return;
}

// Only homepage check
function is_homepage_request() {
    $uri = $_SERVER['REQUEST_URI'];
    return ($uri === '/' || $uri === '');
}

// Cookies to ignore
function get_ignored_cookies() {
    return [
        '_fbp',
        '_sbp',
    ];
}

// Remove cookies early
function ignore_tracking_cookies_homepage() {

    if ( ! is_homepage_request() ) {
        return;
    }

    if ( empty($_COOKIE) ) {
        return;
    }

    $ignored = get_ignored_cookies();

    foreach ($_COOKIE as $name => $value) {
        foreach ($ignored as $cookie) {

            if (strpos($name, $cookie) !== false) {

                // Remove from PHP
                unset($_COOKIE[$name]);

                // Remove from header string (important for cache layers)
                if (isset($_SERVER['HTTP_COOKIE'])) {
                    $_SERVER['HTTP_COOKIE'] = preg_replace(
                        '/\b' . preg_quote($name, '/') . '=[^;]+;?\s*/',
                        '',
                        $_SERVER['HTTP_COOKIE']
                    );
                }
            }
        }
    }
}

add_action('init', 'ignore_tracking_cookies_homepage', 0);
