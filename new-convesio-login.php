<?php
/**
 * Plugin Name: WP Auto Login
 * Description: Allows direct auto-login to wp-admin from convesio dashboard
 * Author: Team Convesio
 * Author URI: https://convesio.com
 * Version: 1.4
 */

function is_eligible_request()
{
    return ! (
        (defined('WP_CLI') && WP_CLI)
        || (defined('DOING_AJAX') && DOING_AJAX)
        || (defined('DOING_CRON') && DOING_CRON)
        || (defined('WP_INSTALLING') && WP_INSTALLING)
        || 'GET' != strtoupper(@$_SERVER['REQUEST_METHOD'])
        || count($_GET) > 0
        || is_admin()
        || stripos($_SERVER['REQUEST_URI'], 'convesiologin') === false
    );
}

function init_convesion_login_server()
{
    list($endpoint, $public) = Convesio_Login_Server::parseUri(@$_SERVER['REQUEST_URI']);
    if ($endpoint && $public) {
        Convesio_Login_Server::handle($endpoint, $public);
    }
}

if (is_eligible_request()) {
    add_action('plugins_loaded', 'init_convesion_login_server');
}

class Convesio_Login_Server
{
    // Max allowed login attempts before locking
    const MAX_ATTEMPTS = 3;
    // Lockout duration in seconds (15 minutes)
    const LOCKOUT_TIME = 900;
    // Key must be at least this long
    const MIN_KEY_LENGTH = 20;
    // Max age of login token in seconds (5 minutes)
    const MAX_TOKEN_AGE = 300;

    public static function parseUri($uri)
    {
        return array_slice(
            array_merge(['',''], explode('/', $uri)),
            -2
        );
    }

    public static function invalid()
    {
        // Rate limiting - track failed attempts by IP
        $ip = self::getClientIp();
        $attempts_key = 'convesio_attempts_' . md5($ip);
        $attempts = get_option($attempts_key, ['count' => 0, 'time' => 0]);

        // Check if IP is locked out
        if ($attempts['count'] >= self::MAX_ATTEMPTS) {
            $locked_until = $attempts['time'] + self::LOCKOUT_TIME;
            if (time() < $locked_until) {
                // IP is locked out - just die silently
                wp_die('Too many attempts. Please try again later.', 'Access Denied', ['response' => 429]);
            } else {
                // Lockout expired, reset
                delete_option($attempts_key);
            }
        }

        // Increment failed attempts
        update_option($attempts_key, [
            'count' => $attempts['count'] + 1,
            'time'  => time()
        ], false);

        // Log the failed attempt
        error_log(sprintf(
            'Convesio Login: Failed attempt from IP %s at %s',
            $ip,
            date('Y-m-d H:i:s')
        ));

        wp_safe_redirect('/wp-login.php');
        exit;
    }

    public static function login(WP_User $user)
    {
        // Clear any failed attempts for this IP on success
        $ip = self::getClientIp();
        $attempts_key = 'convesio_attempts_' . md5($ip);
        delete_option($attempts_key);

        // Log successful login
        error_log(sprintf(
            'Convesio Login: Successful login for user %s (ID: %d) from IP %s at %s',
            $user->user_login,
            $user->ID,
            $ip,
            date('Y-m-d H:i:s')
        ));

        wp_set_auth_cookie($user->ID, false); // false = not persistent/remember me
        do_action('wp_login', $user->user_login, $user);
        wp_redirect(admin_url());
        exit;
    }

    public static function handle($endpoint, $publicKey)
    {
        // Validate endpoint and key format (alphanumeric only)
        if (!preg_match('/^[a-zA-Z0-9]+$/', $endpoint) || 
            !preg_match('/^[a-zA-Z0-9]+$/', $publicKey)) {
            error_log('Convesio Login: Invalid endpoint or key format');
            return static::invalid();
        }

        // Enforce minimum key length
        if (strlen($publicKey) < self::MIN_KEY_LENGTH) {
            error_log('Convesio Login: Key too short');
            return static::invalid();
        }

        $loginKeysData = get_option('convesio_login_' . $endpoint);

        if (!$loginKeysData) {
            return static::invalid();
        }

        // Try direct JSON decode first
        $keyData = json_decode($loginKeysData, true);

        // Fallback if not valid JSON
        if (!is_array($keyData)) {
            $formatted = preg_replace('/(\w+):/', '"$1":', $loginKeysData);
            $formatted = preg_replace('/:(\w+)/', ':"$1"', $formatted);
            $keyData = json_decode($formatted, true);
        }

        // Validate decoded data structure
        if (!is_array($keyData) || 
            empty($keyData['key']) || 
            empty($keyData['expiry'])) {
            delete_option('convesio_login_' . $endpoint);
            return static::invalid();
        }

        $now = time();

        // Check token is not too old (max 5 minutes)
        if (($now - ($keyData['expiry'] - self::MAX_TOKEN_AGE)) > self::MAX_TOKEN_AGE) {
            delete_option('convesio_login_' . $endpoint);
            return static::invalid();
        }

        // Use hash_equals for timing-safe comparison (prevents timing attacks)
        if (!hash_equals($keyData['key'], $publicKey)) {
            delete_option('convesio_login_' . $endpoint);
            return static::invalid();
        }

        // Check expiry
        if ($now > $keyData['expiry']) {
            delete_option('convesio_login_' . $endpoint);
            return static::invalid();
        }

        // Delete option immediately (one-time use)
        delete_option('convesio_login_' . $endpoint);

        // Get first administrator
        $admin_user = get_users([
            'role'    => 'administrator',
            'number'  => 1,
            'orderby' => 'ID',
            'order'   => 'ASC'
        ]);

        if (empty($admin_user)) {
            return static::invalid();
        }

        return static::login(new WP_User($admin_user[0]->ID));
    }

    private static function getClientIp()
    {
        // Check for Convesio/proxy forwarded IP
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxy/Load balancer
            'HTTP_X_REAL_IP',            // Nginx
            'REMOTE_ADDR'                // Direct
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
