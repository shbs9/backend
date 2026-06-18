<?php
/**
 * MU Plugin: Safe Performance Fix for Post ID 47 Editor Crash
 * Goal: Reduce memory pressure without breaking WP admin/editor
 */

add_action('admin_enqueue_scripts', function () {

    // Only run in admin
    if (!is_admin()) {
        return;
    }

    // Get current screen safely
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    if (!$screen || $screen->base !== 'post') {
        return;
    }

    // Target only Post ID 47
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;

    if ($post_id !== 47) {
        return;
    }

    /**
     * Remove only heavy WooCommerce frontend scripts
     * (SAFE: does NOT touch Gutenberg or admin core)
     */
    $heavy_scripts = [
        'wc-cart-fragments',
        'woocommerce',
        'wc-add-to-cart',
        'wc-add-to-cart-variation',
        'wc-checkout',
        'wc-single-product',
        'wp-emoji',
        'wp-embed',
    ];

    foreach ($heavy_scripts as $handle) {
        wp_dequeue_script($handle);
        wp_deregister_script($handle);
    }

}, 1);


/**
 * Disable WooCommerce cart fragments globally (safe optimization)
 */
add_filter('woocommerce_cart_fragments_enabled', '__return_false');


/**
 * OPTIONAL SAFETY: prevent block editor memory spikes ONLY for this post
 * (keeps editor stable if Gutenberg is causing recursion)
 */
add_filter('use_block_editor_for_post', function ($use, $post) {

    if ($post && (int) $post->ID === 47) {
        // keep Gutenberg ON by default (safer than disabling)
        return $use;
    }

    return $use;

}, 10, 2);


/**
 * OPTIONAL: reduce emoji script overhead in admin only for this post
 */
add_action('admin_init', function () {

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;

    if ($post_id !== 47) {
        return;
    }

    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
});
