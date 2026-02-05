<?php
/**
 * License Manager Page Template
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Fetch licenses from the server as source-of-truth (cached for admin UX)
$licenses_cache_key = 'reactwoo_admin_licenses_cache_v1';
$licenses_cache_ttl = 2 * MINUTE_IN_SECONDS;
$licenses = null;
$licenses_error = null;
$status_update_notice = null;
$status_update_error  = null;

// Handle status change actions from this screen.
if ( isset( $_POST['reactwoo_update_license_status'] ) && check_admin_referer( 'reactwoo_update_license_status', 'reactwoo_update_license_status_nonce' ) ) {
    if ( current_user_can( 'manage_woocommerce' ) ) {
        $license_id = isset( $_POST['reactwoo_license_id'] ) ? absint( $_POST['reactwoo_license_id'] ) : 0;
        $new_status = isset( $_POST['reactwoo_new_status'] ) ? sanitize_text_field( wp_unslash( $_POST['reactwoo_new_status'] ) ) : '';

        if ( $license_id && $new_status ) {
            $api    = new ReactWoo_License_Server_API();
            $result = $api->update_license_status( $license_id, $new_status );

            if ( is_wp_error( $result ) ) {
                $status_update_error = sprintf(
                    /* translators: 1: status, 2: error message */
                    __( 'Failed to update license status to %1$s: %2$s', 'reactwoo-api-manager' ),
                    $new_status,
                    $result->get_error_message()
                );
            } else {
                $status_update_notice = sprintf(
                    /* translators: 1: status */
                    __( 'License status updated to %1$s.', 'reactwoo-api-manager' ),
                    $new_status
                );
                // Clear cache so we re-fetch fresh statuses.
                delete_transient( $licenses_cache_key );
            }
        } else {
            $status_update_error = __( 'Missing license ID or status for update.', 'reactwoo-api-manager' );
        }
    } else {
        $status_update_error = __( 'You do not have permission to change license statuses.', 'reactwoo-api-manager' );
    }
}

if ( isset( $_POST['reactwoo_refresh_server_licenses'] ) && check_admin_referer( 'reactwoo_refresh_server_licenses', 'reactwoo_refresh_server_licenses_nonce' ) ) {
    delete_transient( $licenses_cache_key );
}

$licenses = get_transient( $licenses_cache_key );
if ( false === $licenses ) {
    $api = new ReactWoo_License_Server_API();
    $licenses = $api->get_all_licenses();
    if ( is_wp_error( $licenses ) ) {
        $licenses_error = $licenses;
        $licenses = array();
    } else {
        set_transient( $licenses_cache_key, $licenses, $licenses_cache_ttl );
    }
}

