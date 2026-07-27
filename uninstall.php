<?php
/**
 * Uninstall cleanup for Waiter24 AI Assistant for WooCommerce.
 *
 * Runs when the plugin is DELETED from the Plugins screen (not on deactivate).
 * Without this file the settings option survives a delete-and-reinstall, which
 * resurfaces an old Unique Key / Import Token in the settings form and looks
 * like the fields "filled themselves in".
 *
 * Note: the main plugin file is not loaded here, so constants like
 * W24_OPTION_KEY are unavailable — values are duplicated literally.
 *
 * @package Waiter24
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'waiter24_export_settings' );
delete_option( 'waiter24_export_last_run' );

wp_clear_scheduled_hook( 'waiter24_scheduled_export' );

// Versions up to 1.7.0 also kept a local copy of the exported catalog under
// wp-content/uploads/waiter24/. 1.8.0 stopped writing it; remove any leftover.
$w24_legacy_dir = WP_CONTENT_DIR . '/uploads/waiter24';

if ( is_dir( $w24_legacy_dir ) ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';

    if ( WP_Filesystem() ) {
        global $wp_filesystem;

        if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
            $wp_filesystem->delete( $w24_legacy_dir, true );
        }
    }
}
