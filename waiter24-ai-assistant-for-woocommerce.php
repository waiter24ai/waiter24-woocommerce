<?php
/**
 * Plugin Name:          Waiter24 AI Assistant for WooCommerce
 * Plugin URI:           https://waiter24.ai/
 * Description:          Syncs your WooCommerce catalog to your Waiter24 account and adds the Waiter24 AI chat assistant to the storefront, so shoppers can ask questions and add products to the real WooCommerce cart from inside the chat.
 * Version:              1.10.1
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
define( 'W24_EXPORT_VERSION', '1.10.1' );
define( 'W24_CRON_HOOK', 'waiter24_scheduled_export' );
define( 'W24_CHUNK_HOOK', 'waiter24_export_chunk' ); // One background slice of a running export.
define( 'W24_OPTION_KEY', 'waiter24_export_settings' );
define( 'W24_LAST_RUN_KEY', 'waiter24_export_last_run' );
define( 'W24_PROGRESS_KEY', 'waiter24_export_progress' );
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
        'export_period'     => 'daily',
        'enable_widget'     => 0,
        'demo_mode'         => 0, // When on, the widget only loads on URLs carrying the demo param.
        'simple_stock_mode' => 1, // Enabled by default.
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
 * Make sure the recurring export is scheduled, without ever firing immediately:
 * a run scheduled at time() would go out before the store owner has pasted the
 * Import Token and would only produce a failed push.
 */
