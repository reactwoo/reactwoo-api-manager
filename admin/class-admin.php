<?php
/**
 * Admin Class
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReactWoo_API_Manager_Admin {

    /**
     * Plugin instance
     *
     * @var ReactWoo_API_Manager_Admin
     */
    private static $instance = null;

    /**
     * Get plugin instance
     *
     * @return ReactWoo_API_Manager_Admin
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
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_filter( 'woocommerce_subscription_list_table_columns', array( $this, 'add_subscription_license_column' ) );
        add_action( 'woocommerce_subscription_list_table_column_license', array( $this, 'render_subscription_license_column' ), 10, 1 );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'ReactWoo License Manager', 'reactwoo-api-manager' ),
            __( 'ReactWoo Licenses', 'reactwoo-api-manager' ),
            'manage_woocommerce',
            'reactwoo-license-manager',
            array( $this, 'render_license_manager_page' ),
            'dashicons-admin-network',
            56
        );

        add_submenu_page(
            'reactwoo-license-manager',
            __( 'License Manager', 'reactwoo-api-manager' ),
            __( 'All Licenses', 'reactwoo-api-manager' ),
            'manage_woocommerce',
            'reactwoo-license-manager',
            array( $this, 'render_license_manager_page' )
        );

        add_submenu_page(
            'reactwoo-license-manager',
            __( 'Settings', 'reactwoo-api-manager' ),
            __( 'Settings', 'reactwoo-api-manager' ),
            'manage_options',
            'reactwoo-license-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting( 'reactwoo_api_manager_settings', 'reactwoo_license_server_url', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://license.reactwoo.com',
        ) );

        register_setting( 'reactwoo_api_manager_settings', 'reactwoo_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ) );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( strpos( $hook, 'reactwoo-license' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'reactwoo-api-manager-admin',
            REACTWOO_API_MANAGER_PLUGIN_URL . 'admin/assets/admin.css',
            array(),
            REACTWOO_API_MANAGER_VERSION
        );

        wp_enqueue_script(
            'reactwoo-api-manager-admin',
            REACTWOO_API_MANAGER_PLUGIN_URL . 'admin/assets/admin.js',
            array( 'jquery' ),
            REACTWOO_API_MANAGER_VERSION,
            true
        );

        wp_localize_script( 'reactwoo-api-manager-admin', 'reactwooApiManager', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'reactwoo-api-manager-nonce' ),
        ) );
    }

    /**
     * Render license manager page
     */
    public function render_license_manager_page() {
        require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'admin/views/license-manager.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Add license column to subscription list
     *
     * @param array $columns Existing columns
     * @return array
     */
    public function add_subscription_license_column( $columns ) {
        $columns['license'] = __( 'License Key', 'reactwoo-api-manager' );
        return $columns;
    }

    /**
     * Render license column in subscription list
     *
     * @param WC_Subscription $subscription Subscription object
     */
    public function render_subscription_license_column( $subscription ) {
        $license_key = $subscription->get_meta( '_reactwoo_license_key' );
        $license_id = $subscription->get_meta( '_reactwoo_license_id' );
        
        if ( $license_key ) {
            echo '<code>' . esc_html( $license_key ) . '</code>';
            if ( $license_id ) {
                echo '<br><small>' . sprintf( __( 'License ID: %d', 'reactwoo-api-manager' ), $license_id ) . '</small>';
            }
        } else {
            echo '<span class="dashicons dashicons-no-alt" style="color: #dc3232;"></span> ' . __( 'No license', 'reactwoo-api-manager' );
        }
    }
}

