<?php
/*
 * Plugin Name: Stripe Connect Payment Integration
 * Plugin URI: 
 * Description: Accept payments from your WooCommerce store via Stripe Checkout using Stripe connect.
 * Author: Adrian Fernandez - Foco Azul
 * Author URI: https://www.linkedin.com/in/adrian-fernandez-1701/
 * Version: 1.0
 * WC requires at least: 3.0
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: stripe-payment
 */


if (!defined('ABSPATH')) {
    exit;
}
if (!defined('SP_PLUGIN_URL')) {
    define('SP_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('SP_MAIN_PATH')) {
    define('SP_MAIN_PATH', plugin_dir_path(__FILE__));
}
if (!defined('SP_VERSION')) {
    define('SP_VERSION', '1.0');
}
if (!defined('SP_PLUGIN_NAME')) {
    define('SP_PLUGIN_NAME', 'Stripe_Connect_Payment_Integration');
}
if (!defined('SP_MAIN_FILE')) {
    define('SP_MAIN_FILE', __FILE__);
}

if (!class_exists('Stripe\Stripe')) { //fix for SFRWDF-184
    include(SP_MAIN_PATH . "vendor/autoload.php");
}

require_once(ABSPATH . "wp-admin/includes/plugin.php");

add_action('plugins_loaded', 'sp_stripe_check', 99);

function sp_stripe_check()
{

    if (class_exists('WooCommerce')) {
        register_activation_hook(__FILE__, 'sp_stripe_init_log');
        include(SP_MAIN_PATH . "includes/log.php");

        sp_plugin_init();
    } else {

        deactivate_plugins(plugin_basename(__FILE__));
        add_action('admin_notices', 'sp_stripe_wc_admin_notices', 99);
    }
}

function sp_stripe_wc_admin_notices()
{
    $text = "<span style='color:red'>Stripe Payment</span> Plugin Needs WooCommerce to Work.";
    return $text;
}


function sp_plugin_init()
{
    add_action('init', 'sp_lang_loader');

    function sp_lang_loader()
    {
        load_plugin_textdomain('stripe', false, dirname(plugin_basename(__FILE__)) . '/lang');
    }

    //adds payment gateways
    function sp_add_stripe_gateway($methods)
    {
        $methods[] = 'SP_Payment_Gateway';
        return $methods;
    }

    //includes neccessary payment method files
    add_filter('woocommerce_payment_gateways', 'sp_add_stripe_gateway');
    if (!class_exists('SP_Payment_Gateway')) {
        include(SP_MAIN_PATH . "includes/stripe-classes/class-stripe-checkout.php");
        new SP_Payment_Gateway;
        include(SP_MAIN_PATH . "includes/stripe-classes/init.php");
        Sp_Checkout_Init::instance();
    }
}

/**
 * Returns an instance of the usefull functions of the plugin
 */
function sp_functions()
{
    require_once(SP_MAIN_PATH . "includes/stripe-classes/functions.php");
    return new Stripe_Payment_Functions();
}

//initialises log file
function sp_stripe_init_log()
{
    if (WC()->version >= '2.7.0') {
        $logger = wc_get_logger();
        $live_context = array('source' => 'sp_stripe_pay_live');
        $init_msg = SP_Stripe_Log::init_live_log();
        $logger->log("debug", $init_msg, $live_context);
        $dead_context = array('source' => 'sp_stripe_pay_dead');
        $init_msg = SP_Stripe_Log::init_dead_log();
        $logger->log("debug", $init_msg, $dead_context);
    } else {
        $log = new WC_Logger();
        $init_msg = SP_Stripe_Log::init_live_log();
        $log->add("sp_stripe_pay_live", $init_msg);
        $init_msg = SP_Stripe_Log::init_dead_log();
        $log->add("sp_stripe_pay_dead", $init_msg);
    }
}
