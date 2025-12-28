<?php
/**
 * Plugin Name: ReactWoo API Manager
 * Description: Integrates WooCommerce Subscriptions with the ReactWoo License Server for secure license key generation and management.
 * Version: 2.0.0
 * Author: ReactWoo
 * Author URI: https://reactwoo.com
 * License: GPL-3.0-or-later
 * Text Domain: reactwoo-api-manager
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package ReactWoo_API_Manager
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'REACTWOO_API_MANAGER_VERSION', '2.0.0' );
define( 'REACTWOO_API_MANAGER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'REACTWOO_API_MANAGER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'REACTWOO_API_MANAGER_PLUGIN_FILE', __FILE__ );

// Check if WooCommerce and WooCommerce Subscriptions are active
if ( ! function_exists( 'reactwoo_api_manager_check_dependencies' ) ) {
    function reactwoo_api_manager_check_dependencies() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="error"><p><strong>ReactWoo API Manager</strong> requires WooCommerce to be installed and active.</p></div>';
            } );
            return false;
        }

        if ( ! class_exists( 'WC_Subscriptions' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="error"><p><strong>ReactWoo API Manager</strong> requires WooCommerce Subscriptions to be installed and active.</p></div>';
            } );
            return false;
        }

        return true;
    }
}

// Initialize the plugin
if ( reactwoo_api_manager_check_dependencies() ) {
    require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-reactwoo-api-manager.php';
    ReactWoo_API_Manager::get_instance();
}
