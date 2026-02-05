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
        add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_create_license_on_order_completion' ), 5, 1 );
        
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

        add_action( 'wp_trash_post', array( $this, 'handle_order_trashed' ), 10, 1 );
        add_action( 'before_delete_post', array( $this, 'handle_order_deleted' ), 10, 1 );

        // HPOS-safe hooks (WooCommerce order lifecycle)
        add_action( 'woocommerce_before_trash_order', array( $this, 'handle_wc_order_trashed' ), 10, 1 );
        add_action( 'woocommerce_trash_order', array( $this, 'handle_wc_order_trashed' ), 10, 1 );
        add_action( 'woocommerce_before_delete_order', array( $this, 'handle_wc_order_deleted' ), 10, 1 );
        add_action( 'woocommerce_delete_order', array( $this, 'handle_wc_order_deleted' ), 10, 1 );
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

        // Sync subscription state to license server v1 endpoint (it will map to license statuses)
        $current_period_end = $subscription->get_date( 'end' );
        $api->sync_subscription_v1( $subscription->get_id(), $new_status, $current_period_end );
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
        $license_id = $subscription->get_meta( '_reactwoo_license_id' );
        
        if ( ! $license_id ) {
            return;
        }

        // Get updated pricing information from renewal
        $subscription_price = $subscription->get_total();
        $currency = $subscription->get_currency();
        $billing_period = $subscription->get_billing_period();
        $billing_interval = $subscription->get_billing_interval();
        
        // Calculate new expiration date
        $expires_at = null;
        if ( $subscription->get_date( 'end' ) ) {
            $expires_at = $subscription->get_date( 'end' );
        } elseif ( $billing_period ) {
            $expires_at = date( 'Y-m-d H:i:s', strtotime( '+' . $billing_interval . ' ' . $billing_period ) );
        }

        // Update license with new pricing and expiration via API
        // Note: This assumes the license server has an endpoint to update license pricing
        // For now, we'll log it and the license server can track renewals separately
        $api = new ReactWoo_License_Server_API();
        
        // Store renewal pricing in subscription meta for reference
        $subscription->add_meta_data( '_reactwoo_last_renewal', current_time( 'mysql' ), true );
        $subscription->add_meta_data( '_reactwoo_last_renewal_price', $subscription_price, true );
        $subscription->add_meta_data( '_reactwoo_last_renewal_currency', $currency, true );
        $subscription->save();
        
        // TODO: If license server has an update endpoint for pricing, call it here
        // $api->update_license_pricing( $license_id, $pricing_data );
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
            // Check for subscription product types (including variations)
            if ( $product && ( $product->is_type( 'subscription' ) || $product->is_type( 'subscription_variation' ) ) ) {
                // For variable subscriptions, check variation first, then parent
                $product_id = $product->get_id();
                if ( $product->is_type( 'subscription_variation' ) ) {
                    $variation_id = $product_id;
                    $package_id = get_post_meta( $variation_id, '_reactwoo_license_package_id', true );
                    // If not set on variation, check parent
                    if ( ! $package_id ) {
                        $parent_id = $product->get_parent_id();
                        $package_id = get_post_meta( $parent_id, '_reactwoo_license_package_id', true );
                    }
                } else {
                    $package_id = get_post_meta( $product_id, '_reactwoo_license_package_id', true );
                }
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

        // Get domain from order (stored for reference; v1 provisioning is keyed by subscription, not domain)
        $domain = $this->get_domain_from_order( $order );

        // Calculate expiration date based on subscription billing period
        $expires_at = null;
        $billing_period = $subscription->get_billing_period();
        $billing_interval = $subscription->get_billing_interval();
        
        if ( $subscription->get_date( 'end' ) ) {
            $expires_at = $subscription->get_date( 'end' );
        } elseif ( $billing_period ) {
            // Calculate expiration based on billing period
            $expires_at = date( 'Y-m-d H:i:s', strtotime( '+' . $billing_interval . ' ' . $billing_period ) );
        }

        // Get subscription pricing information
        $subscription_price = $subscription->get_total();
        $currency = $subscription->get_currency();
        $start_date = $subscription->get_date( 'start' ) ? $subscription->get_date( 'start' ) : current_time( 'mysql' );

        // Calculate human-readable renewal frequency
        $renewal_frequency = '';
        if ( $billing_period && $billing_interval ) {
            $periods = array(
                'day' => 'Daily',
                'week' => 'Weekly',
                'month' => 'Monthly',
                'year' => 'Yearly',
            );
            $period_label = isset( $periods[ $billing_period ] ) ? $periods[ $billing_period ] : ucfirst( $billing_period );
            if ( $billing_interval > 1 ) {
                $renewal_frequency = sprintf( 'Every %d %s', $billing_interval, $period_label );
            } else {
                $renewal_frequency = $period_label;
            }
        }

        // Prepare pricing data to send to license server
        $pricing_data = array(
            'price' => $subscription_price,
            'currency' => $currency,
            'start_date' => $start_date,
        );

        if ( $billing_period ) {
            $pricing_data['billing_period'] = $billing_period;
        }
        if ( $billing_interval ) {
            $pricing_data['billing_interval'] = $billing_interval;
        }
        if ( $renewal_frequency ) {
            $pricing_data['renewal_frequency'] = $renewal_frequency;
        }

        $api = new ReactWoo_License_Server_API();
        $package = $api->get_package_by_id( $package_id );
        if ( is_wp_error( $package ) ) {
            error_log( 'ReactWoo API Manager: Failed to look up package #' . $package_id . ' (' . $package->get_error_message() . ')' );
            return;
        }
        if ( ! $package || empty( $package['slug'] ) ) {
            error_log( 'ReactWoo API Manager: Package slug missing for package #' . $package_id );
            return;
        }

        $customer_email = $order->get_billing_email();
        $customer_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        // Provision via v1 endpoint (idempotent on subscription id)
        $license = $api->provision_license_v1(
            array(
                'customer_email'     => $customer_email,
                'customer_name'      => $customer_name,
                'package_slug'       => $package['slug'],
                'wc_subscription_id' => $subscription->get_id(),
                'wc_order_id'        => $order->get_id(),
                'status'             => 'active',
            )
        );

        if ( is_wp_error( $license ) ) {
            error_log( 'ReactWoo API Manager: Failed to create license for subscription #' . $subscription->get_id() . ': ' . $license->get_error_message() );
            return;
        }

        // Store license information in subscription meta
        if ( isset( $license['license_key'] ) ) {
            $subscription->update_meta_data( '_reactwoo_license_key', $license['license_key'] );
        }
        if ( isset( $license['license_id'] ) ) {
            $subscription->update_meta_data( '_reactwoo_license_id', $license['license_id'] );
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
        if ( isset( $license['license_id'] ) ) {
            $order->update_meta_data( '_reactwoo_license_id', $license['license_id'] );
        }
        $order->save();

        // Log the creation
        error_log( 'ReactWoo API Manager: License created for subscription #' . $subscription->get_id() . ' - License Key: ' . $license['license_key'] );
    }

    /**
     * Handle order moving to trash.
     *
     * @param int $post_id Post ID
     */
    public function handle_order_trashed( $post_id ) {
        if ( get_post_type( $post_id ) !== 'shop_order' ) {
            return;
        }
        $this->deactivate_order_license( $post_id );
    }

    /**
     * Handle order deletion.
     *
     * @param int $post_id Post ID
     */
    public function handle_order_deleted( $post_id ) {
        if ( get_post_type( $post_id ) !== 'shop_order' ) {
            return;
        }
        $this->deactivate_order_license( $post_id );
    }

    public function handle_wc_order_trashed( $order_id ) {
        $this->deactivate_order_license( intval( $order_id ) );
    }

    public function handle_wc_order_deleted( $order_id ) {
        $this->deactivate_order_license( intval( $order_id ) );
    }

    /**
     * Deactivate license associated with an order.
     *
     * @param int $order_id Order ID
     */
    private function deactivate_order_license( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $license_ids = $this->collect_license_ids_for_order( $order );

        if ( empty( $license_ids ) ) {
            return;
        }

        $api = new ReactWoo_License_Server_API();
        foreach ( array_unique( $license_ids ) as $license_id ) {
            if ( ! $license_id ) {
                continue;
            }

            $result = $api->update_license_status( $license_id, 'inactive' );
            if ( is_wp_error( $result ) ) {
                error_log( 'ReactWoo API Manager: Failed to deactivate license #' . $license_id . ' after order deletion - ' . $result->get_error_message() );
            }
        }
    }

    /**
     * Collect license IDs associated with an order (order + linked subscriptions).
     *
     * @param WC_Order $order
     * @return array
     */
    private function collect_license_ids_for_order( $order ) {
        $license_ids = array();

        $order_license_id = $order->get_meta( '_reactwoo_license_id' );
        if ( $order_license_id ) {
            $license_ids[] = $order_license_id;
        }

        if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            $subscriptions = wcs_get_subscriptions_for_order( $order->get_id() );
            foreach ( $subscriptions as $subscription ) {
                $subscription_license_id = $subscription->get_meta( '_reactwoo_license_id', true );
                if ( $subscription_license_id ) {
                    $license_ids[] = $subscription_license_id;
                }
            }
        }

        // Fallback: if we don't have IDs saved locally, try to locate license by domain+package_type and deactivate.
        if ( empty( $license_ids ) ) {
            $domain = $order->get_meta( '_reactwoo_domain', true );
            if ( $domain ) {
                $package_ids = array();
                if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
                    $subscriptions = wcs_get_subscriptions_for_order( $order->get_id() );
                    foreach ( $subscriptions as $subscription ) {
                        foreach ( $subscription->get_items() as $item ) {
                            $product = $item->get_product();
                            if ( $product ) {
                                $package_id = get_post_meta( $product->get_id(), '_reactwoo_license_package_id', true );
                                if ( $package_id ) {
                                    $package_ids[] = $package_id;
                                }
                            }
                        }
                    }
                }

                $package_ids = array_unique( array_filter( $package_ids ) );
                if ( ! empty( $package_ids ) ) {
                    $api = new ReactWoo_License_Server_API();
                    $licenses = $api->get_licenses_by_domain( $domain );
                    if ( ! is_wp_error( $licenses ) && is_array( $licenses ) ) {
                        foreach ( $package_ids as $pid ) {
                            $ptype = $api->get_package_type_by_id( $pid );
                            if ( is_wp_error( $ptype ) ) {
                                $ptype = null;
                            }
                            foreach ( $licenses as $l ) {
                                $matches = false;
                                if ( $ptype && isset( $l['package_type'] ) ) {
                                    $matches = ( $l['package_type'] === $ptype );
                                } elseif ( isset( $l['package_id'] ) ) {
                                    $matches = ( intval( $l['package_id'] ) === intval( $pid ) );
                                }

                                if ( $matches && isset( $l['id'] ) ) {
                                    $license_ids[] = $l['id'];
                                }
                            }
                        }
                    }
                }
            }
        }

        return $license_ids;
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

