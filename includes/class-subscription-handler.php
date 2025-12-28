<?php
/**
 * Subscription Handler
 * Handles license key generation and lifecycle management
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReactWoo_Subscription_Handler {

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into subscription creation
        add_action( 'woocommerce_subscription_status_active', array( $this, 'handle_subscription_activated' ), 10, 1 );
        
        // Hook into subscription status changes
        add_action( 'woocommerce_subscription_status_updated', array( $this, 'handle_subscription_status_change' ), 10, 3 );
        
        // Hook into order completion to create license for initial subscription
        add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_create_license_on_order_completion' ), 10, 1 );
        
        // Hook into renewal order completion
        add_action( 'woocommerce_subscription_renewal_payment_complete', array( $this, 'handle_subscription_renewal' ), 10, 2 );
        
        // Hook into payment failures
        add_action( 'woocommerce_subscription_payment_failed', array( $this, 'handle_payment_failure' ), 10, 1 );
        
        // Store domain field on checkout
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_checkout_domain_field' ), 10, 1 );
        add_action( 'woocommerce_after_order_notes', array( $this, 'add_domain_field_to_checkout' ) );
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_domain_field' ) );
        
        // Add domain field to order admin
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_order_domain_field' ), 10, 1 );
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_order_domain_field' ), 10, 1 );
    }

    /**
     * Handle subscription activation
     *
     * @param WC_Subscription $subscription Subscription object
     */
    public function handle_subscription_activated( $subscription ) {
        // Check if license already exists
        $license_key = $subscription->get_meta( '_reactwoo_license_key' );
        if ( $license_key ) {
            return; // License already created
        }

        // Get parent order
        $order = $subscription->get_parent();
        if ( ! $order ) {
            return;
        }

        // Create license for this subscription
        $this->create_license_for_subscription( $subscription, $order );
    }

    /**
     * Handle subscription status change
     *
     * @param WC_Subscription $subscription Subscription object
     * @param string          $old_status Old status
     * @param string          $new_status New status
     */
    public function handle_subscription_status_change( $subscription, $old_status, $new_status ) {
        $license_key = $subscription->get_meta( '_reactwoo_license_key' );
        $license_id = $subscription->get_meta( '_reactwoo_license_id' );

        if ( ! $license_key || ! $license_id ) {
            return; // No license associated
        }

        $api = new ReactWoo_License_Server_API();

        // Handle different status changes
        switch ( $new_status ) {
            case 'cancelled':
            case 'on-hold':
            case 'expired':
                // Cancel/deactivate license
                $api->update_license_status( $license_id, 'inactive' );
                break;

            case 'active':
                // Reactivate license if it was previously cancelled
                if ( in_array( $old_status, array( 'cancelled', 'on-hold', 'expired' ) ) ) {
                    $api->update_license_status( $license_id, 'active' );
                }
                break;
        }
    }

    /**
     * Create license for subscription on order completion
     *
     * @param int $order_id Order ID
     */
    public function maybe_create_license_on_order_completion( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Get subscriptions for this order
        $subscriptions = wcs_get_subscriptions_for_order( $order_id );

        foreach ( $subscriptions as $subscription ) {
            // Check if license already exists
            $license_key = $subscription->get_meta( '_reactwoo_license_key' );
            if ( $license_key ) {
                continue; // License already created
            }

            $this->create_license_for_subscription( $subscription, $order );
        }
    }

    /**
     * Handle subscription renewal
     *
     * @param WC_Subscription $subscription Subscription object
     * @param WC_Order        $renewal_order Renewal order
     */
    public function handle_subscription_renewal( $subscription, $renewal_order ) {
        // License should remain active on successful renewal
        // No action needed here, but we log it
        $license_key = $subscription->get_meta( '_reactwoo_license_key' );
        if ( $license_key ) {
            // Log renewal event
            $subscription->add_meta_data( '_reactwoo_last_renewal', current_time( 'mysql' ), true );
            $subscription->save();
        }
    }

    /**
     * Handle payment failure
     *
     * @param WC_Subscription $subscription Subscription object
     */
    public function handle_payment_failure( $subscription ) {
        $license_id = $subscription->get_meta( '_reactwoo_license_id' );

        if ( ! $license_id ) {
            return;
        }

        $api = new ReactWoo_License_Server_API();
        
        // Set license to inactive on payment failure
        $api->update_license_status( $license_id, 'inactive' );
        
        // Log the payment failure
        $subscription->add_meta_data( '_reactwoo_payment_failure_date', current_time( 'mysql' ), true );
        $subscription->save();
    }

    /**
     * Create license for subscription
     *
     * @param WC_Subscription $subscription Subscription object
     * @param WC_Order        $order Parent order
     */
    private function create_license_for_subscription( $subscription, $order ) {
        // Get package ID from subscription items
        $package_id = null;
        foreach ( $subscription->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && $product->is_type( 'subscription' ) ) {
                $package_id = get_post_meta( $product->get_id(), '_reactwoo_license_package_id', true );
                if ( $package_id ) {
                    break;
                }
            }
        }

        if ( ! $package_id ) {
            // No package selected for this subscription product
            error_log( 'ReactWoo API Manager: No license package ID found for subscription #' . $subscription->get_id() );
            return;
        }

        // Get domain from order
        $domain = $this->get_domain_from_order( $order );
        if ( ! $domain ) {
            error_log( 'ReactWoo API Manager: No domain found for subscription #' . $subscription->get_id() );
            return;
        }

        // Calculate expiration date based on subscription billing period
        $expires_at = null;
        if ( $subscription->get_date( 'end' ) ) {
            $expires_at = $subscription->get_date( 'end' );
        } elseif ( $subscription->get_billing_period() ) {
            // Calculate expiration based on billing period
            $billing_period = $subscription->get_billing_period();
            $billing_interval = $subscription->get_billing_interval();
            
            $expires_at = date( 'Y-m-d H:i:s', strtotime( '+' . $billing_interval . ' ' . $billing_period ) );
        }

        // Create license via API
        $api = new ReactWoo_License_Server_API();
        $license = $api->create_license( $domain, $package_id, 'active', $expires_at );

        if ( is_wp_error( $license ) ) {
            error_log( 'ReactWoo API Manager: Failed to create license for subscription #' . $subscription->get_id() . ': ' . $license->get_error_message() );
            return;
        }

        // Store license information in subscription meta
        if ( isset( $license['license_key'] ) ) {
            $subscription->update_meta_data( '_reactwoo_license_key', $license['license_key'] );
        }
        if ( isset( $license['id'] ) ) {
            $subscription->update_meta_data( '_reactwoo_license_id', $license['id'] );
        }
        if ( isset( $license['domain'] ) ) {
            $subscription->update_meta_data( '_reactwoo_license_domain', $license['domain'] );
        }
        if ( isset( $license['package_id'] ) ) {
            $subscription->update_meta_data( '_reactwoo_license_package_id', $license['package_id'] );
        }
        
        $subscription->save();

        // Also store in order meta for easy reference
        $order->update_meta_data( '_reactwoo_license_key', $license['license_key'] );
        $order->update_meta_data( '_reactwoo_license_id', $license['id'] );
        $order->save();

        // Log the creation
        error_log( 'ReactWoo API Manager: License created for subscription #' . $subscription->get_id() . ' - License Key: ' . $license['license_key'] );
    }

    /**
     * Get domain from order
     *
     * @param WC_Order $order Order object
     * @return string|null
     */
    private function get_domain_from_order( $order ) {
        // Check order meta for domain field
        $domain = $order->get_meta( '_reactwoo_domain' );
        if ( $domain ) {
            return $domain;
        }

        // Fallback to billing email domain (as last resort)
        $billing_email = $order->get_billing_email();
        if ( $billing_email ) {
            $email_parts = explode( '@', $billing_email );
            if ( isset( $email_parts[1] ) ) {
                return $email_parts[1];
            }
        }

        return null;
    }

    /**
     * Add domain field to checkout
     */
    public function add_domain_field_to_checkout() {
        $checkout = WC()->checkout();
        
        echo '<div id="reactwoo_domain_field">';
        woocommerce_form_field( 'reactwoo_domain', array(
            'type' => 'text',
            'class' => array( 'form-row-wide' ),
            'label' => __( 'License Domain', 'reactwoo-api-manager' ),
            'placeholder' => __( 'example.com', 'reactwoo-api-manager' ),
            'required' => true,
            'description' => __( 'Enter the domain where you will use the license key.', 'reactwoo-api-manager' ),
        ), $checkout->get_value( 'reactwoo_domain' ) );
        echo '</div>';
    }

    /**
     * Validate domain field on checkout
     */
    public function validate_domain_field() {
        if ( empty( $_POST['reactwoo_domain'] ) ) {
            wc_add_notice( __( 'Please enter your license domain.', 'reactwoo-api-manager' ), 'error' );
        } else {
            // Basic domain validation
            $domain = sanitize_text_field( $_POST['reactwoo_domain'] );
            if ( ! preg_match( '/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $domain ) ) {
                wc_add_notice( __( 'Please enter a valid domain name.', 'reactwoo-api-manager' ), 'error' );
            }
        }
    }

    /**
     * Save domain field from checkout
     *
     * @param int $order_id Order ID
     */
    public function save_checkout_domain_field( $order_id ) {
        if ( ! empty( $_POST['reactwoo_domain'] ) ) {
            $domain = sanitize_text_field( $_POST['reactwoo_domain'] );
            update_post_meta( $order_id, '_reactwoo_domain', $domain );
            
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_reactwoo_domain', $domain );
                $order->save();
            }
        }
    }

    /**
     * Display domain field in order admin
     *
     * @param WC_Order $order Order object
     */
    public function display_order_domain_field( $order ) {
        $domain = $order->get_meta( '_reactwoo_domain' );
        ?>
        <p class="form-field form-field-wide">
            <label for="reactwoo_domain"><?php esc_html_e( 'License Domain:', 'reactwoo-api-manager' ); ?></label>
            <input type="text" id="reactwoo_domain" name="reactwoo_domain" value="<?php echo esc_attr( $domain ); ?>" />
        </p>
        <?php
    }

    /**
     * Save domain field from order admin
     *
     * @param int $order_id Order ID
     */
    public function save_order_domain_field( $order_id ) {
        if ( isset( $_POST['reactwoo_domain'] ) ) {
            $domain = sanitize_text_field( $_POST['reactwoo_domain'] );
            update_post_meta( $order_id, '_reactwoo_domain', $domain );
            
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_reactwoo_domain', $domain );
                $order->save();
            }
        }
    }
}

