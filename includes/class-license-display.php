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
        add_shortcode( 'reactwoo_license_keys', array( $this, 'render_license_shortcode' ) );
        add_shortcode( 'license_keys', array( $this, 'render_license_shortcode' ) );
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
        $license_domain = $order->get_meta( '_reactwoo_license_domain' );
        if ( $license_key ) {
            return array(
                'key'    => $license_key,
                'domain' => $license_domain,
            );
        }

        // Fallback: derive domain+package from the order and fetch from server (cached).
        $domain = $order->get_meta( '_reactwoo_domain', true );
        if ( ! $domain ) {
            return null;
        }

        $package_id = null;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product ) {
                $maybe_package_id = get_post_meta( $product->get_id(), '_reactwoo_license_package_id', true );
                if ( $maybe_package_id ) {
                    $package_id = $maybe_package_id;
                    break;
                }
            }
        }

        if ( ! $package_id ) {
            return null;
        }

        $license = $this->get_cached_license( $domain, $package_id );
        if ( ! $license ) {
            return null;
        }

        // Backfill order meta for future use (email templates/admin list)
        $order->update_meta_data( '_reactwoo_license_key', $license['key'] );
        $order->update_meta_data( '_reactwoo_license_domain', $license['domain'] );
        $order->save();

        return $license;
    }

    /**
     * Get license info from a subscription, fallback to parent order.
     *
     * @param WC_Subscription $subscription
     * @return array|null
     */
    private function get_subscription_license_data( $subscription ) {
        $domain = $subscription->get_meta( '_reactwoo_license_domain', true );
        $package_id = $subscription->get_meta( '_reactwoo_license_package_id', true );
        $license_key = $subscription->get_meta( '_reactwoo_license_key', true );

        // Fallbacks for older data (or when meta wasn't saved correctly):
        // - domain may exist only on parent order as _reactwoo_domain
        // - package_id may exist only on product meta
        if ( ! $domain ) {
            $parent_order = $subscription->get_parent();
            if ( $parent_order instanceof WC_Order ) {
                $domain = $parent_order->get_meta( '_reactwoo_domain', true );
            }
        }

        if ( ! $package_id ) {
            foreach ( $subscription->get_items() as $item ) {
                $product = $item->get_product();
                if ( $product ) {
                    $maybe_package_id = get_post_meta( $product->get_id(), '_reactwoo_license_package_id', true );
                    if ( $maybe_package_id ) {
                        $package_id = $maybe_package_id;
                        break;
                    }
                }
            }
        }

        // If we can identify domain+package, fetch from server (cached).
        if ( $domain && $package_id ) {
            $license = $this->get_cached_license( $domain, $package_id );
            if ( $license ) {
                return $license;
            }
        }

        // Final fallback to local meta
        if ( $license_key ) {
            return array(
                'key'    => $license_key,
                'domain' => $domain,
            );
        }

        $order = $subscription->get_parent();
        if ( $order instanceof WC_Order ) {
            $license_key = $order->get_meta( '_reactwoo_license_key', true );
            if ( $license_key ) {
                return array(
                    'key'    => $license_key,
                    'domain' => $order->get_meta( '_reactwoo_license_domain', true ),
                );
            }
        }

        return null;
    }
    /**
     * Get license data from the server and cache result briefly.
     *
     * @param string $domain
     * @param int    $package_id
     * @return array|null
     */
    private function get_cached_license( $domain, $package_id ) {
        if ( ! $domain || ! $package_id ) {
            return null;
        }

        $cache_key = 'reactwoo_license_' . md5( $domain . '_' . $package_id );
        $cached = get_transient( $cache_key );
        if ( $cached ) {
            return $cached;
        }

        $api = new ReactWoo_License_Server_API();
        $package_type = $api->get_package_type_by_id( $package_id );
        if ( is_wp_error( $package_type ) || ! $package_type ) {
            return null;
        }

        // Use domain endpoint (no API key required) as source-of-truth for customer UI.
        $licenses = $api->get_licenses_by_domain( $domain );
        if ( is_wp_error( $licenses ) || empty( $licenses ) ) {
            return null;
        }

        $license = null;
        foreach ( $licenses as $l ) {
            if ( isset( $l['package_type'] ) && $l['package_type'] === $package_type && ( ! isset( $l['status'] ) || $l['status'] === 'active' ) ) {
                $license = $l;
                break;
            }
        }

        if ( ! $license ) {
            return null;
        }

        $value = array(
            'key'    => isset( $license['license_key'] ) ? $license['license_key'] : ( isset( $license['licenseKey'] ) ? $license['licenseKey'] : '' ),
            'domain' => isset( $license['domain'] ) ? $license['domain'] : $domain,
        );

        set_transient( $cache_key, $value, MINUTE_IN_SECONDS * 5 );
        return $value;
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
            // Always show the field (even if pending), per UX request.
            $fields['reactwoo_license_key'] = array(
                'label' => __( 'License Key', 'reactwoo-api-manager' ),
                'value' => __( 'Pending — please check My Account → License shortly.', 'reactwoo-api-manager' ),
            );
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

        // Treat download param as subscription ID and validate ownership.
        $subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( intval( $download ) ) : null;
        if ( ! $subscription || ! ( $subscription instanceof WC_Subscription ) ) {
            return;
        }
        if ( intval( $subscription->get_customer_id() ) !== intval( get_current_user_id() ) ) {
            return;
        }

        $license = $this->get_subscription_license_data( $subscription );
        if ( ! $license || empty( $license['key'] ) ) {
            return;
        }

        $filename = 'reactwoo-license-' . sanitize_file_name( $license['key'] ) . '.txt';
        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        echo "ReactWoo License\n";
        echo "License Key: {$license['key']}\n";
        if ( ! empty( $license['domain'] ) ) {
            echo "Domain: {$license['domain']}\n";
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
        $licenses = $this->get_user_license_rows();
        if ( empty( $licenses ) ) {
            echo '<p>' . esc_html__( 'No licenses found. Complete a subscription to generate one.', 'reactwoo-api-manager' ) . '</p>';
            return;
        }

        $this->print_license_rows( $licenses );
    }

    public function render_license_shortcode() {
        $licenses = $this->get_user_license_rows();
        if ( empty( $licenses ) ) {
            return '<p>' . esc_html__( 'No licenses found. Complete a subscription to generate one.', 'reactwoo-api-manager' ) . '</p>';
        }

        ob_start();
        $this->print_license_rows( $licenses );
        return ob_get_clean();
    }

    private function get_user_license_rows() {
        $licenses = array();
        if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
            return $licenses;
        }

        $subscriptions = wcs_get_users_subscriptions( array( 'user_id' => get_current_user_id() ) );
        foreach ( $subscriptions as $subscription ) {
            // Use server as source-of-truth (cached). If meta is missing, we can still show the license.
            $license = $this->get_subscription_license_data( $subscription );
            if ( ! $license || empty( $license['key'] ) ) {
                continue;
            }

            $item = $subscription->get_items();
            $product_name = '';
            if ( ! empty( $item ) ) {
                $product_name = reset( $item )->get_name();
            }

            $licenses[] = array(
                'key'    => $license['key'],
                'domain' => isset( $license['domain'] ) ? $license['domain'] : '',
                'name'   => $product_name,
                'id'     => $subscription->get_id(),
            );

            // Self-heal: persist meta back to subscription when we successfully discovered a license.
            if ( ! $subscription->get_meta( '_reactwoo_license_key', true ) ) {
                $subscription->update_meta_data( '_reactwoo_license_key', $license['key'] );
                if ( ! empty( $license['domain'] ) ) {
                    $subscription->update_meta_data( '_reactwoo_license_domain', $license['domain'] );
                }
                $subscription->save();
            }
        }

        return $licenses;
    }

    private function print_license_rows( $licenses ) {
        echo '<div class="reactwoo-licenses-table">';
        foreach ( $licenses as $license ) {
            echo '<div class="reactwoo-license-row" style="border:1px solid #dcdcdc;padding:16px;margin-bottom:16px;border-radius:6px;">';
            echo '<h2>' . esc_html( $license['name'] ?: esc_html__( 'Subscription', 'reactwoo-api-manager' ) ) . ' #' . esc_html( $license['id'] ) . '</h2>';
            echo '<p><strong>' . esc_html__( 'License Key', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $license['key'] ) . '</p>';
            if ( $license['domain'] ) {
                echo '<p><strong>' . esc_html__( 'Domain', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $license['domain'] ) . '</p>';
            }
            $download_url = esc_url( add_query_arg( 'reactwoo_license_download', $license['id'], trailingslashit( get_permalink( wc_get_page_id( 'myaccount' ) ) . 'license' ) ) );
            echo '<p><a class="button button-secondary" href="' . $download_url . '">' . esc_html__( 'Download License File', 'reactwoo-api-manager' ) . '</a></p>';
            echo '</div>';
        }
        echo '</div>';
    }
}