// Build a lightweight map of local subscriptions by license key (optional, only if meta exists)
$subscription_by_license_key = array();
if ( function_exists( 'wcs_get_subscriptions' ) ) {
    $local_subs = wcs_get_subscriptions( array(
        'limit'  => -1,
        'status' => 'any',
    ) );
    foreach ( $local_subs as $sub ) {
        $k = $sub->get_meta( '_reactwoo_license_key', true );
        if ( $k ) {
            $subscription_by_license_key[ $k ] = $sub;
        }
    }
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'License Manager', 'reactwoo-api-manager' ); ?></h1>
    <p><?php esc_html_e( 'Licenses shown below are pulled from the license server (cached briefly).', 'reactwoo-api-manager' ); ?></p>

    <?php if ( $status_update_notice ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html( $status_update_notice ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $status_update_error ) : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html( $status_update_error ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" style="margin: 12px 0 18px;">
        <?php wp_nonce_field( 'reactwoo_refresh_server_licenses', 'reactwoo_refresh_server_licenses_nonce' ); ?>
        <?php submit_button( __( 'Refresh from Server', 'reactwoo-api-manager' ), 'secondary', 'reactwoo_refresh_server_licenses', false ); ?>
        <span class="description" style="margin-left: 8px;">
            <?php esc_html_e( 'Cache duration: ~2 minutes.', 'reactwoo-api-manager' ); ?>
        </span>
    </form>

    <?php if ( $licenses_error ) : ?>
        <div class="notice notice-error">
            <p>
                <?php echo esc_html( sprintf( __( 'Error fetching licenses from server: %s', 'reactwoo-api-manager' ), $licenses_error->get_error_message() ) ); ?>
            </p>
            <?php if ( $licenses_error->get_error_code() === 'api_auth_error' ) : ?>
                <p>
                    <?php esc_html_e( 'Tip: Configure your API key in ReactWoo Licenses → Settings to allow server-wide license listing.', 'reactwoo-api-manager' ); ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="reactwoo-license-manager">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'License', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'License Key', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'Domain', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'Package', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'Expires', 'reactwoo-api-manager' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'reactwoo-api-manager' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ( empty( $licenses ) ) :
                ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px;">
                            <p style="margin: 0; color: #646970;">
                                <?php esc_html_e( 'No licenses found on the server.', 'reactwoo-api-manager' ); ?>
                            </p>
                            <p style="margin: 10px 0 0 0; font-size: 13px; color: #8c8f94;">
                                <?php esc_html_e( 'If you expect licenses, verify your API key configuration and use Refresh.', 'reactwoo-api-manager' ); ?>
                            </p>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $licenses as $license ) : ?>
                        <?php
                        $license_key = isset( $license['license_key'] ) ? $license['license_key'] : ( isset( $license['licenseKey'] ) ? $license['licenseKey'] : '' );
                        $license_id = isset( $license['id'] ) ? $license['id'] : '';
                        $license_domain = isset( $license['domain'] ) ? $license['domain'] : '';
                        $package_name = isset( $license['package_name'] ) ? $license['package_name'] : '';
                        $package_type = isset( $license['package_type'] ) ? $license['package_type'] : '';
                        $status = isset( $license['status'] ) ? $license['status'] : '';
                        $expires_at = isset( $license['expires_at'] ) ? $license['expires_at'] : '';

                        $linked_subscription = $license_key && isset( $subscription_by_license_key[ $license_key ] ) ? $subscription_by_license_key[ $license_key ] : null;
                        ?>
                        <tr>
                            <td>
                                <?php if ( $license_id ) : ?>
                                    <strong>#<?php echo esc_html( $license_id ); ?></strong>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                                <?php if ( $linked_subscription ) : ?>
                                    <br><small>
                                        <?php esc_html_e( 'Subscription:', 'reactwoo-api-manager' ); ?>
                                        <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $linked_subscription->get_id() . '&action=edit' ) ); ?>">
                                            #<?php echo esc_html( $linked_subscription->get_id() ); ?>
                                        </a>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code><?php echo esc_html( $license_key ); ?></code>
                            </td>
                            <td>
                                <?php echo $license_domain ? esc_html( $license_domain ) : '—'; ?>
                            </td>
                            <td>
                                <?php
                                $label = $package_name ? wp_strip_all_tags( $package_name ) : '';
                                if ( $package_type ) {
                                    $label .= $label ? ' (' . $package_type . ')' : $package_type;
                                }
                                echo $label ? esc_html( $label ) : '—';
                                ?>
                            </td>
                            <td>
                                <?php echo $status ? esc_html( $status ) : '—'; ?>
                            </td>
                            <td>
                                <?php echo $expires_at ? esc_html( $expires_at ) : '—'; ?>
                            </td>
                            <td>
                                <?php if ( $linked_subscription ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $linked_subscription->get_id() . '&action=edit' ) ); ?>" class="button button-small" style="margin-bottom:4px;">
                                        <?php esc_html_e( 'View Subscription', 'reactwoo-api-manager' ); ?>
                                    </a>
                                    <br />
                                <?php endif; ?>

                                <?php if ( $license_id && $status ) : ?>
                                    <div class="reactwoo-license-actions" style="display:flex;flex-wrap:wrap;gap:4px;margin-top:2px;">
                                        <?php
                                        // Simple status transitions: active ↔ on-hold, any → cancelled.
                                        $can_suspend   = ( 'active' === $status );
                                        $can_reactivate = ( 'active' !== $status && 'cancelled' !== $status );
                                        $can_cancel    = ( 'cancelled' !== $status );

                                        if ( $can_suspend ) :
                                        ?>
                                            <form method="post" style="display:inline;">
                                                <?php wp_nonce_field( 'reactwoo_update_license_status', 'reactwoo_update_license_status_nonce' ); ?>
                                                <input type="hidden" name="reactwoo_update_license_status" value="1" />
                                                <input type="hidden" name="reactwoo_license_id" value="<?php echo esc_attr( $license_id ); ?>" />
                                                <input type="hidden" name="reactwoo_new_status" value="on-hold" />
                                                <button type="submit" class="button button-small">
                                                    <?php esc_html_e( 'Suspend', 'reactwoo-api-manager' ); ?>
                                                </button>
                                            </form>
                                        <?php
                                        endif;

                                        if ( $can_reactivate ) :
                                        ?>
                                            <form method="post" style="display:inline;">
                                                <?php wp_nonce_field( 'reactwoo_update_license_status', 'reactwoo_update_license_status_nonce' ); ?>
                                                <input type="hidden" name="reactwoo_update_license_status" value="1" />
                                                <input type="hidden" name="reactwoo_license_id" value="<?php echo esc_attr( $license_id ); ?>" />
                                                <input type="hidden" name="reactwoo_new_status" value="active" />
                                                <button type="submit" class="button button-small">
                                                    <?php esc_html_e( 'Reactivate', 'reactwoo-api-manager' ); ?>
                                                </button>
                                            </form>
                                        <?php
                                        endif;

                                        if ( $can_cancel ) :
                                        ?>
                                            <form method="post" style="display:inline;">
                                                <?php wp_nonce_field( 'reactwoo_update_license_status', 'reactwoo_update_license_status_nonce' ); ?>
                                                <input type="hidden" name="reactwoo_update_license_status" value="1" />
                                                <input type="hidden" name="reactwoo_license_id" value="<?php echo esc_attr( $license_id ); ?>" />
                                                <input type="hidden" name="reactwoo_new_status" value="cancelled" />
                                                <button type="submit" class="button button-small button-link-delete">
                                                    <?php esc_html_e( 'Cancel', 'reactwoo-api-manager' ); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
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

