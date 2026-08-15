<?php
/**
 * Main Plugin Class
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReactWoo_API_Manager {

	/**
	 * Plugin instance
	 *
	 * @var ReactWoo_API_Manager
	 */
	private static $instance = null;

	/**
	 * Get plugin instance
	 *
	 * @return ReactWoo_API_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize the plugin
	 */
	private function init() {
		require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-license-server-api.php';
		require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-license-sync.php';
		require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-account-logger.php';
		require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-customer-account-service.php';
		require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-plugin-download-service.php';
		require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-account-rest-controller.php';
		require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-license-display.php';
		require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-email-delayed.php';
		require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-product-meta.php';
		require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-subscription-handler.php';

		new ReactWoo_Product_Meta();
		new ReactWoo_Subscription_Handler();
		new ReactWoo_License_Display();
		new ReactWoo_Account_REST_Controller();
		new ReactWoo_Email_Delayed();

		/**
		 * API Manager finished loading licence, download and My Account services.
		 * The Cloud module may listen. Nothing Cloud-related is loaded unless the flag is on.
		 */
		do_action( 'reactwoo_api_manager_loaded' );

		if ( defined( 'REACTWOO_CLOUD_BRIDGE_ENABLED' ) && REACTWOO_CLOUD_BRIDGE_ENABLED ) {
			require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/cloud-commerce/class-rwcc-bootstrap.php';
			RWCC_Bootstrap::init();
		}

		if ( is_admin() ) {
			require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'admin/class-admin.php';
			ReactWoo_API_Manager_Admin::get_instance();
			add_action( 'admin_notices', array( $this, 'maybe_notice_missing_api_key' ) );
		}

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Warn admins when the shared API key is missing.
	 */
	public function maybe_notice_missing_api_key() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ReactWoo_License_Server_API::has_master_key() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'ReactWoo API Manager: set the shared API Key under ReactWoo License Manager → Settings so licence provisioning and sync can authenticate.',
			'reactwoo-api-manager'
		);
		echo '</p></div>';
	}

	/**
	 * Load plugin textdomain
	 */
	public function load_textdomain() {
		$plugin_dir = dirname( plugin_basename( REACTWOO_API_MANAGER_PLUGIN_FILE ) );
		load_plugin_textdomain( 'reactwoo-api-manager', false, $plugin_dir . '/languages' );
	}

	/**
	 * Register plugin settings
	 */
	public function register_settings() {
		register_setting(
			'reactwoo_api_manager_settings',
			'reactwoo_license_server_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => 'https://license.reactwoo.com',
			)
		);

		register_setting(
			'reactwoo_api_manager_settings',
			'reactwoo_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'reactwoo_api_manager_settings',
			'reactwoo_updates_api_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => 'https://api.reactwoo.com',
			)
		);

		register_setting(
			'reactwoo_api_manager_settings',
			'reactwoo_updates_store_download_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	/**
	 * Get license server URL
	 *
	 * @return string
	 */
	public static function get_license_server_url() {
		return get_option( 'reactwoo_license_server_url', 'https://license.reactwoo.com' );
	}

	/**
	 * Get API key
	 *
	 * @return string
	 */
	public static function get_api_key() {
		return get_option( 'reactwoo_api_key', '' );
	}
}
