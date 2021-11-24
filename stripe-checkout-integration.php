<?php
/*
 * Plugin Name: Stripe Connect Payment Integration
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
if (!defined('SP_MAIN_FILE')) {
    define('SP_MAIN_FILE', __FILE__);
}

if (!class_exists('Stripe\Stripe')) { //fix for SFRWDF-184
    include(SP_MAIN_PATH . "vendor/autoload.php");
}
