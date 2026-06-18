<?php
/**
 * Safe Post 47 Editor Fix (MU Plugin)
 * Prevents memory crash in WP HTML parser without disabling plugins
 */

add_action('init', function () {

    // Only target admin edit screen for post 47
    if (!is_admin()) {
        return;
    }

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;

    if ($post_id !== 47) {
        return;
    }

    /**
     * 1. Stop frontend scripts/styles injection in admin
     * (prevents WooCommerce, FunnelKit, tracking scripts from loading in editor)
     */
    add_action('admin_enqueue_scripts', function () {
        global $wp_scripts, $wp_styles;

        if (isset($wp_scripts)) {
            foreach ($wp_scripts->queue as $handle) {
                wp_dequeue_script($handle);
            }
        }

        if (isset($wp_styles)) {
            foreach ($wp_styles->queue as $handle) {
                wp_dequeue_style($handle);
            }
        }
    }, 9999);

    /**
     * 2. Disable WooCommerce cart fragments (prevents AJAX spam)
     */
    add_filter('woocommerce_cart_fragments_enabled', '__return_false');

    /**
     * 3. Reduce Gutenberg/HTML parser pressure
     * (prevents heavy block processing)
     */
    add_filter('use_block_editor_for_post', function ($use, $post) {
        if ($post && $post->ID == 47) {
            return false; // fallback to classic editor for this post only
        }
        return $use;
    }, 10, 2);

    /**
     * 4. Prevent WooFunnels/FunnelKit worker execution in admin edit
     */
    add_action('plugins_loaded', function () {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        // Soft guard (does NOT disable plugin, only blocks execution path)
        if (isset($_GET['post']) && $_GET['post'] == 47) {
            remove_all_actions('wp_footer');
            remove_all_actions('wp_head');
        }
    }, 1);

});
