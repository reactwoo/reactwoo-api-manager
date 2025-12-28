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

    <h2><?php esc_html_e( 'Sync Licenses from Server', 'reactwoo-api-manager' ); ?></h2>
    <p><?php esc_html_e( 'Fetch licenses from the license server and associate them with packages if needed.', 'reactwoo-api-manager' ); ?></p>
    
    <form method="post" action="" id="sync-licenses-form">
        <?php wp_nonce_field( 'reactwoo_sync_licenses', 'reactwoo_sync_nonce' ); ?>
        <p>
            <label for="sync_domain">
                <?php esc_html_e( 'Domain (optional - leave empty to sync all)', 'reactwoo-api-manager' ); ?>
            </label><br>
            <input type="text" id="sync_domain" name="sync_domain" class="regular-text" placeholder="example.com" />
        </p>
        <?php submit_button( __( 'Sync Licenses', 'reactwoo-api-manager' ), 'primary', 'sync_licenses', false ); ?>
    </form>

    <?php
    /**
     * Sync licenses from server
     */
    function reactwoo_sync_licenses_from_server( $domain = '' ) {
        $api = new ReactWoo_License_Server_API();
        
        if ( $domain ) {
            $licenses = $api->get_licenses_by_domain( $domain );
        } else {
            // Bulk sync all licenses - this requires admin authentication on the license server
            // For now, we'll show a helpful message
            echo '<div class="notice notice-info"><p>';
            echo esc_html__( 'To sync all licenses at once, please enter a domain name above. This will sync all licenses for that specific domain from the license server.', 'reactwoo-api-manager' );
            echo '<br><em>' . esc_html__( 'Note: Bulk syncing all licenses across all domains requires additional authentication setup on the license server.', 'reactwoo-api-manager' ) . '</em>';
            echo '</p></div>';
            return;
        }

        if ( is_wp_error( $licenses ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $licenses->get_error_message() ) . '</p></div>';
            return;
        }

        $synced = 0;
        foreach ( $licenses as $license ) {
            // Try to find matching subscription by license key
            $subscriptions = get_posts( array(
                'post_type' => 'shop_subscription',
                'posts_per_page' => 1,
                'meta_query' => array(
                    array(
                        'key' => '_reactwoo_license_key',
                        'value' => $license['license_key'],
                        'compare' => '=',
                    ),
                ),
            ) );

            if ( empty( $subscriptions ) ) {
                // License exists on server but not in WordPress
                // Could create a record or just skip
                continue;
            }

            $subscription = wcs_get_subscription( $subscriptions[0]->ID );
            if ( $subscription ) {
                // Update license information
                if ( isset( $license['id'] ) ) {
                    $subscription->update_meta_data( '_reactwoo_license_id', $license['id'] );
                }
                if ( isset( $license['package_id'] ) && ! $subscription->get_meta( '_reactwoo_license_package_id' ) ) {
                    $subscription->update_meta_data( '_reactwoo_license_package_id', $license['package_id'] );
                }
                $subscription->save();
                $synced++;
            }
        }

        if ( $synced > 0 ) {
            echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Synced %d license(s).', 'reactwoo-api-manager' ), $synced ) . '</p></div>';
        } else {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'No licenses to sync.', 'reactwoo-api-manager' ) . '</p></div>';
        }
    }

    // Handle sync action
    if ( isset( $_POST['sync_licenses'] ) && check_admin_referer( 'reactwoo_sync_licenses', 'reactwoo_sync_nonce' ) ) {
        reactwoo_sync_licenses_from_server( isset( $_POST['sync_domain'] ) ? sanitize_text_field( $_POST['sync_domain'] ) : '' );
    }
    ?>
</div>

