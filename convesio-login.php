<?php
/**
 * Plugin Name: WP Auto Login - Final
 * Description: Secure auto-login via token
 * Version: 3.1
 */

add_action('init', function () {

    // Only run for convesiologin URLs
    if (strpos($_SERVER['REQUEST_URI'], '/convesiologin/') === false) {
        return;
    }

    // Parse URL: /convesiologin/{endpoint}/{key}
    $path  = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $parts = explode('/', $path);

    if (count($parts) < 3) {
        wp_safe_redirect('/wp-login.php');
        exit;
    }

    $endpoint  = $parts[1];
    $publicKey = $parts[2];

    // Fetch option from DB
    $option_name = 'convesio_login_' . $endpoint;
    $raw = get_option($option_name);

    if (!$raw) {
        wp_safe_redirect('/wp-login.php');
        exit;
    }

    // Decode JSON safely
    $keyData = json_decode(trim($raw), true);
    if (!is_array($keyData)) {
        wp_safe_redirect('/wp-login.php');
        exit;
    }

    // Validate key, user ID, expiry
    if (
        empty($keyData['key']) ||
        empty($keyData['uid']) ||
        empty($keyData['expiry']) ||
        $publicKey !== $keyData['key'] ||
        time() > intval($keyData['expiry'])
    ) {
        delete_option($option_name); // remove expired/invalid
        wp_safe_redirect('/wp-login.php');
        exit;
    }

    // Login the user
    wp_set_current_user($keyData['uid']);
    wp_set_auth_cookie($keyData['uid'], true);
    do_action('wp_login', get_userdata($keyData['uid'])->user_login);

    // Delete the token after successful login
    delete_option($option_name);

    wp_safe_redirect(admin_url());
    exit;

}, 0);
