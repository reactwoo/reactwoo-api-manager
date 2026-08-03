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
                        <?php esc_html_e( 'Optional: API key for authenticated requests to the license server', 'reactwoo-api-manager' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Master key', 'reactwoo-api-manager' ); ?></th>
                <td>
                    <?php if ( ReactWoo_License_Server_API::has_master_key() ) : ?>
                        <p><span class="dashicons dashicons-yes-alt" style="color:#198754;"></span> <?php esc_html_e( 'REACTWOO_LICENSE_MASTER_KEY is defined in wp-config.php.', 'reactwoo-api-manager' ); ?></p>
                    <?php else : ?>
                        <p><span class="dashicons dashicons-warning" style="color:#b32d2e;"></span> <?php esc_html_e( 'Missing. Add define( \'REACTWOO_LICENSE_MASTER_KEY\', \'…\' ); to wp-config.php. Never store this in the database or commit it to Git.', 'reactwoo-api-manager' ); ?></p>
                    <?php endif; ?>
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
</div>

