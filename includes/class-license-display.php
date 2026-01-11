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
        add_action( 'woocommerce_email_after_order_table', array( $this, 'maybe_add_license_to_email' ), 10, 4 );
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'print_license_on_order_page' ), 15, 1 );
        add_action( 'woocommerce_subscription_details_after_order_table', array( $this, 'print_license_on_subscription_page' ), 15, 1 );
        add_action( 'wcs_view_subscription', array( $this, 'print_license_on_subscription_page' ), 15, 1 );
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
}
