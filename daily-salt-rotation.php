<?php
/**
 * Plugin Name: Daily Salt Rotation (MU)
 * Description: Rotates WordPress salts daily at midnight via WP-Cron for compliance requirements
 * Version: 1.0.0
 * Author: Custom Development
 * Requires: WP-CLI installed and shell_exec() enabled
 *
 * ⚠️ CRITICAL WARNING:
 *
 * This plugin automatically rotates WordPress salts daily for compliance.
 * Salt rotation causes:
 * - All users logged out immediately
 * - All nonces (form tokens) invalidated
 * - POTENTIAL DATA LOSS in plugins that use salts for encryption
 *
 * Known affected plugin types:
 * - SSO/SAML authentication plugins
 * - Two-factor authentication (2FA) plugins
 * - Custom encryption implementations
 * - Some OAuth/API token storage plugins
 *
 * ⚠️ TEST THOROUGHLY IN STAGING before production deployment!
 * ⚠️ Audit all plugins for salt dependencies before deploying!
 *
 * Configuration Options (define in wp-config.php):
 * - define('WPCLI_PATH', '/custom/path/to/wp'); // Custom WP-CLI path
 * - define('SALT_ROTATION_DISABLED', false); // Emergency disable
 * - define('SALT_ROTATION_NOTIFY_FAILURES', true); // Email on failures
 * - define('SALT_ROTATION_BACKUP_KEEP', 5); // Number of backups to keep
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Daily Salt Rotation Class
 *
 * Handles automatic rotation of WordPress security salts/keys for compliance.
 * Provides backup, audit logging, and error handling capabilities.
 */
class Daily_Salt_Rotation {

    /**
     * Constants
     */
    const CRON_HOOK = 'daily_salt_rotation_event';
    const LOG_TABLE_SUFFIX = 'salt_rotation_log';
    const BACKUP_OPTION_PREFIX = 'salt_rotation_backup_';
    const BACKUPS_TO_KEEP = 5;
    const ADMIN_NOTICE_OPTION = 'salt_rotation_admin_notice_dismissed';

    /**
     * All 8 WordPress salt/key constants
     */
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

    /**
     * Initialize the plugin
     */
    public static function init() {
        // Create database table if not exists
        add_action('plugins_loaded', [__CLASS__, 'create_log_table_if_needed']);

        // Schedule WP-Cron event
        add_action('plugins_loaded', [__CLASS__, 'schedule_rotation']);

        // Hook for the actual rotation
        add_action(self::CRON_HOOK, [__CLASS__, 'execute_rotation']);

        // Check if rotation is overdue (fallback)
        add_action('init', [__CLASS__, 'check_if_overdue']);

        // Admin notices
        add_action('admin_notices', [__CLASS__, 'admin_notices']);

        // AJAX handler for dismissing admin notice
        add_action('wp_ajax_dismiss_salt_rotation_notice', [__CLASS__, 'dismiss_admin_notice']);
    }

