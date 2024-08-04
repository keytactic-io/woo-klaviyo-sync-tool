<?php
/**
 * Plugin Name: Woo Subscription Events for Klaviyo
 * Description: Sends WooCommerce Subscription events to Klaviyo.
 * Version: 1.0.0
 * Author: Mo Hassan
 * Text Domain: woo-subscription-events-for-klaviyo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Include the main Klaviyo integration class.
require_once plugin_dir_path(__FILE__) . 'includes/class-klaviyo-integration.php';
require_once plugin_dir_path(__FILE__) . 'admin/class-klaviyo-admin.php';

// Initialize the plugin.
function my_klaviyo_woocommerce_plugin_init() {
    $klaviyo_integration = new Klaviyo_Integration();
    $klaviyo_integration->init();

    $klaviyo_admin = new Klaviyo_Admin();
    $klaviyo_admin->init();
}
add_action('plugins_loaded', 'my_klaviyo_woocommerce_plugin_init');
