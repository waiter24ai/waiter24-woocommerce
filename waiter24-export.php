<?php
/**
 * Plugin Name: Waiter24 - export WooCommerce
 * Description: Export WooCommerce products to JSON on schedule or manually, push them to the Waiter24 import endpoint, and manage a frontend chat widget.
 * Version: 1.7.0
 * Author: Developer
 * Requires Plugins: woocommerce
 * Text Domain: waiter24-export
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =============================================
 *  CONSTANTS
 * =============================================
 */
define( 'W24_EXPORT_VERSION', '1.7.0' );
define( 'W24_EXPORT_DIR', WP_CONTENT_DIR . '/uploads/waiter24' );
define( 'W24_EXPORT_FILE', W24_EXPORT_DIR . '/export.json' );
define( 'W24_CRON_HOOK', 'waiter24_scheduled_export' );
define( 'W24_OPTION_KEY', 'waiter24_export_settings' );
// Production Waiter24 host. For local testing against an OSPanel dev host
// (http://waiter.loc) override these and set sslverify => false in
// w24_save_and_notify(), since the dev host has no certificate.
define( 'W24_IMPORT_URL', 'https://waiter24.ai/api/integrations/menu' );
define( 'W24_WIDGET_URL', 'https://waiter24.ai/widget.js' );
define( 'W24_DEMO_PARAM', 'waiter24_demo' ); // GET param that reveals the widget in demo mode

/**
 * =============================================
 *  LOAD TEXT DOMAIN
 * =============================================
 */
add_action( 'init', 'w24_load_textdomain' );

