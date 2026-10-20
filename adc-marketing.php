<?php
/**
 * Plugin Name: ADC Cookies & Commerce Hub
 * Plugin URI: https://github.com/dany547/adc-cookies-commerce-hub-wordpress
 * Description: Gestionează cookies, consimțământul vizitatorilor și preferințele de marketing. Centralizează integrările de tracking și rapoartele comerciale WooCommerce, cu controale distincte pentru colectarea datelor.
 * Version: 2.2.4
 * Author: Dan Mutu
 * Author URI: https://adcelerum.ro
 * Text Domain: adc-marketing
 * Domain Path: /languages
 * Requires at least: 6.8
 * Tested up to: 7.1
 * Requires PHP: 8.2
 * WC requires at least: 9.5
 * WC tested up to: 11.2
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/dany547/adc-cookies-commerce-hub-wordpress
 */

declare(strict_types=1);

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ADC_MARKETING_VERSION', '2.2.4');
define('ADC_MARKETING_FILE', __FILE__);
define('ADC_MARKETING_DB_VERSION', '1');
define('ADC_MARKETING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ADC_MARKETING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ADC_MARKETING_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Register the autoloader (ADC_* -> includes/{,core,consent,tracking,integrations,admin}/class|interface-*.php).
require_once ADC_MARKETING_PLUGIN_DIR . 'includes/core/class-adc-autoloader.php';
ADC_Autoloader::register();

/**
 * Check if WooCommerce is active
 */
function adc_marketing_check_woocommerce(): bool
{
    return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')), true);
}

/**
 * Display admin notice if WooCommerce is not active
 */
function adc_marketing_woocommerce_missing_notice(): void
{
    ?>
    <div class="error">
        <p>
            <strong><?php esc_html_e('ADC Cookies & Commerce Hub', 'adc-marketing'); ?></strong> 
            <?php esc_html_e('necesită WooCommerce pentru a funcționa. Vă rugăm să activați WooCommerce.', 'adc-marketing'); ?>
        </p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function adc_marketing_init(): void
{
    // Check if WooCommerce is active
    if (!adc_marketing_check_woocommerce()) {
        add_action('admin_notices', 'adc_marketing_woocommerce_missing_notice');
        return;
    }

    // Load textdomain
    load_plugin_textdomain('adc-marketing', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Load main class
    require_once ADC_MARKETING_PLUGIN_DIR . 'includes/class-adc-marketing.php';

    // Initialize plugin (legacy classes + 2.0 module registry)
    ADC_Marketing::get_instance();
}

/**
 * Declare WooCommerce compatibility
 */
function adc_marketing_declare_compatibility(): void
{
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}

// Hook into plugins_loaded
add_action('plugins_loaded', 'adc_marketing_init');

// Declare WooCommerce compatibility
add_action('before_woocommerce_init', 'adc_marketing_declare_compatibility');

// Register activation hook: tables + db version + My Account endpoint (ADC_Installer).
register_activation_hook(__FILE__, array('ADC_Installer', 'activate'));

// Register deactivation hook: flush rewrite rules (ADC_Installer).
register_deactivation_hook(__FILE__, array('ADC_Installer', 'deactivate'));

