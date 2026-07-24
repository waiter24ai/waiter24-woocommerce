<?php
/**
 * Uninstall cleanup for Waiter24 - export WooCommerce.
 *
 * Runs when the plugin is DELETED from the Plugins screen (not on deactivate).
 * Without this file the `waiter24_export_settings` option survives a
 * delete-and-reinstall, which resurfaces an old Unique Key / Import Token in
 * the settings form and looks like the fields "filled themselves in".
 *
 * Note: the main plugin file is not loaded here, so constants like
 * W24_OPTION_KEY are unavailable — values are duplicated literally.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'waiter24_export_settings' );

wp_clear_scheduled_hook( 'waiter24_scheduled_export' );

// Local export copy (kept only for debugging) and its directory, if empty.
$w24_export_file = WP_CONTENT_DIR . '/uploads/waiter24/export.json';
if ( file_exists( $w24_export_file ) ) {
    unlink( $w24_export_file );
}

$w24_export_dir = WP_CONTENT_DIR . '/uploads/waiter24';
if ( is_dir( $w24_export_dir ) ) {
    $w24_leftovers = array_diff( (array) scandir( $w24_export_dir ), array( '.', '..' ) );
    if ( empty( $w24_leftovers ) ) {
        rmdir( $w24_export_dir );
    }
}
