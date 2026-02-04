<?php
/**
 * Plugin Name: Convesio Auto Login (Fixed)
 * Description: Secure auto-login from Convesio dashboard
 * Author: Team Convesio
 * Version: 2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if request is eligible
 */
function convesio_is_eligible_request() {

    if (
        (defined('WP_CLI') && WP_CLI) ||
        (defined('DOING_AJAX') && DOING_AJAX) ||
        (defined('DOING_CRON') && DOING_CRON) ||
        (defined('WP_INSTALLING') && WP_INSTALLING)
    ) {
        return false;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        return false;
    }

    if (stripos($_SERVER['REQUEST_URI'], 'convesiologin') === false) {
        return false;
    }

    return true;
}

/**
 * Init handler
 */
function convesio_init_login() {

    $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $parts = explode('/', $path);

    if (count($parts) < 3) {
        convesio_invalid();
    }

    // last two segments
    $endpoint  = $parts[count($parts) - 2];
    $publicKey = $parts[count($parts) - 1];

    convesio_handle_login($endpoint, $publicKey);
}

/**
 * Invalid request
 */
function convesio_invalid() {
    wp_safe_redirect(wp_login_url());
    exit;
}

/**
 * Handle login
 */
function convesio_handle_login($endpoint, $publicKey) {

    $option = get_option('convesio_login_' . $endpoint);

    if (!$option) {
        convesio_invalid();
    }

    $data = json_decode($option, true);

    if (!is_array($data)) {
        delete_option('convesio_login_' . $endpoint);
        convesio_invalid();
    }

    $now = time();

    if (
        empty($data['key']) ||
        empty($data['expiry']) ||
        empty($data['uid'])
    ) {
        delete_option('convesio_login_' . $endpoint);
        convesio_invalid();
    }

    if ($publicKey !== $data['key']) {
        convesio_invalid();
    }

    if ($now > (int)$data['expiry']) {
        delete_option('convesio_login_' . $endpoint);
        convesio_invalid();
    }

    $user = get_user_by('ID', (int)$data['uid']);

    if (!$user || !user_can($user, 'read')) {
        convesio_invalid();
    }

    // One-time login
    delete_option('convesio_login_' . $endpoint);

    // Login user
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true, is_ssl());
    do_action('wp_login', $user->user_login, $user);

    wp_safe_redirect(admin_url());
    exit;
}

/**
 * Hook early
 */
if (convesio_is_eligible_request()) {
    add_action('init', 'convesio_init_login', 1);
}
