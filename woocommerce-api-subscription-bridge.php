<?php
/**
 * Plugin Name: ReactWoo API Manager
 * Description: Integrates WooCommerce Subscriptions with the ReactWoo License Server for secure license key generation and management.
 * Version: 2.1.1
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
define( 'REACTWOO_API_MANAGER_VERSION', '2.1.1' );
define( 'REACTWOO_API_MANAGER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'REACTWOO_API_MANAGER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'REACTWOO_API_MANAGER_PLUGIN_FILE', __FILE__ );

/**
 * Register My Account licence endpoint (used on init and activation).
 */
function reactwoo_api_manager_register_license_endpoint() {
	add_rewrite_endpoint( 'license', EP_PAGES );
}

/**
 * Activation: register endpoint and flush rewrites once.
 */
function reactwoo_api_manager_activate() {
	reactwoo_api_manager_register_license_endpoint();
	flush_rewrite_rules();
	update_option( 'reactwoo_api_manager_rewrite_version', REACTWOO_API_MANAGER_VERSION, false );
}

/**
 * Deactivation: flush rewrites once.
 */
function reactwoo_api_manager_deactivate() {
	flush_rewrite_rules();
}

/**
 * One-time rewrite flush after version upgrades (avoids /license/ 404 → redirect loops).
 */
function reactwoo_api_manager_maybe_flush_rewrites() {
	$stored = get_option( 'reactwoo_api_manager_rewrite_version', '' );
	if ( $stored === REACTWOO_API_MANAGER_VERSION ) {
		return;
	}
	reactwoo_api_manager_register_license_endpoint();
	flush_rewrite_rules( false );
	update_option( 'reactwoo_api_manager_rewrite_version', REACTWOO_API_MANAGER_VERSION, false );
}

register_activation_hook( __FILE__, 'reactwoo_api_manager_activate' );
register_deactivation_hook( __FILE__, 'reactwoo_api_manager_deactivate' );
add_action( 'init', 'reactwoo_api_manager_register_license_endpoint', 5 );
add_action( 'init', 'reactwoo_api_manager_maybe_flush_rewrites', 20 );

// Declare compatibility with WooCommerce features
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', REACTWOO_API_MANAGER_PLUGIN_FILE, true );
	}
} );

// Check if WooCommerce and WooCommerce Subscriptions are active
add_action( 'plugins_loaded', 'reactwoo_api_manager_init', 20 );

function reactwoo_api_manager_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error"><p><strong>ReactWoo API Manager</strong> requires WooCommerce to be installed and active.</p></div>';
		} );
		return;
	}

	if ( ! class_exists( 'WC_Subscriptions' ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error"><p><strong>ReactWoo API Manager</strong> requires WooCommerce Subscriptions to be installed and active.</p></div>';
		} );
		return;
	}

	require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-reactwoo-api-manager.php';
	ReactWoo_API_Manager::get_instance();
}
