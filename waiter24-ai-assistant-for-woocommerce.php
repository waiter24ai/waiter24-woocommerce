<?php
/**
 * Plugin Name:          Waiter24 AI Assistant for WooCommerce
 * Plugin URI:           https://waiter24.ai/
 * Description:          Syncs your WooCommerce catalog to your Waiter24 account and adds the Waiter24 AI chat assistant to the storefront, so shoppers can ask questions and add products to the real WooCommerce cart from inside the chat.
 * Version:              1.13.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Waiter24
 * Author URI:           https://waiter24.ai/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          waiter24-ai-assistant-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 7.0
 * WC tested up to:      10.9
 *
 * Waiter24 AI Assistant for WooCommerce is free software: you can redistribute
 * it and/or modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 2 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 *
 * @package Waiter24
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =============================================
 *  CONSTANTS
 * =============================================
 */
define( 'W24_EXPORT_VERSION', '1.13.0' );
define( 'W24_CRON_HOOK', 'waiter24_scheduled_export' );
define( 'W24_CHUNK_HOOK', 'waiter24_export_chunk' ); // One background slice of a running export.
define( 'W24_OPTION_KEY', 'waiter24_export_settings' );
define( 'W24_LAST_RUN_KEY', 'waiter24_export_last_run' );
define( 'W24_PROGRESS_KEY', 'waiter24_export_progress' );
define( 'W24_QUEUE_KEY', 'waiter24_export_queue' ); // Product ids pinned when an export starts.
// An export whose slices stopped arriving for this long is considered dead, so
// Export Now works again after a crashed queue runner.
define( 'W24_EXPORT_STALL_SECONDS', 600 );
// How long the settings page waits for the background queue before pushing the
// next slice itself. Long enough that a working queue is never raced, short
// enough that a store whose background tasks never fire still exports.
define( 'W24_EXPORT_HANDOFF_SECONDS', 15 );
define( 'W24_IMPORT_URL', 'https://waiter24.ai/api/integrations/menu' );
define( 'W24_WIDGET_URL', 'https://waiter24.ai/widget.js' );
define( 'W24_DEMO_PARAM', 'waiter24_demo' ); // GET param that reveals the widget in demo mode.
define( 'W24_SCRIPT_HANDLE', 'waiter24-widget' );

/**
 * =============================================
 *  LOAD TEXT DOMAIN
 * =============================================
 */
add_action( 'init', 'w24_load_textdomain' );

function w24_load_textdomain() {
    load_plugin_textdomain(
        'waiter24-ai-assistant-for-woocommerce',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}

/**
 * =============================================
 *  WOOCOMMERCE FEATURE COMPATIBILITY
 * =============================================
 * The plugin never reads or writes order storage directly (it only appends
 * line-item meta through WooCommerce's own CRUD), so it is HPOS-safe.
 */
add_action( 'before_woocommerce_init', 'w24_declare_wc_compatibility' );

function w24_declare_wc_compatibility() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
}

/**
 * =============================================
 *  DEFAULT SETTINGS
 * =============================================
 */
function w24_get_defaults() {
    return array(
        'unique_key'        => '', // Public widget key (data-key on the chat script).
        'import_token'      => '', // Secret token authenticating the menu push.
        // 'off' means the catalog is only ever sent when the owner asks for it.
        // A fresh install must not start pushing a catalog nobody asked it to
        // push, so automatic sync is opt-in. Stores set up before 1.12.0 already
        // have an explicit period saved ('daily' by default), so this default
        // never reaches them and their schedule keeps running untouched.
        'export_period'     => 'off',
        'enable_widget'     => 0,
        'demo_mode'         => 0, // When on, the widget only loads on URLs carrying the demo param.
        'simple_stock_mode' => 1, // Enabled by default.
        // 'auto' follows Polylang/WPML's own default-language setting; 'all'
        // turns the filter off; anything else is one of that plugin's language
        // codes. Ignored entirely on a store without a multilingual plugin.
        'export_language'   => 'auto',
    );
}

/**
 * Helper: merge saved settings with defaults.
 */
function w24_get_settings() {
    $defaults = w24_get_defaults();
    $saved    = get_option( W24_OPTION_KEY, array() );

    if ( ! is_array( $saved ) ) {
        $saved = array();
    }

    return wp_parse_args( $saved, $defaults );
}

/**
 * =============================================
 *  ACTIVATION / DEACTIVATION
 * =============================================
 */
register_activation_hook( __FILE__, 'w24_activate' );
register_deactivation_hook( __FILE__, 'w24_deactivate' );

function w24_activate() {
    if ( false === get_option( W24_OPTION_KEY ) ) {
        add_option( W24_OPTION_KEY, w24_get_defaults() );
    }

    w24_ensure_cron();
}

function w24_deactivate() {
    wp_clear_scheduled_hook( W24_CRON_HOOK );

    // Drop any slices of an export still queued, so reactivating does not
    // resume half of yesterday's catalog against a fresh session.
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( W24_CHUNK_HOOK );
    }

    delete_option( W24_PROGRESS_KEY );
    delete_option( W24_QUEUE_KEY );
}

/**
 * =============================================
 *  CRON
 * =============================================
 */
