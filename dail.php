<?php
/**
 * Plugin Name: Daily Salt Rotation (MU)
 * Description: Rotates WordPress salts daily using WP-CLI with backups and logging.
 * Version: 1.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class Daily_Salt_Rotation {

    const CRON_HOOK = 'daily_salt_rotation_event';
    const LOG_TABLE = 'salt_rotation_log';
    const BACKUP_PREFIX = 'salt_rotation_backup_';
    const KEEP_BACKUPS = 5;

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

        add_action('plugins_loaded',[__CLASS__,'create_log_table']);
        add_action('plugins_loaded',[__CLASS__,'schedule_event']);

        add_action(self::CRON_HOOK,[__CLASS__,'rotate_salts']);

        add_action('init',[__CLASS__,'cron_fallback']);
    }

    /**
     * Create audit table
     */
    public static function create_log_table(){

        global $wpdb;

        $table=$wpdb->prefix.self::LOG_TABLE;

        if($wpdb->get_var("SHOW TABLES LIKE '$table'")==$table){
            return;
        }

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $charset=$wpdb->get_charset_collate();

        $sql="CREATE TABLE $table(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rotation_date DATETIME NOT NULL,
        status VARCHAR(20),
        wpcli_output TEXT,
        error_message TEXT,
        backup_option_id VARCHAR(100),
        execution_time FLOAT,
        triggered_by VARCHAR(50),
        INDEX(rotation_date)
        ) $charset;";

        dbDelta($sql);
    }

    /**
     * Schedule cron
     */
    public static function schedule_event(){

        if(wp_next_scheduled(self::CRON_HOOK)){
            return;
        }

        wp_schedule_event(strtotime('tomorrow midnight'),'daily',self::CRON_HOOK);
    }

    /**
     * Cron fallback
     */
    public static function cron_fallback(){

        if(!wp_doing_cron()){
            return;
        }

        global $wpdb;

        $table=$wpdb->prefix.self::LOG_TABLE;

        $last=$wpdb->get_var("SELECT rotation_date FROM $table ORDER BY rotation_date DESC LIMIT 1");

        if(!$last || strtotime($last)<strtotime('-25 hours')){
            self::rotate_salts('overdue-fallback');
        }
    }

    /**
     * Backup salts using constants
     */
    private static function backup_salts(){

        $backup=[];

        foreach(self::SALT_KEYS as $key){

            if(defined($key)){
                $backup[$key]=constant($key);
            }

        }

        if(count($backup)<8){
            return false;
        }

        $timestamp=current_time('timestamp');

        $name=self::BACKUP_PREFIX.$timestamp;

        add_option($name,$backup,'','no');

        return $name;
    }

    /**
     * Cleanup backups
     */
    private static function cleanup_backups(){

        global $wpdb;

        $opts=$wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s ORDER BY option_name DESC",
                $wpdb->esc_like(self::BACKUP_PREFIX).'%'
            )
        );

        if(count($opts)>self::KEEP_BACKUPS){

            $delete=array_slice($opts,self::KEEP_BACKUPS);

            foreach($delete as $opt){
                delete_option($opt);
            }

        }

    }

    /**
     * Locate wp-cli
     */
    private static function wpcli_path(){

        $paths=[
            '/usr/local/bin/wp-cli',
            '/usr/local/bin/wp',
            '/usr/bin/wp'
        ];

        foreach($paths as $p){
            if(file_exists($p) && is_executable($p)){
                return $p;
            }
        }

        return false;
    }

    /**
     * Execute CLI
     */
    private static function run_cli(){

        if(!function_exists('shell_exec')){
            return ['success'=>false,'output'=>'','error'=>'shell_exec disabled'];
        }

        $cli=self::wpcli_path();

        if(!$cli){
            return ['success'=>false,'output'=>'','error'=>'wp-cli not found'];
        }

        $php=PHP_BINARY;

        $cmd=$php.' '.$cli.' config shuffle-salts --path='.escapeshellarg(ABSPATH).' 2>&1';

        $output=shell_exec($cmd);

        if($output===null){
            return ['success'=>false,'output'=>'','error'=>'command failed'];
        }

        $success=stripos($output,'Success')!==false;

        return [
            'success'=>$success,
            'output'=>trim($output),
            'error'=>$success?'':'wp-cli failed'
        ];
    }

    /**
     * Main rotation
     */
    public static function rotate_salts($trigger='wp-cron'){

        $start=microtime(true);

        $backup=self::backup_salts();

        if(!$backup){

            self::log('failure','','backup failed',null,0,$trigger);
            return;

        }

        $result=self::run_cli();

        $time=microtime(true)-$start;

        if($result['success']){

            self::log('success',$result['output'],'',$backup,$time,$trigger);
            self::cleanup_backups();

        }else{

            self::log('failure',$result['output'],$result['error'],$backup,$time,$trigger);

        }
    }

    /**
     * Log event
     */
    private static function log($status,$output,$error,$backup,$time,$trigger){

        global $wpdb;

        $table=$wpdb->prefix.self::LOG_TABLE;

        $wpdb->insert($table,[
            'rotation_date'=>current_time('mysql'),
            'status'=>$status,
            'wpcli_output'=>$output,
            'error_message'=>$error,
            'backup_option_id'=>$backup,
            'execution_time'=>$time,
            'triggered_by'=>$trigger
        ]);

    }

}

Daily_Salt_Rotation::init();
