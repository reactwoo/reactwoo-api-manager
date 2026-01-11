<?php
/**
 * Delays the completed order email to ensure the license exists.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReactWoo_Email_Delayed {

    const META_KEY = '_reactwoo_delay_completed_email';

    public function __construct() {
        add_action( 'woocommerce_order_status_completed', array( $this, 'queue_delayed_email' ), 1, 1 );
        add_filter( 'woocommerce_email_enabled_customer_completed_order', array( $this, 'maybe_disable_completed_email' ), 10, 2 );
        add_action( 'reactwoo_send_delayed_customer_email', array( $this, 'send_delayed_email' ), 10, 1 );
    }

    public function queue_delayed_email( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( $order->get_meta( self::META_KEY ) ) {
            return;
        }

        $order->update_meta_data( self::META_KEY, time() );
        $order->save();
        wp_schedule_single_event( time() + 180, 'reactwoo_send_delayed_customer_email', array( $order_id ) );
    }

    public function maybe_disable_completed_email( $enabled, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return $enabled;
        }
        if ( $order->get_meta( self::META_KEY ) ) {
            return false;
        }
        return $enabled;
    }

    public function send_delayed_email( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();
        if ( empty( $emails['WC_Email_Customer_Completed_Order'] ) ) {
            return;
        }

        $email = $emails['WC_Email_Customer_Completed_Order'];
        $email->trigger( $order_id, $order );
        $order->delete_meta_data( self::META_KEY );
        $order->save();
    }
}