    /**
     * Create the audit log table if it doesn't exist
     */
    public static function create_log_table_if_needed() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return;
        }

        // Create table
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rotation_date DATETIME NOT NULL,
            status ENUM('success', 'failure') NOT NULL,
            wpcli_output TEXT,
            error_message TEXT,
            backup_option_id VARCHAR(100),
            execution_time FLOAT,
            triggered_by VARCHAR(50),
            INDEX idx_rotation_date (rotation_date),
            INDEX idx_status (status)
        ) $charset_collate;";

        dbDelta($sql);

        // Log table creation
        error_log('[Salt Rotation] Audit log table created: ' . $table_name);
    }

    /**
     * Schedule the daily rotation event
     */
    public static function schedule_rotation() {
        // Check if already scheduled
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        // Schedule for tomorrow at midnight
        $timestamp = strtotime('tomorrow midnight');

        wp_schedule_event($timestamp, 'daily', self::CRON_HOOK);

        error_log('[Salt Rotation] WP-Cron event scheduled for: ' . date('Y-m-d H:i:s', $timestamp));
    }

    /**
     * Check if rotation is overdue (fallback for missed cron)
     */
    public static function check_if_overdue() {
        global $wpdb;

        // Only check once per request, and only on frontend/cron (not admin)
        if (is_admin() || defined('DOING_AJAX')) {
            return;
        }

        // Check if rotation has been disabled
        if (defined('SALT_ROTATION_DISABLED') && SALT_ROTATION_DISABLED === true) {
            return;
        }

        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        // Get last successful rotation
        $last_rotation = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT rotation_date FROM $table_name WHERE status = %s ORDER BY rotation_date DESC LIMIT 1",
                'success'
            )
        );

        // If no rotation ever, or last rotation was >25 hours ago, trigger now
        if (!$last_rotation || (strtotime($last_rotation) < strtotime('-25 hours'))) {
            error_log('[Salt Rotation] Overdue rotation detected, triggering now');
            self::execute_rotation('overdue-fallback');
        }
    }

    /**
     * Execute the salt rotation
     *
     * @param string $triggered_by How the rotation was triggered
     */
    public static function execute_rotation($triggered_by = 'wp-cron') {
        $start_time = microtime(true);

        error_log('[Salt Rotation] Starting rotation, triggered by: ' . $triggered_by);

        // Check if rotation has been disabled
        if (defined('SALT_ROTATION_DISABLED') && SALT_ROTATION_DISABLED === true) {
            error_log('[Salt Rotation] Rotation is disabled via SALT_ROTATION_DISABLED constant');
            return;
        }

        // Step 1: Backup current salts
        $backup_id = self::backup_current_salts();
        if (!$backup_id) {
            $error = 'Failed to backup current salts';
            error_log('[Salt Rotation] ERROR: ' . $error);
            self::log_rotation_event('failure', '', $error, null, 0, $triggered_by);
            self::send_failure_notification($error);
            return;
        }

        error_log('[Salt Rotation] Backup created: ' . $backup_id);

        // Step 2: Execute WP-CLI command
        $result = self::execute_wpcli_command();

        $execution_time = microtime(true) - $start_time;

        // Step 3: Log the result
        if ($result['success']) {
            error_log('[Salt Rotation] SUCCESS: Salts rotated successfully in ' . $execution_time . ' seconds');
            self::log_rotation_event(
                'success',
                $result['output'],
                '',
                $backup_id,
                $execution_time,
                $triggered_by
            );

            // Step 4: Cleanup old backups
            self::cleanup_old_backups();
        } else {
            error_log('[Salt Rotation] FAILURE: ' . $result['error']);
            self::log_rotation_event(
                'failure',
                $result['output'],
                $result['error'],
                $backup_id,
                $execution_time,
                $triggered_by
            );

            // Step 5: Send notification on failure
            self::send_failure_notification($result['error'], $result['output']);
        }
    }

    /**
     * Backup current salts from wp-config.php
     *
     * @return string|false Backup option name or false on failure
     */
    private static function backup_current_salts() {
        $config_file = ABSPATH . 'wp-config.php';

        if (!file_exists($config_file) || !is_readable($config_file)) {
            error_log('[Salt Rotation] Cannot read wp-config.php');
            return false;
        }

        $config_content = file_get_contents($config_file);
        if ($config_content === false) {
            return false;
        }

        $backup = [];

        // Extract all 8 salts/keys using regex
        foreach (self::SALT_KEYS as $key) {
            // Match define('KEY', 'value') or define("KEY", "value")
            $pattern = "/define\s*\(\s*['\"]" . preg_quote($key, '/') . "['\"]\s*,\s*['\"](.+?)['\"]\s*\)/s";

            if (preg_match($pattern, $config_content, $matches)) {
                $backup[$key] = $matches[1];
            } else {
                error_log('[Salt Rotation] WARNING: Could not extract ' . $key);
            }
        }

        // Verify we got all 8 keys
        if (count($backup) !== 8) {
            error_log('[Salt Rotation] WARNING: Only extracted ' . count($backup) . ' of 8 salts');
        }

        // Store backup in wp_options
        $timestamp = current_time('timestamp');
        $option_name = self::BACKUP_OPTION_PREFIX . $timestamp;

        $backup_data = [
            'salts' => $backup,
            'timestamp' => $timestamp,
            'date' => current_time('mysql'),
            'count' => count($backup)
        ];

        $added = add_option($option_name, $backup_data, '', 'no'); // autoload = no

        if (!$added) {
            error_log('[Salt Rotation] Failed to save backup to wp_options');
            return false;
        }

        return $option_name;
    }

    /**
     * Cleanup old backups, keep only the configured number
     */
    private static function cleanup_old_backups() {
        global $wpdb;

        // Get number to keep (default 5, or from constant)
        $keep_count = defined('SALT_ROTATION_BACKUP_KEEP')
            ? (int) SALT_ROTATION_BACKUP_KEEP
            : self::BACKUPS_TO_KEEP;

        // Get all backup options, sorted by name (which contains timestamp)
        $options = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s ORDER BY option_name DESC",
                $wpdb->esc_like(self::BACKUP_OPTION_PREFIX) . '%'
            )
        );

        // Delete old backups beyond the keep count
        if (count($options) > $keep_count) {
            $to_delete = array_slice($options, $keep_count);

            foreach ($to_delete as $option_name) {
                delete_option($option_name);
                error_log('[Salt Rotation] Deleted old backup: ' . $option_name);
            }
        }
    }

    /**
     * Detect WP-CLI path
     *
     * @return string|false Path to WP-CLI or false if not found
     */
    private static function get_wpcli_path() {
        // Priority 1: Check for defined constant
        if (defined('WPCLI_PATH') && file_exists(WPCLI_PATH) && is_executable(WPCLI_PATH)) {
            return WPCLI_PATH;
        }

        // Priority 2: Check common locations
        $common_paths = [
            '/usr/local/bin/wp',
            '/usr/bin/wp',
            '/opt/wp-cli/wp',
            ABSPATH . 'wp-cli.phar',
        ];

        foreach ($common_paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Priority 3: Try 'which wp' command
        $which = @shell_exec('which wp 2>/dev/null');
        if ($which && is_string($which)) {
            $which = trim($which);
            if ($which && file_exists($which) && is_executable($which)) {
                return $which;
            }
        }

        return false;
    }

    /**
     * Execute WP-CLI shuffle-salts command
     *
     * @return array ['success' => bool, 'output' => string, 'error' => string]
     */
    private static function execute_wpcli_command() {
        // Check if shell_exec is available
        if (!function_exists('shell_exec')) {
            return [
                'success' => false,
                'output' => '',
                'error' => 'shell_exec() function is not available (disabled in php.ini)'
            ];
        }

        // Get WP-CLI path
        $wpcli = self::get_wpcli_path();
        if (!$wpcli) {
            return [
                'success' => false,
                'output' => '',
                'error' => 'WP-CLI not found in system. Checked common locations and PATH.'
            ];
        }

        // Build command with proper escaping
        $wp_path = escapeshellarg(ABSPATH);
        $command = sprintf(
            '%s config shuffle-salts --path=%s 2>&1',
            escapeshellarg($wpcli),
            $wp_path
        );

        // Execute command
        $output = shell_exec($command);

        if ($output === null) {
            return [
                'success' => false,
                'output' => '',
                'error' => 'shell_exec() returned null - command may have failed or timed out'
            ];
        }

        // Parse output for success
        $success = (
            stripos($output, 'Success') !== false ||
            stripos($output, 'Shuffled the salt keys') !== false
        );

        return [
            'success' => $success,
            'output' => trim($output),
            'error' => $success ? '' : 'WP-CLI command failed. Output: ' . trim($output)
        ];
    }

    /**
     * Log rotation event to database
     *
     * @param string $status 'success' or 'failure'
     * @param string $output WP-CLI output
     * @param string $error Error message (if any)
     * @param string|null $backup_id Backup option name
     * @param float $execution_time Execution time in seconds
     * @param string $triggered_by How rotation was triggered
     */
    private static function log_rotation_event($status, $output, $error, $backup_id, $execution_time, $triggered_by = 'wp-cron') {
        global $wpdb;

        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        $wpdb->insert(
            $table_name,
            [
                'rotation_date' => current_time('mysql'),
                'status' => $status,
                'wpcli_output' => $output,
                'error_message' => $error,
                'backup_option_id' => $backup_id,
                'execution_time' => $execution_time,
                'triggered_by' => $triggered_by
            ],
            ['%s', '%s', '%s', '%s', '%s', '%f', '%s']
        );

        // Also log to PHP error_log for compliance
        error_log(sprintf(
            '[Salt Rotation] Event logged: Status=%s, Backup=%s, ExecutionTime=%.2fs, TriggeredBy=%s',
            $status,
            $backup_id ?: 'none',
            $execution_time,
            $triggered_by
        ));
    }

    /**
     * Send failure notification email to admin
     *
     * @param string $error Error message
     * @param string $details Additional details
     */
    private static function send_failure_notification($error, $details = '') {
        // Check if notifications are enabled
        if (defined('SALT_ROTATION_NOTIFY_FAILURES') && SALT_ROTATION_NOTIFY_FAILURES === false) {
            return;
        }

        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        $subject = sprintf('[%s] ⚠️ Salt Rotation Failed', $site_name);

        $message = sprintf(
            "Daily salt rotation failed on %s\n\n" .
            "⚠️ This is a COMPLIANCE-CRITICAL operation. Please investigate immediately.\n\n" .
            "Error Details:\n%s\n\n",
            $site_name,
            $error
        );

        if ($details) {
            $message .= sprintf(
                "Additional Information:\n%s\n\n",
                $details
            );
        }

        $message .= sprintf(
            "Site: %s\n" .
            "Time: %s\n\n" .
            "To troubleshoot:\n" .
            "1. SSH into the server\n" .
            "2. Verify WP-CLI is installed: wp --version\n" .
            "3. Check PHP error log for details\n" .
            "4. Manually test: wp config shuffle-salts\n\n" .
            "To temporarily disable rotation:\n" .
            "Add to wp-config.php: define('SALT_ROTATION_DISABLED', true);\n\n" .
            "For emergency recovery:\n" .
            "Run: wp option list | grep salt_rotation_backup\n" .
            "Then manually restore salts from the latest backup.\n",
            $site_url,
            current_time('mysql')
        );

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        wp_mail($admin_email, $subject, $message, $headers);

        error_log('[Salt Rotation] Failure notification sent to: ' . $admin_email);
    }

    /**
     * Display admin notices
     */
    public static function admin_notices() {
        global $wpdb;

        // Check if notice has been dismissed
        if (get_option(self::ADMIN_NOTICE_OPTION)) {
            return;
        }

        // Only show for admins
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get last rotation status
        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;
        $last_rotation = $wpdb->get_row(
            "SELECT rotation_date, status, error_message FROM $table_name ORDER BY rotation_date DESC LIMIT 1"
        );

        ?>
        <div class="notice notice-warning is-dismissible" id="salt-rotation-notice">
            <h3>⚠️ Daily Salt Rotation Active</h3>
            <p>
                <strong>Important:</strong> This site has automatic daily salt rotation enabled for compliance.
                This means:
            </p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li>All users will be logged out at midnight every night</li>
                <li>Some plugins that use salts for encryption may lose encrypted data</li>
                <li>Nonces (form tokens) are invalidated daily</li>
            </ul>

            <?php if ($last_rotation): ?>
                <p>
                    <strong>Last Rotation:</strong>
                    <?php echo esc_html($last_rotation->rotation_date); ?> -
                    <span style="color: <?php echo $last_rotation->status === 'success' ? 'green' : 'red'; ?>;">
                        <?php echo esc_html(strtoupper($last_rotation->status)); ?>
                    </span>
                    <?php if ($last_rotation->status === 'failure' && $last_rotation->error_message): ?>
                        <br>
                        <span style="color: red;">Error: <?php echo esc_html($last_rotation->error_message); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <p>
                <strong>To disable:</strong> Add <code>define('SALT_ROTATION_DISABLED', true);</code> to wp-config.php
            </p>

            <p>
                <strong>View logs:</strong>
                <code>wp db query "SELECT * FROM <?php echo esc_html($table_name); ?> ORDER BY rotation_date DESC LIMIT 10"</code>
            </p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#salt-rotation-notice').on('click', '.notice-dismiss', function() {
                $.post(ajaxurl, {
                    action: 'dismiss_salt_rotation_notice'
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for dismissing admin notice
     */
    public static function dismiss_admin_notice() {
        update_option(self::ADMIN_NOTICE_OPTION, true, false);
        wp_die();
    }
}

// Initialize the plugin
Daily_Salt_Rotation::init();
