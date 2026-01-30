<?php
/**
 * Plugin Name: Daily Cache Purge (MU)
 * Description: Purges Batcache or WP Edge Cache daily at midnight via WP-Cron.
 * Version: 1.0.0
 * Author: Custom Development
 * Requires: WP-CLI installed and shell_exec() enabled
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Daily_Cache_Purge {

    const CRON_HOOK = 'daily_cache_purge_event';
    const LOG_TABLE_SUFFIX = 'cache_purge_log';
    const ADMIN_NOTICE_OPTION = 'cache_purge_admin_notice_dismissed';

    public static function init() {
        // Create database table if not exists
        add_action('plugins_loaded', [__CLASS__, 'create_log_table_if_needed']);

        // Schedule WP-Cron event
        add_action('plugins_loaded', [__CLASS__, 'schedule_purge']);

        // Hook for the actual purge
        add_action(self::CRON_HOOK, [__CLASS__, 'execute_purge']);

        // Check if purge is overdue (fallback)
        add_action('init', [__CLASS__, 'check_if_overdue']);

        // Admin notices
        add_action('admin_notices', [__CLASS__, 'admin_notices']);

        // AJAX handler for dismissing admin notice
        add_action('wp_ajax_dismiss_cache_purge_notice', [__CLASS__, 'dismiss_admin_notice']);
    }

    public static function create_log_table_if_needed() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            purge_date DATETIME NOT NULL,
            status ENUM('success', 'failure') NOT NULL,
            output TEXT,
            error_message TEXT,
            execution_time FLOAT,
            triggered_by VARCHAR(50),
            INDEX idx_purge_date (purge_date),
            INDEX idx_status (status)
        ) $charset_collate;";

        dbDelta($sql);
        error_log('[Cache Purge] Audit log table created: ' . $table_name);
    }

    public static function schedule_purge() {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }
        $timestamp = strtotime('tomorrow midnight');
        wp_schedule_event($timestamp, 'daily', self::CRON_HOOK);
        error_log('[Cache Purge] WP-Cron event scheduled for: ' . date('Y-m-d H:i:s', $timestamp));
    }

    public static function check_if_overdue() {
        global $wpdb;
        if (is_admin() || defined('DOING_AJAX')) return;

        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;
        $last_purge = $wpdb->get_var(
            "SELECT purge_date FROM $table_name WHERE status='success' ORDER BY purge_date DESC LIMIT 1"
        );

        if (!$last_purge || (strtotime($last_purge) < strtotime('-25 hours'))) {
            error_log('[Cache Purge] Overdue purge detected, triggering now');
            self::execute_purge('overdue-fallback');
        }
    }

    public static function execute_purge($triggered_by = 'wp-cron') {
        $start_time = microtime(true);
        error_log('[Cache Purge] Starting purge, triggered by: ' . $triggered_by);

        $result = self::purge_cache();

        $execution_time = microtime(true) - $start_time;

        if ($result['success']) {
            error_log('[Cache Purge] SUCCESS: Cache purged in ' . $execution_time . ' seconds');
            self::log_purge_event('success', $result['output'], '', $execution_time, $triggered_by);
        } else {
            error_log('[Cache Purge] FAILURE: ' . $result['error']);
            self::log_purge_event('failure', $result['output'], $result['error'], $execution_time, $triggered_by);
        }
    }

    private static function purge_cache() {
        // 1. Try Batcache
        if (isset($GLOBALS['batcache'])) {
            try {
                $GLOBALS['batcache']->flush();
                return ['success' => true, 'output' => 'Batcache flushed', 'error' => ''];
            } catch (Exception $e) {
                return ['success' => false, 'output' => '', 'error' => 'Batcache flush failed: ' . $e->getMessage()];
            }
        }

        // 2. Fallback to WP Edge Cache via WP-CLI
        $wpcli = self::get_wpcli_path();
        if (!$wpcli) {
            return ['success' => false, 'output' => '', 'error' => 'WP-CLI not found'];
        }

        $wp_path = escapeshellarg(ABSPATH);
        $command = sprintf('%s edge-cache purge --domain=%s 2>&1', escapeshellarg($wpcli), $wp_path);
        $output = shell_exec($command);

        if ($output === null) {
            return ['success' => false, 'output' => '', 'error' => 'WP-CLI command returned null'];
        }

        $success = stripos($output, 'Success') !== false || stripos($output, 'purged') !== false;

        return [
            'success' => $success,
            'output' => trim($output),
            'error' => $success ? '' : 'WP-CLI purge failed: ' . trim($output)
        ];
    }

    private static function get_wpcli_path() {
        if (defined('WPCLI_PATH') && file_exists(WPCLI_PATH) && is_executable(WPCLI_PATH)) {
            return WPCLI_PATH;
        }
        $common_paths = ['/usr/local/bin/wp','/usr/bin/wp','/opt/wp-cli/wp', ABSPATH . 'wp-cli.phar'];
        foreach ($common_paths as $path) {
            if (file_exists($path) && is_executable($path)) return $path;
        }
        $which = @shell_exec('which wp 2>/dev/null');
        if ($which && file_exists(trim($which)) && is_executable(trim($which))) return trim($which);
        return false;
    }

    private static function log_purge_event($status, $output, $error, $execution_time, $triggered_by = 'wp-cron') {
        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        $wpdb->insert(
            $table_name,
            [
                'purge_date' => current_time('mysql'),
                'status' => $status,
                'output' => $output,
                'error_message' => $error,
                'execution_time' => $execution_time,
                'triggered_by' => $triggered_by
            ],
            ['%s','%s','%s','%f','%s']
        );

        error_log(sprintf(
            '[Cache Purge] Event logged: Status=%s, ExecutionTime=%.2fs, TriggeredBy=%s',
            $status,
            $execution_time,
            $triggered_by
        ));
    }

    public static function admin_notices() {
        global $wpdb;
        if (get_option(self::ADMIN_NOTICE_OPTION)) return;
        if (!current_user_can('manage_options')) return;

        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;
        $last = $wpdb->get_row("SELECT purge_date, status, error_message FROM $table_name ORDER BY purge_date DESC LIMIT 1");
        ?>
        <div class="notice notice-warning is-dismissible" id="cache-purge-notice">
            <h3>⚠️ Daily Cache Purge Active</h3>
            <p>This site automatically purges cache daily at midnight.</p>
            <?php if ($last): ?>
                <p><strong>Last Purge:</strong>
                    <?php echo esc_html($last->purge_date); ?> -
                    <span style="color: <?php echo $last->status==='success'?'green':'red'; ?>;">
                        <?php echo esc_html(strtoupper($last->status)); ?>
                    </span>
                    <?php if ($last->status==='failure' && $last->error_message): ?>
                        <br><span style="color:red;">Error: <?php echo esc_html($last->error_message); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <script>
        jQuery(document).ready(function($){
            $('#cache-purge-notice').on('click','.notice-dismiss',function(){
                $.post(ajaxurl,{action:'dismiss_cache_purge_notice'});
            });
        });
        </script>
        <?php
    }

    public static function dismiss_admin_notice() {
        update_option(self::ADMIN_NOTICE_OPTION, true, false);
        wp_die();
    }
}

Daily_Cache_Purge::init();
