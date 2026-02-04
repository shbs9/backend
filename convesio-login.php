<?php
/**
 * Plugin Name: WP Auto Login - Final
 * Description: Secure one-time auto-login links
 * Version: 4.1
 */

class WP_Auto_Login_Final {

    public static function init() {
        add_action('init', [__CLASS__, 'handle_auto_login'], 0);
    }

    public static function handle_auto_login() {
        $uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

        if (strpos($uri, 'convesiologin/') !== 0) return;

        $parts = explode('/', $uri);
        if (count($parts) < 3) {
            wp_safe_redirect('/wp-login.php');
            exit;
        }

        $endpoint  = $parts[1];
        $publicKey = $parts[2];

        $option_name = 'convesio_login_' . $endpoint;
        $raw = get_option($option_name);
        if (!$raw) {
            wp_safe_redirect('/wp-login.php');
            exit;
        }

        $keyData = json_decode($raw, true);
        if (!is_array($keyData) || empty($keyData['key']) || empty($keyData['uid']) || empty($keyData['expiry'])) {
            wp_safe_redirect('/wp-login.php');
            exit;
        }

        if ($publicKey !== $keyData['key'] || time() > intval($keyData['expiry'])) {
            delete_option($option_name);
            wp_safe_redirect('/wp-login.php');
            exit;
        }

        wp_set_current_user($keyData['uid']);
        wp_set_auth_cookie($keyData['uid'], true);
        do_action('wp_login', get_userdata($keyData['uid'])->user_login);

        delete_option($option_name);

        wp_safe_redirect(admin_url());
        exit;
    }

    // Generate working URL
    public static function generate_url($uid = 1, $minutes = 5) {
        $endpoint = bin2hex(random_bytes(8));
        $key = bin2hex(random_bytes(16));
        $expiry = time() + ($minutes * 60);

        update_option(
            'convesio_login_' . $endpoint,
            json_encode([
                'uid' => $uid,
                'key' => $key,
                'expiry' => $expiry
            ]),
            false
        );

        return site_url("/convesiologin/{$endpoint}/{$key}");
    }
}

WP_Auto_Login_Final::init();
