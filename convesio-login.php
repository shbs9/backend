<?php
/**
 * Plugin Name: Convesio Auto Login (Final)
 * Description: Secure auto-login via one-time token
 * Version: 3.0
 */

add_action('init', function () {

    if (strpos($_SERVER['REQUEST_URI'], '/convesiologin/') === false) {
        return;
    }

    $path  = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $parts = explode('/', $path);

    // Expect: convesiologin/{endpoint}/{key}
    if (count($parts) < 3) {
        wp_safe_redirect('/wp-login.php');
        exit;
    }

    $endpoint  = $parts[1];
    $publicKey = $parts[2];

    $option_name = 'convesio_login_' . $endpoint;
    $data = get_option($option_name);

    if (!$data) {
        wp_safe_redirect('/wp-login.php');
        exit;
    }

    // Accept JSON or array
    $keyData = is_array($data) ? $data : json_decode(trim($data), true);
    if (!$keyData) {
        wp_safe_redirect('/wp-login.php');
        exit;
    }

    // Validate key + expiry
    if (
        empty($keyData['uid']) ||
        empty($keyData['key']) ||
        empty($keyData['expiry']) ||
        $publicKey !== $keyData['key'] ||
        time() > intval($keyData['expiry'])
    ) {
        wp_safe_redirect('/wp-login.php');
        exit;
    }

    $user = get_user_by('ID', intval($keyData['uid']));
    if (!$user) {
        wp_safe_redirect('/wp-login.php');
        exit;
    }

    // LOGIN
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);

    // 🔥 delete token AFTER successful login
    delete_option($option_name);

    wp_redirect(admin_url());
    exit;

}, 0);
