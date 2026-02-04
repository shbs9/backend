<?php
/**
 * Plugin Name: WP Auto Login
 * Description: Allows direct auto-login to wp-admin from Convesio dashboard
 * Author: Team Convesio
 * Author URI: https://convesio.com
 * Version: 1.4
 */

function is_eligible_request() {
    return ! (
        (defined('WP_CLI') && WP_CLI)                  // Ignore CLI
        || (defined('DOING_AJAX') && DOING_AJAX)       // Ignore AJAX
        || (defined('DOING_CRON') && DOING_CRON)       // Ignore CRON
        || (defined('WP_INSTALLING') && WP_INSTALLING) // Ignore installing
        || 'GET' != strtoupper(@$_SERVER['REQUEST_METHOD']) // Only GET
        || is_admin()                                  // Ignore admin requests
        || stripos($_SERVER['REQUEST_URI'], 'convesiologin') === false // Must contain endpoint
    );
}

function init_convesio_login_server() {
    list($endpoint, $publicKey) = Convesio_Login_Server::parseUri(@$_SERVER['REQUEST_URI']);

    // Debug log (uncomment to check)
    // error_log("Auto-login triggered | Endpoint: $endpoint | Key: $publicKey");

    if ($endpoint && $publicKey) {
        Convesio_Login_Server::handle($endpoint, $publicKey);
    }
}

// Hook early
if (is_eligible_request()) {
    add_action('plugins_loaded', 'init_convesio_login_server', 0);
}

class Convesio_Login_Server {

    public static function parseUri($uri) {
        return array_slice(array_merge(['',''], explode('/', $uri)), -2);
    }

    public static function invalid() {
        wp_safe_redirect('/wp-login.php');
        exit;
    }

    public static function login(WP_User $user) {
        // Force correct cookie domain and HTTPS
        $secure = is_ssl(); 
        $domain = parse_url(site_url(), PHP_URL_HOST); 
        $path = '/';

        wp_set_auth_cookie($user->ID, true, $secure, $domain, $path);

        do_action('wp_login', $user->user_login, $user);

        $redirect_to = apply_filters('login_redirect', admin_url(), '', $user);
        wp_safe_redirect($redirect_to);
        exit;
    }

    public static function handle($endpoint, $publicKey) {
        $loginKeysData = get_option('convesio_login_' . $endpoint);
        if (!$loginKeysData) return static::invalid();

        $keyData = json_decode($loginKeysData, true);
        if (!$keyData || !isset($keyData['key'], $keyData['uid'], $keyData['expiry'])) {
            return static::invalid();
        }

        $now = time();
        if ($publicKey !== $keyData['key'] || $now > $keyData['expiry']) {
            delete_option('convesio_login_' . $endpoint);
            return static::invalid();
        }

        delete_option('convesio_login_' . $endpoint);

        $user = get_user_by('ID', $keyData['uid']);
        if (!$user) return static::invalid();

        return static::login($user);
    }
}
