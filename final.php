<?php
/**
 * MU Plugin: Emergency Script Load Stopper for Post 47
 * This runs BEFORE WP starts building script dependency tree
 */

add_action('plugins_loaded', function () {

    if (!is_admin()) {
        return;
    }

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;

    if ($post_id !== 47) {
        return;
    }

    /**
     * 🚨 CRITICAL: Stop WooCommerce + heavy systems early
     * before wp_scripts builds dependency graph
     */

    add_action('init', function () {

        // Stop WooCommerce initialization effects
        remove_action('wp_loaded', 'wc_cart_fragments');
        remove_action('wp_enqueue_scripts', 'woocommerce_frontend_scripts');

    }, 0);

}, 0);


/**
 * Hard stop WooCommerce cart fragments (safe + early)
 */
add_filter('woocommerce_cart_fragments_enabled', '__return_false', 1);


/**
 * Prevent emoji + embed early
 */
add_action('init', function () {

    if (!is_admin()) return;

    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');

    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');

}, 1);
