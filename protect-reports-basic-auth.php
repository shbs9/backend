<?php
/**
 * Plugin Name: Protect Reports – HTTP Basic Auth
 * Description: Adds HTTP Basic Authentication to /reports/latest.html (and optionally the whole /reports/ directory).
 * Version:     1.0.0
 * Author:      Your Name
 *
 * Place this file in: wp-content/mu-plugins/protect-reports-basic-auth.php
 */

defined( 'ABSPATH' ) || exit;

/**
 * -----------------------------------------------------------------------
 * CONFIGURATION — edit these values before deploying
 * -----------------------------------------------------------------------
 */
define( 'REPORT_AUTH_USERNAME', 'support-team' );          // change me
define( 'REPORT_AUTH_PASSWORD', 'Convesio!Rocks' ); // change me

/**
 * Protected path prefix.
 * Everything under /reports/ will be gated, including latest.html.
 * Change to '/reports/latest.html' to protect only that one file.
 */
define( 'REPORT_AUTH_PATH_PREFIX', '/reports/' );
// -----------------------------------------------------------------------

add_action( 'init', 'report_auth_check', 1 );

/**
 * Intercept the request early and demand Basic Auth credentials
 * if the request URI matches the protected path.
 */
function report_auth_check() {

    $request_uri = isset( $_SERVER['REQUEST_URI'] )
        ? strtok( $_SERVER['REQUEST_URI'], '?' ) // strip query string
        : '';

    // Bail out if this request is not for the protected path.
    if ( strpos( $request_uri, REPORT_AUTH_PATH_PREFIX ) !== 0 ) {
        return;
    }

    $provided_user = isset( $_SERVER['PHP_AUTH_USER'] ) ? $_SERVER['PHP_AUTH_USER'] : '';
    $provided_pass = isset( $_SERVER['PHP_AUTH_PW'] )   ? $_SERVER['PHP_AUTH_PW']   : '';

    $user_ok = hash_equals( REPORT_AUTH_USERNAME, $provided_user );
    $pass_ok = hash_equals( REPORT_AUTH_PASSWORD, $provided_pass );

    if ( ! $user_ok || ! $pass_ok ) {
        report_auth_send_401();
    }
}

/**
 * Send a 401 Unauthorized response and stop execution.
 */
function report_auth_send_401() {
    if ( ! headers_sent() ) {
        header( 'WWW-Authenticate: Basic realm="Reports – Restricted Area"' );
        header( 'HTTP/1.1 401 Unauthorized' );
    }
    echo '<!DOCTYPE html><html><head><title>401 Unauthorized</title></head>';
    echo '<body><h1>401 Unauthorized</h1>';
    echo '<p>Valid credentials are required to access this page.</p></body></html>';
    exit;
}