function w24_load_textdomain() {
    load_plugin_textdomain(
        'waiter24-export',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}

/**
 * =============================================
 *  DEFAULT SETTINGS
 * =============================================
 */
function w24_get_defaults() {
    return array(
        'unique_key'        => '', // public widget key (data-key on the chat script)
        'import_token'      => '', // secret token authenticating the menu push
        'export_period'     => 'daily',
        'enable_widget'     => 0,
        'demo_mode'         => 0, // when on, the widget only loads on URLs carrying the demo param
        'simple_stock_mode' => 1, // enabled by default
    );
}

/**
 * Helper: merge saved settings with defaults.
 */
function w24_get_settings() {
    $defaults = w24_get_defaults();
    $saved    = get_option( W24_OPTION_KEY, array() );
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
    // Create upload directory
    if ( ! file_exists( W24_EXPORT_DIR ) ) {
        wp_mkdir_p( W24_EXPORT_DIR );
    }

    // Set default options if not present
    if ( false === get_option( W24_OPTION_KEY ) ) {
        update_option( W24_OPTION_KEY, w24_get_defaults() );
    }

    // Schedule cron
    $settings = w24_get_settings();
    if ( ! wp_next_scheduled( W24_CRON_HOOK ) ) {
        wp_schedule_event( time(), $settings['export_period'], W24_CRON_HOOK );
    }
}

function w24_deactivate() {
    wp_clear_scheduled_hook( W24_CRON_HOOK );
}

/**
 * =============================================
 *  CUSTOM CRON SCHEDULES (weekly & monthly)
 * =============================================
 */
add_filter( 'cron_schedules', 'w24_custom_cron_schedules' );

function w24_custom_cron_schedules( $schedules ) {
    $schedules['weekly'] = array(
        'interval' => 604800,
        'display'  => __( 'Once Weekly', 'waiter24-export' ),
    );
    $schedules['monthly'] = array(
        'interval' => 2592000,
        'display'  => __( 'Once Monthly', 'waiter24-export' ),
    );
    return $schedules;
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
        __( 'Waiter24 — Export Woo', 'waiter24-export' ),
        __( 'Waiter24 Export  Woo', 'waiter24-export' ),
        'manage_woocommerce',
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
        . esc_html__( 'Settings', 'waiter24-export' )
        . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

function w24_register_settings() {
    register_setting( 'waiter24_settings_group', W24_OPTION_KEY, 'w24_sanitize_settings' );
}

/**
 * Sanitize settings on save.
 */
function w24_sanitize_settings( $input ) {
    $sanitized = array();

    $sanitized['unique_key'] = isset( $input['unique_key'] )
        ? sanitize_text_field( $input['unique_key'] )
        : '';

    $sanitized['import_token'] = isset( $input['import_token'] )
        ? sanitize_text_field( $input['import_token'] )
        : '';

    $allowed_periods = array( 'daily', 'weekly', 'monthly' );
    $sanitized['export_period'] = ( isset( $input['export_period'] ) && in_array( $input['export_period'], $allowed_periods, true ) )
        ? $input['export_period']
        : 'daily';

    $sanitized['enable_widget']     = ! empty( $input['enable_widget'] ) ? 1 : 0;
    $sanitized['demo_mode']         = ! empty( $input['demo_mode'] ) ? 1 : 0;
    $sanitized['simple_stock_mode'] = ! empty( $input['simple_stock_mode'] ) ? 1 : 0;

    // Reschedule cron when period changes
    wp_clear_scheduled_hook( W24_CRON_HOOK );
    wp_schedule_event( time(), $sanitized['export_period'], W24_CRON_HOOK );

    return $sanitized;
}

/**
 * Render the settings page.
 */
function w24_render_settings_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'waiter24-export' ) );
    }

    // Handle manual export
    $manual_result = '';
    $manual_error  = '';
    if (
        isset( $_POST['waiter24_manual_export'] )
        && check_admin_referer( 'waiter24_manual_export_action', 'waiter24_manual_export_nonce' )
    ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'waiter24-export' ) );
        }
        $result = w24_run_export();
        if ( true === $result ) {
            $manual_result = 'success';
        } else {
            $manual_result = 'error';
            $manual_error  = is_string( $result ) ? $result : '';
        }
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
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <?php if ( 'success' === $manual_result ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Export completed successfully!', 'waiter24-export' ); ?></strong>
                    <?php esc_html_e( 'The file has been saved and the notification sent.', 'waiter24-export' ); ?>
                </p>
            </div>
        <?php elseif ( 'error' === $manual_result ) : ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Export failed.', 'waiter24-export' ); ?></strong>
                    <?php
                    if ( '' !== $manual_error ) {
                        echo esc_html( $manual_error );
                    } else {
                        esc_html_e( 'Make sure WooCommerce is active and products exist.', 'waiter24-export' );
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
                        <label for="w24_unique_key"><?php esc_html_e( 'Unique Key', 'waiter24-export' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="w24_unique_key"
                            name="<?php echo esc_attr( W24_OPTION_KEY ); ?>[unique_key]"
                            value="<?php echo esc_attr( $unique_key ); ?>"
                            class="regular-text"
                            placeholder="<?php esc_attr_e( 'Enter unique key', 'waiter24-export' ); ?>"
                        />
                        <p class="description">
                            <?php esc_html_e( 'Public widget key — used to load the chat widget on your storefront.', 'waiter24-export' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Import Token -->
                <tr>
                    <th scope="row">
                        <label for="w24_import_token"><?php esc_html_e( 'Import Token', 'waiter24-export' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="w24_import_token"
                            name="<?php echo esc_attr( W24_OPTION_KEY ); ?>[import_token]"
                            value="<?php echo esc_attr( $import_token ); ?>"
                            class="regular-text"
                            autocomplete="off"
                            placeholder="<?php esc_attr_e( 'Enter import token', 'waiter24-export' ); ?>"
                        />
                        <p class="description">
                            <?php esc_html_e( 'The 48-character Import Token from your Waiter24 dashboard (Widget → Menu auto-import tab). This is NOT the Unique (widget) Key above — using the wrong value returns a 401 error.', 'waiter24-export' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Export Period -->
                <tr>
                    <th scope="row">
                        <label for="w24_export_period"><?php esc_html_e( 'Export Period', 'waiter24-export' ); ?></label>
                    </th>
                    <td>
                        <select id="w24_export_period" name="<?php echo esc_attr( W24_OPTION_KEY ); ?>[export_period]">
                            <option value="daily"   <?php selected( $export_period, 'daily' ); ?>><?php esc_html_e( 'Daily', 'waiter24-export' ); ?></option>
                            <option value="weekly"  <?php selected( $export_period, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'waiter24-export' ); ?></option>
                            <option value="monthly" <?php selected( $export_period, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'waiter24-export' ); ?></option>
                        </select>
                    </td>
                </tr>

                <!-- Enable Chat Widget -->
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Enable Chat Widget', 'waiter24-export' ); ?>
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
                            <?php esc_html_e( 'Insert chat widget script on all frontend pages', 'waiter24-export' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'When enabled, the Waiter24 chat widget script will be loaded before the closing &lt;/body&gt; tag.', 'waiter24-export' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Demo Mode -->
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Demo Mode', 'waiter24-export' ); ?>
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
                            <?php esc_html_e( 'Show the chat widget only to visitors who arrive via the demo link', 'waiter24-export' ); ?>
                        </label>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: the demo GET parameter name. */
                                esc_html__( 'When enabled, the widget stays hidden for regular visitors and appears only on URLs carrying the "%s" parameter. The parameter is then remembered for the browsing session and re-applied to links the assistant opens, so the chat stays visible while you click around.', 'waiter24-export' ),
                                esc_html( W24_DEMO_PARAM )
                            );
                            ?>
                        </p>
                        <p class="description">
                            <strong><?php esc_html_e( 'Open demo:', 'waiter24-export' ); ?></strong>
                            <a href="<?php echo esc_url( $demo_url ); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo esc_html( $demo_url ); ?>
                            </a>
                        </p>
                    </td>
                </tr>

                <!-- Simple Stock Mode -->
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Simple Stock Mode', 'waiter24-export' ); ?>
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
                            <?php esc_html_e( 'Always mark all products as in-stock (ignore real stock data)', 'waiter24-export' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'When enabled: every product is exported as available, ignoring real stock. When disabled: real WooCommerce stock availability is used.', 'waiter24-export' ); ?>
                        </p>
                    </td>
                </tr>

            </table>
            <?php submit_button( __( 'Save Settings', 'waiter24-export' ) ); ?>
        </form>

        <hr />

        <!-- Manual Export -->
        <h2><?php esc_html_e( 'Manual Export', 'waiter24-export' ); ?></h2>
        <p class="description" style="max-width:640px">
            <strong><?php esc_html_e( 'Note:', 'waiter24-export' ); ?></strong>
            <?php esc_html_e( 'The export replaces your primary Waiter24 menu (the one marked with a star). Items missing from this store are hidden, not deleted.', 'waiter24-export' ); ?>
        </p>
        <form method="post">
            <?php wp_nonce_field( 'waiter24_manual_export_action', 'waiter24_manual_export_nonce' ); ?>
            <p>
                <button type="submit" name="waiter24_manual_export" value="1" class="button button-primary">
                    <?php esc_html_e( 'Export Now', 'waiter24-export' ); ?>
                </button>
            </p>
        </form>

        <?php if ( $next_scheduled ) : ?>
            <p class="description">
                <?php esc_html_e( 'Next scheduled export:', 'waiter24-export' ); ?>
                <strong><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_scheduled ), 'd.m.Y H:i:s' ) ); ?></strong>
            </p>
        <?php endif; ?>

        <?php if ( file_exists( W24_EXPORT_FILE ) ) : ?>
            <p class="description">
                <?php esc_html_e( 'Last export file:', 'waiter24-export' ); ?>
                <code><?php echo esc_html( W24_EXPORT_FILE ); ?></code>
                (<?php echo esc_html( size_format( filesize( W24_EXPORT_FILE ) ) ); ?>,
                <?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', filemtime( W24_EXPORT_FILE ) ), 'd.m.Y H:i:s' ) ); ?>)
            </p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * =============================================
 *  FRONTEND: CHAT WIDGET
 * =============================================
 */
add_action( 'wp_footer', 'w24_inject_widget_script' );

function w24_inject_widget_script() {
    $settings = w24_get_settings();

    if ( empty( $settings['enable_widget'] ) || empty( $settings['unique_key'] ) ) {
        return;
    }

    $demo_attr = ! empty( $settings['demo_mode'] )
        ? sprintf( ' data-demo-param="%s"', esc_attr( W24_DEMO_PARAM ) )
        : '';

    printf(
        '<script defer src="%s" data-key="%s"%s></script>' . "\n",
        esc_url( W24_WIDGET_URL ),
        esc_attr( $settings['unique_key'] ),
        $demo_attr
    );
}

/**
 * =============================================
 *  EXPORT LOGIC
 * =============================================
 */
add_action( W24_CRON_HOOK, 'w24_run_export' );

/**
 * Main export function.
 *
 * @return true|string true on success, or a human-readable error message.
 */
function w24_run_export() {
    // Verify WooCommerce is active
    if ( ! class_exists( 'WooCommerce' ) ) {
        return __( 'WooCommerce is not active.', 'waiter24-export' );
    }

    // Ensure directory exists
    if ( ! file_exists( W24_EXPORT_DIR ) ) {
        wp_mkdir_p( W24_EXPORT_DIR );
    }

    $settings     = w24_get_settings();
    $simple_stock = ! empty( $settings['simple_stock_mode'] );

    // ------------------------------------------
    //  site_config
    // ------------------------------------------
    // Note: cart_integration_enabled is deliberately NOT sent — that toggle is
    // owned by the Waiter24 tenant panel, and pushing a value here would reset
    // the tenant's choice on every scheduled export.
    $site_config = array(
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
        // lets the chat widget see what's already in the guest's cart for
        // cart-aware upsell. Toggle lives in the tenant panel (off by default).
        'cart_read_url'                 => rest_url( 'wc/store/v1/cart' ),
    );

    // ------------------------------------------
    //  items
    // ------------------------------------------
    $items = array();

    $products = wc_get_products( array(
        'status'  => 'publish',
        'limit'   => -1,
        'orderby' => 'menu_order',
        'order'   => 'ASC',
    ) );

    if ( empty( $products ) ) {
        $data = array(
            'site_config' => $site_config,
            'items'       => array(),
        );
        return w24_save_and_notify( $data );
    }

    $currency = get_woocommerce_currency();

    foreach ( $products as $product ) {
        /** @var WC_Product $product */
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
                } else {
                    if ( null === $subcategory ) {
                        $subcategory = $term->name;
                        if ( null === $category ) {
                            $parent_term = get_term( $term->parent, 'product_cat' );
                            if ( $parent_term && ! is_wp_error( $parent_term ) ) {
                                $category = $parent_term->name;
                            }
                        }
                    }
                }
            }
            if ( null === $category && ! empty( $term_ids ) ) {
                $first = get_term( $term_ids[0], 'product_cat' );
                if ( $first && ! is_wp_error( $first ) ) {
                    $category = $first->name;
                }
            }
        }

        // --- Tags ---
        $tag_ids   = $product->get_tag_ids();
        $tags_list = array();
        foreach ( $tag_ids as $tag_id ) {
            $tag = get_term( $tag_id, 'product_tag' );
            if ( $tag && ! is_wp_error( $tag ) ) {
                $tags_list[] = $tag->name;
            }
        }

        // --- Photo ---
        // Export the "medium" thumbnail rather than the full-size original: the
        // widget renders dish photos small, so the lighter file loads faster with
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

        // --- Product URL ---
        $product_url = get_permalink( $product_id );

        // --- Price ---
        $price      = $product->get_regular_price();
        $price      = '' !== $price ? (float) $price : 0;
        $sale_price = $product->get_sale_price();
        $sale_price = '' !== $sale_price ? (float) $sale_price : null;

        // --- Weight ---
        $weight = $product->get_weight();
        $weight = '' !== $weight ? $weight . get_option( 'woocommerce_weight_unit', 'kg' ) : null;

        // --- Availability (Simple Stock Mode forces every product in-stock) ---
        $is_available = $simple_stock ? true : $product->is_in_stock();

        // --- Sort order ---
        $sort_order = (int) $product->get_menu_order();

        // --- Description (full first, short as fallback) ---
        $description = $product->get_description();
        if ( empty( $description ) ) {
            $description = $product->get_short_description();
        }
        $description = ! empty( $description ) ? wp_strip_all_tags( $description ) : null;

        // --- Variations ---
        $variations      = null;
        $variation_values = null;

        if ( $product->is_type( 'variable' ) ) {
            /** @var WC_Product_Variable $product */
            $available_variations = $product->get_available_variations();

            if ( ! empty( $available_variations ) ) {
                $variations = array();

                foreach ( $available_variations as $var ) {
                    $var_obj = wc_get_product( $var['variation_id'] );
                    if ( ! $var_obj ) {
                        continue;
                    }

                    $var_attributes = $var_obj->get_attributes();
                    $var_name_parts = array();
                    $var_values     = array();

                    foreach ( $var_attributes as $attr_key => $attr_val ) {
                        if ( '' === $attr_val ) {
                            continue;
                        }
                        $term_obj   = get_term_by( 'slug', $attr_val, $attr_key );
                        $val_label  = $term_obj ? $term_obj->name : $attr_val;

                        $var_name_parts[] = $val_label;
                        $clean_key               = str_replace( 'pa_', '', $attr_key );
                        $var_values[ $clean_key ] = $attr_val;
                    }

                    $var_name       = implode( ', ', $var_name_parts );
                    $var_price      = $var_obj->get_regular_price();
                    $var_price      = '' !== $var_price ? (float) $var_price : 0;
                    $var_sale_price = $var_obj->get_sale_price();
                    $var_sale_price = '' !== $var_sale_price ? (float) $var_sale_price : null;

                    $variation_item = array(
                        'name'        => $var_name,
                        'price'       => $var_price,
                        // Woo variation id — lets the chat widget AJAX-add the
                        // exact variation the guest picked in the dish card.
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
            'external_id'  => (string) $product_id,
            'category'     => $category,
            'subcategory'  => $subcategory,
            'name'         => $product->get_name(),
            'description'  => $description,
            'price'        => $price,
        );

        if ( null !== $sale_price ) {
            $item['sale_price'] = $sale_price;
        }

        $item['currency']     = $currency;
        $item['weight']       = $weight;
        $item['tags']         = $tags_list;
        $item['photo_url']    = $photo_url;
        $item['product_url']  = $product_url;

        if ( null !== $variation_values ) {
            $item['variation_values'] = $variation_values;
        }

        $item['variations']   = $variations;
        $item['is_available'] = $is_available;
        $item['sort_order']   = $sort_order;

        $items[] = $item;
    }

    // ------------------------------------------
    //  Final JSON
    // ------------------------------------------
    $data = array(
        'site_config' => $site_config,
        'items'       => $items,
    );

    return w24_save_and_notify( $data );
}

/**
 * Save data to file and push it to the Waiter24 import endpoint.
 *
 * @param array $data Data to write.
 * @return true|string true when the push succeeded (HTTP 2xx), otherwise a
 *                     human-readable error message.
 */
function w24_save_and_notify( $data ) {
    $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );

    if ( false === $json ) {
        return __( 'Failed to encode the menu as JSON.', 'waiter24-export' );
    }

    // Keep a local copy for debugging / inspection.
    file_put_contents( W24_EXPORT_FILE, $json );

    // Push to the Waiter24 import endpoint, authenticated with the import token.
    $settings     = w24_get_settings();
    $import_token  = $settings['import_token'];

    if ( empty( $import_token ) ) {
        return __( 'Import Token is empty. Paste it from the Menu auto-import tab in your Waiter24 dashboard.', 'waiter24-export' );
    }

    $response = wp_remote_post( W24_IMPORT_URL, array(
        'timeout'   => 30,
        'sslverify' => true,
        'headers'   => array(
            'Authorization' => 'Bearer ' . $import_token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ),
        'body'      => $json,
    ) );

    if ( is_wp_error( $response ) ) {
        /* translators: %s: connection error message. */
        return sprintf( __( 'Could not reach Waiter24: %s', 'waiter24-export' ), $response->get_error_message() );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );

    if ( $code >= 200 && $code < 300 ) {
        return true;
    }

    if ( 401 === $code || 403 === $code ) {
        return __( 'Authentication failed (HTTP 401/403). Check that the Import Token is correct — it is NOT the Unique (widget) Key.', 'waiter24-export' );
    }

    $body = wp_remote_retrieve_body( $response );
    /* translators: 1: HTTP status code, 2: response body excerpt. */
    return sprintf( __( 'Import endpoint returned HTTP %1$d: %2$s', 'waiter24-export' ), $code, mb_substr( (string) $body, 0, 300 ) );
}

/**
 * =============================================
 *  ADD-ONS (Waiter24 quantity-priced dish extras)
 * =============================================
 * The chat widget's native add-to-cart AJAX call (wc-ajax=add_to_cart) gains
 * one additive POST field, `waiter24_addons` — a JSON array of
 * {name, price, qty}. WooCommerce's own handler only reads
 * product_id/quantity/variation_id by default, so these three filters are
 * what actually attach the add-ons to the cart line: stash them as cart item
 * data, fold their price into the line total, and display them under the
 * line item in cart/checkout. See docs/addons.md in the main waiter-saas repo
 * for the full contract (also implemented for Magento 2 and Shopware).
 */
add_filter( 'woocommerce_add_cart_item_data', 'w24_attach_addons_to_cart_item', 10, 2 );

function w24_attach_addons_to_cart_item( $cart_item_data, $product_id ) {
    if ( empty( $_POST['waiter24_addons'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only cart line metadata, mirrors core's own handling of $_POST['quantity']
        return $cart_item_data;
    }

    $decoded = json_decode( wp_unslash( $_POST['waiter24_addons'] ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

    if ( ! is_array( $decoded ) ) {
        return $cart_item_data;
    }

    $addons = array();
    foreach ( $decoded as $a ) {
        if ( empty( $a['name'] ) ) {
            continue;
        }
        $addons[] = array(
            'name'  => sanitize_text_field( (string) $a['name'] ),
            'price' => isset( $a['price'] ) ? (float) $a['price'] : 0,
            'qty'   => isset( $a['qty'] ) ? max( 1, (int) $a['qty'] ) : 1,
        );
    }

    if ( ! empty( $addons ) ) {
        // Nothing else needed to keep this a distinct cart line: WooCommerce
        // hashes the full cart_item_data (via generate_cart_id()) to decide
        // whether an add matches an existing line, so a different add-on
        // combination already produces a different line automatically —
        // and an identical one still correctly merges/increments.
        $cart_item_data['waiter24_addons'] = $addons;
    }

    return $cart_item_data;
}

add_filter( 'woocommerce_before_calculate_totals', 'w24_apply_addons_price', 20 );

function w24_apply_addons_price( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    foreach ( $cart->get_cart() as $cart_item ) {
        if ( empty( $cart_item['waiter24_addons'] ) ) {
            continue;
        }

        $addon_total = 0.0;
        foreach ( $cart_item['waiter24_addons'] as $addon ) {
            $addon_total += (float) $addon['price'] * (int) $addon['qty'];
        }

        // get_price() (not get_regular_price()) so an active sale is still
        // honoured — add-ons stack on top of whatever the guest actually pays.
        $base_price = (float) $cart_item['data']->get_price();
        $cart_item['data']->set_price( $base_price + $addon_total );
    }
}

add_filter( 'woocommerce_get_item_data', 'w24_display_addons_in_cart', 10, 2 );

function w24_display_addons_in_cart( $item_data, $cart_item ) {
    if ( empty( $cart_item['waiter24_addons'] ) ) {
        return $item_data;
    }

    foreach ( $cart_item['waiter24_addons'] as $addon ) {
        $item_data[] = array(
            'name'  => sanitize_text_field( $addon['name'] ),
            'value' => $addon['qty'] > 1 ? sprintf( '×%d', (int) $addon['qty'] ) : ' ',
        );
    }

    return $item_data;
}
