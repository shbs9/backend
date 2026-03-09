<?php
/*
Plugin Name: Daily Salt Rotation
Description: Rotates WordPress salts daily with logging.
*/

if (!defined('ABSPATH')) {
    exit;
}

class Daily_Salt_Rotation {

    const SALT_KEYS = [
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT'
    ];

    public function __construct() {

        add_action('init', [$this, 'schedule_event']);
        add_action('daily_salt_rotation_event', [$this, 'rotate_salts']);

    }

    public function schedule_event() {

        if (!wp_next_scheduled('daily_salt_rotation_event')) {
            wp_schedule_event(time(), 'daily', 'daily_salt_rotation_event');
        }

    }

    private function get_config_path() {

        $path = dirname(ABSPATH) . '/wp-config.php';

        if (file_exists($path)) {
            return $path;
        }

        return ABSPATH . 'wp-config.php';
    }

    private function backup_salts($config_path) {

        $content = file_get_contents($config_path);

        $backup = [];

        foreach (self::SALT_KEYS as $key) {

            $pattern = '/define\s*\(\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*,\s*[\'"](.+?)[\'"]\s*\)/';

            if (preg_match($pattern, $content, $m)) {
                $backup[$key] = $m[1];
            }

        }

        if (count($backup) !== 8) {
            return false;
        }

        $option = 'salt_rotation_backup_' . time();

        add_option($option, $backup);

        return $option;

    }

    private function generate_salts() {

        $salts = [];

        foreach (self::SALT_KEYS as $key) {

            $salts[$key] = wp_generate_password(64, true, true);

        }

        return $salts;

    }

    private function update_config($config_path, $salts) {

        $content = file_get_contents($config_path);

        foreach ($salts as $key => $value) {

            $pattern = '/define\s*\(\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*,\s*[\'"].+?[\'"]\s*\)/';

            $replacement = "define('" . $key . "', '" . $value . "')";

            $content = preg_replace($pattern, $replacement, $content);

        }

        file_put_contents($config_path, $content);

    }

    private function log_result($status, $error = '', $backup_option = null) {

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'salt_rotation_log',
            [
                'rotation_date' => current_time('mysql'),
                'status' => $status,
                'error_message' => $error,
                'backup_option_id' => $backup_option,
                'execution_time' => 0,
                'triggered_by' => 'wp-cron'
            ]
        );

    }

    public function rotate_salts() {

        $config = $this->get_config_path();

        if (!file_exists($config)) {

            $this->log_result('failure', 'wp-config.php not found');

            return;

        }

        $backup = $this->backup_salts($config);

        if (!$backup) {

            $this->log_result('failure', 'Failed to backup salts');

            return;

        }

        $salts = $this->generate_salts();

        $this->update_config($config, $salts);

        $this->log_result('success', '', $backup);

    }

}

new Daily_Salt_Rotation();