function w24_ensure_cron() {
    if ( wp_next_scheduled( W24_CRON_HOOK ) ) {
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
    $new_period = ( is_array( $value ) && isset( $value['export_period'] ) ) ? $value['export_period'] : 'daily';

    if ( $old_period === $new_period && wp_next_scheduled( W24_CRON_HOOK ) ) {
        return;
    }

    wp_clear_scheduled_hook( W24_CRON_HOOK );
    wp_schedule_event( time() + HOUR_IN_SECONDS, $new_period, W24_CRON_HOOK );
}

/**
 * =============================================
 *  ADMIN MENU & SETTINGS PAGE
 * =============================================
 */
add_action( 'admin_menu', 'w24_add_admin_menu' );
add_action( 'admin_init', 'w24_register_settings' );

function w24_add_admin_menu() {
    add_submenu_page(
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

    $allowed_periods            = array( 'daily', 'weekly', 'monthly' );
    $sanitized['export_period'] = ( isset( $input['export_period'] ) && in_array( $input['export_period'], $allowed_periods, true ) )
        ? $input['export_period']
        : 'daily';

    $sanitized['enable_widget']     = empty( $input['enable_widget'] ) ? 0 : 1;
    $sanitized['demo_mode']         = empty( $input['demo_mode'] ) ? 0 : 1;
    $sanitized['simple_stock_mode'] = empty( $input['simple_stock_mode'] ) ? 0 : 1;

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

    $manual_result = '';
    $manual_error  = '';

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect above.
    $flag = isset( $_GET['w24_export'] ) ? sanitize_key( wp_unslash( $_GET['w24_export'] ) ) : '';

    if ( 'started' === $flag ) {
        $manual_result = 'success';
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

        <?php if ( 'success' === $manual_result ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Export started.', 'waiter24-ai-assistant-for-woocommerce' ); ?></strong>
                    <?php esc_html_e( 'It runs in the background, a batch at a time, so a large catalog cannot time out. Progress is shown below.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
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
                            <?php esc_html_e( 'The 48-character Import Token from your Waiter24 dashboard (Widget → Menu auto-import tab). This is NOT the Unique (widget) Key above — using the wrong value returns a 401 error.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Export Period -->
                <tr>
                    <th scope="row">
                        <label for="w24_export_period"><?php esc_html_e( 'Export Period', 'waiter24-ai-assistant-for-woocommerce' ); ?></label>
                    </th>
                    <td>
                        <select id="w24_export_period" name="<?php echo esc_attr( W24_OPTION_KEY ); ?>[export_period]">
                            <option value="daily"   <?php selected( $export_period, 'daily' ); ?>><?php esc_html_e( 'Daily', 'waiter24-ai-assistant-for-woocommerce' ); ?></option>
                            <option value="weekly"  <?php selected( $export_period, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'waiter24-ai-assistant-for-woocommerce' ); ?></option>
                            <option value="monthly" <?php selected( $export_period, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'waiter24-ai-assistant-for-woocommerce' ); ?></option>
                        </select>
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
                <span id="w24-export-count">
                    <?php
                    printf(
                        /* translators: %d: number of products sent so far. */
                        esc_html( _n( '%d product sent so far.', '%d products sent so far.', (int) $progress['sent'], 'waiter24-ai-assistant-for-woocommerce' ) ),
                        (int) $progress['sent']
                    );
                    ?>
                </span>
                <br />
                <?php esc_html_e( 'Keep this page open until the export finishes: if your site does not run background tasks, this page pushes the batches itself.', 'waiter24-ai-assistant-for-woocommerce' ); ?>
            </p>
            <script>
                ( function () {
                    var endpoint = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
                    var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'waiter24_export_step' ) ); ?>;
                    var counter  = document.getElementById( 'w24-export-count' );

                    // Sequential, never on a timer: one call may spend a while
                    // pushing a batch, and overlapping calls would race it.
                    function step() {
                        var body = new FormData();
                        body.append( 'action', 'waiter24_export_step' );
                        body.append( 'nonce', nonce );

                        fetch( endpoint, { method: 'POST', body: body, credentials: 'same-origin' } )
                            .then( function ( r ) { return r.json(); } )
                            .then( function ( res ) {
                                if ( ! res || ! res.success ) {
                                    window.location.reload();
                                    return;
                                }

                                if ( counter && res.data.label ) {
                                    counter.textContent = res.data.label;
                                }

                                if ( ! res.data.running ) {
                                    window.location.reload();
                                    return;
                                }

                                // Pushing the batches ourselves: come straight
                                // back for the next one. Merely watching a
                                // working queue: check in every few seconds.
                                setTimeout( step, res.data.driving ? 250 : 3000 );
                            } )
                            .catch( function () { setTimeout( step, 5000 ); } );
                    }

                    step();
                } )();
            </script>
        <?php endif; ?>

        <?php if ( $next_scheduled ) : ?>
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
add_action( W24_CRON_HOOK, 'w24_start_export' );
add_action( W24_CHUNK_HOOK, 'w24_run_export_chunk', 10, 2 );

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
            __( 'Import Token is empty. Paste it from the Menu auto-import tab in your Waiter24 dashboard.', 'waiter24-ai-assistant-for-woocommerce' ),
            0
        );
    }

    if ( w24_export_is_running() ) {
        return __( 'An export is already running.', 'waiter24-ai-assistant-for-woocommerce' );
    }

    $session = w24_new_session_id();

    w24_set_progress(
        array(
            'status'  => 'running',
            'session' => $session,
            'page'    => 1,
            'sent'    => 0,
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
 * Export one slice of the catalog: read a page of products, push it, then queue
 * the next page — or close the session when the catalog runs out.
 *
 * @param string $session    Import-session id shared by every slice of this run.
 * @param int    $page       1-based page of the product query.
 * @param bool   $background Whether to queue the next slice (false = inline run).
 * @return bool Whether another slice is pending.
 */
function w24_run_export_chunk( $session, $page, $background = true ) {
    $progress = w24_export_progress();
    $page     = max( 1, (int) $page );

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

    $settings     = w24_get_settings();
    $simple_stock = ! empty( $settings['simple_stock_mode'] );
    $per_page     = w24_chunk_size();
    $currency     = get_woocommerce_currency();

    wp_raise_memory_limit( 'admin' );

    // Ordered by ID, which is unique and stable: paging over a non-unique key
    // like menu_order can shuffle ties between queries and skip a product, and
    // a product missed by every slice would be hidden when the session closes.
    // Display order is carried by each item's own sort_order field anyway.
    $products = wc_get_products(
        array(
            'status'  => 'publish',
            'limit'   => $per_page,
            'page'    => $page,
            'orderby' => 'ID',
            'order'   => 'ASC',
        )
    );

    if ( ! is_array( $products ) ) {
        $products = array();
    }

    $items = array();

    foreach ( $products as $product ) {
        $items[] = w24_build_item( $product, $currency, $simple_stock );
    }

    $fetched = count( $products );
    unset( $products );

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

    $sent = (int) $progress['sent'] + $fetched;

    w24_set_progress(
        array(
            'status'  => 'running',
            'session' => $session,
            'page'    => $page + 1,
            'sent'    => $sent,
            'trigger' => $progress['trigger'],
            'driver'  => $progress['driver'],
            'started' => $progress['started'],
        )
    );

    // A short page means the catalog is exhausted.
    if ( $fetched < $per_page ) {
        w24_finalize_export( $session, $sent );

        return false;
    }

    if ( $background ) {
        as_enqueue_async_action( W24_CHUNK_HOOK, array( $session, $page + 1 ), 'waiter24' );
    }

    return true;
}

/**
 * Drive a running export from the settings page.
 *
 * Action Scheduler needs the site's background tasks to actually fire, and on
 * plenty of hosts they do not: WP-Cron is disabled, or loopback requests are
 * blocked, and the slice sits in the queue as "pending" forever. So the settings
 * page keeps asking — and if the queue has not moved the export for a while, it
 * runs the next slice itself, inside this request.
 *
 * One slice per call, and the page-match guard in w24_run_export_chunk() keeps
 * this from colliding with a queue runner that wakes up late.
 */
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

    $sent = (int) $progress['sent'];

    wp_send_json_success(
        array(
            'status'  => $progress['status'],
            'sent'    => $sent,
            'running' => ( 'running' === $progress['status'] ),
            'driving' => ( 'page' === $progress['driver'] ),
            // Pluralized here rather than in the browser: languages with more
            // than two plural forms cannot be served by a JS string swap.
            'label'   => sprintf(
                /* translators: %d: number of products sent so far. */
                _n( '%d product sent so far.', '%d products sent so far.', $sent, 'waiter24-ai-assistant-for-woocommerce' ),
                $sent
            ),
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

    return w24_record_run( $result, (int) $sent );
}

/**
 * How many products one slice carries. Small enough that building and pushing it
 * fits comfortably inside one PHP request on modest hosting.
 *
 * @return int
 */
function w24_chunk_size() {
    // 50 keeps one slice comfortably inside even a slow shared host's request
    // limit — the settings page runs slices itself when the site's background
    // queue is dead, and those go through the same gateway that timed out on
    // the old single-shot export.
    $size = (int) apply_filters( 'waiter24_export_batch_size', 50 );

    return max( 10, min( 500, $size ) );
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
        'status'  => 'idle',  // idle | running | done | error
        'session' => '',
        'page'    => 1,
        'sent'    => 0,
        'trigger' => 'cron',
        'driver'  => 'queue', // queue | page — who is pushing the slices.
        'started' => 0,
        'updated' => 0,
    );

    $saved = get_option( W24_PROGRESS_KEY, array() );

    return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
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
 * @param true|string $result Export result.
 * @param int         $count  Number of exported products.
 * @return true|string The untouched $result, so callers can return it directly.
 */
function w24_record_run( $result, $count ) {
    update_option(
        W24_LAST_RUN_KEY,
        array(
            'time'    => time(),
            'items'   => (int) $count,
            'ok'      => ( true === $result ),
            'message' => is_string( $result ) ? $result : '',
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
    // Export the "medium" thumbnail rather than the full-size original: the
    // widget renders product photos small, so the lighter file loads faster with
    // no visible quality loss. WordPress falls back to the full size when the
    // 'medium' image was never generated. Filterable so a site owner can pick
    // another registered size (e.g. 'woocommerce_thumbnail' or 'large').
    $image_id  = $product->get_image_id();
    $photo_url = null;
    if ( $image_id ) {
        $image_size = apply_filters( 'waiter24_export_image_size', 'medium', $product );
        $photo_url  = wp_get_attachment_image_url( $image_id, $image_size );
        if ( ! $photo_url ) {
            $photo_url = wp_get_attachment_url( $image_id );
        }
    }

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
 * Push the payload to the Waiter24 import endpoint.
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
        return __( 'Import Token is empty. Paste it from the Menu auto-import tab in your Waiter24 dashboard.', 'waiter24-ai-assistant-for-woocommerce' );
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
        return sprintf( __( 'Could not reach Waiter24: %s', 'waiter24-ai-assistant-for-woocommerce' ), $response->get_error_message() );
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
    return sprintf( __( 'Import endpoint returned HTTP %1$d: %2$s', 'waiter24-ai-assistant-for-woocommerce' ), $code, mb_substr( (string) $body, 0, 300 ) );
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
