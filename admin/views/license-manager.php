<?php
/**
 * License Manager Page Template
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Handle actions
if ( isset( $_GET['action'] ) && isset( $_GET['subscription_id'] ) && check_admin_referer( 'reactwoo_license_action' ) ) {
    $subscription_id = intval( $_GET['subscription_id'] );
    $subscription = wcs_get_subscription( $subscription_id );
    
    if ( $subscription ) {
        switch ( $_GET['action'] ) {
            case 'sync_license':
                // Sync license information from server
                $this->sync_license_from_server( $subscription );
                break;
        }
    }
}

$manual_license_result = $this->process_manual_license_creation();
$subscription_products = $this->get_license_subscription_products();
$license_api = new ReactWoo_License_Server_API();
$package_map = array();
$packages = $license_api->get_packages();
if ( ! is_wp_error( $packages ) && is_array( $packages ) ) {
    foreach ( $packages as $package ) {
        if ( isset( $package['id'] ) ) {
            $package_map[ intval( $package['id'] ) ] = $package;
        }
    }
}

// Get all subscriptions with licenses
$subscriptions_query = new WP_Query( array(
    'post_type' => 'shop_subscription',
    'posts_per_page' => -1,
    'post_status' => 'any',
    'meta_query' => array(
        array(
            'key' => '_reactwoo_license_key',
            'compare' => 'EXISTS',
        ),
    ),
) );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'License Manager', 'reactwoo-api-manager' ); ?></h1>
    <p><?php esc_html_e( 'Manage licenses associated with WooCommerce subscriptions.', 'reactwoo-api-manager' ); ?></p>

    <?php if ( $manual_license_result ) : ?>
        <div class="notice <?php echo is_wp_error( $manual_license_result ) ? 'notice-error' : 'notice-success'; ?> inline">
            <p>
                <?php
                if ( is_wp_error( $manual_license_result ) ) {
                    echo esc_html( $manual_license_result->get_error_message() );
                } else {
                    echo esc_html( $manual_license_result['message'] );
                    if ( isset( $manual_license_result['order_id'] ) ) {
                        echo '<br />';
                        printf(
                            esc_html__( 'Order #%1$d and Subscription #%2$d have been created. The license creation process will run automatically once the subscription is active.', 'reactwoo-api-manager' ),
                            intval( $manual_license_result['order_id'] ),
                            intval( $manual_license_result['subscription_id'] )
                        );
                    }
                }
                ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="reactwoo-manual-order-panel" style="background: #fff; border: 1px solid #dcdcdc; padding: 24px; margin: 20px 0; border-radius: 6px;">
        <h2><?php esc_html_e( 'Generate License Order', 'reactwoo-api-manager' ); ?></h2>
        <p><?php esc_html_e( 'Create the order, domain, and subscription in one step so the license creation workflow runs automatically.', 'reactwoo-api-manager' ); ?></p>
        <form method="post">
            <?php wp_nonce_field( 'reactwoo_manual_license', 'reactwoo_manual_license_nonce' ); ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="manual_customer_email"><?php esc_html_e( 'Customer Email', 'reactwoo-api-manager' ); ?></label>
                        </th>
                        <td>
                            <input type="email" id="manual_customer_email" name="manual_customer_email" class="regular-text" required value="<?php echo isset( $_POST['manual_customer_email'] ) ? esc_attr( sanitize_email( wp_unslash( $_POST['manual_customer_email'] ) ) ) : ''; ?>" />
                            <p class="description"><?php esc_html_e( 'Use an existing customer email or enter a new one (a customer account will be created automatically).', 'reactwoo-api-manager' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="manual_license_product"><?php esc_html_e( 'Subscription Product', 'reactwoo-api-manager' ); ?></label>
                        </th>
                        <td>
                            <select id="manual_license_product" name="manual_license_product">
                                <option value=""><?php esc_html_e( '-- Select a subscription product --', 'reactwoo-api-manager' ); ?></option>
                                <?php foreach ( $subscription_products as $product ) : ?>
                                    <?php
                                        $product_id = $product->get_id();
                                        $selected = isset( $_POST['manual_license_product'] ) && intval( $_POST['manual_license_product'] ) === $product_id;
                                        $package_id = get_post_meta( $product_id, '_reactwoo_license_package_id', true );
                                        $package_name = $package_id && isset( $package_map[ intval( $package_id ) ] ) ? $package_map[ intval( $package_id ) ]['name'] : __( 'No license package assigned', 'reactwoo-api-manager' );
                                    ?>
                                    <option value="<?php echo esc_attr( $product_id ); ?>" <?php selected( $selected ); ?>>
                                        <?php echo esc_html( $product->get_name() . ' — ' . $package_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'The chosen product must already be linked to a license package type.', 'reactwoo-api-manager' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="manual_license_domain"><?php esc_html_e( 'License Domain', 'reactwoo-api-manager' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="manual_license_domain" name="manual_license_domain" class="regular-text" required value="<?php echo isset( $_POST['manual_license_domain'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['manual_license_domain'] ) ) ) : ''; ?>" />
                            <p class="description"><?php esc_html_e( 'Enter the domain that should be associated with this license.', 'reactwoo-api-manager' ); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button( __( 'Create Order & Subscription', 'reactwoo-api-manager' ), 'primary', 'reactwoo_create_manual_license' ); ?>
        </form>
    </div>

    <div class="reactwoo-license-manager">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Subscription', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'Customer', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'License Key', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'Domain', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'reactwoo-api-manager' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $subscriptions = array();
                foreach ( $subscriptions_query->posts as $post ) {
                    $subscription = wcs_get_subscription( $post->ID );
                    if ( $subscription ) {
                        $subscriptions[] = $subscription;
                    }
                }

                if ( empty( $subscriptions ) ) :
                ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px;">
                            <p style="margin: 0; color: #646970;">
                                <?php esc_html_e( 'No subscriptions with licenses found.', 'reactwoo-api-manager' ); ?>
                            </p>
                            <p style="margin: 10px 0 0 0; font-size: 13px; color: #8c8f94;">
                                <?php esc_html_e( 'Licenses will appear here once subscriptions with license package types are created and orders are completed.', 'reactwoo-api-manager' ); ?>
                            </p>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $subscriptions as $subscription ) : ?>
                        <?php
                        $license_key = $subscription->get_meta( '_reactwoo_license_key' );
                        $license_id = $subscription->get_meta( '_reactwoo_license_id' );
                        $license_domain = $subscription->get_meta( '_reactwoo_license_domain' );
                        $order = $subscription->get_parent();
                        
                        if ( ! $license_key ) {
                            continue;
                        }
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' ) ); ?>">
                                    #<?php echo esc_html( $subscription->get_id() ); ?>
                                </a>
                            </td>
                            <td>
                                <?php
                                $customer_id = $subscription->get_customer_id();
                                $customer = new WC_Customer( $customer_id );
                                echo esc_html( $customer->get_display_name() );
                                echo '<br><small>' . esc_html( $customer->get_email() ) . '</small>';
                                ?>
                            </td>
                            <td>
                                <code><?php echo esc_html( $license_key ); ?></code>
                                <?php if ( $license_id ) : ?>
                                    <br><small><?php printf( esc_html__( 'ID: %d', 'reactwoo-api-manager' ), $license_id ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $license_domain ? esc_html( $license_domain ) : '—'; ?>
                            </td>
                            <td>
                                <?php
                                $status = $subscription->get_status();
                                $status_labels = wcs_get_subscription_statuses();
                                echo '<span class="subscription-status status-' . esc_attr( $status ) . '">';
                                echo esc_html( isset( $status_labels[ 'wc-' . $status ] ) ? $status_labels[ 'wc-' . $status ] : $status );
                                echo '</span>';
                                ?>
                            </td>
                            <td>
                                <?php echo esc_html( $subscription->get_date_created()->date_i18n( get_option( 'date_format' ) ) ); ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' ) ); ?>" class="button button-small">
                                    <?php esc_html_e( 'View', 'reactwoo-api-manager' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <hr>

    <h2><?php esc_html_e( 'Sync & Match Licenses', 'reactwoo-api-manager' ); ?></h2>
    
    <div class="reactwoo-sync-section" style="background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid #2271b1;">
        <h3><?php esc_html_e( 'Match Existing Licenses', 'reactwoo-api-manager' ); ?></h3>
        <p><?php esc_html_e( 'Match licenses from the server with your WooCommerce subscriptions. This will attempt to link licenses to subscriptions by license key, domain, or customer email.', 'reactwoo-api-manager' ); ?></p>
        
        <form method="post" action="" id="match-licenses-form">
            <?php wp_nonce_field( 'reactwoo_match_licenses', 'reactwoo_match_nonce' ); ?>
            <?php submit_button( __( 'Match Licenses to Subscriptions', 'reactwoo-api-manager' ), 'secondary', 'match_licenses', false ); ?>
        </form>
    </div>

    <div class="reactwoo-sync-section" style="background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid #2271b1;">
        <h3><?php esc_html_e( 'Sync Licenses from Server', 'reactwoo-api-manager' ); ?></h3>
    <p><?php esc_html_e( 'Fetch licenses from the license server and associate them with packages if needed. Leave domain empty to sync all licenses, or enter a domain to sync licenses for that specific domain only.', 'reactwoo-api-manager' ); ?></p>
    
    <form method="post" action="" id="sync-licenses-form">
        <?php wp_nonce_field( 'reactwoo_sync_licenses', 'reactwoo_sync_nonce' ); ?>
        <p>
            <label for="sync_domain">
                <?php esc_html_e( 'Domain (optional - leave empty to sync all licenses)', 'reactwoo-api-manager' ); ?>
            </label><br>
            <input type="text" id="sync_domain" name="sync_domain" class="regular-text" placeholder="example.com (leave empty for all)" />
            <span class="description"><?php esc_html_e( 'Note: Syncing all licenses requires an API key to be configured in Settings.', 'reactwoo-api-manager' ); ?></span>
        </p>
        <?php submit_button( __( 'Sync Licenses', 'reactwoo-api-manager' ), 'primary', 'sync_licenses', false ); ?>
    </form>

    <?php
    // Load license sync class
    require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-license-sync.php';

    /**
     * Sync licenses from server and match to subscriptions
     */
    function reactwoo_sync_licenses_from_server( $domain = '' ) {
        $api = new ReactWoo_License_Server_API();
        
        if ( $domain ) {
            // Sync licenses for a specific domain
            $licenses = $api->get_licenses_by_domain( $domain );
        } else {
            // Sync all licenses from the server
            $licenses = $api->get_all_licenses();
            
            if ( is_wp_error( $licenses ) ) {
                if ( $licenses->get_error_code() === 'api_auth_error' ) {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'API key is required to sync all licenses. Please configure your API key in ReactWoo Licenses > Settings.', 'reactwoo-api-manager' );
                    echo '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html( sprintf( __( 'Error fetching licenses: %s', 'reactwoo-api-manager' ), $licenses->get_error_message() ) );
                    echo '</p></div>';
                }
                return;
            }
        }

        if ( is_wp_error( $licenses ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $licenses->get_error_message() ) . '</p></div>';
            return;
        }

        // Match licenses to subscriptions
        $match_results = ReactWoo_License_Sync::match_licenses_to_subscriptions( $licenses );
        
        // Display results
        echo ReactWoo_License_Sync::format_match_results( $match_results );
    }

    /**
     * Sync and match licenses (separate action)
     */
    function reactwoo_match_licenses_locally() {
        $api = new ReactWoo_License_Server_API();
        
        // Get all licenses from server
        $licenses = $api->get_all_licenses();
        
        if ( is_wp_error( $licenses ) ) {
            if ( $licenses->get_error_code() === 'api_auth_error' ) {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__( 'API key is required to sync licenses. Please configure your API key in ReactWoo Licenses > Settings.', 'reactwoo-api-manager' );
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>';
                echo esc_html( sprintf( __( 'Error fetching licenses: %s', 'reactwoo-api-manager' ), $licenses->get_error_message() ) );
                echo '</p></div>';
            }
            return;
        }

        if ( empty( $licenses ) ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'No licenses found on the server.', 'reactwoo-api-manager' ) . '</p></div>';
            return;
        }

        // Match licenses to subscriptions
        $match_results = ReactWoo_License_Sync::match_licenses_to_subscriptions( $licenses );
        
        // Display results
        echo ReactWoo_License_Sync::format_match_results( $match_results );
    }

    // Handle sync action
    if ( isset( $_POST['sync_licenses'] ) && check_admin_referer( 'reactwoo_sync_licenses', 'reactwoo_sync_nonce' ) ) {
        reactwoo_sync_licenses_from_server( isset( $_POST['sync_domain'] ) ? sanitize_text_field( $_POST['sync_domain'] ) : '' );
    }

    // Handle match licenses action (separate from sync)
    if ( isset( $_POST['match_licenses'] ) && check_admin_referer( 'reactwoo_match_licenses', 'reactwoo_match_nonce' ) ) {
        reactwoo_match_licenses_locally();
    }
    ?>
</div>

