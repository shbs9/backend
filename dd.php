<?php
/**
 * Plugin Name: Daily Salt Rotation (MU)
 * Description: Rotates WordPress salts daily for compliance with logging and backups.
 * Version: 1.1.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class Daily_Salt_Rotation {

    const CRON_HOOK = 'daily_salt_rotation_event';
    const LOG_TABLE_SUFFIX = 'salt_rotation_log';
    const BACKUP_OPTION_PREFIX = 'salt_rotation_backup_';
    const BACKUPS_TO_KEEP = 5;

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

    public static function init() {

        add_action('plugins_loaded', [__CLASS__, 'create_log_table_if_needed']);
        add_action('plugins_loaded', [__CLASS__, 'schedule_rotation']);

        add_action(self::CRON_HOOK, [__CLASS__, 'execute_rotation']);

        add_action('init', [__CLASS__, 'check_if_overdue']);
    }

    /**
     * Create log table
     */
    public static function create_log_table_if_needed() {

        global $wpdb;

        $table = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") == $table) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rotation_date DATETIME NOT NULL,
            status ENUM('success','failure') NOT NULL,
            wpcli_output TEXT,
            error_message TEXT,
            backup_option_id VARCHAR(100),
            execution_time FLOAT,
            triggered_by VARCHAR(50),
            INDEX(rotation_date),
            INDEX(status)
        ) $charset;";

        dbDelta($sql);
    }

    /**
     * Schedule cron
     */
    public static function schedule_rotation() {

        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        $timestamp = strtotime('tomorrow midnight');

        wp_schedule_event($timestamp, 'daily', self::CRON_HOOK);
    }

    /**
     * Cron fallback
     */
    public static function check_if_overdue() {

        if (!wp_doing_cron()) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        $last = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT rotation_date FROM $table WHERE status=%s ORDER BY rotation_date DESC LIMIT 1",
                'success'
            )
        );

        if (!$last || strtotime($last) < strtotime('-25 hours')) {
            self::execute_rotation('overdue-fallback');
        }
    }

    /**
     * Execute rotation
     */
    public static function execute_rotation($trigger = 'wp-cron') {

        if (defined('SALT_ROTATION_DISABLED') && SALT_ROTATION_DISABLED) {
            return;
        }

        $start = microtime(true);

        $backup = self::backup_current_salts();

        if (!$backup) {

            self::log_rotation_event(
                'failure',
                '',
                'Failed to backup salts',
                null,
                0,
                $trigger
            );

            return;
        }

        $result = self::execute_wpcli_command();

        $time = microtime(true) - $start;

        if ($result['success']) {

            self::log_rotation_event(
                'success',
                $result['output'],
                '',
                $backup,
                $time,
                $trigger
            );

            self::cleanup_old_backups();

        } else {

            self::log_rotation_event(
                'failure',
                $result['output'],
                $result['error'],
                $backup,
                $time,
                $trigger
            );
        }
    }

    /**
     * Backup salts
     */
    private static function backup_current_salts() {

        $config = dirname(ABSPATH) . '/wp-config.php';

        if (!file_exists($config)) {
            return false;
        }

        $content = file_get_contents($config);

        $backup = [];

        foreach (self::SALT_KEYS as $key) {

            $pattern = '/define\s*\(\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*,\s*[\'"](.+?)[\'"]\s*\)/';

            if (preg_match($pattern, $content, $m)) {
                $backup[$key] = $m[1];
            }
        }

        if (count($backup) < 8) {
            return false;
        }

        $timestamp = current_time('timestamp');

        $name = self::BACKUP_OPTION_PREFIX . $timestamp;

        add_option($name, [
            'salts' => $backup,
            'timestamp' => $timestamp,
            'date' => current_time('mysql')
        ], '', 'no');

        return $name;
    }

    /**
     * Cleanup backups
     */
    private static function cleanup_old_backups() {

        global $wpdb;

        $keep = defined('SALT_ROTATION_BACKUP_KEEP')
            ? SALT_ROTATION_BACKUP_KEEP
            : self::BACKUPS_TO_KEEP;

        $options = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM $wpdb->options
                 WHERE option_name LIKE %s
                 ORDER BY option_name DESC",
                $wpdb->esc_like(self::BACKUP_OPTION_PREFIX) . '%'
            )
        );

        if (count($options) > $keep) {

            $delete = array_slice($options, $keep);

            foreach ($delete as $opt) {
                delete_option($opt);
            }
        }
    }

    /**
     * Find WP CLI
     */
    private static function get_wpcli_path() {

        $paths = [
            '/usr/local/bin/wp-cli',
            '/usr/local/bin/wp',
            '/usr/bin/wp'
        ];

        foreach ($paths as $p) {

            if (file_exists($p) && is_executable($p)) {
                return $p;
            }
        }

        return false;
    }

    /**
     * Execute CLI command
     */
    private static function execute_wpcli_command() {

        if (!function_exists('shell_exec')) {

            return [
                'success' => false,
                'output' => '',
                'error' => 'shell_exec disabled'
            ];
        }

        $wpcli = self::get_wpcli_path();

        if (!$wpcli) {

            return [
                'success' => false,
                'output' => '',
                'error' => 'WP CLI not found'
            ];
        }

        $php = PHP_BINARY;

        $cmd = sprintf(
            '%s %s config shuffle-salts --path=%s 2>&1',
            escapeshellcmd($php),
            escapeshellcmd($wpcli),
            escapeshellarg(ABSPATH)
        );

        $output = shell_exec($cmd);

        if ($output === null) {

            return [
                'success' => false,
                'output' => '',
                'error' => 'command failed'
            ];
        }

        $success = stripos($output, 'Success') !== false;

        return [
            'success' => $success,
            'output' => trim($output),
            'error' => $success ? '' : 'WP CLI failure'
        ];
    }

    /**
     * Log rotation
     */
    private static function log_rotation_event($status, $output, $error, $backup, $time, $trigger) {

        global $wpdb;

        $table = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        $wpdb->insert($table, [
            'rotation_date' => current_time('mysql'),
            'status' => $status,
            'wpcli_output' => $output,
            'error_message' => $error,
            'backup_option_id' => $backup,
            'execution_time' => $time,
            'triggered_by' => $trigger
        ]);
    }

}

Daily_Salt_Rotation::init();
