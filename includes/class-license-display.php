<?php
/**
 * License Display helpers
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReactWoo_License_Display {

    public function __construct() {
        add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'add_license_order_meta' ), 10, 3 );
        add_action( 'woocommerce_email_after_order_table', array( $this, 'maybe_add_license_to_email' ), 20, 4 );
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'print_license_on_order_page' ), 15, 1 );
        add_action( 'woocommerce_subscription_details_after_order_table', array( $this, 'print_license_on_subscription_page' ), 15, 1 );
        add_action( 'wcs_view_subscription', array( $this, 'print_license_on_subscription_page' ), 15, 1 );
        add_action( 'init', array( $this, 'register_license_endpoint' ) );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'maybe_handle_license_download' ) );
        add_filter( 'woocommerce_account_menu_items', array( $this, 'add_license_menu_item' ) );
        add_action( 'woocommerce_account_license_endpoint', array( $this, 'render_license_endpoint' ) );
    }

    /**
     * Append license info to the completed order email.
     */
    public function maybe_add_license_to_email( $order, $sent_to_admin, $plain_text, $email ) {
        if ( ! $order instanceof WC_Order || 'customer_completed_order' !== $email->id ) {
            return;
        }

        $license = $this->get_order_license_data( $order );
        if ( ! $license ) {
            return;
        }

        $this->print_license_block( $license );
    }

    /**
     * Render license details under the customer order details table.
     */
    public function print_license_on_order_page( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $license = $this->get_order_license_data( $order );
        if ( ! $license ) {
            return;
        }

        $this->print_license_block( $license );
    }

    /**
     * Render license details in subscription view screens.
     *
     * @param WC_Subscription $subscription
     */
    public function print_license_on_subscription_page( $subscription ) {
        if ( ! $subscription instanceof WC_Subscription ) {
            return;
        }

        $license = $this->get_subscription_license_data( $subscription );
        if ( ! $license ) {
            return;
        }

        $this->print_license_block( $license );
    }

    /**
     * Get license info from an order.
     *
     * @param WC_Order $order
     * @return array|null
     */
    private function get_order_license_data( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return null;
        }

        $license_key = $order->get_meta( '_reactwoo_license_key' );
        if ( ! $license_key ) {
            return null;
        }

        return array(
            'key'    => $license_key,
            'domain' => $order->get_meta( '_reactwoo_license_domain' ),
        );
    }

    /**
     * Get license info from a subscription, fallback to parent order.
     *
     * @param WC_Subscription $subscription
     * @return array|null
     */
    private function get_subscription_license_data( $subscription ) {
        $license_key = $subscription->get_meta( '_reactwoo_license_key', true );
        if ( $license_key ) {
            return array(
                'key'    => $license_key,
                'domain' => $subscription->get_meta( '_reactwoo_license_domain', true ),
            );
        }

        $order = $subscription->get_parent();
        if ( $order instanceof WC_Order ) {
            return $this->get_order_license_data( $order );
        }

        return null;
    }

    /**
     * Render the license block.
     *
     * @param array $license
     */
    private function print_license_block( $license ) {
        if ( empty( $license['key'] ) ) {
            return;
        }

        $domain_line = '';
        if ( ! empty( $license['domain'] ) ) {
            $domain_line = '<p class="reactwoo-license-domain"><strong>' . esc_html__( 'Domain', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $license['domain'] ) . '</p>';
        }

        echo '<div class="reactwoo-license-block" style="margin:24px 0;padding:16px;border:1px solid #c7c7c7;border-radius:6px;background:#fafbfc;">';
        echo '<h2 style="margin-top:0;font-size:18px;">' . esc_html__( 'Your License Key', 'reactwoo-api-manager' ) . '</h2>';
        echo '<p>' . esc_html__( 'Use this license key on your website once it is active:', 'reactwoo-api-manager' ) . '</p>';
        echo '<p style="font-size:18px;font-family:monospace;background:#fff;padding:12px;border:1px dashed #dcdcdc;border-radius:4px;margin-bottom:8px;">' . esc_html( $license['key'] ) . '</p>';
        echo $domain_line;
        echo '</div>';
    }

    /**
     * Add license meta field to completed-order emails.
     *
     * @param array    $fields
     * @param bool     $sent_to_admin
     * @param WC_Order $order
     * @return array
     */
    public function add_license_order_meta( $fields, $sent_to_admin, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return $fields;
        }

        $license = $this->get_order_license_data( $order );
        if ( empty( $license ) ) {
            return $fields;
        }

        $fields['reactwoo_license_key'] = array(
            'label' => __( 'License Key', 'reactwoo-api-manager' ),
            'value' => $license['key'],
        );

        if ( ! empty( $license['domain'] ) ) {
            $fields['reactwoo_license_domain'] = array(
                'label' => __( 'License Domain', 'reactwoo-api-manager' ),
                'value' => $license['domain'],
            );
        }

        return $fields;
    }

    /**
     * Register rewrite endpoint for license display.
     */
    public function register_license_endpoint() {
        add_rewrite_endpoint( 'license', EP_PAGES );
    }

    /**
     * Add query var.
     *
     * @param array $vars
     * @return array
     */
    public function register_query_vars( $vars ) {
        $vars[] = 'reactwoo_license_download';
        return $vars;
    }

    /**
     * Handle download requests.
     */
    public function maybe_handle_license_download() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $download = get_query_var( 'reactwoo_license_download' );
        if ( ! $download ) {
            return;
        }

        $license_key = get_post_meta( intval( $download ), '_reactwoo_license_key', true ) ?: get_post_meta( intval( $download ), '_reactwoo_license_label', true );
        $domain = get_post_meta( intval( $download ), '_reactwoo_license_domain', true );

        if ( ! $license_key ) {
            return;
        }

        $filename = 'reactwoo-license-' . sanitize_file_name( $license_key ) . '.txt';
        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        echo "ReactWoo License\n";
        echo "License Key: {$license_key}\n";
        if ( $domain ) {
            echo "Domain: {$domain}\n";
        }
        echo "\nInstructions:\n";
        echo "1. Install the license key on your ReactWoo product.\n";
        echo "2. Activate the plugin on the domain above.\n";
        echo "3. Contact support if you need help.\n";
        exit;
    }

    /**
     * Add menu item to My Account.
     *
     * @param array $items
     * @return array
     */
    public function add_license_menu_item( $items ) {
        $items['license'] = __( 'License', 'reactwoo-api-manager' );
        return $items;
    }

    /**
     * Display license tab content.
     */
    public function render_license_endpoint() {
        $subscriptions = wcs_get_users_subscriptions( array( 'user_id' => get_current_user_id() ) );
        $licenses = array();
        foreach ( $subscriptions as $subscription ) {
            $license_key = $subscription->get_meta( '_reactwoo_license_key', true );
            if ( $license_key ) {
                $licenses[] = array(
                    'key'    => $license_key,
                    'domain' => $subscription->get_meta( '_reactwoo_license_domain', true ),
                    'name'   => $subscription->get_items()[0]->get_name(),
                    'id'     => $subscription->get_id(),
                );
            }
        }

        if ( empty( $licenses ) ) {
            echo '<p>' . esc_html__( 'No licenses found. Complete a subscription to generate one.', 'reactwoo-api-manager' ) . '</p>';
            return;
        }

        echo '<div class="reactwoo-licenses-table">';
        foreach ( $licenses as $license ) {
            echo '<div class="reactwoo-license-row" style="border:1px solid #dcdcdc;padding:16px;margin-bottom:16px;border-radius:6px;">';
            echo '<h2>' . esc_html( $license['name'] ) . ' #' . esc_html( $license['id'] ) . '</h2>';
            echo '<p><strong>' . esc_html__( 'License Key', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $license['key'] ) . '</p>';
            if ( $license['domain'] ) {
                echo '<p><strong>' . esc_html__( 'Domain', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $license['domain'] ) . '</p>';
            }
            $download_url = esc_url( add_query_arg( 'reactwoo_license_download', $license['id'], get_permalink( wc_get_page_id( 'myaccount' ) ) . 'license/' ) );
            echo '<p><a class="button button-secondary" href="' . $download_url . '">' . esc_html__( 'Download License File', 'reactwoo-api-manager' ) . '</a></p>';
            echo '</div>';
        }
        echo '</div>';
    }
}
