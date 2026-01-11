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
        // Load on license manager pages
        if ( strpos( $hook, 'reactwoo-license' ) !== false ) {
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
        
        // Load on product edit pages for package selection functionality
        if ( $hook === 'post.php' || $hook === 'post-new.php' ) {
            global $post;
            if ( $post && $post->post_type === 'product' ) {
                wp_enqueue_script(
                    'reactwoo-api-manager-product',
                    REACTWOO_API_MANAGER_PLUGIN_URL . 'admin/assets/admin.js',
                    array( 'jquery' ),
                    REACTWOO_API_MANAGER_VERSION,
                    true
                );

                wp_localize_script( 'reactwoo-api-manager-product', 'reactwooApiManager', array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce' => wp_create_nonce( 'reactwoo-api-manager-nonce' ),
                ) );
            }
        }
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

    /**
     * Process manual license creation form submission
     *
     * @return array|WP_Error|null
     */
    public function process_manual_license_creation() {
        if ( ! isset( $_POST['reactwoo_create_manual_license'] ) ) {
            return null;
        }

        if ( ! check_admin_referer( 'reactwoo_manual_license', 'reactwoo_manual_license_nonce' ) ) {
            return new WP_Error( 'invalid_nonce', __( 'Security check failed.', 'reactwoo-api-manager' ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new WP_Error( 'permission_denied', __( 'You do not have permission to create manual licenses.', 'reactwoo-api-manager' ) );
        }

        $product_id = isset( $_POST['manual_license_product'] ) ? intval( $_POST['manual_license_product'] ) : 0;
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->exists() ) {
            return new WP_Error( 'invalid_product', __( 'Please select a valid subscription product.', 'reactwoo-api-manager' ) );
        }

        if ( ! $product->is_type( array( 'subscription', 'variable-subscription' ) ) ) {
            return new WP_Error( 'not_subscription', __( 'The selected product must be a subscription.', 'reactwoo-api-manager' ) );
        }

        $package_id = get_post_meta( $product_id, '_reactwoo_license_package_id', true );
        if ( ! $package_id ) {
            return new WP_Error( 'missing_package', __( 'This product does not have a license package assigned.', 'reactwoo-api-manager' ) );
        }

        $domain = isset( $_POST['manual_license_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_license_domain'] ) ) : '';
        if ( '' === trim( $domain ) ) {
            return new WP_Error( 'missing_domain', __( 'Please enter a license domain.', 'reactwoo-api-manager' ) );
        }

        $customer_email = isset( $_POST['manual_customer_email'] ) ? sanitize_email( wp_unslash( $_POST['manual_customer_email'] ) ) : '';
        if ( ! is_email( $customer_email ) ) {
            return new WP_Error( 'missing_customer', __( 'Please enter a valid customer email address.', 'reactwoo-api-manager' ) );
        }

        $customer_id = isset( $_POST['manual_customer_id'] ) ? intval( $_POST['manual_customer_id'] ) : 0;
        $customer = $customer_id ? get_user_by( 'id', $customer_id ) : get_user_by( 'email', $customer_email );

        if ( ! $customer ) {
            $password = wp_generate_password( 12, true );
            if ( function_exists( 'wc_create_new_customer' ) ) {
                $new_customer_id = wc_create_new_customer( $customer_email, '', $password );
            } else {
                $username = sanitize_user( current( explode( '@', $customer_email ) ), true );
                $new_customer_id = wp_create_user( $username, $password, $customer_email );
                if ( ! is_wp_error( $new_customer_id ) ) {
                    wp_update_user( array( 'ID' => $new_customer_id, 'role' => 'customer' ) );
                }
            }

            if ( is_wp_error( $new_customer_id ) ) {
                return $new_customer_id;
            }

            $customer = get_user_by( 'id', $new_customer_id );
        }

        if ( ! $customer ) {
            return new WP_Error( 'customer_not_found', __( 'Unable to load the specified customer.', 'reactwoo-api-manager' ) );
        }

        $wc_customer = $this->get_wc_customer_instance( $customer->ID );
        if ( ! $wc_customer ) {
            return new WP_Error( 'customer_load_failed', __( 'Unable to load the WooCommerce customer.', 'reactwoo-api-manager' ) );
        }

        $order = wc_create_order( array( 'customer_id' => $wc_customer->get_id() ) );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $order->add_product( $product, 1, array(
            'subtotal' => $product->get_price(),
            'total'    => $product->get_price(),
        ) );

        $order->set_address( $this->get_customer_address_for_order( $wc_customer, 'billing' ), 'billing' );
        $order->set_address( $this->get_customer_address_for_order( $wc_customer, 'shipping' ), 'shipping' );
        $order->set_currency( get_woocommerce_currency() );
        $order->update_meta_data( '_reactwoo_domain', $domain );
        $order->set_payment_method( 'manual' );
        $order->set_payment_method_title( __( 'Manual license creation', 'reactwoo-api-manager' ) );
        $order->calculate_totals();
        $order->save();

        if ( ! function_exists( 'wcs_create_subscription' ) ) {
            return new WP_Error( 'subscription_missing', __( 'WooCommerce Subscriptions must be installed and active.', 'reactwoo-api-manager' ) );
        }

        $subscription = wcs_create_subscription( array(
            'customer_id'     => $wc_customer->get_id(),
            'status'          => 'active',
            'order_id'        => $order->get_id(),
            'start_date'      => current_time( 'mysql' ),
            'billing_period'  => method_exists( $product, 'get_billing_period' ) ? $product->get_billing_period() : $product->get_meta( '_subscription_period' ),
            'billing_interval'=> method_exists( $product, 'get_billing_interval' ) ? $product->get_billing_interval() : $product->get_meta( '_subscription_interval' ),
        ) );

        if ( is_wp_error( $subscription ) ) {
            return $subscription;
        }

        $subscription->add_product( $product, 1, array(
            'subtotal' => $product->get_price(),
            'total'    => $product->get_price(),
        ) );
        $subscription->set_parent_id( $order->get_id() );
        $subscription->calculate_totals();
        $subscription->save();
        $subscription->update_status( 'active' );

        $order->add_order_note( __( 'ReactWoo license manager created this order.', 'reactwoo-api-manager' ) );
        $order->payment_complete( __( 'Created via ReactWoo License Manager', 'reactwoo-api-manager' ) );

        return array(
            'order_id'        => $order->get_id(),
            'subscription_id' => $subscription->get_id(),
            'message'         => __( 'Order and subscription created successfully.', 'reactwoo-api-manager' ),
        );
    }

    /**
     * Map customer data to order address arrays
     *
     * @param WC_Customer $customer Customer object
     * @param string      $type     Address type (billing|shipping)
     * @return array
     */
    private function get_customer_address_for_order( $customer, $type = 'billing' ) {
        if ( ! $customer instanceof WC_Customer ) {
            return array();
        }

        $fields = array(
            'first_name',
            'last_name',
            'company',
            'address_1',
            'address_2',
            'city',
            'state',
            'postcode',
            'country',
        );

        $address = array();
        foreach ( $fields as $field ) {
            $getter = 'get_' . $type . '_' . $field;
            if ( method_exists( $customer, $getter ) ) {
                $address[ $field ] = $customer->{$getter}();
            } else {
                $fallback = 'get_' . $field;
                $address[ $field ] = method_exists( $customer, $fallback ) ? $customer->{$fallback}() : '';
            }
        }

        return array_filter( $address );
    }

    /**
     * Return subscription products that have license packages
     *
     * @return WC_Product[]
     */
    public function get_license_subscription_products() {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return array();
        }

        $args = array(
            'limit'   => -1,
            'status'  => 'publish',
            'orderby' => 'title',
            'order'   => 'ASC',
            'type'    => array( 'subscription', 'variable-subscription' ),
        );

        return wc_get_products( $args );
    }

    /**
     * Safely load a WC_Customer instance
     *
     * @param int $customer_id
     * @return false|WC_Customer
     */
    private function get_wc_customer_instance( $customer_id ) {
        if ( ! $customer_id ) {
            return false;
        }

        if ( function_exists( 'wc_get_customer' ) ) {
            $customer = wc_get_customer( $customer_id );
            if ( $customer instanceof WC_Customer ) {
                return $customer;
            }
        }

        if ( class_exists( 'WC_Customer' ) ) {
            return new WC_Customer( $customer_id );
        }

        return false;
    }

}

