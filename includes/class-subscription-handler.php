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
     * One website for a maintenance plan or support pack.
     * Stored on the order line item, and on the subscription for a maintenance plan.
     */
    const SERVICE_DOMAIN_META = '_rw_service_domain';

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

        // Service website: one field per maintenance or support line. Not the licence domain.
        add_action( 'woocommerce_after_order_notes', array( $this, 'add_service_website_fields_to_checkout' ), 11 );
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_service_website_fields' ), 11 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_checkout_service_domain_line_item' ), 10, 4 );
        add_action( 'woocommerce_checkout_subscription_created', array( $this, 'save_service_domain_on_new_subscription' ), 10, 3 );
        add_action( 'wcs_renewal_order_created', array( $this, 'copy_service_domain_to_renewal_order' ), 10, 2 );
        add_action( 'woocommerce_subscription_item_switched', array( $this, 'keep_service_domain_on_switched_item' ), 10, 4 );

        // Add domain field to order admin
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_order_domain_field' ), 10, 1 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_service_domain_admin' ), 15, 1 );
        add_action( 'woocommerce_after_order_itemmeta', array( $this, 'display_service_domain_order_item' ), 10, 3 );
        add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_service_domain_item_meta' ) );
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
            $this->log_debug(
                'handle_subscription_activated: license already exists, skipping',
                array(
                    'subscription_id' => $subscription->get_id(),
                    'license_key'     => $license_key,
                )
            );
            return; // License already created
        }

        // Get parent order
        $order = $subscription->get_parent();
        if ( ! $order ) {
            $this->log_debug(
                'handle_subscription_activated: no parent order found, skipping',
                array(
                    'subscription_id' => $subscription->get_id(),
                )
            );
            return;
        }

        $this->log_debug(
            'handle_subscription_activated: creating license for activated subscription',
            array(
                'subscription_id' => $subscription->get_id(),
                'order_id'        => $order->get_id(),
            )
        );

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
        $bundle_licences = class_exists( 'ReactWoo_Maintenance_Bundle' ) ? ReactWoo_Maintenance_Bundle::stored_licenses( $subscription ) : array();

        if ( ( ! $license_key || ! $license_id ) && ! $bundle_licences ) {
            $this->log_debug(
                'handle_subscription_status_change: no license meta present, skipping',
                array(
                    'subscription_id' => $subscription->get_id(),
                    'old_status'      => $old_status,
                    'new_status'      => $new_status,
                )
            );
            return; // No license associated
        }

        $this->log_debug(
            'handle_subscription_status_change: syncing subscription status to license server',
            array(
                'subscription_id' => $subscription->get_id(),
                'old_status'      => $old_status,
                'new_status'      => $new_status,
            )
        );

        $api = new ReactWoo_License_Server_API();

        // Sync subscription state to license server v1 endpoint (it will map to license statuses)
        $current_period_end = $subscription->get_date( 'end' );
        if ( $license_key && $license_id ) {
            $api->sync_subscription_v1( $subscription->get_id(), $new_status, $current_period_end );
        }
        if ( $bundle_licences && class_exists( 'ReactWoo_Maintenance_Bundle' ) ) {
            ReactWoo_Maintenance_Bundle::sync_stored_licences( $subscription, $new_status, $current_period_end, $api );
        }

        /**
         * Observe-only. Cloud bridge must not replace this sync.
         *
         * @param WC_Subscription $subscription Subscription.
         * @param string          $old_status   Previous status.
         * @param string          $new_status   New status.
         */
        do_action( 'reactwoo_license_status_synced', $subscription, $old_status, $new_status );
    }

    /**
     * Create license for subscription on order completion
     *
     * @param int $order_id Order ID
     */
    public function maybe_create_license_on_order_completion( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $this->log_debug(
                'maybe_create_license_on_order_completion: order not found',
                array(
                    'order_id' => $order_id,
                )
            );
            return;
        }

        // Get subscriptions for this order
        $subscriptions = wcs_get_subscriptions_for_order( $order_id );

        if ( empty( $subscriptions ) ) {
            $this->log_debug(
                'maybe_create_license_on_order_completion: no subscriptions found for order',
                array(
                    'order_id' => $order_id,
                )
            );
        } else {
            $this->log_debug(
                'maybe_create_license_on_order_completion: processing subscriptions for order completion',
                array(
                    'order_id'          => $order_id,
                    'subscription_ids'  => wp_list_pluck( $subscriptions, 'id' ),
                )
            );
        }

        foreach ( $subscriptions as $subscription ) {
            // Check if license already exists
            $license_key = $subscription->get_meta( '_reactwoo_license_key' );
            if ( $license_key ) {
                $this->log_debug(
                    'maybe_create_license_on_order_completion: license already exists on subscription, skipping',
                    array(
                        'order_id'        => $order_id,
                        'subscription_id' => $subscription->get_id(),
                        'license_key'     => $license_key,
                    )
                );
                continue; // License already created
            }

            $this->log_debug(
                'maybe_create_license_on_order_completion: creating license for subscription on completed order',
                array(
                    'order_id'        => $order_id,
                    'subscription_id' => $subscription->get_id(),
                )
            );

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
        if ( class_exists( 'ReactWoo_Maintenance_Bundle' ) && ReactWoo_Maintenance_Bundle::is_maintenance_subscription( $subscription ) ) {
            ReactWoo_Maintenance_Bundle::note_paid_renewal( $subscription, $renewal_order );
            $this->create_license_for_subscription( $subscription, $renewal_order );
        }

        $license_id = $subscription->get_meta( '_reactwoo_license_id' );
        
        if ( ! $license_id ) {
            if ( class_exists( 'ReactWoo_Maintenance_Bundle' ) && ReactWoo_Maintenance_Bundle::stored_licenses( $subscription ) ) {
                do_action( 'reactwoo_license_renewed', $subscription, $renewal_order );
            }
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

        /**
         * Observe-only. Cloud bridge must not replace renewal licence handling.
         *
         * @param WC_Subscription $subscription  Subscription.
         * @param WC_Order        $renewal_order Renewal order.
         */
        do_action( 'reactwoo_license_renewed', $subscription, $renewal_order );
        
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
        $bundle_ids = class_exists( 'ReactWoo_Maintenance_Bundle' ) ? ReactWoo_Maintenance_Bundle::license_ids( $subscription ) : array();

        if ( ! $license_id && ! $bundle_ids ) {
            return;
        }

        $api = new ReactWoo_License_Server_API();
        
        // Set license to inactive on payment failure
        if ( $license_id ) {
            $api->update_license_status( $license_id, 'inactive' );
        }
        if ( $bundle_ids && class_exists( 'ReactWoo_Maintenance_Bundle' ) ) {
            ReactWoo_Maintenance_Bundle::mark_payment_failed( $subscription, $api );
        }
        
        // Log the payment failure
        $subscription->add_meta_data( '_reactwoo_payment_failure_date', current_time( 'mysql' ), true );
        $subscription->save();

        /**
         * Observe-only. Cloud bridge must not replace payment-failure licence handling.
         *
         * @param WC_Subscription $subscription Subscription.
         */
        do_action( 'reactwoo_license_payment_failed', $subscription );
    }

    /**
     * Create license for subscription
     *
     * @param WC_Subscription $subscription Subscription object
     * @param WC_Order        $order Parent order
     */
    private function create_license_for_subscription( $subscription, $order ) {
        $correlation_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'rw_', true );

        $this->log_debug(
            'create_license_for_subscription: entered',
            array(
                'correlation_id'  => $correlation_id,
                'subscription_id' => $subscription->get_id(),
                'order_id'        => $order->get_id(),
            )
        );

        // Snapshot of subscription items before we try to resolve the package
        $items = $subscription->get_items();
        $items_snapshot = array();
        foreach ( $items as $item ) {
            $items_snapshot[] = array(
                'item_id'       => $item->get_id(),
                'product_id'    => $item->get_product_id(),
                'variation_id'  => $item->get_variation_id(),
                'name'          => $item->get_name(),
                'type'          => $item->get_type(),
            );
        }
        $this->log_debug(
            'create_license_for_subscription: subscription items snapshot',
            array(
                'correlation_id'  => $correlation_id,
                'subscription_id' => $subscription->get_id(),
                'order_id'        => $order->get_id(),
                'item_count'      => count( $items_snapshot ),
                'items'           => $items_snapshot,
            )
        );

        // Get package ID from subscription items
        $package_id = null;
        $handled_maintenance = false;
        foreach ( $items as $item ) {
            $product = $item->get_product();
            // Check for subscription product types (including variations)
            if ( $product && ( $product->is_type( 'subscription' ) || $product->is_type( 'subscription_variation' ) ) ) {
                $product_id = $product->get_id();
                $maintenance_slugs = $this->maintenance_entitlement_slugs( $product );
                if ( is_array( $maintenance_slugs ) ) {
                    $handled_maintenance = true;
                    if ( class_exists( 'ReactWoo_Maintenance_Bundle' ) ) {
                        ReactWoo_Maintenance_Bundle::mark_subscription( $subscription );
                        $maintenance_slugs = ReactWoo_Maintenance_Bundle::slugs_for_subscription( $subscription, $maintenance_slugs );
                    }
                    foreach ( $maintenance_slugs as $maintenance_slug ) {
                        $this->provision_entitlement_slug( $subscription, $order, $maintenance_slug, $correlation_id );
                    }
                    if ( class_exists( 'ReactWoo_Maintenance_Bundle' ) ) {
                        ReactWoo_Maintenance_Bundle::revoke_to_match( $subscription, $maintenance_slugs );
                    }
                    $this->log_debug(
                        'create_license_for_subscription: maintenance entitlements resolved',
                        array(
                            'correlation_id'  => $correlation_id,
                            'subscription_id' => $subscription->get_id(),
                            'product_id'      => $product_id,
                            'slug_count'      => count( $maintenance_slugs ),
                        )
                    );
                    continue;
                }
                // For variable subscriptions, check variation first, then parent
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
                $this->log_debug(
                    'create_license_for_subscription: inspected subscription item product',
                    array(
                        'correlation_id'      => $correlation_id,
                        'subscription_id'     => $subscription->get_id(),
                        'order_id'            => $order->get_id(),
                        'item_id'             => $item->get_id(),
                        'product_id'          => isset( $product_id ) ? $product_id : null,
                        'product_type'        => $product->get_type(),
                        'package_id'          => $package_id,
                        'variation_parent_id' => isset( $parent_id ) ? $parent_id : null,
                    )
                );
                if ( $package_id ) {
                    break;
                }
            } elseif ( $product ) {
                $this->log_debug(
                    'create_license_for_subscription: skipping non-subscription product item',
                    array(
                        'correlation_id'  => $correlation_id,
                        'subscription_id' => $subscription->get_id(),
                        'order_id'        => $order->get_id(),
                        'item_id'         => $item->get_id(),
                        'product_id'      => $product->get_id(),
                        'product_type'    => $product->get_type(),
                    )
                );
            }
        }

        // Fallback: if subscription has no items (can happen for wizard-created subs),
        // try to resolve the package ID from the parent order's items instead.
        if ( ! $package_id ) {
            $this->log_debug(
                'create_license_for_subscription: no package from subscription items, falling back to order items',
                array(
                    'correlation_id'  => $correlation_id,
                    'subscription_id' => $subscription->get_id(),
                    'order_id'        => $order->get_id(),
                )
            );

            foreach ( $order->get_items() as $order_item ) {
                $order_product = $order_item->get_product();
                if ( ! $order_product ) {
                    continue;
                }

                $order_product_id = $order_product->get_id();
                $maintenance_slugs = $this->maintenance_entitlement_slugs( $order_product );
                if ( is_array( $maintenance_slugs ) ) {
                    if ( $handled_maintenance ) {
                        continue;
                    }
                    if ( class_exists( 'ReactWoo_Maintenance_Bundle' ) ) {
                        ReactWoo_Maintenance_Bundle::mark_subscription( $subscription );
                        $maintenance_slugs = ReactWoo_Maintenance_Bundle::slugs_for_subscription( $subscription, $maintenance_slugs );
                    }
                    foreach ( $maintenance_slugs as $maintenance_slug ) {
                        $this->provision_entitlement_slug( $subscription, $order, $maintenance_slug, $correlation_id );
                    }
                    if ( class_exists( 'ReactWoo_Maintenance_Bundle' ) ) {
                        ReactWoo_Maintenance_Bundle::revoke_to_match( $subscription, $maintenance_slugs );
                    }
                    continue;
                }
                $order_package_id = get_post_meta( $order_product_id, '_reactwoo_license_package_id', true );

                $this->log_debug(
                    'create_license_for_subscription: inspected order item product for fallback package lookup',
                    array(
                        'correlation_id'  => $correlation_id,
                        'subscription_id' => $subscription->get_id(),
                        'order_id'        => $order->get_id(),
                        'order_item_id'   => $order_item->get_id(),
                        'product_id'      => $order_product_id,
                        'product_type'    => $order_product->get_type(),
                        'package_id'      => $order_package_id,
                    )
                );

                if ( $order_package_id ) {
                    $package_id = $order_package_id;
                    break;
                }
            }
        }

        if ( ! $package_id ) {
            // No package selected for this subscription product
            $this->log_debug(
                'create_license_for_subscription: no license package ID found for subscription',
                array(
                    'correlation_id'  => $correlation_id,
                    'subscription_id' => $subscription->get_id(),
                )
            );
            return;
        }

        // Get domain from order (stored for reference; v1 provisioning is keyed by subscription, not domain)
        $domain = $this->get_domain_from_order( $order );

        $this->log_debug(
            'create_license_for_subscription: resolved package and domain',
            array(
                'correlation_id'  => $correlation_id,
                'subscription_id' => $subscription->get_id(),
                'order_id'        => $order->get_id(),
                'package_id'      => $package_id,
                'domain'          => $domain,
            )
        );

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
        // Fallback: some wizard-created subscriptions may have 0 total; fall back to parent order total.
        if ( ! $subscription_price && $order instanceof WC_Order ) {
            $subscription_price = $order->get_total();
        }
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
            $this->log_debug(
                'create_license_for_subscription: failed to look up package',
                array(
                    'correlation_id' => $correlation_id,
                    'package_id'     => $package_id,
                    'error'          => $package->get_error_message(),
                )
            );
            return;
        }
        if ( ! $package || empty( $package['slug'] ) ) {
            $this->log_debug(
                'create_license_for_subscription: package slug missing',
                array(
                    'correlation_id' => $correlation_id,
                    'package_id'     => $package_id,
                    'package'        => $package,
                )
            );
            return;
        }

        $customer_email = $order->get_billing_email();
        $customer_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        $provision_args = array(
            'customer_email'     => $customer_email,
            'customer_name'      => $customer_name,
            'package_slug'       => $package['slug'],
            'wc_subscription_id' => $subscription->get_id(),
            'wc_order_id'        => $order->get_id(),
            'status'             => 'active',
            'domain'             => $domain,
            'correlation_id'     => $correlation_id,
            'price'              => $subscription_price,
            'currency'           => $currency,
            'start_date'         => $start_date,
            'billing_period'     => $billing_period,
            'billing_interval'   => $billing_interval,
            'renewal_frequency'  => $renewal_frequency,
        );
        if ( function_exists( 'apply_filters' ) ) {
            $plan_code = (string) apply_filters( 'reactwoo_license_provision_plan_code', '', $subscription, $order, $package );
            if ( $plan_code !== '' ) {
                $provision_args['plan_code'] = $plan_code;
            }
        }

        // Provision via v1 endpoint (idempotent on subscription id)
        // Include pricing/billing metadata so license server can populate price, billing_period, etc.
        $license = $api->provision_license_v1( $provision_args );

        if ( is_wp_error( $license ) ) {
            $this->log_debug(
                'create_license_for_subscription: failed to create license via API',
                array(
                    'correlation_id'  => $correlation_id,
                    'subscription_id' => $subscription->get_id(),
                    'order_id'        => $order->get_id(),
                    'error'           => $license->get_error_message(),
                    'error_data'      => $license->get_error_data(),
                )
            );
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

        /**
         * Observe-only after a licence key is stored. Cloud bridge must not generate keys.
         *
         * @param WC_Subscription $subscription Subscription.
         * @param WC_Order        $order        Parent order.
         * @param array           $license      Licence server payload.
         */
        do_action( 'reactwoo_license_generated', $subscription, $order, $license );

        // Also store in order meta for easy reference
        $order->update_meta_data( '_reactwoo_license_key', $license['license_key'] );
        if ( isset( $license['license_id'] ) ) {
            $order->update_meta_data( '_reactwoo_license_id', $license['license_id'] );
        }
        $order->save();

        // Log the creation
        $this->log_debug(
            'create_license_for_subscription: license created successfully',
            array(
                'correlation_id'  => $correlation_id,
                'subscription_id' => $subscription->get_id(),
                'order_id'        => $order->get_id(),
                'license_key'     => isset( $license['license_key'] ) ? $license['license_key'] : null,
                'license_id'      => isset( $license['license_id'] ) ? $license['license_id'] : null,
            )
        );
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
                if ( class_exists( 'ReactWoo_Maintenance_Bundle' ) ) {
                    foreach ( ReactWoo_Maintenance_Bundle::license_ids( $subscription ) as $bundle_license_id ) {
                        $license_ids[] = $bundle_license_id;
                    }
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
     * Whether checkout should collect and validate a licence domain.
     *
     * True when the cart contains at least one product that issues a plugin
     * licence. An unreadable cart keeps the historical requirement so a
     * licensed checkout is not silently skipped.
     *
     * Renewals and subscription switches use the product in the cart. They
     * are not exempt, and they do not force the field on for a service.
     *
     * @param WC_Cart|object|null $cart Cart. Null uses the current WooCommerce cart.
     * @return bool
     */
    public function cart_needs_license_domain( $cart = null ) {
        if ( null === $cart ) {
            if ( function_exists( 'WC' ) && WC() && is_object( WC()->cart ) && method_exists( WC()->cart, 'get_cart' ) ) {
                $cart = WC()->cart;
            } else {
                return true;
            }
        }

        if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
            return true;
        }

        $items = $cart->get_cart();
        if ( ! is_array( $items ) ) {
            return true;
        }

        foreach ( $items as $item ) {
            if ( $this->product_issues_plugin_licence( $this->cart_item_product( $item ) ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether purchasing this product provisions a plugin licence.
     *
     * Matches create_license_for_subscription: subscription products (including
     * variations) with a positive _reactwoo_license_package_id on the product
     * or its parent. That package id is the licensed-product flag. Maintenance
     * plans do not issue a plugin licence, even when a package id is still
     * stored. Products already excluded by reactwoo_api_manager_unlicensed_product_ids
     * (maintenance plans 3654, 3657, 3660 and their variations, by default)
     * are the same service products.
     *
     * @param WC_Product|object|null $product Product.
     * @return bool
     */
    public function product_issues_plugin_licence( $product ) {
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! method_exists( $product, 'is_type' ) ) {
            return false;
        }

        if ( ! $product->is_type( 'subscription' ) && ! $product->is_type( 'subscription_variation' ) && ! $product->is_type( 'variable-subscription' ) ) {
            return false;
        }

        if ( is_array( $this->maintenance_entitlement_slugs( $product ) ) ) {
            return false;
        }

        if ( $this->product_is_unlicensed_service( $product ) ) {
            return false;
        }

        if ( $this->product_is_support_pack( $product ) ) {
            return false;
        }

        return $this->license_package_id_for_product( $product ) > 0;
    }

    /**
     * Product object stored on a cart line, or a lookup by variation / product id.
     *
     * @param array<string,mixed>|mixed $item Cart item.
     * @return object|null
     */
    private function cart_item_product( $item ) {
        if ( is_array( $item ) && isset( $item['data'] ) && is_object( $item['data'] ) && method_exists( $item['data'], 'get_id' ) ) {
            return $item['data'];
        }

        $product_id = 0;
        if ( is_array( $item ) ) {
            if ( ! empty( $item['variation_id'] ) ) {
                $product_id = (int) $item['variation_id'];
            } elseif ( ! empty( $item['product_id'] ) ) {
                $product_id = (int) $item['product_id'];
            }
        }

        if ( $product_id && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $product_id );
            return is_object( $product ) ? $product : null;
        }

        return null;
    }

    /**
     * Licence package id from the product, then its parent. Same lookup as
     * subscription licence creation.
     *
     * @param object $product Product.
     * @return int
     */
    private function license_package_id_for_product( $product ) {
        $package_id = absint( get_post_meta( $product->get_id(), '_reactwoo_license_package_id', true ) );
        if ( ! $package_id && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
            $package_id = absint( get_post_meta( (int) $product->get_parent_id(), '_reactwoo_license_package_id', true ) );
        }

        return (int) $package_id;
    }

    /**
     * Service products the plugin already refuses to treat as licensed.
     *
     * The default ids are maintenance plans. Variations inherit the parent
     * id. Support packs and hosting are not listed here: they are services
     * because they have no licence package id.
     *
     * @param object $product Product.
     * @return bool
     */
    private function product_is_unlicensed_service( $product ) {
        $excluded = array( 3654, 3657, 3660 );
        if ( function_exists( 'apply_filters' ) ) {
            $excluded = (array) apply_filters( 'reactwoo_api_manager_unlicensed_product_ids', $excluded );
        }

        $excluded   = array_map( 'intval', $excluded );
        $product_id = (int) $product->get_id();
        $parent_id  = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;

        return in_array( $product_id, $excluded, true ) || ( $parent_id && in_array( $parent_id, $excluded, true ) );
    }

    /**
     * Domain pattern used by licence checkout and service-website checkout.
     *
     * @return string
     */
    private function checkout_domain_pattern() {
        return '/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/';
    }

    /**
     * Sanitise a checkout website the same way as the licence domain check.
     * An invalid value becomes an empty string and is not stored.
     *
     * @param mixed $raw Posted value.
     * @return string
     */
    public function normalize_checkout_domain( $raw ) {
        $domain = sanitize_text_field( (string) $raw );
        if ( '' === $domain || ! preg_match( $this->checkout_domain_pattern(), $domain ) ) {
            return '';
        }
        return $domain;
    }

    /**
     * Maintenance plan by product meta or the known plan ids, including variations.
     *
     * @param object|null $product Product.
     * @return bool
     */
    public function product_is_maintenance_plan( $product ) {
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return false;
        }
        if ( is_array( $this->maintenance_entitlement_slugs( $product ) ) ) {
            return true;
        }
        return $this->product_id_in_list( $product, array( 3654, 3657, 3660 ) );
    }

    /**
     * Support packs 3663–3666, including a variation of one of those parents.
     *
     * @param object|null $product Product.
     * @return bool
     */
    public function product_is_support_pack( $product ) {
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return false;
        }
        $ids = array( 3663, 3664, 3665, 3666 );
        if ( function_exists( 'apply_filters' ) ) {
            $ids = (array) apply_filters( 'reactwoo_api_manager_support_pack_product_ids', $ids );
        }
        return $this->product_id_in_list( $product, $ids );
    }

    /**
     * Maintenance plans and support packs must be tied to one website.
     * Hosting is not included.
     *
     * @param object|null $product Product.
     * @return bool
     */
    public function product_needs_service_website( $product ) {
        return $this->product_is_maintenance_plan( $product ) || $this->product_is_support_pack( $product );
    }

    /**
     * @param object $product Product.
     * @param array<int,mixed> $ids Product ids.
     * @return bool
     */
    private function product_id_in_list( $product, $ids ) {
        $ids        = array_map( 'intval', (array) $ids );
        $product_id = (int) $product->get_id();
        $parent_id  = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
        return in_array( $product_id, $ids, true ) || ( $parent_id && in_array( $parent_id, $ids, true ) );
    }

    /**
     * Add domain field to checkout
     */
    public function add_domain_field_to_checkout() {
        if ( ! $this->cart_needs_license_domain() ) {
            return;
        }

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
        if ( ! $this->cart_needs_license_domain() ) {
            return;
        }

        if ( empty( $_POST['reactwoo_domain'] ) ) {
            wc_add_notice( __( 'Please enter your license domain.', 'reactwoo-api-manager' ), 'error' );
        } else {
            // Basic domain validation
            $domain = sanitize_text_field( $_POST['reactwoo_domain'] );
            if ( ! preg_match( $this->checkout_domain_pattern(), $domain ) ) {
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

    /**
     * Service lines that still need a website entered at checkout.
     * Renewals and switches are omitted; they keep the stored site.
     *
     * One entry per cart line, so two plans can name two sites. The licence
     * domain stays a separate field and is never written here.
     *
     * @param WC_Cart|object|null $cart Cart. Null uses the current cart.
     * @return array<string,object>
     */
    public function cart_service_website_fields( $cart = null ) {
        if ( null === $cart ) {
            if ( function_exists( 'WC' ) && WC() && is_object( WC()->cart ) && method_exists( WC()->cart, 'get_cart' ) ) {
                $cart = WC()->cart;
            } else {
                return array();
            }
        }

        if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
            return array();
        }

        $items = $cart->get_cart();
        if ( ! is_array( $items ) ) {
            return array();
        }

        $fields = array();
        foreach ( $items as $key => $item ) {
            if ( $this->cart_item_reuses_service_website( $item ) ) {
                continue;
            }
            $product = $this->cart_item_product( $item );
            if ( ! $this->product_needs_service_website( $product ) ) {
                continue;
            }
            $fields[ (string) $key ] = $product;
        }

        return $fields;
    }

    /**
     * Website fields for maintenance plans and support packs.
     */
    public function add_service_website_fields_to_checkout() {
        $lines = $this->cart_service_website_fields();
        if ( ! $lines ) {
            return;
        }

        $checkout = WC()->checkout();
        $multiple = count( $lines ) > 1;
        foreach ( $lines as $key => $product ) {
            $field_name = 'rw_service_domain[' . $key . ']';
            $label      = __( 'Website for this service', 'reactwoo-api-manager' );
            if ( $multiple ) {
                $label = sprintf(
                    /* translators: %s is the product name. */
                    __( 'Website for this service (%s)', 'reactwoo-api-manager' ),
                    $this->service_product_label( $product )
                );
            }
            $description = $this->product_is_support_pack( $product )
                ? __( 'This support applies to that one site.', 'reactwoo-api-manager' )
                : __( 'This plan applies to that one site.', 'reactwoo-api-manager' );

            echo '<div id="rw_service_domain_' . esc_attr( $key ) . '_field" class="rw-service-domain-field">';
            woocommerce_form_field(
                $field_name,
                array(
                    'type'        => 'text',
                    'class'       => array( 'form-row-wide' ),
                    'label'       => $label,
                    'placeholder' => __( 'example.com', 'reactwoo-api-manager' ),
                    'required'    => true,
                    'description' => $description,
                ),
                $checkout->get_value( $field_name )
            );
            echo '</div>';
        }
    }

    /**
     * Require a website for each new maintenance or support line.
     */
    public function validate_service_website_fields() {
        foreach ( $this->cart_service_website_fields() as $key => $product ) {
            $label = $this->service_product_label( $product );
            $raw   = $this->posted_service_domain_raw( $key );
            if ( '' === trim( (string) $raw ) ) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s is the product name. */
                        __( 'Please enter the website for %s.', 'reactwoo-api-manager' ),
                        $label
                    ),
                    'error'
                );
                continue;
            }
            if ( '' === $this->normalize_checkout_domain( $raw ) ) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s is the product name. */
                        __( 'Please enter a valid website for %s.', 'reactwoo-api-manager' ),
                        $label
                    ),
                    'error'
                );
            }
        }
    }

    /**
     * Store the service website on the order line. Never copies the licence domain.
     *
     * @param WC_Order_Item_Product $item Cart line being saved.
     * @param string                $cart_item_key Cart key.
     * @param array<string,mixed>   $values Cart line.
     * @param WC_Order              $order Order.
     */
    public function save_checkout_service_domain_line_item( $item, $cart_item_key, $values, $order ) {
        unset( $order );
        $product = $this->cart_item_product( $values );
        if ( ! $this->product_needs_service_website( $product ) ) {
            return;
        }

        if ( $this->cart_item_reuses_service_website( $values ) ) {
            $domain = $this->stored_service_domain_for_cart_item( $values );
        } else {
            $domain = $this->normalize_checkout_domain( $this->posted_service_domain_raw( $cart_item_key ) );
        }

        if ( '' === $domain || ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
            return;
        }

        $item->add_meta_data( self::SERVICE_DOMAIN_META, $domain, true );
    }

    /**
     * Copy maintenance websites onto the new subscription.
     *
     * Line item meta is kept for every service line. Subscription meta is set
     * when every maintenance line on that subscription names the same site.
     *
     * @param WC_Subscription $subscription New subscription.
     * @param WC_Order        $order Parent order.
     * @param WC_Cart|mixed   $recurring_cart Recurring cart.
     */
    public function save_service_domain_on_new_subscription( $subscription, $order, $recurring_cart ) {
        unset( $recurring_cart );
        if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_items' ) ) {
            return;
        }

        $from_order          = $this->service_domains_by_product( $order );
        $maintenance_domains = array();

        foreach ( $subscription->get_items() as $item ) {
            if ( ! is_object( $item ) ) {
                continue;
            }
            $product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
            if ( ! $this->product_needs_service_website( $product ) ) {
                continue;
            }

            $domain = method_exists( $item, 'get_meta' )
                ? $this->normalize_checkout_domain( $item->get_meta( self::SERVICE_DOMAIN_META, true ) )
                : '';
            if ( '' === $domain ) {
                $domain = $this->domain_for_product_ids( $from_order, $product, $item );
            }
            if ( '' === $domain ) {
                continue;
            }

            if ( method_exists( $item, 'update_meta_data' ) ) {
                $item->update_meta_data( self::SERVICE_DOMAIN_META, $domain );
                if ( method_exists( $item, 'save' ) ) {
                    $item->save();
                }
            }
            if ( $this->product_is_maintenance_plan( $product ) ) {
                $maintenance_domains[] = $domain;
            }
        }

        $maintenance_domains = array_values( array_unique( $maintenance_domains ) );
        if ( 1 === count( $maintenance_domains ) && method_exists( $subscription, 'update_meta_data' ) ) {
            $subscription->update_meta_data( self::SERVICE_DOMAIN_META, $maintenance_domains[0] );
            if ( method_exists( $subscription, 'save' ) ) {
                $subscription->save();
            }
        }
    }

    /**
     * Automatic renewals do not show checkout. Copy the stored site onto the renewal lines.
     *
     * @param WC_Order        $renewal_order Renewal order.
     * @param WC_Subscription $subscription Subscription.
     */
    public function copy_service_domain_to_renewal_order( $renewal_order, $subscription ) {
        $domain = $this->stored_service_domain_on_subscription( $subscription );
        if ( '' === $domain || ! is_object( $renewal_order ) || ! method_exists( $renewal_order, 'get_items' ) ) {
            return;
        }

        foreach ( $renewal_order->get_items() as $item ) {
            if ( ! is_object( $item ) || ! method_exists( $item, 'update_meta_data' ) ) {
                continue;
            }
            $product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
            if ( ! $this->product_needs_service_website( $product ) ) {
                continue;
            }
            $item->update_meta_data( self::SERVICE_DOMAIN_META, $domain );
            if ( method_exists( $item, 'save' ) ) {
                $item->save();
            }
        }
    }

    /**
     * A switch keeps the site already stored on the subscription.
     *
     * @param WC_Order        $order Switch order.
     * @param WC_Subscription $subscription Subscription.
     * @param mixed           $add_line_item New line.
     * @param mixed           $remove_line_item Previous line.
     */
    public function keep_service_domain_on_switched_item( $order, $subscription, $add_line_item, $remove_line_item ) {
        unset( $order );
        $domain = '';
        if ( is_object( $remove_line_item ) && method_exists( $remove_line_item, 'get_meta' ) ) {
            $domain = $this->normalize_checkout_domain( $remove_line_item->get_meta( self::SERVICE_DOMAIN_META, true ) );
        }
        if ( '' === $domain ) {
            $domain = $this->stored_service_domain_on_subscription( $subscription );
        }
        if ( '' === $domain ) {
            return;
        }

        if ( is_object( $add_line_item ) && method_exists( $add_line_item, 'update_meta_data' ) ) {
            $add_line_item->update_meta_data( self::SERVICE_DOMAIN_META, $domain );
            if ( method_exists( $add_line_item, 'save' ) ) {
                $add_line_item->save();
            }
        }

        $product = ( is_object( $add_line_item ) && method_exists( $add_line_item, 'get_product' ) ) ? $add_line_item->get_product() : null;
        if ( $this->product_is_maintenance_plan( $product ) && is_object( $subscription ) && method_exists( $subscription, 'update_meta_data' ) ) {
            $subscription->update_meta_data( self::SERVICE_DOMAIN_META, $domain );
            if ( method_exists( $subscription, 'save' ) ) {
                $subscription->save();
            }
        }
    }

    /**
     * Admin order and subscription screen: the site a service is for.
     *
     * @param WC_Order $order Order or subscription.
     */
    public function display_service_domain_admin( $order ) {
        $lines = $this->service_domain_rows( $order );
        if ( ! $lines ) {
            return;
        }
        echo '<div class="form-field form-field-wide rw-service-domain">';
        echo '<p><strong>' . esc_html__( 'Website for this service', 'reactwoo-api-manager' ) . '</strong></p>';
        foreach ( $lines as $line ) {
            $text = '' !== $line['name'] ? $line['name'] . ': ' . $line['domain'] : $line['domain'];
            echo '<p>' . esc_html( $text ) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Admin order item row.
     *
     * @param int                       $item_id Item id.
     * @param WC_Order_Item_Product     $item Item.
     * @param WC_Product|false|null     $product Product.
     */
    public function display_service_domain_order_item( $item_id, $item, $product ) {
        unset( $item_id, $product );
        if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
            return;
        }
        $domain = $this->normalize_checkout_domain( $item->get_meta( self::SERVICE_DOMAIN_META, true ) );
        if ( '' === $domain ) {
            return;
        }
        echo '<div class="rw-service-domain"><strong>' . esc_html__( 'Website for this service:', 'reactwoo-api-manager' ) . '</strong> ' . esc_html( $domain ) . '</div>';
    }

    /**
     * Keep the raw meta key out of customer-facing item meta.
     *
     * @param array<int,string> $keys Hidden keys.
     * @return array<int,string>
     */
    public function hide_service_domain_item_meta( $keys ) {
        $keys[] = self::SERVICE_DOMAIN_META;
        return $keys;
    }

    /**
     * @param array<string,mixed>|mixed $cart_item Cart line.
     * @return bool
     */
    private function cart_item_reuses_service_website( $cart_item ) {
        if ( ! is_array( $cart_item ) ) {
            return false;
        }
        return ! empty( $cart_item['subscription_renewal'] ) || ! empty( $cart_item['subscription_switch'] );
    }

    /**
     * @param string $cart_key Cart line key.
     * @return string
     */
    private function posted_service_domain_raw( $cart_key ) {
        if ( empty( $_POST['rw_service_domain'] ) || ! is_array( $_POST['rw_service_domain'] ) ) {
            return '';
        }
        if ( ! isset( $_POST['rw_service_domain'][ $cart_key ] ) ) {
            return '';
        }
        return (string) $_POST['rw_service_domain'][ $cart_key ];
    }

    /**
     * @param object $product Product.
     * @return string
     */
    private function service_product_label( $product ) {
        if ( is_object( $product ) && method_exists( $product, 'get_name' ) ) {
            $name = trim( (string) $product->get_name() );
            if ( '' !== $name ) {
                return $name;
            }
        }
        if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
            return '#' . (int) $product->get_id();
        }
        return __( 'this service', 'reactwoo-api-manager' );
    }

    /**
     * @param array<string,mixed> $cart_item Renewal or switch line.
     * @return string
     */
    private function stored_service_domain_for_cart_item( $cart_item ) {
        $subscription_id = 0;
        if ( ! empty( $cart_item['subscription_renewal']['subscription_id'] ) ) {
            $subscription_id = (int) $cart_item['subscription_renewal']['subscription_id'];
        } elseif ( ! empty( $cart_item['subscription_switch']['subscription_id'] ) ) {
            $subscription_id = (int) $cart_item['subscription_switch']['subscription_id'];
        }
        if ( ! $subscription_id || ! function_exists( 'wcs_get_subscription' ) ) {
            return '';
        }
        $subscription = wcs_get_subscription( $subscription_id );
        return $this->stored_service_domain_on_subscription( $subscription );
    }

    /**
     * @param object|null $subscription Subscription.
     * @return string
     */
    private function stored_service_domain_on_subscription( $subscription ) {
        if ( ! is_object( $subscription ) ) {
            return '';
        }
        if ( method_exists( $subscription, 'get_meta' ) ) {
            $domain = $this->normalize_checkout_domain( $subscription->get_meta( self::SERVICE_DOMAIN_META, true ) );
            if ( '' !== $domain ) {
                return $domain;
            }
        }
        if ( ! method_exists( $subscription, 'get_items' ) ) {
            return '';
        }
        foreach ( $subscription->get_items() as $line ) {
            if ( ! is_object( $line ) || ! method_exists( $line, 'get_meta' ) ) {
                continue;
            }
            $domain = $this->normalize_checkout_domain( $line->get_meta( self::SERVICE_DOMAIN_META, true ) );
            if ( '' !== $domain ) {
                return $domain;
            }
        }
        return '';
    }

    /**
     * @param WC_Order|object|null $order Order.
     * @return array<int,string|null>
     */
    private function service_domains_by_product( $order ) {
        $map = array();
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
            return $map;
        }
        foreach ( $order->get_items() as $item ) {
            if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
                continue;
            }
            $domain = $this->normalize_checkout_domain( $item->get_meta( self::SERVICE_DOMAIN_META, true ) );
            if ( '' === $domain ) {
                continue;
            }
            $ids = array();
            $product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
            if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
                $ids[] = (int) $product->get_id();
                if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
                    $ids[] = (int) $product->get_parent_id();
                }
            }
            if ( method_exists( $item, 'get_product_id' ) ) {
                $ids[] = (int) $item->get_product_id();
            }
            if ( method_exists( $item, 'get_variation_id' ) && $item->get_variation_id() ) {
                $ids[] = (int) $item->get_variation_id();
            }
            foreach ( $ids as $id ) {
                if ( ! $id ) {
                    continue;
                }
                if ( array_key_exists( $id, $map ) && $map[ $id ] !== $domain ) {
                    $map[ $id ] = null;
                } elseif ( ! array_key_exists( $id, $map ) ) {
                    $map[ $id ] = $domain;
                }
            }
        }
        return $map;
    }

    /**
     * @param array<int,string|null> $map Product id to domain.
     * @param object|null            $product Product.
     * @param object                 $item Subscription line.
     * @return string
     */
    private function domain_for_product_ids( $map, $product, $item ) {
        $ids = array();
        if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
            $ids[] = (int) $product->get_id();
            if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
                $ids[] = (int) $product->get_parent_id();
            }
        }
        if ( method_exists( $item, 'get_product_id' ) ) {
            $ids[] = (int) $item->get_product_id();
        }
        if ( method_exists( $item, 'get_variation_id' ) && $item->get_variation_id() ) {
            $ids[] = (int) $item->get_variation_id();
        }
        foreach ( $ids as $id ) {
            if ( $id && isset( $map[ $id ] ) && is_string( $map[ $id ] ) && '' !== $map[ $id ] ) {
                return $map[ $id ];
            }
        }
        return '';
    }

    /**
     * @param object|null $order Order or subscription.
     * @return array<int,array{name:string,domain:string}>
     */
    private function service_domain_rows( $order ) {
        $rows = array();
        if ( ! is_object( $order ) ) {
            return $rows;
        }
        if ( method_exists( $order, 'get_items' ) ) {
            foreach ( $order->get_items() as $item ) {
                if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
                    continue;
                }
                $domain = $this->normalize_checkout_domain( $item->get_meta( self::SERVICE_DOMAIN_META, true ) );
                if ( '' === $domain ) {
                    continue;
                }
                $name = method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '';
                $rows[] = array(
                    'name'   => $name,
                    'domain' => $domain,
                );
            }
        }
        if ( ! $rows && method_exists( $order, 'get_meta' ) ) {
            $domain = $this->normalize_checkout_domain( $order->get_meta( self::SERVICE_DOMAIN_META, true ) );
            if ( '' !== $domain ) {
                $rows[] = array(
                    'name'   => '',
                    'domain' => $domain,
                );
            }
        }
        return $rows;
    }

    /**
     * Maintenance plans provision only reactwoo_product_entitlements.
     * An empty list performs no licence call, even if a package id is stored on the product.
     *
     * @param WC_Product $product Product.
     * @return array<int,string>|null Null when the product is not a Maintenance plan.
     */
    private function maintenance_entitlement_slugs( $product ) {
        if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
            return null;
        }

        $product_id = (int) $product->get_id();
        $key        = (string) get_post_meta( $product_id, '_rw_maintenance_plan_key', true );
        if ( ! in_array( $key, array( 'essential', 'standard', 'business' ), true ) && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
            $key = (string) get_post_meta( (int) $product->get_parent_id(), '_rw_maintenance_plan_key', true );
        }
        if ( ! in_array( $key, array( 'essential', 'standard', 'business' ), true ) ) {
            return null;
        }

        $slugs = array();
        if ( class_exists( 'RW_Maintenance_Plans' ) ) {
            $resolved = RW_Maintenance_Plans::resolve( $key );
            $slugs    = isset( $resolved['reactwoo_product_entitlements'] ) ? (array) $resolved['reactwoo_product_entitlements'] : array();
        }
        if ( function_exists( 'apply_filters' ) ) {
            $slugs = apply_filters( 'reactwoo_maintenance_product_entitlements', $slugs, $key, $product_id );
        }

        $blocked = class_exists( 'RW_Maintenance_Plans' ) ? RW_Maintenance_Plans::third_party_premium_entitlements() : array();
        $clean   = array();
        foreach ( (array) $slugs as $slug ) {
            $slug = sanitize_key( (string) $slug );
            if ( '' === $slug || in_array( $slug, $blocked, true ) ) {
                continue;
            }
            $clean[] = $slug;
        }

        return array_values( array_unique( $clean ) );
    }

    /**
     * Provision one bundled ReactWoo package. Not used while Maintenance entitlements are empty.
     *
     * @param WC_Subscription $subscription Subscription.
     * @param WC_Order        $order        Order.
     * @param string          $slug         Package slug.
     * @param string          $correlation_id Correlation id.
     */
    private function provision_entitlement_slug( $subscription, $order, $slug, $correlation_id ) {
        $slug = sanitize_key( (string) $slug );
        if ( '' === $slug ) {
            return;
        }

        if ( ! class_exists( 'ReactWoo_Maintenance_Bundle' ) ) {
            $this->log_debug(
                'create_license_for_subscription: bundled entitlement was not provisioned',
                array(
                    'correlation_id' => $correlation_id,
                    'package_slug'   => $slug,
                    'error'          => 'maintenance bundle unavailable',
                )
            );
            return;
        }

        ReactWoo_Maintenance_Bundle::provision_slug( $subscription, $order, $slug, $correlation_id );
    }

    /**
     * Internal helper for structured debug logging.
     *
     * Logs only when WP_DEBUG is enabled to avoid noisy production logs.
     *
     * @param string $message
     * @param array  $context
     */
    private function log_debug( $message, $context = array() ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $line = 'ReactWoo API Manager: ' . $message;
            if ( ! empty( $context ) ) {
                if ( class_exists( 'ReactWoo_Maintenance_Bundle' ) ) {
                    $context = ReactWoo_Maintenance_Bundle::redact( $context );
                }
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                $line .= ' | ' . wp_json_encode( $context );
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( $line );
        }
    }
}