add_filter( 'cron_schedules', 'w24_custom_cron_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- both added intervals are longer than a day.

function w24_custom_cron_schedules( $schedules ) {
    $schedules['weekly'] = array(
        'interval' => WEEK_IN_SECONDS,
        'display'  => __( 'Once Weekly', 'waiter24-ai-assistant-for-woocommerce' ),
    );
    $schedules['monthly'] = array(
        'interval' => 30 * DAY_IN_SECONDS,
        'display'  => __( 'Once Monthly', 'waiter24-ai-assistant-for-woocommerce' ),
    );
    return $schedules;
}

/**
 * Is automatic sync switched on?
 *
 * @return bool
 */
function w24_auto_sync_enabled() {
    $settings = w24_get_settings();

    return 'off' !== $settings['export_period'];
}

/**
 * Keep the recurring export in step with the setting.
 *
 * Two rules, and both matter:
 *
 * - Automatic sync off means *no* schedule exists. Nothing may push the catalog
 *   behind the owner's back — not the cron event, and not the admin-side driver
 *   that picks up an overdue one.
 * - A run is never scheduled at time(): it would go out before the owner has
 *   pasted the Import Token and would only produce a failed push.
 */
function w24_ensure_cron() {
    $enabled = w24_auto_sync_enabled();
    $next    = wp_next_scheduled( W24_CRON_HOOK );

    if ( ! $enabled ) {
        if ( $next ) {
            wp_clear_scheduled_hook( W24_CRON_HOOK );
        }

        return;
    }

    if ( $next ) {
        return;
    }

    $settings = w24_get_settings();
    wp_schedule_event( time() + HOUR_IN_SECONDS, $settings['export_period'], W24_CRON_HOOK );
}

add_action( 'admin_init', 'w24_ensure_cron' );

/**
 * Reschedule when the period changes. Kept out of the sanitize callback on
 * purpose — a sanitize filter can run more than once per request and must not
 * carry side effects.
 *
 * @param mixed $old_value Previous option value.
 * @param mixed $value     New option value.
 */
add_action( 'update_option_' . W24_OPTION_KEY, 'w24_maybe_reschedule_cron', 10, 2 );

function w24_maybe_reschedule_cron( $old_value, $value ) {
    $old_period = ( is_array( $old_value ) && isset( $old_value['export_period'] ) ) ? $old_value['export_period'] : '';
    $new_period = ( is_array( $value ) && isset( $value['export_period'] ) ) ? $value['export_period'] : 'off';

    if ( 'off' === $new_period ) {
        wp_clear_scheduled_hook( W24_CRON_HOOK );

        return;
    }

    if ( $old_period === $new_period && wp_next_scheduled( W24_CRON_HOOK ) ) {
        return;
    }

    wp_clear_scheduled_hook( W24_CRON_HOOK );
    wp_schedule_event( time() + HOUR_IN_SECONDS, $new_period, W24_CRON_HOOK );
}

/**
 * Has WP-Cron stopped firing?
 *
 * The scheduled export is a WP-Cron event, and WP-Cron only runs when the site
 * receives traffic (or a real system cron calls wp-cron.php). Where it is
 * disabled outright the event's timestamp simply sits in the past forever —
 * that overdue timestamp is the signal.
 *
 * @return bool
 */
function w24_cron_looks_dead() {
    $next = wp_next_scheduled( W24_CRON_HOOK );

    return $next && $next < ( time() - HOUR_IN_SECONDS );
}

/**
 * Run the missed scheduled export from wp-admin.
 *
 * On a site whose background tasks never fire, the scheduled export would never
 * start at all. So an overdue schedule is picked up on any admin page load: this
 * only *starts* the export (a couple of option writes, no catalog work), and the
 * driver printed into the admin footer pushes the batches over AJAX while the
 * owner is in the dashboard. The store therefore still syncs roughly as often as
 * its owner logs in, instead of never.
 */
add_action( 'admin_init', 'w24_maybe_run_missed_schedule' );

function w24_maybe_run_missed_schedule() {
    if ( wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    // Automatic sync off: there is no schedule to catch up on, and an export the
    // owner did not ask for must never start from a page load.
    if ( ! w24_auto_sync_enabled() ) {
        return;
    }

    // An export already under way will be carried on by the driver.
    if ( w24_export_is_running() || ! w24_cron_looks_dead() ) {
        return;
    }

    $settings = w24_get_settings();

    if ( '' === trim( (string) $settings['import_token'] ) ) {
        return;
    }

    // Push the schedule forward first, whatever happens next: a start that fails
    // must not retry on every single admin page load.
    $period = $settings['export_period'];
    wp_clear_scheduled_hook( W24_CRON_HOOK );
    wp_schedule_event( time() + w24_period_seconds( $period ), $period, W24_CRON_HOOK );

    w24_start_export( 'cron' );
}

/**
 * Length of an export period in seconds.
 *
 * @param string $period daily | weekly | monthly.
 * @return int
 */
function w24_period_seconds( $period ) {
    if ( 'weekly' === $period ) {
        return WEEK_IN_SECONDS;
    }

    if ( 'monthly' === $period ) {
        return 30 * DAY_IN_SECONDS;
    }

    return DAY_IN_SECONDS;
}

/**
 * =============================================
 *  ADMIN MENU & SETTINGS PAGE
 * =============================================
 */
add_action( 'admin_menu', 'w24_add_admin_menu' );
add_action( 'admin_init', 'w24_register_settings' );

function w24_add_admin_menu() {
    $hook = add_submenu_page(
        'woocommerce',
        __( 'Waiter24 AI Assistant', 'waiter24-ai-assistant-for-woocommerce' ),
        __( 'Waiter24 AI Assistant', 'waiter24-ai-assistant-for-woocommerce' ),
        'manage_woocommerce',
        // Page slug kept from earlier versions on purpose: it is what existing
        // installs have bookmarked, and it is the target of the Plugins-row
        // "Settings" link below.
        'waiter24-export',
        'w24_render_settings_page'
    );

    w24_settings_screen_id( $hook );
}

/**
 * Screen id of the settings page, remembered from add_submenu_page() rather
 * than spelled out, so a change of parent menu cannot silently break the
 * screen checks that depend on it.
 *
 * @param string|null $hook Hook suffix to store (pass nothing to read).
 * @return string
 */
function w24_settings_screen_id( $hook = null ) {
    static $stored = '';

    if ( is_string( $hook ) && '' !== $hook ) {
        $stored = $hook;
    }

    return $stored;
}

/**
 * "Settings" quick link on the Plugins list page row.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'w24_plugin_action_links' );

function w24_plugin_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=waiter24-export' ) ) . '">'
        . esc_html__( 'Settings', 'waiter24-ai-assistant-for-woocommerce' )
        . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

function w24_register_settings() {
    register_setting(
        'waiter24_settings_group',
        W24_OPTION_KEY,
        array(
            'type'              => 'array',
            'sanitize_callback' => 'w24_sanitize_settings',
            'default'           => w24_get_defaults(),
        )
    );
}

/**
 * Sanitize settings on save.
 *
 * @param mixed $input Raw submitted value.
 * @return array
 */
function w24_sanitize_settings( $input ) {
    if ( ! is_array( $input ) ) {
        return w24_get_defaults();
    }

    $sanitized = array();

    $sanitized['unique_key'] = isset( $input['unique_key'] )
        ? sanitize_text_field( $input['unique_key'] )
        : '';

    $sanitized['import_token'] = isset( $input['import_token'] )
        ? sanitize_text_field( $input['import_token'] )
        : '';

    // 'off' is both a valid choice and the fallback: an unrecognised value must
    // not switch automatic sync on for a store that never asked for it.
    $allowed_periods            = array( 'off', 'daily', 'weekly', 'monthly' );
    $sanitized['export_period'] = ( isset( $input['export_period'] ) && in_array( $input['export_period'], $allowed_periods, true ) )
        ? $input['export_period']
        : 'off';

    $sanitized['enable_widget']     = empty( $input['enable_widget'] ) ? 0 : 1;
    $sanitized['demo_mode']         = empty( $input['demo_mode'] ) ? 0 : 1;
    $sanitized['simple_stock_mode'] = empty( $input['simple_stock_mode'] ) ? 0 : 1;

    // 'auto' and 'all' are always valid; any other value must be a language
    // code the site is actually set up for, otherwise fall back to 'auto'
    // rather than silently exporting an empty (mismatched-language) catalog.
    $raw_language = isset( $input['export_language'] ) ? sanitize_key( $input['export_language'] ) : 'auto';
    if ( in_array( $raw_language, array( 'auto', 'all' ), true ) || array_key_exists( $raw_language, w24_available_languages() ) ) {
        $sanitized['export_language'] = $raw_language;
    } else {
        $sanitized['export_language'] = 'auto';
    }

    return $sanitized;
}

/**
 * Render the settings page.
 */
function w24_render_settings_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'waiter24-ai-assistant-for-woocommerce' ) );
    }

    // Handle manual export. The request only *starts* it — see w24_start_export()
    // for why the catalog is never built inside this page load. The outcome is
    // carried through a redirect (post/redirect/get) so that the progress block
    // below can reload the page without the browser re-posting the button.
    if (
        isset( $_POST['waiter24_manual_export'] )
        && check_admin_referer( 'waiter24_manual_export_action', 'waiter24_manual_export_nonce' )
    ) {
        $result = w24_start_export( 'manual' );

        if ( true !== $result ) {
            set_transient( 'w24_export_error_' . get_current_user_id(), (string) $result, MINUTE_IN_SECONDS );
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'       => 'waiter24-export',
                    'w24_export' => ( true === $result ) ? 'started' : 'error',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    // Stop a running export. Cancelling only drops the slices that have not been
    // sent yet — see w24_cancel_export() for why the menu in Waiter24 is left
    // exactly as it was.
    if (
        isset( $_POST['waiter24_cancel_export'] )
        && check_admin_referer( 'waiter24_cancel_export_action', 'waiter24_cancel_export_nonce' )
    ) {
        w24_cancel_export();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'       => 'waiter24-export',
                    'w24_export' => 'cancelled',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    $manual_result = '';
    $manual_error  = '';

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect above.
    $flag = isset( $_GET['w24_export'] ) ? sanitize_key( wp_unslash( $_GET['w24_export'] ) ) : '';

    if ( 'started' === $flag ) {
        $manual_result = 'success';
    } elseif ( 'cancelled' === $flag ) {
        $manual_result = 'cancelled';
    } elseif ( 'error' === $flag ) {
        $manual_result = 'error';
        $stored        = get_transient( 'w24_export_error_' . get_current_user_id() );
        $manual_error  = is_string( $stored ) ? $stored : '';
        delete_transient( 'w24_export_error_' . get_current_user_id() );
    }

    $settings       = w24_get_settings();
    $unique_key     = $settings['unique_key'];
    $import_token   = $settings['import_token'];
    $export_period  = $settings['export_period'];
    $enable_widget  = $settings['enable_widget'];
    $demo_mode      = $settings['demo_mode'];
    $simple_stock   = $settings['simple_stock_mode'];
    $export_language      = $settings['export_language'];
    $ml_plugin            = w24_multilingual_plugin();
    $available_languages  = $ml_plugin ? w24_available_languages() : array();
    $default_lang_code    = $ml_plugin ? w24_default_site_language() : '';
    $default_lang_name    = isset( $available_languages[ $default_lang_code ] ) ? $available_languages[ $default_lang_code ] : $default_lang_code;
    $demo_url       = add_query_arg( W24_DEMO_PARAM, '1', home_url( '/' ) );
    $next_scheduled = wp_next_scheduled( W24_CRON_HOOK );
    $last_run       = get_option( W24_LAST_RUN_KEY, array() );
    $progress       = w24_export_progress();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <p class="description" style="max-width:640px">
            <?php
            printf(
                /* translators: %s: link to the Waiter24 website. */
                esc_html__( 'This plugin connects your store to the Waiter24 service. A Waiter24 account is required to obtain the two keys below — see %s.', 'waiter24-ai-assistant-for-woocommerce' ),
                '<a href="https://waiter24.ai/" target="_blank" rel="noopener noreferrer">waiter24.ai</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, hard-coded markup.
            );
            ?>
        </p>

        <?php if ( '' !== $flag ) : ?>
            <script>
                /* The flag has done its job: this page load rendered the notice
                   above. Dropping it from the address bar keeps a reload — the
                   export driver finishes with one — from announcing "Export
                   started" again next to the finished run's own result. */
                ( function () {
                    if ( ! window.history || ! window.history.replaceState ) {
                        return;
                    }

                    try {
                        var url = new URL( window.location.href );
                        url.searchParams.delete( 'w24_export' );
                        window.history.replaceState( null, '', url.toString() );
                    } catch ( e ) {}
                } )();
            </script>
        <?php endif; ?>

        <?php if ( 'success' === $manual_result ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Export started.', 'waiter24-ai-assistant-for-woocommerce' ); ?></strong>
                    <?php esc_html_e( 'It runs in the background, a batch at a time, so a large catalog cannot time out. Progress is shown below.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                </p>
            </div>
        <?php elseif ( 'cancelled' === $manual_result ) : ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Export cancelled.', 'waiter24-ai-assistant-for-woocommerce' ); ?></strong>
                    <?php esc_html_e( 'The batches that were already sent stay in Waiter24, and nothing was hidden — your menu is exactly as it was before this export. Press "Export Now" when you want to start over.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                </p>
            </div>
        <?php elseif ( 'error' === $manual_result ) : ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Export failed.', 'waiter24-ai-assistant-for-woocommerce' ); ?></strong>
                    <?php
                    if ( '' !== $manual_error ) {
                        echo esc_html( $manual_error );
                    } else {
                        esc_html_e( 'Make sure WooCommerce is active and products exist.', 'waiter24-ai-assistant-for-woocommerce' );
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Settings form -->
        <form method="post" action="options.php">
            <?php settings_fields( 'waiter24_settings_group' ); ?>
            <table class="form-table" role="presentation">

                <!-- Unique Key -->
                <tr>
                    <th scope="row">
                        <label for="w24_unique_key"><?php esc_html_e( 'Unique Key', 'waiter24-ai-assistant-for-woocommerce' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="w24_unique_key"
                            name="<?php echo esc_attr( W24_OPTION_KEY ); ?>[unique_key]"
                            value="<?php echo esc_attr( $unique_key ); ?>"
                            class="regular-text"
                            placeholder="<?php esc_attr_e( 'Enter unique key', 'waiter24-ai-assistant-for-woocommerce' ); ?>"
                        />
                        <p class="description">
                            <?php esc_html_e( 'Public widget key — used to load the chat widget on your storefront.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Import Token -->
                <tr>
                    <th scope="row">
                        <label for="w24_import_token"><?php esc_html_e( 'Import Token', 'waiter24-ai-assistant-for-woocommerce' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="w24_import_token"
                            name="<?php echo esc_attr( W24_OPTION_KEY ); ?>[import_token]"
                            value="<?php echo esc_attr( $import_token ); ?>"
                            class="regular-text"
                            autocomplete="off"
                            placeholder="<?php esc_attr_e( 'Enter import token', 'waiter24-ai-assistant-for-woocommerce' ); ?>"
                        />
                        <p class="description">
                            <?php esc_html_e( 'The 48-character Import Token from your Waiter24 dashboard (Site Integration → Menu auto-import). This is NOT the Unique (widget) Key above — using the wrong value returns a 401 error.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Automatic Sync -->
                <tr>
                    <th scope="row">
                        <label for="w24_export_period"><?php esc_html_e( 'Automatic Sync', 'waiter24-ai-assistant-for-woocommerce' ); ?></label>
                    </th>
                    <td>
                        <select id="w24_export_period" name="<?php echo esc_attr( W24_OPTION_KEY ); ?>[export_period]">
                            <option value="off"     <?php selected( $export_period, 'off' ); ?>><?php esc_html_e( 'Off — export only when I click the button', 'waiter24-ai-assistant-for-woocommerce' ); ?></option>
                            <option value="daily"   <?php selected( $export_period, 'daily' ); ?>><?php esc_html_e( 'Daily', 'waiter24-ai-assistant-for-woocommerce' ); ?></option>
                            <option value="weekly"  <?php selected( $export_period, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'waiter24-ai-assistant-for-woocommerce' ); ?></option>
                            <option value="monthly" <?php selected( $export_period, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'waiter24-ai-assistant-for-woocommerce' ); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'How often the catalog is sent to Waiter24 on its own. While this is off, nothing is exported unless you press "Export Now" below.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Enable Chat Widget -->
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Enable Chat Widget', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                    </th>
                    <td>
                        <label for="w24_enable_widget">
                            <input
                                type="checkbox"
                                id="w24_enable_widget"
                                name="<?php echo esc_attr( W24_OPTION_KEY ); ?>[enable_widget]"
                                value="1"
                                <?php checked( $enable_widget, 1 ); ?>
                            />
                            <?php esc_html_e( 'Insert chat widget script on all frontend pages', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'When enabled, the Waiter24 chat widget script is loaded in the site footer.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Demo Mode -->
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Demo Mode', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                    </th>
                    <td>
                        <label for="w24_demo_mode">
                            <input
                                type="checkbox"
                                id="w24_demo_mode"
                                name="<?php echo esc_attr( W24_OPTION_KEY ); ?>[demo_mode]"
                                value="1"
                                <?php checked( $demo_mode, 1 ); ?>
                            />
                            <?php esc_html_e( 'Show the chat widget only to visitors who arrive via the demo link', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                        </label>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: the demo GET parameter name. */
                                esc_html__( 'When enabled, the widget script is not added to the page at all unless the URL carries the "%s" parameter, so regular visitors never load it. The "Enable Chat Widget" switch above still has to be on. Links the assistant opens keep the parameter, so the chat stays visible while you browse the demo.', 'waiter24-ai-assistant-for-woocommerce' ),
                                esc_html( W24_DEMO_PARAM )
                            );
                            ?>
                        </p>
                        <p class="description">
                            <strong><?php esc_html_e( 'Open demo:', 'waiter24-ai-assistant-for-woocommerce' ); ?></strong>
                            <a href="<?php echo esc_url( $demo_url ); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo esc_html( $demo_url ); ?>
                            </a>
                        </p>
                    </td>
                </tr>

                <!-- Simple Stock Mode -->
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Simple Stock Mode', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                    </th>
                    <td>
                        <label for="w24_simple_stock_mode">
                            <input
                                type="checkbox"
                                id="w24_simple_stock_mode"
                                name="<?php echo esc_attr( W24_OPTION_KEY ); ?>[simple_stock_mode]"
                                value="1"
                                <?php checked( $simple_stock, 1 ); ?>
                            />
                            <?php esc_html_e( 'Always mark all products as in-stock (ignore real stock data)', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'When enabled: every product is exported as available, ignoring real stock. When disabled: real WooCommerce stock availability is used.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                        </p>
                    </td>
                </tr>

                <?php if ( $ml_plugin ) : ?>
                <!-- Menu Language -->
                <tr>
                    <th scope="row">
                        <label for="w24_export_language"><?php esc_html_e( 'Menu Language', 'waiter24-ai-assistant-for-woocommerce' ); ?></label>
                    </th>
                    <td>
                        <select id="w24_export_language" name="<?php echo esc_attr( W24_OPTION_KEY ); ?>[export_language]">
                            <option value="auto" <?php selected( $export_language, 'auto' ); ?>>
                                <?php
                                printf(
                                    /* translators: %s: detected default language name. */
                                    esc_html__( 'Auto-detected — %s (your site\'s default language)', 'waiter24-ai-assistant-for-woocommerce' ),
                                    esc_html( $default_lang_name )
                                );
                                ?>
                            </option>
                            <?php foreach ( $available_languages as $code => $name ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $export_language, $code ); ?>>
                                    <?php echo esc_html( $name ); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="all" <?php selected( $export_language, 'all' ); ?>>
                                <?php esc_html_e( 'All languages (no filter)', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: name of the detected multilingual plugin (Polylang or WPML). */
                                esc_html__( '%s was detected. Waiter24 shows the assistant one menu in one language, so only products in the language chosen here are exported — other translations of the same product are skipped automatically.', 'waiter24-ai-assistant-for-woocommerce' ),
                                esc_html( 'polylang' === $ml_plugin ? 'Polylang' : 'WPML' )
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>

            </table>
            <?php submit_button( __( 'Save Settings', 'waiter24-ai-assistant-for-woocommerce' ) ); ?>
        </form>

        <hr />

        <!-- Manual Export -->
        <h2><?php esc_html_e( 'Manual Export', 'waiter24-ai-assistant-for-woocommerce' ); ?></h2>
        <p class="description" style="max-width:640px">
            <strong><?php esc_html_e( 'Note:', 'waiter24-ai-assistant-for-woocommerce' ); ?></strong>
            <?php esc_html_e( 'The export replaces your primary Waiter24 menu (the one marked with a star). Items missing from this store are hidden, not deleted.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
        </p>
        <form method="post">
            <?php wp_nonce_field( 'waiter24_manual_export_action', 'waiter24_manual_export_nonce' ); ?>
            <p>
                <button type="submit" name="waiter24_manual_export" value="1" class="button button-primary">
                    <?php esc_html_e( 'Export Now', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                </button>
            </p>
        </form>

        <?php if ( w24_export_is_running() ) : ?>
            <p class="description" id="w24-export-progress">
                <span class="spinner is-active" style="float:none;margin:0 4px 0 0"></span>
                <strong><?php esc_html_e( 'Export in progress…', 'waiter24-ai-assistant-for-woocommerce' ); ?></strong>
                <span id="w24-export-count"><?php echo esc_html( w24_progress_label( $progress ) ); ?></span>
                <br />
                <?php esc_html_e( 'Stay in the WordPress admin until the export finishes: if your site does not run background tasks, the admin pushes the batches itself.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
            </p>
            <form method="post">
                <?php wp_nonce_field( 'waiter24_cancel_export_action', 'waiter24_cancel_export_nonce' ); ?>
                <p>
                    <button type="submit" name="waiter24_cancel_export" value="1" class="button">
                        <?php esc_html_e( 'Cancel Export', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                    </button>
                </p>
            </form>
        <?php endif; ?>

        <?php if ( 'off' === $export_period ) : ?>
            <p class="description">
                <?php esc_html_e( 'Automatic sync is off — the catalog is sent only when you press "Export Now".', 'waiter24-ai-assistant-for-woocommerce' ); ?>
            </p>
        <?php elseif ( $next_scheduled ) : ?>
            <p class="description">
                <?php esc_html_e( 'Next scheduled export:', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                <strong><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_scheduled ), 'd.m.Y H:i:s' ) ); ?></strong>
            </p>
        <?php endif; ?>

        <?php if ( is_array( $last_run ) && ! empty( $last_run['time'] ) ) : ?>
            <p class="description">
                <?php esc_html_e( 'Last export:', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                <strong><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $last_run['time'] ), 'd.m.Y H:i:s' ) ); ?></strong>
                &mdash;
                <?php if ( ! empty( $last_run['ok'] ) ) : ?>
                    <?php
                    printf(
                        /* translators: %d: number of products sent. */
                        esc_html( _n( 'sent %d product', 'sent %d products', (int) $last_run['items'], 'waiter24-ai-assistant-for-woocommerce' ) ),
                        (int) $last_run['items']
                    );
                    ?>
                <?php elseif ( ! empty( $last_run['cancelled'] ) ) : ?>
                    <?php
                    printf(
                        /* translators: %d: number of products sent before the export was cancelled. */
                        esc_html( _n( 'cancelled after %d product', 'cancelled after %d products', (int) $last_run['items'], 'waiter24-ai-assistant-for-woocommerce' ) ),
                        (int) $last_run['items']
                    );
                    ?>
                <?php else : ?>
                    <?php esc_html_e( 'failed:', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                    <?php echo esc_html( isset( $last_run['message'] ) ? $last_run['message'] : '' ); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * =============================================
 *  FRONTEND: CHAT WIDGET
 * =============================================
 * Loaded from the Waiter24 service host — the widget *is* the service: its
 * markup, styling and AI replies are all produced by the Waiter24 backend and
 * cannot be bundled locally. See the "External services" section of readme.txt.
 */
add_action( 'wp_enqueue_scripts', 'w24_enqueue_widget_script' );

/**
 * Is the current request carrying the demo parameter?
 *
 * Any value counts (`?waiter24_demo=1` is just the link the settings page
 * hands out) — the parameter is a visibility switch, not input.
 *
 * @return bool
 */
function w24_demo_param_present() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only visibility switch; nothing is written.
    return isset( $_GET[ W24_DEMO_PARAM ] );
}

function w24_enqueue_widget_script() {
    $settings = w24_get_settings();

    if ( empty( $settings['enable_widget'] ) || '' === trim( (string) $settings['unique_key'] ) ) {
        return;
    }

    // Demo mode is enforced here, on the server: the script is simply not
    // printed unless the visitor arrived on a demo link. Regular shoppers never
    // receive the widget at all, so the gate cannot be defeated by a page cache
    // that stored the markup before the toggle was flipped, nor by an optimizer
    // that strips the tag's data attributes.
    if ( ! empty( $settings['demo_mode'] ) ) {
        if ( ! w24_demo_param_present() ) {
            return;
        }

        // Keep page caches from storing (and later serving to everyone) the one
        // response that does carry the widget. Honoured by W3TC, WP Super Cache,
        // LiteSpeed Cache, WP Rocket and friends.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
    }

    // A null version keeps the query string off the service URL, so the CDN
    // serves the same cached asset for every store.
    wp_enqueue_script( W24_SCRIPT_HANDLE, W24_WIDGET_URL, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
}

/**
 * Add the widget's own data attributes to the enqueued tag.
 *
 * @param string $tag    The script tag markup.
 * @param string $handle Script handle.
 * @param string $src    Script source URL.
 * @return string
 */
add_filter( 'script_loader_tag', 'w24_widget_script_tag', 10, 3 );

function w24_widget_script_tag( $tag, $handle, $src ) {
    if ( W24_SCRIPT_HANDLE !== $handle ) {
        return $tag;
    }

    $settings = w24_get_settings();
    $attrs    = sprintf( ' data-key="%s"', esc_attr( $settings['unique_key'] ) );

    // In demo mode the script only ever reaches a page that already carries the
    // parameter (see w24_enqueue_widget_script). The attribute is still sent so
    // the widget re-applies the parameter to the links it opens itself — product
    // pages, cart, checkout — keeping the demo alive across in-chat navigation.
    if ( ! empty( $settings['demo_mode'] ) ) {
        $attrs .= sprintf( ' data-demo-param="%s"', esc_attr( W24_DEMO_PARAM ) );
    }

    return sprintf( '<script defer src="%s"%s></script>' . "\n", esc_url( $src ), $attrs );
}

/**
 * =============================================
 *  EXPORT LOGIC
 * =============================================
 */
add_action( W24_CRON_HOOK, 'w24_run_scheduled_export' );
add_action( W24_CHUNK_HOOK, 'w24_run_export_chunk', 10, 2 );

/**
 * The scheduled export, with a last check that it is still wanted.
 *
 * A leftover cron event must never push a catalog the owner switched sync off
 * for: the event can outlive the setting (switched off while wp-cron.php was
 * already on its way, or an event carried over by a site migration). The check
 * costs one option read, at most once a day.
 */
function w24_run_scheduled_export() {
    if ( ! w24_auto_sync_enabled() ) {
        wp_clear_scheduled_hook( W24_CRON_HOOK );

        return;
    }

    w24_start_export( 'cron' );
}

/**
 * Start an export.
 *
 * The catalog is never built inside the request that asks for it: a large store
 * needs minutes to read every product, and the web server in front of PHP gives
 * up long before that (the classic "504 Gateway Timeout" on Export Now). Instead
 * the work is handed to Action Scheduler — WooCommerce's own background queue —
 * which walks the catalog one slice at a time. Each slice is its own short
 * request to Waiter24, so neither end ever has to hold the whole catalog.
 *
 * @param string $trigger What asked for this export: 'manual' or 'cron'.
 * @return true|string true when the export was started, or an error message.
 */
function w24_start_export( $trigger = 'cron' ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return w24_record_run( __( 'WooCommerce is not active.', 'waiter24-ai-assistant-for-woocommerce' ), 0 );
    }

    $settings = w24_get_settings();

    if ( '' === trim( (string) $settings['import_token'] ) ) {
        return w24_record_run(
            __( 'Import Token is empty. Paste it from the Menu auto-import block in your Waiter24 dashboard (Site Integration).', 'waiter24-ai-assistant-for-woocommerce' ),
            0
        );
    }

    if ( w24_export_is_running() ) {
        return __( 'An export is already running.', 'waiter24-ai-assistant-for-woocommerce' );
    }

    // The catalog is pinned to a list of ids up front — cheap id-only queries,
    // no product objects. Slices then walk that list by offset, which means a
    // product added or deleted mid-export cannot shift the paging under us and
    // make a slice skip (a skipped product would be hidden when the session
    // closes, so this is worth the option write).
    $ids = w24_public_product_ids();

    if ( empty( $ids ) ) {
        return w24_record_run( __( 'No published products to export.', 'waiter24-ai-assistant-for-woocommerce' ), 0 );
    }

    update_option( W24_QUEUE_KEY, $ids, false );

    $session = w24_new_session_id();

    w24_set_progress(
        array(
            'status'  => 'running',
            'session' => $session,
            'page'    => 1,
            'sent'    => 0,
            'total'   => count( $ids ),
            'trigger' => 'manual' === $trigger ? 'manual' : 'cron',
            'driver'  => 'queue',
            'started' => time(),
        )
    );

    if ( function_exists( 'as_enqueue_async_action' ) ) {
        as_enqueue_async_action( W24_CHUNK_HOOK, array( $session, 1 ), 'waiter24' );

        return true;
    }

    // Safety net only: Action Scheduler ships with WooCommerce, which this
    // plugin requires. Without it there is nowhere to defer to, so the slices
    // run inline and a big catalog can still outlive the request.
    $page = 1;
    while ( w24_run_export_chunk( $session, $page, false ) ) {
        ++$page;
    }

    $last = get_option( W24_LAST_RUN_KEY, array() );

    return empty( $last['ok'] ) && ! empty( $last['message'] ) ? $last['message'] : true;
}

/**
 * Stop the export that is under way.
 *
 * Nothing has to be undone in Waiter24: an import session only hides the dishes
 * it never sent when its *closing* call arrives (see w24_finalize_export), and a
 * cancelled export never makes that call. The batches already pushed are simply
 * kept as updates to the dishes they carried, so the menu the assistant serves
 * stays exactly as it was.
 *
 * @return bool Whether an export was actually stopped.
 */
function w24_cancel_export() {
    $progress = w24_export_progress();

    if ( 'running' !== $progress['status'] ) {
        return false;
    }

    // The page-match guard in w24_run_export_chunk() already makes a leftover
    // slice exit, but a queue full of pending waiter24 actions still looks to
    // the store owner (and to WooCommerce's scheduled-actions screen) like the
    // export is going, so drop them.
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( W24_CHUNK_HOOK, null, 'waiter24' );
    }

    $progress['status'] = 'cancelled';
    w24_set_progress( $progress );
    delete_option( W24_QUEUE_KEY );

    w24_record_run( '', (int) $progress['sent'], true );

    return true;
}

/**
 * =============================================
 *  MULTILINGUAL SUPPORT (Polylang / WPML)
 * =============================================
 * Waiter24 shows the AI one menu in one language. Polylang/WPML tag each
 * product's *translation* with a language, but do not filter background
 * (cron/Action Scheduler) queries by language on their own — that only
 * happens automatically for a real frontend request. Left alone, every
 * translation of every product would be exported and the AI would mix
 * languages inside a single menu. Translations of the same product commonly
 * share the same `product_cat` term (Polylang's default — "categories do not
 * need to be translated"), so the language has to be resolved per *product*,
 * not per category.
 */

/**
 * Which multilingual plugin, if any, is active.
 *
 * @return string|null 'polylang', 'wpml', or null.
 */
function w24_multilingual_plugin() {
    if ( function_exists( 'pll_languages_list' ) ) {
        return 'polylang';
    }

    if ( function_exists( 'icl_object_id' ) && has_filter( 'wpml_active_languages' ) ) {
        return 'wpml';
    }

    return null;
}

/**
 * Every language the site is set up for.
 *
 * @return array<string,string> Language code => native name. Empty when no
 *                               multilingual plugin is active.
 */
function w24_available_languages() {
    $plugin = w24_multilingual_plugin();
    $out    = array();

    if ( 'polylang' === $plugin ) {
        $languages = pll_languages_list( array( 'fields' => '' ) );

        foreach ( (array) $languages as $lang ) {
            if ( isset( $lang->slug ) ) {
                $out[ $lang->slug ] = isset( $lang->name ) ? $lang->name : $lang->slug;
            }
        }
    } elseif ( 'wpml' === $plugin ) {
        $languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );

        foreach ( (array) $languages as $code => $lang ) {
            $out[ $code ] = isset( $lang['native_name'] ) ? $lang['native_name'] : $code;
        }
    }

    return $out;
}

/**
 * The site's default/primary language code (e.g. what Polylang/WPML calls
 * the language every non-translated product falls back to).
 *
 * @return string Language code, or '' when no multilingual plugin is active.
 */
function w24_default_site_language() {
    $plugin = w24_multilingual_plugin();

    if ( 'polylang' === $plugin ) {
        return (string) pll_default_language( 'slug' );
    }

    if ( 'wpml' === $plugin ) {
        $default = apply_filters( 'wpml_default_language', null );

        return is_string( $default ) ? $default : '';
    }

    return '';
}

/**
 * Language code the export should actually filter products to.
 *
 * @return string Language code to filter to, or '' to export every language
 *                (no multilingual plugin, or the owner picked "All languages").
 */
function w24_export_language_code() {
    if ( ! w24_multilingual_plugin() ) {
        return '';
    }

    $setting = w24_get_settings()['export_language'];

    if ( 'all' === $setting ) {
        return '';
    }

    if ( 'auto' === $setting || ! array_key_exists( $setting, w24_available_languages() ) ) {
        return w24_default_site_language();
    }

    return $setting;
}

/**
 * Ids of the products a shopper can actually reach.
 *
 * The assistant recommends what it is given, so anything a customer cannot open
 * on the storefront has no business being in it. "Published" alone does not mean
 * public in WooCommerce, so three more groups are dropped:
 *
 * - **Catalog visibility "hidden"** — carries both the `exclude-from-catalog`
 *   and `exclude-from-search` terms. Typically component or add-on products the
 *   store sells only as part of something else.
 * - **Password-protected** — the page exists but its content does not open
 *   without the password.
 * - **Out of stock, when the store hides those** (WooCommerce → Settings →
 *   Products → "Hide out of stock items"). The owner already decided customers
 *   must not see them; the assistant offering them anyway would send shoppers to
 *   a page they cannot buy from. Note this applies even in Simple Stock Mode:
 *   that setting governs how availability is *reported*, not whether the store
 *   shows the product at all.
 * - **Other-language translations**, on a Polylang/WPML store (see
 *   {@see w24_export_language_code()}) — only the resolved menu language is
 *   exported, so the AI never mixes languages inside one menu.
 *
 * Private and draft products never enter the list — they are not `publish`.
 *
 * @return int[] Ascending product ids.
 */
function w24_public_product_ids() {
    $lang_code   = w24_export_language_code();
    $ml_plugin   = $lang_code ? w24_multilingual_plugin() : null;
    $query_args  = array(
        'status'  => 'publish',
        'limit'   => -1,
        'return'  => 'ids',
        'orderby' => 'ID',
        'order'   => 'ASC',
    );

    if ( $lang_code && 'polylang' === $ml_plugin ) {
        // Polylang tags every post (not every category) with a `language`
        // term, so filtering here — rather than by product_cat — still works
        // even when translated products share the same category.
        $query_args['tax_query'] = array(
            array(
                'taxonomy' => 'language',
                'field'    => 'slug',
                'terms'    => $lang_code,
            ),
        );
    }

    if ( $lang_code && 'wpml' === $ml_plugin ) {
        // WPML filters WP_Query by the "current" language, which a cron/Action
        // Scheduler request does not have — force it for the duration of this
        // one query, then restore whatever it was.
        do_action( 'wpml_switch_language', $lang_code );
    }

    $ids = wc_get_products( $query_args );

    if ( $lang_code && 'wpml' === $ml_plugin ) {
        do_action( 'wpml_switch_language', null );
    }

    $ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();

    if ( empty( $ids ) ) {
        return array();
    }

    $hide_out_of_stock = ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) );

    // Catalog visibility is stored as terms, so the non-public sets are read as
    // id lists and subtracted — far cheaper than loading every product object
    // just to ask it how visible it is.
    $hidden = get_posts(
        array(
            'post_type'        => 'product',
            'post_status'      => 'publish',
            'fields'           => 'ids',
            'numberposts'      => -1,
            'suppress_filters' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-off query when an export starts, not a page-load query.
            'tax_query'        => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'exclude-from-catalog',
                ),
                array(
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'exclude-from-search',
                ),
            ),
        )
    );

    $protected = get_posts(
        array(
            'post_type'        => 'product',
            'post_status'      => 'publish',
            'fields'           => 'ids',
            'numberposts'      => -1,
            'has_password'     => true,
            'suppress_filters' => false,
        )
    );

    $out_of_stock = array();

    if ( $hide_out_of_stock ) {
        $out_of_stock = get_posts(
            array(
                'post_type'        => 'product',
                'post_status'      => 'publish',
                'fields'           => 'ids',
                'numberposts'      => -1,
                'suppress_filters' => false,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-off query when an export starts, not a page-load query.
                'tax_query'        => array(
                    array(
                        'taxonomy' => 'product_visibility',
                        'field'    => 'name',
                        'terms'    => 'outofstock',
                    ),
                ),
            )
        );
    }

    $excluded = array_map(
        'intval',
        array_merge(
            is_array( $hidden ) ? $hidden : array(),
            is_array( $protected ) ? $protected : array(),
            is_array( $out_of_stock ) ? $out_of_stock : array()
        )
    );

    if ( ! empty( $excluded ) ) {
        $ids = array_diff( $ids, $excluded );
    }

    /**
     * Filters the products an export sends.
     *
     * @param int[] $ids Product ids judged publicly visible.
     */
    return array_values( apply_filters( 'waiter24_export_product_ids', array_values( $ids ) ) );
}

/**
 * Export one slice of the catalog: build as many products as fit in the slice's
 * time budget, push them, then queue the next slice — or close the session when
 * the catalog runs out.
 *
 * The budget is what makes this work everywhere. A fixed slice of N products is
 * a bet on how fast the host is, and that bet is lost on shared hosting: a store
 * that builds two products a second needs half a minute for fifty, which is
 * exactly the `max_execution_time` PHP is usually given. So the slice is bounded
 * by seconds, not by products — a slow host simply sends fewer per request.
 *
 * @param string $session    Import-session id shared by every slice of this run.
 * @param int    $sequence   1-based slice number, matched against the progress.
 * @param bool   $background Whether to queue the next slice (false = inline run).
 * @return bool Whether another slice is pending.
 */
function w24_run_export_chunk( $session, $sequence, $background = true ) {
    $progress = w24_export_progress();
    $page     = max( 1, (int) $sequence );

    // A leftover action from a superseded (or cancelled) run must not resurrect
    // that run and interleave its slices with the current one. Neither may two
    // runners (the queue and the settings page, see w24_ajax_export_step) work
    // the same export at once: only the slice the progress is waiting for runs,
    // so a duplicate action exits instead of rewinding the counter.
    if ( 'running' !== $progress['status'] || $progress['session'] !== $session ) {
        return false;
    }

    if ( $page !== (int) $progress['page'] ) {
        return false;
    }

    $queue = get_option( W24_QUEUE_KEY, array() );

    if ( ! is_array( $queue ) || empty( $queue ) ) {
        w24_set_progress( array( 'status' => 'error' ) + $progress );
        w24_record_run( __( 'The export lost its product list. Start the export again.', 'waiter24-ai-assistant-for-woocommerce' ), (int) $progress['sent'] );

        return false;
    }

    $settings     = w24_get_settings();
    $simple_stock = ! empty( $settings['simple_stock_mode'] );
    $max_items    = w24_chunk_size();
    $budget       = w24_chunk_budget_seconds();
    $currency     = get_woocommerce_currency();

    wp_raise_memory_limit( 'admin' );

    // Best effort — plenty of hosts forbid it, which is precisely why the slice
    // is time-boxed rather than trusting this to work.
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on many hosts; the time budget below is the real guard.
    }

    $offset  = (int) $progress['sent'];
    $started = microtime( true );
    $items   = array();

    // Stop on whichever comes first: the slice size, the time budget, or the end
    // of the catalog. Always build at least one product, so an unusually slow
    // product cannot stall the export forever.
    while ( count( $items ) < $max_items && isset( $queue[ $offset + count( $items ) ] ) ) {
        $product = wc_get_product( $queue[ $offset + count( $items ) ] );

        if ( $product ) {
            $items[] = w24_build_item( $product, $currency, $simple_stock );
        } else {
            // Deleted between the snapshot and now: skip it, but keep the offset
            // moving or the export would stick on a hole in the list.
            $items[] = null;
        }

        if ( ( microtime( true ) - $started ) >= $budget ) {
            break;
        }
    }

    $consumed = count( $items );
    $items    = array_values( array_filter( $items ) );

    $payload = array(
        'items'          => $items,
        'import_session' => $session,
        'chunk'          => $page,
    );

    // The store's selectors and endpoints do not change between slices, so they
    // ride the first one.
    if ( 1 === $page ) {
        $payload['site_config'] = w24_site_config();
    }

    $result = w24_save_and_notify( $payload );

    if ( true !== $result ) {
        w24_set_progress( array( 'status' => 'error' ) + $progress );
        w24_record_run( $result, (int) $progress['sent'] );

        return false;
    }

    // The offset counts products consumed from the snapshot, including any that
    // vanished from the store — otherwise the export would never reach the end.
    $sent = $offset + $consumed;

    w24_set_progress(
        array(
            'status'  => 'running',
            'session' => $session,
            'page'    => $page + 1,
            'sent'    => $sent,
            'total'   => (int) $progress['total'],
            'trigger' => $progress['trigger'],
            'driver'  => $progress['driver'],
            'started' => $progress['started'],
        )
    );

    // Nothing consumed means the list is exhausted (or a corrupt offset) — either
    // way, carrying on would loop forever.
    if ( $sent >= count( $queue ) || 0 === $consumed ) {
        w24_finalize_export( $session, $sent );

        return false;
    }

    if ( $background ) {
        as_enqueue_async_action( W24_CHUNK_HOOK, array( $session, $page + 1 ), 'waiter24' );
    }

    return true;
}

/**
 * Drive a running export from wp-admin.
 *
 * Action Scheduler needs the site's background tasks to actually fire, and on
 * plenty of hosts they do not: WP-Cron is disabled, or loopback requests are
 * blocked, and the slice sits in the queue as "pending" forever. So every admin
 * page keeps asking — and if the queue has not moved the export for a while, it
 * runs the next slice itself, inside this request. The work happens in these
 * AJAX calls, so no admin page is ever slowed down by it.
 *
 * One slice per call, and the page-match guard in w24_run_export_chunk() keeps
 * this from colliding with a queue runner that wakes up late.
 */
/**
 * Tell the owner why scheduled exports are not firing on their own.
 *
 * Shown on the plugin's own screen only — this is a fact about the site, not
 * something to nag about on every page.
 */
add_action( 'admin_notices', 'w24_cron_notice' );

function w24_cron_notice() {
    $screen = get_current_screen();

    if ( ! $screen || $screen->id !== w24_settings_screen_id() ) {
        return;
    }

    if ( ! w24_cron_looks_dead() ) {
        return;
    }
    ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php esc_html_e( 'Scheduled exports are not firing on this site.', 'waiter24-ai-assistant-for-woocommerce' ); ?></strong>
            <?php esc_html_e( 'WordPress background tasks (WP-Cron) appear to be disabled or blocked by your host, and the schedule below depends on them. Waiter24 works around it by exporting while you are in the WordPress admin, so the catalog still syncs about as often as you sign in. For unattended sync, ask your host to run wp-cron.php with a real cron job.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
        </p>
    </div>
    <?php
}

add_action( 'admin_footer', 'w24_print_export_driver' );

function w24_print_export_driver() {
    if ( ! current_user_can( 'manage_woocommerce' ) || ! w24_export_is_running() ) {
        return;
    }
    ?>
    <script>
        ( function () {
            var endpoint = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'waiter24_export_step' ) ); ?>;
            var counter  = document.getElementById( 'w24-export-count' );

            // A batch that dies mid-request — PHP's time limit, a gateway giving
            // up — leaves no usable answer. Retrying is what gets the export past
            // it: the batch is time-boxed, so the retry asks for less work than
            // the attempt that failed. Backs off so a genuinely broken site is
            // not hammered, and gives up loudly rather than stalling in silence.
            var failures = 0;

            function retry() {
                failures++;

                if ( failures > 8 ) {
                    if ( counter ) {
                        window.location.reload();
                    }
                    return;
                }

                setTimeout( step, Math.min( 30000, 3000 * failures ) );
            }

            // Sequential, never on a timer: one call may spend a while pushing a
            // batch, and overlapping calls would race it.
            function step() {
                var body = new FormData();
                body.append( 'action', 'waiter24_export_step' );
                body.append( 'nonce', nonce );

                fetch( endpoint, { method: 'POST', body: body, credentials: 'same-origin' } )
                    .then( function ( r ) { return r.ok ? r.json() : null; } )
                    .then( function ( res ) {
                        if ( ! res || ! res.success || ! res.data ) {
                            retry();
                            return;
                        }

                        failures = 0;

                        if ( counter && res.data.label ) {
                            counter.textContent = res.data.label;
                        }

                        if ( ! res.data.running ) {
                            // Only the settings page has a result to redraw.
                            if ( counter ) {
                                window.location.reload();
                            }
                            return;
                        }

                        // Pushing the batches ourselves: come straight back for
                        // the next one. Merely watching a working queue: check in
                        // every few seconds.
                        setTimeout( step, res.data.driving ? 250 : 3000 );
                    } )
                    .catch( retry );
            }

            step();
        } )();
    </script>
    <?php
}

add_action( 'wp_ajax_waiter24_export_step', 'w24_ajax_export_step' );

function w24_ajax_export_step() {
    check_ajax_referer( 'waiter24_export_step', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions to access this page.', 'waiter24-ai-assistant-for-woocommerce' ) ), 403 );
    }

    $progress = w24_export_progress();

    // The export is only nudged along here, never started: an idle, finished or
    // failed run is simply reported back so the page can show its result.
    //
    // The handoff is one-way. Waiting out the window again between every batch
    // would drag a big catalog out into hours, so once this page has taken the
    // export over it keeps it — a queue that wakes up late finds every slice
    // already claimed and exits.
    $stalled = ( time() - (int) $progress['updated'] ) >= W24_EXPORT_HANDOFF_SECONDS;

    if ( 'running' === $progress['status'] && ( 'page' === $progress['driver'] || $stalled ) ) {
        $progress['driver'] = 'page';
        w24_set_progress( $progress );

        w24_run_export_chunk( $progress['session'], (int) $progress['page'], false );
        $progress = w24_export_progress();
    }

    $last = get_option( W24_LAST_RUN_KEY, array() );

    wp_send_json_success(
        array(
            'status'  => $progress['status'],
            'sent'    => (int) $progress['sent'],
            'running' => ( 'running' === $progress['status'] ),
            'driving' => ( 'page' === $progress['driver'] ),
            'label'   => w24_progress_label( $progress ),
            'message' => ( is_array( $last ) && ! empty( $last['message'] ) ) ? (string) $last['message'] : '',
        )
    );
}

/**
 * Close the session. Waiter24 hides whatever this run never sent — the dishes
 * the store no longer sells — and only this call may do that.
 *
 * @param string $session Import-session id.
 * @param int    $sent    Products pushed across every slice.
 * @return true|string
 */
function w24_finalize_export( $session, $sent ) {
    $result = w24_save_and_notify(
        array(
            'items'          => array(),
            'import_session' => $session,
            'final'          => true,
        )
    );

    $progress            = w24_export_progress();
    $progress['status']  = ( true === $result ) ? 'done' : 'error';
    $progress['sent']    = (int) $sent;
    $progress['session'] = $session;

    w24_set_progress( $progress );
    delete_option( W24_QUEUE_KEY );

    return w24_record_run( $result, (int) $sent );
}

/**
 * How many products one slice carries. Small enough that building and pushing it
 * fits comfortably inside one PHP request on modest hosting.
 *
 * @return int
 */
function w24_chunk_size() {
    // The ceiling, not the target: the time budget below usually stops a slice
    // long before this on modest hosting.
    $size = (int) apply_filters( 'waiter24_export_batch_size', 50 );

    return max( 1, min( 500, $size ) );
}

/**
 * How long one slice may spend building products.
 *
 * Sized against the limits these requests actually run under: PHP's
 * `max_execution_time` is commonly 30 seconds and the gateway in front of it is
 * often 60, so a 12-second build plus the push to Waiter24 leaves generous room
 * even when the host is having a bad minute.
 *
 * @return float
 */
function w24_chunk_budget_seconds() {
    $budget = (float) apply_filters( 'waiter24_export_batch_seconds', 12 );

    return max( 2, min( 120, $budget ) );
}

/**
 * Opaque id tying every slice of one run together. Waiter24 stamps it on the
 * dishes it receives and uses it to work out what is missing at the end.
 *
 * @return string
 */
function w24_new_session_id() {
    return 'w24' . str_replace( '-', '', wp_generate_uuid4() );
}

/**
 * Current export progress, always shaped the same so callers can read it blind.
 *
 * @return array{status:string, session:string, page:int, sent:int, trigger:string, started:int, updated:int}
 */
function w24_export_progress() {
    $defaults = array(
        'status'  => 'idle',  // idle | running | done | error | cancelled
        'session' => '',
        'page'    => 1,
        'sent'    => 0,
        'total'   => 0,
        'trigger' => 'cron',
        'driver'  => 'queue', // queue | page — who is pushing the slices.
        'started' => 0,
        'updated' => 0,
    );

    $saved = get_option( W24_PROGRESS_KEY, array() );

    return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
}

/**
 * "120 of 480 products exported" — built here rather than in the browser, since
 * languages with more than two plural forms cannot be served by a JS swap.
 *
 * @param array $progress Progress as returned by w24_export_progress().
 * @return string
 */
function w24_progress_label( $progress ) {
    $sent  = (int) $progress['sent'];
    $total = (int) $progress['total'];

    if ( $total > 0 ) {
        return sprintf(
            /* translators: 1: products exported so far, 2: products in the catalog. */
            __( '%1$d of %2$d products exported.', 'waiter24-ai-assistant-for-woocommerce' ),
            $sent,
            $total
        );
    }

    return sprintf(
        /* translators: %d: number of products sent so far. */
        _n( '%d product sent so far.', '%d products sent so far.', $sent, 'waiter24-ai-assistant-for-woocommerce' ),
        $sent
    );
}

/**
 * @param array $progress Progress fields to store.
 */
function w24_set_progress( $progress ) {
    $progress['updated'] = time();

    update_option( W24_PROGRESS_KEY, $progress, false );
}

/**
 * Is an export still working? A run whose slices stopped arriving (a fatal error
 * in the queue runner, a disabled cron) is treated as finished, so the store
 * owner is never locked out of pressing Export Now again.
 *
 * @return bool
 */
function w24_export_is_running() {
    $progress = w24_export_progress();

    return 'running' === $progress['status']
        && ( time() - (int) $progress['updated'] ) < W24_EXPORT_STALL_SECONDS;
}

/**
 * The store's cart selectors and endpoints, sent with the first slice.
 *
 * Note: cart_integration_enabled is deliberately NOT sent — that toggle is
 * owned by the Waiter24 dashboard, and pushing a value here would reset the
 * store owner's choice on every scheduled export.
 *
 * @return array
 */
function w24_site_config() {
    return array(
        'platform_preset'               => 'woocommerce',
        'cart_url'                      => wc_get_cart_url(),
        'add_button_selector'           => '.single_add_to_cart_button',
        'qty_selector'                  => '.quantity input.qty',
        'variations_container_selector' => '.variations',
        'variation_select_pattern'      => 'select[name="attribute_{attribute}"]',
        'dom_wait_ms'                   => 500,
        'after_add_js'                  => "jQuery(document.body).trigger('wc_fragment_refresh')",
        // No-reload add-to-cart endpoint (exact URL, correct for subdirectory
        // installs). The widget POSTs product_id/quantity here and refreshes
        // the mini-cart from the returned fragments.
        'ajax_add_url'                  => WC_AJAX::get_endpoint( 'add_to_cart' ),
        // Read-only cart endpoint (WooCommerce Store API, core since WC 6.6) —
        // lets the chat widget see what is already in the shopper's cart for
        // cart-aware suggestions. Toggle lives in the dashboard (off by default).
        'cart_read_url'                 => rest_url( 'wc/store/v1/cart' ),
    );
}

/**
 * Store the outcome of the last run for display on the settings page.
 *
 * @param true|string $result    Export result.
 * @param int         $count     Number of exported products.
 * @param bool        $cancelled Whether the run was stopped by the store owner
 *                               — shown as "cancelled", not as a failure.
 * @return true|string The untouched $result, so callers can return it directly.
 */
function w24_record_run( $result, $count, $cancelled = false ) {
    update_option(
        W24_LAST_RUN_KEY,
        array(
            'time'      => time(),
            'items'     => (int) $count,
            'ok'        => ( true === $result ),
            'message'   => is_string( $result ) ? $result : '',
            'cancelled' => (bool) $cancelled,
        ),
        false
    );

    return $result;
}

/**
 * Map one WooCommerce product onto the neutral Waiter24 menu-item shape.
 *
 * @param WC_Product $product      Product object.
 * @param string     $currency     Store currency code.
 * @param bool       $simple_stock Whether every product is forced in-stock.
 * @return array
 */
function w24_build_item( $product, $currency, $simple_stock ) {
    $product_id = $product->get_id();

    // --- Categories: category / subcategory ---
    $category    = null;
    $subcategory = null;
    $term_ids    = $product->get_category_ids();

    if ( ! empty( $term_ids ) ) {
        foreach ( $term_ids as $tid ) {
            $term = get_term( $tid, 'product_cat' );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            if ( 0 === (int) $term->parent ) {
                if ( null === $category ) {
                    $category = $term->name;
                }
            } elseif ( null === $subcategory ) {
                $subcategory = $term->name;
                if ( null === $category ) {
                    $parent_term = get_term( $term->parent, 'product_cat' );
                    if ( $parent_term && ! is_wp_error( $parent_term ) ) {
                        $category = $parent_term->name;
                    }
                }
            }
        }
        if ( null === $category ) {
            $first = get_term( $term_ids[0], 'product_cat' );
            if ( $first && ! is_wp_error( $first ) ) {
                $category = $first->name;
            }
        }
    }

    // --- Tags ---
    $tags_list = array();
    foreach ( $product->get_tag_ids() as $tag_id ) {
        $tag = get_term( $tag_id, 'product_tag' );
        if ( $tag && ! is_wp_error( $tag ) ) {
            $tags_list[] = $tag->name;
        }
    }

    // --- Photo ---
    $image_id  = $product->get_image_id();
    $photo_url = $image_id ? w24_product_image_url( (int) $image_id, $product ) : null;

    // --- Price ---
    $price      = $product->get_regular_price();
    $price      = ( '' !== $price ) ? (float) $price : 0;
    $sale_price = $product->get_sale_price();
    $sale_price = ( '' !== $sale_price ) ? (float) $sale_price : null;

    // --- Weight ---
    $weight = $product->get_weight();
    $weight = ( '' !== $weight ) ? $weight . get_option( 'woocommerce_weight_unit', 'kg' ) : null;

    // --- Description (full first, short as fallback) ---
    $description = $product->get_description();
    if ( empty( $description ) ) {
        $description = $product->get_short_description();
    }
    $description = ! empty( $description ) ? wp_strip_all_tags( $description ) : null;

    // --- Variations ---
    $variations = null;

    if ( $product->is_type( 'variable' ) ) {
        $available_variations = $product->get_available_variations();

        if ( ! empty( $available_variations ) ) {
            $variations = array();

            foreach ( $available_variations as $var ) {
                $var_obj = wc_get_product( $var['variation_id'] );
                if ( ! $var_obj ) {
                    continue;
                }

                $var_name_parts = array();
                $var_values     = array();

                foreach ( $var_obj->get_attributes() as $attr_key => $attr_val ) {
                    if ( '' === $attr_val ) {
                        continue;
                    }
                    $term_obj  = get_term_by( 'slug', $attr_val, $attr_key );
                    $val_label = $term_obj ? $term_obj->name : $attr_val;

                    $var_name_parts[]         = $val_label;
                    $clean_key                = str_replace( 'pa_', '', $attr_key );
                    $var_values[ $clean_key ] = $attr_val;
                }

                $var_price      = $var_obj->get_regular_price();
                $var_price      = ( '' !== $var_price ) ? (float) $var_price : 0;
                $var_sale_price = $var_obj->get_sale_price();
                $var_sale_price = ( '' !== $var_sale_price ) ? (float) $var_sale_price : null;

                $variation_item = array(
                    'name'        => implode( ', ', $var_name_parts ),
                    'price'       => $var_price,
                    // Woo variation id — lets the chat widget AJAX-add the exact
                    // variation the shopper picked in the product card.
                    'external_id' => (string) $var['variation_id'],
                );

                if ( null !== $var_sale_price ) {
                    $variation_item['sale_price'] = $var_sale_price;
                }

                if ( ! empty( $var_values ) ) {
                    $variation_item['values'] = $var_values;
                }

                $variations[] = $variation_item;
            }

            if ( empty( $variations ) ) {
                $variations = null;
            }
        }
    }

    // --- Build item ---
    $item = array(
        'external_id' => (string) $product_id,
        'category'    => $category,
        'subcategory' => $subcategory,
        'name'        => $product->get_name(),
        'description' => $description,
        'price'       => $price,
    );

    if ( null !== $sale_price ) {
        $item['sale_price'] = $sale_price;
    }

    $item['currency']     = $currency;
    $item['weight']       = $weight;
    $item['tags']         = $tags_list;
    $item['photo_url']    = $photo_url;
    $item['product_url']  = get_permalink( $product_id );
    $item['variations']   = $variations;
    $item['is_available'] = $simple_stock ? true : $product->is_in_stock();
    $item['sort_order']   = (int) $product->get_menu_order();

    return $item;
}

/**
 * URL of a product photo, small enough to belong in a chat message.
 *
 * The widget renders product photos in small cards, so the full-size original —
 * often several megabytes of shop photography — is wasted bandwidth on every
 * message that mentions the product. The exported size is therefore the WordPress
 * 'thumbnail' (150×150, cropped on a default install), and two things WordPress
 * does not handle on its own are handled here:
 *
 * - `wp_get_attachment_image_url()` quietly falls back to the **original** when
 *   the requested size was never generated. Catalogs filled by a CSV importer or
 *   a store migration routinely carry no sub-sizes at all, so every product came
 *   out full-size and nothing said so.
 * - A missing size is generated once, on the spot, and recorded in the
 *   attachment metadata — so the next export, and the storefront, reuse it.
 *
 * Falls back through the sizes a store is most likely to already have and, in
 * the last resort, the original: a 120px original has nothing smaller to offer.
 *
 * @param int        $image_id Attachment id.
 * @param WC_Product $product  Product being exported.
 * @return string|null
 */
function w24_product_image_url( $image_id, $product ) {
    /**
     * Registered image size to export.
     *
     * @param string     $size    Registered image size name.
     * @param WC_Product $product Product being exported.
     */
    $size = apply_filters( 'waiter24_export_image_size', 'thumbnail', $product );

    $candidates = array_unique( array( $size, 'thumbnail', 'woocommerce_thumbnail', 'medium' ) );

    foreach ( $candidates as $candidate ) {
        $url = w24_intermediate_image_url( $image_id, $candidate );

        if ( $url ) {
            return $url;
        }
    }

    $original = wp_get_attachment_url( $image_id );

    return $original ? $original : null;
}

/**
 * URL of a genuinely resized copy — never the original dressed up as one.
 *
 * @param int    $image_id Attachment id.
 * @param string $size     Registered image size name.
 * @return string|null Null when this size is not available for this image.
 */
function w24_intermediate_image_url( $image_id, $size ) {
    $src = wp_get_attachment_image_src( $image_id, $size );

    // The fourth element is WordPress saying whether it really had a resized
    // copy; false means it handed back the original instead.
    if ( is_array( $src ) && ! empty( $src[3] ) && ! empty( $src[0] ) ) {
        return $src[0];
    }

    return w24_generate_intermediate_size( $image_id, $size );
}

/**
 * Make the missing sub-size for an image the store never generated one for.
 *
 * @param int    $image_id Attachment id.
 * @param string $size     Registered image size name.
 * @return string|null URL of the new file, or null when it could not be made.
 */
function w24_generate_intermediate_size( $image_id, $size ) {
    /**
     * Whether the export may generate image sizes the store is missing.
     *
     * Turning this off means such products export at full size again.
     *
     * @param bool   $allowed  Whether generating is allowed.
     * @param int    $image_id Attachment id.
     * @param string $size     Registered image size name.
     */
    if ( ! apply_filters( 'waiter24_generate_missing_image_sizes', true, $image_id, $size ) ) {
        return null;
    }

    $registered = wp_get_registered_image_subsizes();

    if ( ! isset( $registered[ $size ] ) ) {
        return null;
    }

    // Vector and animated sources are left alone: rasterising an SVG gains
    // nothing, and resizing a GIF drops its animation.
    if ( in_array( get_post_mime_type( $image_id ), array( 'image/svg+xml', 'image/gif' ), true ) ) {
        return null;
    }

    $file = get_attached_file( $image_id );

    if ( ! $file || ! file_exists( $file ) ) {
        return null;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';

    $resized = image_make_intermediate_size(
        $file,
        (int) $registered[ $size ]['width'],
        (int) $registered[ $size ]['height'],
        (bool) $registered[ $size ]['crop']
    );

    // False means the original is already no bigger than the size asked for —
    // there is nothing to shrink, and the original is the small file.
    if ( ! is_array( $resized ) || empty( $resized['file'] ) ) {
        return null;
    }

    $meta = wp_get_attachment_metadata( $image_id );

    if ( is_array( $meta ) ) {
        if ( ! isset( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
            $meta['sizes'] = array();
        }

        $meta['sizes'][ $size ] = $resized;
        wp_update_attachment_metadata( $image_id, $meta );

        $src = wp_get_attachment_image_src( $image_id, $size );

        if ( is_array( $src ) && ! empty( $src[3] ) && ! empty( $src[0] ) ) {
            return $src[0];
        }
    }

    // Nothing to register the new file in (an attachment with no metadata), so
    // its URL is built from the original's — they share a directory.
    $original = wp_get_attachment_url( $image_id );

    if ( ! $original ) {
        return null;
    }

    return trailingslashit( dirname( $original ) ) . wp_basename( $resized['file'] );
}

/**
 * Push the payload to the Waiter24 import endpoint.
 *
 * Transient failures are retried, because one unlucky batch used to kill the
 * whole export: any error marks the run failed, and the store then waits for the
 * next schedule with a half-sent session that never closes. A connection reset,
 * a gateway timeout or a 5xx while the service restarts is over in seconds, so
 * the batch is simply sent again with a short backoff. Answers that will not
 * change on a retry — a bad token (401/403), a payload the service rejects
 * (422) — return immediately.
 *
 * @param array $data Data to send.
 * @return true|string true when the push succeeded (HTTP 2xx), otherwise a
 *                     human-readable error message.
 */
function w24_save_and_notify( $data ) {
    $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );

    if ( false === $json ) {
        return __( 'Failed to encode the catalog as JSON.', 'waiter24-ai-assistant-for-woocommerce' );
    }

    $settings     = w24_get_settings();
    $import_token = trim( (string) $settings['import_token'] );

    if ( '' === $import_token ) {
        return __( 'Import Token is empty. Paste it from the Menu auto-import block in your Waiter24 dashboard (Site Integration).', 'waiter24-ai-assistant-for-woocommerce' );
    }

    /**
     * Filters how many times one batch is sent before the export gives up.
     *
     * @param int $attempts Total attempts, including the first one.
     */
    $attempts = (int) apply_filters( 'waiter24_export_push_attempts', 3 );
    $attempts = max( 1, min( 5, $attempts ) );
    $error    = '';

    for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
        if ( $attempt > 1 ) {
            // Short and bounded: this runs inside a batch that is already
            // time-boxed (see w24_chunk_budget_seconds), so waiting minutes for
            // a service that is down would cost more than failing does.
            sleep( 2 * ( $attempt - 1 ) );
        }

        $response = wp_remote_post(
            W24_IMPORT_URL,
            array(
                'timeout'   => 30,
                'sslverify' => true,
                'headers'   => array(
                    'Authorization' => 'Bearer ' . $import_token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body'      => $json,
            )
        );

        if ( is_wp_error( $response ) ) {
            /* translators: %s: connection error message. */
            $error = sprintf( __( 'Could not reach Waiter24: %s', 'waiter24-ai-assistant-for-woocommerce' ), $response->get_error_message() );
            continue;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( $code >= 200 && $code < 300 ) {
            return true;
        }

        if ( 401 === $code || 403 === $code ) {
            return __( 'Authentication failed (HTTP 401/403). Check that the Import Token is correct — it is NOT the Unique (widget) Key.', 'waiter24-ai-assistant-for-woocommerce' );
        }

        $body = wp_remote_retrieve_body( $response );
        /* translators: 1: HTTP status code, 2: response body excerpt. */
        $error = sprintf( __( 'Import endpoint returned HTTP %1$d: %2$s', 'waiter24-ai-assistant-for-woocommerce' ), $code, mb_substr( (string) $body, 0, 300 ) );

        // 4xx other than "too many requests" is a verdict on this payload, not a
        // bad moment: sending it again would get the same answer.
        if ( $code < 500 && 429 !== $code ) {
            return $error;
        }
    }

    return $error;
}

/**
 * =============================================
 *  ADD-ONS (Waiter24 quantity-priced product extras)
 * =============================================
 * The chat widget's native add-to-cart AJAX call (wc-ajax=add_to_cart) gains
 * one additive POST field, `waiter24_addons` — a JSON array of
 * {name, price, qty}. WooCommerce's own handler only reads
 * product_id/quantity/variation_id, so the filters below are what attach the
 * add-ons to the cart line: stash them as cart item data, fold their price into
 * the line total, display them under the line item, and copy them onto the order
 * so the store can actually fulfil them.
 */

/**
 * Normalize a client-supplied add-on list.
 *
 * Every value here arrives from the shopper's browser, so it is untrusted: a
 * negative price would discount the line (checkout below list price), and an
 * unbounded qty/name/count would let a visitor bloat the cart session. Prices
 * are therefore clamped to >= 0 and quantities to a sane range. Stores that
 * want to verify add-ons against their own source of truth can do so through
 * the `waiter24_cart_line_addons` filter.
 *
 * @param mixed $raw Decoded add-on list.
 * @return array<int, array<string, mixed>>
 */
function w24_sanitize_addons( $raw ) {
    if ( ! is_array( $raw ) ) {
        return array();
    }

    $max_addons = (int) apply_filters( 'waiter24_max_addons_per_line', 20 );
    $max_price  = (float) apply_filters( 'waiter24_max_addon_price', 0 ); // 0 = no ceiling.
    $out        = array();

    foreach ( $raw as $addon ) {
        if ( ! is_array( $addon ) || ! isset( $addon['name'] ) || ! is_scalar( $addon['name'] ) ) {
            continue;
        }

        $name = mb_substr( sanitize_text_field( (string) $addon['name'] ), 0, 120 );
        if ( '' === $name ) {
            continue;
        }

        $price = ( isset( $addon['price'] ) && is_numeric( $addon['price'] ) ) ? (float) $addon['price'] : 0.0;
        $price = max( 0.0, $price );
        if ( $max_price > 0 ) {
            $price = min( $max_price, $price );
        }

        $qty = ( isset( $addon['qty'] ) && is_numeric( $addon['qty'] ) ) ? (int) $addon['qty'] : 1;
        $qty = min( 99, max( 1, $qty ) );

        $out[] = array(
            'name'  => $name,
            'price' => $price,
            'qty'   => $qty,
        );

        if ( count( $out ) >= $max_addons ) {
            break;
        }
    }

    return $out;
}

/**
 * Sum an add-on list.
 *
 * @param array $addons Sanitized add-ons.
 * @return float
 */
function w24_addons_total( $addons ) {
    $total = 0.0;

    foreach ( (array) $addons as $addon ) {
        if ( isset( $addon['price'], $addon['qty'] ) ) {
            $total += (float) $addon['price'] * (int) $addon['qty'];
        }
    }

    return $total;
}

add_filter( 'woocommerce_add_cart_item_data', 'w24_attach_addons_to_cart_item', 10, 2 );

function w24_attach_addons_to_cart_item( $cart_item_data, $product_id ) {
    // No nonce check: this rides on WooCommerce's own public add-to-cart
    // request, which is nonce-free by design. The payload is treated as
    // untrusted input and normalized in w24_sanitize_addons().
    // phpcs:disable WordPress.Security.NonceVerification.Missing
    if ( ! isset( $_POST['waiter24_addons'] ) || ! is_string( $_POST['waiter24_addons'] ) ) {
        return $cart_item_data;
    }

    $decoded = json_decode( wp_unslash( $_POST['waiter24_addons'] ), true );
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    $addons = w24_sanitize_addons( $decoded );

    /**
     * Filter the add-ons accepted onto a cart line.
     *
     * Return an empty array to reject them, or replace the prices with values
     * read from your own source of truth.
     *
     * @param array $addons     Sanitized add-ons.
     * @param int   $product_id Product being added.
     */
    $addons = (array) apply_filters( 'waiter24_cart_line_addons', $addons, $product_id );

    if ( ! empty( $addons ) ) {
        // Nothing else is needed to keep this a distinct cart line: WooCommerce
        // hashes the full cart_item_data (via generate_cart_id()) to decide
        // whether an add matches an existing line, so a different add-on
        // combination already produces a different line automatically —
        // and an identical one still correctly merges/increments.
        $cart_item_data['waiter24_addons'] = $addons;
    }

    return $cart_item_data;
}

/**
 * Remember the product's pristine price before add-ons are folded in.
 *
 * Runs exactly once per cart line per request (on add, and on session load),
 * which is what makes the price adjustment below idempotent.
 *
 * @param array $cart_item Cart item.
 * @return array
 */
function w24_stash_base_price( $cart_item ) {
    if ( ! empty( $cart_item['waiter24_addons'] ) && isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) {
        $cart_item['w24_base_price'] = (float) $cart_item['data']->get_price();
    }

    return $cart_item;
}

add_filter( 'woocommerce_add_cart_item', 'w24_stash_base_price', 20 );

add_filter( 'woocommerce_get_cart_item_from_session', 'w24_restore_addons_from_session', 20, 2 );

function w24_restore_addons_from_session( $cart_item, $values ) {
    if ( empty( $values['waiter24_addons'] ) ) {
        return $cart_item;
    }

    // Re-normalize: the session may hold data written by an older version.
    $cart_item['waiter24_addons'] = w24_sanitize_addons( $values['waiter24_addons'] );

    return w24_stash_base_price( $cart_item );
}

add_action( 'woocommerce_before_calculate_totals', 'w24_apply_addons_price', 20 );

function w24_apply_addons_price( $cart ) {
    if ( ! $cart instanceof WC_Cart ) {
        return;
    }

    if ( is_admin() && ! wp_doing_ajax() ) {
        return;
    }

    foreach ( $cart->get_cart() as $cart_item ) {
        if ( empty( $cart_item['waiter24_addons'] ) || ! isset( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
            continue;
        }

        $addon_total = w24_addons_total( $cart_item['waiter24_addons'] );
        if ( $addon_total <= 0 ) {
            continue;
        }

        // Set the price absolutely from the stashed base, never additively:
        // woocommerce_before_calculate_totals can fire several times in one
        // request, and `get_price() + addons` would compound on each pass.
        // The base comes from get_price() (not get_regular_price()), so an
        // active sale is honoured — add-ons stack on what the shopper pays.
        $base = isset( $cart_item['w24_base_price'] )
            ? (float) $cart_item['w24_base_price']
            : (float) $cart_item['data']->get_price();

        $cart_item['data']->set_price( $base + $addon_total );
    }
}

add_filter( 'woocommerce_get_item_data', 'w24_display_addons_in_cart', 10, 2 );

function w24_display_addons_in_cart( $item_data, $cart_item ) {
    if ( empty( $cart_item['waiter24_addons'] ) ) {
        return $item_data;
    }

    foreach ( $cart_item['waiter24_addons'] as $addon ) {
        $item_data[] = array(
            'name'  => $addon['name'],
            'value' => ( (int) $addon['qty'] > 1 ) ? sprintf( '×%d', (int) $addon['qty'] ) : ' ',
        );
    }

    return $item_data;
}

/**
 * Copy the add-ons onto the order line item, so they appear on the order the
 * store actually has to prepare and ship.
 *
 * @param WC_Order_Item_Product $item          Order line item.
 * @param string                $cart_item_key Cart item key.
 * @param array                 $values        Cart item data.
 */
add_action( 'woocommerce_checkout_create_order_line_item', 'w24_save_addons_to_order_item', 10, 3 );

function w24_save_addons_to_order_item( $item, $cart_item_key, $values ) {
    if ( empty( $values['waiter24_addons'] ) ) {
        return;
    }

    foreach ( $values['waiter24_addons'] as $addon ) {
        $item->add_meta_data(
            $addon['name'],
            ( (int) $addon['qty'] > 1 ) ? sprintf( '×%d', (int) $addon['qty'] ) : ' ',
            true
        );
    }
}
