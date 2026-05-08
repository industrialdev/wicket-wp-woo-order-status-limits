<?php

/**
 * Plugin Name:       Wicket WooCommerce Order Status Limits
 * Plugin URI:        https://github.com/industrialdev/wicket-wp-woo-order-status-limits
 * Description:       Restricts manual WooCommerce order status changes in wp-admin based on configurable rules and user role exceptions.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Wicket
 * Author URI:        https://wicket.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wicket-osl
 * Requires Plugins:  wicket-wp-base-plugin, woocommerce.
 */
defined('ABSPATH') || exit;

define('OSL_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
define('OSL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OSL_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load the class early so the settings filters can reference it.
// The class itself does nothing on load — no WC or Wicket calls at file level.
if (file_exists(OSL_PLUGIN_DIR . 'includes/class-osl-limiter.php')) {
    require_once OSL_PLUGIN_DIR . 'includes/class-osl-limiter.php';
}

// Register the settings section in the Wicket Integrations tab.
// Uses the same wicket_settings_tabs pattern as wicket-wp-guest-checkout.
// Fires during admin_menu — no dependency on wicket_get_option timing.
add_filter('wicket_settings_tabs', ['OSL_Limiter', 'extend_settings_tabs'], 20);
add_filter('wicket_settings_tab_int', ['OSL_Limiter', 'extend_settings_tab_fallback'], 20);

/**
 * Boot the operational hooks.
 *
 * Runs at init priority 1, after the Wicket base plugin loads its helpers
 * at init priority 0 (Includes::wicket_includes). This ensures wicket_get_option
 * is defined before we use it.
 */
function osl_init(): void
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'osl_notice_missing_woocommerce');

        return;
    }

    if (!function_exists('wicket_get_option')) {
        add_action('admin_notices', 'osl_notice_missing_wicket');

        return;
    }

    OSL_Limiter::init();
}
add_action('init', 'osl_init', 1);

function osl_notice_missing_woocommerce(): void
{
    echo '<div class="notice notice-error"><p>';
    esc_html_e('Wicket WooCommerce Order Status Limits requires WooCommerce to be installed and active.', 'wicket-osl');
    echo '</p></div>';
}

function osl_notice_missing_wicket(): void
{
    echo '<div class="notice notice-error"><p>';
    esc_html_e('Wicket WooCommerce Order Status Limits requires the Wicket Base Plugin to be installed and active.', 'wicket-osl');
    echo '</p></div>';
}
