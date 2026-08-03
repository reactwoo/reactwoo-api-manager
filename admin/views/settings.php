<?php
/**
 * Settings Page Template
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Save settings if form is submitted
if ( isset( $_POST['submit'] ) && check_admin_referer( 'reactwoo_api_manager_settings' ) ) {
    update_option( 'reactwoo_license_server_url', esc_url_raw( $_POST['reactwoo_license_server_url'] ) );
    update_option( 'reactwoo_api_key', sanitize_text_field( $_POST['reactwoo_api_key'] ) );
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved successfully.', 'reactwoo-api-manager' ) . '</p></div>';
}

$license_server_url = get_option( 'reactwoo_license_server_url', 'https://license.reactwoo.com' );
$api_key = get_option( 'reactwoo_api_key', '' );

// Test connection
$connection_status = null;
if ( isset( $_POST['test_connection'] ) && check_admin_referer( 'reactwoo_api_manager_settings' ) ) {
    $api = new ReactWoo_License_Server_API();
    $packages = $api->get_packages();
    
    if ( is_wp_error( $packages ) ) {
        $connection_status = array(
            'success' => false,
            'message' => $packages->get_error_message(),
        );
    } else {
        $connection_status = array(
            'success' => true,
            'message' => sprintf( __( 'Connection successful! Found %d package(s).', 'reactwoo-api-manager' ), count( $packages ) ),
        );
    }
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'ReactWoo License Manager Settings', 'reactwoo-api-manager' ); ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field( 'reactwoo_api_manager_settings' ); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="reactwoo_license_server_url"><?php esc_html_e( 'License Server URL', 'reactwoo-api-manager' ); ?></label>
                </th>
                <td>
                    <input type="url" 
                           id="reactwoo_license_server_url" 
                           name="reactwoo_license_server_url" 
                           value="<?php echo esc_attr( $license_server_url ); ?>" 
                           class="regular-text" 
                           required />
                    <p class="description">
                        <?php esc_html_e( 'The base URL of your license server (e.g., https://license.reactwoo.com)', 'reactwoo-api-manager' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="reactwoo_api_key"><?php esc_html_e( 'API Key', 'reactwoo-api-manager' ); ?></label>
                </th>
                <td>
                    <input type="text" 
                           id="reactwoo_api_key" 
                           name="reactwoo_api_key" 
                           value="<?php echo esc_attr( $api_key ); ?>" 
                           class="regular-text" 
                           autocomplete="off" />
                    <p class="description">
                        <?php esc_html_e( 'Shared ReactWoo API key used for licence provisioning, subscription sync, and authenticated licence-server requests. Must match WOOCOMMERCE_API_KEY (or RW_MASTER_KEY) on the licence server.', 'reactwoo-api-manager' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button( __( 'Save Settings', 'reactwoo-api-manager' ) ); ?>
    </form>

    <hr>

    <h2><?php esc_html_e( 'Test Connection', 'reactwoo-api-manager' ); ?></h2>
    <form method="post" action="">
        <?php wp_nonce_field( 'reactwoo_api_manager_settings' ); ?>
        <?php submit_button( __( 'Test Connection', 'reactwoo-api-manager' ), 'secondary', 'test_connection', false ); ?>
    </form>

    <?php if ( $connection_status ) : ?>
        <div class="notice notice-<?php echo $connection_status['success'] ? 'success' : 'error'; ?>" style="margin-top: 15px;">
            <p><?php echo esc_html( $connection_status['message'] ); ?></p>
        </div>
    <?php endif; ?>

    <hr>
    <h2><?php esc_html_e( 'Account diagnostics', 'reactwoo-api-manager' ); ?></h2>
    <p>
        <?php esc_html_e( 'My Account redirect logging is on by default. Look for “[ReactWoo API Manager]” in PHP error_log, or WooCommerce → Status → Logs → reactwoo-api-manager-account.', 'reactwoo-api-manager' ); ?>
    </p>
    <p>
        <?php esc_html_e( 'Automatic root redirect to Products & licences is disabled. To re-enable later: define( \'REACTWOO_API_MANAGER_ACCOUNT_REDIRECT\', true );', 'reactwoo-api-manager' ); ?>
    </p>
</div>

