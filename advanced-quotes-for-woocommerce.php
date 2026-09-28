<?php

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use WeWP\AdvancedQuotes\Plugin;

/**
 * Plugin Name: Advanced Quotes for WooCommerce
 * Description: Let customers request quotes, set prices in your admin, send numbered PDF quotes and take payment when the customer accepts.
 * Version: 0.1.0
 * Author: UAB BusinessPress
 * Plugin URI: https://we-wp.com/plugins/advanced-quotes-for-woocommerce
 * Update URI: https://we-wp.com/plugins/advanced-quotes-for-woocommerce
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * WC requires at least: 11.1
 * WC tested up to: 11.1
 * License: GPL-3.0-or-later
 * Text Domain: advanced-quotes-for-woocommerce
 */
if (! defined('ABSPATH')) {
    exit;
}

define('WEWP_AQ_VERSION', '0.1.0');
define('WEWP_AQ_FILE', __FILE__);

require_once __DIR__.'/vendor/autoload.php';

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('plugins_loaded', [Plugin::class, 'boot']);
