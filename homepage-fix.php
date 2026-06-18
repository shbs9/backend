<?php
/**
 * SAFE Post 47 Fix (CLEAN VERSION)
 * Does NOT break admin UI
 */

add_action('admin_init', function () {

    if (!isset($_GET['post']) || (int) $_GET['post'] !== 47) {
        return;
    }

    // 1. ONLY disable WooCommerce cart fragments (safe)
    add_filter('woocommerce_cart_fragments_enabled', '__return_false');

    // 2. ONLY remove cart script (not everything!)
    add_action('admin_enqueue_scripts', function () {
        wp_dequeue_script('wc-cart-fragments');
    }, 9999);

});
