<?php
/**
 * Plugin Name: Editor Performance & Memory Fix
 * Description: Targets the WP_HTML_Tag_Processor memory exhaustion seen when
 *              opening wp-admin/post.php?post=47&action=edit. Drop this file
 *              into wp-content/mu-plugins/ (create that folder if it doesn't
 *              exist). Must-Use plugins load automatically on every request —
 *              no need to "activate" it on the Plugins screen.
 * Version:     1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core fix.
 *
 * When the block editor opens a post, WordPress preloads that post's REST
 * API data (content.rendered) and/or auto-generates its excerpt — both of
 * which run the post content through every `the_content` filter, exactly
 * as if a visitor were viewing it on the front end. One of the filters WP
 * core always registers on `the_content` is wp_filter_content_tags(),
 * which scans every <img>/<iframe> tag using WP_HTML_Tag_Processor — the
 * exact class named in your fatal error — to add loading="lazy",
 * decoding, width/height, and srcset attributes.
 *
 * On a long or complex post, or one already straining memory for other
 * reasons (see the heavy front-end the_content filters your APM trace
 * showed on /product/ and /shop/ pages), this is a likely contributor to
 * the crash. We remove it ONLY for admin screens and REST API requests —
 * real front-end visitors still get lazy-loaded images exactly as before.
 *
 * This is a safe, scoped change: if wp_filter_content_tags isn't actually
 * hooked the way core normally hooks it (e.g. a plugin altered it), this
 * call simply does nothing — it won't throw an error either way.
 */
function bls_editor_perf_init() {
	// Raise the memory ceiling as a safety net while the underlying
	// cause gets sorted out. Respects whatever hard cap your host sets.
	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'admin' );
	}
	@ini_set( 'memory_limit', '1024M' );

	// Remove the core tag-processing filter for this request only.
	remove_filter( 'the_content', 'wp_filter_content_tags', 10 );
}
add_action( 'admin_init', 'bls_editor_perf_init' );
add_action( 'rest_api_init', 'bls_editor_perf_init' );

/**
 * Disable Heartbeat on the post editor screens.
 * Heartbeat polls admin-ajax every 15-60s for autosave/lock checks while a
 * post is open. Harmless alone, but adds concurrent load to a site that's
 * already running hot, as your trace showed across multiple page types.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		wp_deregister_script( 'heartbeat' );
	}
}, 1 );

/**
 * Cap stored revisions.
 * Doesn't delete existing ones, just stops the pile growing. Fewer
 * revisions means less data the editor has to account for when it loads
 * a post's history.
 */
add_filter( 'wp_revisions_to_keep', function () {
	return 5;
} );

/**
 * Lightweight diagnostic log.
 * If post 47 still crashes after this, check wp-content/edit-screen-
 * memory.log — it records the peak memory used right before the request
 * ended, which helps narrow things down even without re-pulling the full
 * APM trace.
 */
add_action( 'shutdown', function () {
	global $pagenow;
	$is_rest        = defined( 'REST_REQUEST' ) && REST_REQUEST;
	$is_edit_screen = isset( $pagenow ) && in_array( $pagenow, array( 'post.php', 'post-new.php' ), true );

	if ( ! $is_edit_screen && ! $is_rest ) {
		return;
	}

	$line = sprintf(
		"[%s] %s peak=%sMB post=%s\n",
		gmdate( 'Y-m-d H:i:s' ),
		isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
		round( memory_get_peak_usage( true ) / 1048576, 1 ),
		isset( $_GET['post'] ) ? absint( $_GET['post'] ) : ''
	);
	@file_put_contents( WP_CONTENT_DIR . '/edit-screen-memory.log', $line, FILE_APPEND | LOCK_EX );
} );
