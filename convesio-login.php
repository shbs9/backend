<?php
/**
 * Plugin Name: WP Auto Login
 * Description: Allows direct auto-login to wp-admin from Convesio dashboard
 * Version: 2.0
 */

add_action('init', function () {

    if (strpos($_SERVER['REQUEST_URI'], '/convesiologin/') === false) {
        return;
    }

    list($endpoint, $publicKey) = Convesio_Login_Server::parseUri($_SERVER['REQUEST_URI']);

    if (!$endpoint || !$publicKey) {
        wp_safe_redirect('/wp-login.php');
        exit;
    }

    Convesio_Login_Server::handle($endpoint, $publicKey);

}, 0);

class Convesio_Login_Server {

    public static function parseUri($uri) {
        $path = trim(parse_url($uri, PHP_URL_PATH), '/');
        $parts = explode('/', $path);

        // convesiologin/{endpoint}/{key}
        if (count($parts) < 3) {
            return [null, null];
        }

        return [$parts[1], $parts[2]];
    }

    public static function handle($endpoint, $publicKey) {

        $data = get_option('convesio_login_' . $endpoint);
        if (!$data) self::invalid();

        $keyData = json_decode($data, true);
        if (!$keyData) self::invalid();

        if (
            $publicKey !== $keyData['key'] ||
            time() > $keyData['expiry']
        ) {
            delete_option('convesio_login_' . $endpoint);
            self::invalid();
        }

        delete_option('convesio_login_' . $endpoint);

        $user = get_user_by('ID', $keyData['uid']);
        if (!$user) self::invalid();

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);

        wp_redirect(admin_url());
        exit;
    }

    public static function invalid() {
        wp_safe_redirect('/wp-login.php');
        exit;
    }
}
