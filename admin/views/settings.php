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
    update_option( 'reactwoo_updates_api_url', esc_url_raw( $_POST['reactwoo_updates_api_url'] ) );
    update_option( 'reactwoo_updates_store_download_token', sanitize_text_field( $_POST['reactwoo_updates_store_download_token'] ) );
    if ( class_exists( 'ReactWoo_Maintenance_Bundle' ) && isset( $_POST['reactwoo_maintenance_bundle'] ) && is_array( $_POST['reactwoo_maintenance_bundle'] ) ) {
        update_option(
            ReactWoo_Maintenance_Bundle::OPTION,
            ReactWoo_Maintenance_Bundle::sanitize_settings( wp_unslash( $_POST['reactwoo_maintenance_bundle'] ) )
        );
    }
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved successfully.', 'reactwoo-api-manager' ) . '</p></div>';
}

$license_server_url = get_option( 'reactwoo_license_server_url', 'https://license.reactwoo.com' );
$api_key = get_option( 'reactwoo_api_key', '' );
$updates_api_url = get_option( 'reactwoo_updates_api_url', 'https://api.reactwoo.com' );
$store_download_token = get_option( 'reactwoo_updates_store_download_token', '' );
$bundle_settings = class_exists( 'ReactWoo_Maintenance_Bundle' ) ? ReactWoo_Maintenance_Bundle::settings() : array();

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
            <tr>
                <th scope="row">
                    <label for="reactwoo_updates_api_url"><?php esc_html_e( 'Updates API URL', 'reactwoo-api-manager' ); ?></label>
                </th>
                <td>
                    <input type="url"
                           id="reactwoo_updates_api_url"
                           name="reactwoo_updates_api_url"
                           value="<?php echo esc_attr( $updates_api_url ); ?>"
                           class="regular-text"
                           required />
                    <p class="description">
                        <?php esc_html_e( 'Base URL for plugin ZIP distribution (e.g. https://api.reactwoo.com). Used for My Account satellite downloads.', 'reactwoo-api-manager' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="reactwoo_updates_store_download_token"><?php esc_html_e( 'Store download token', 'reactwoo-api-manager' ); ?></label>
                </th>
                <td>
                    <input type="password"
                           id="reactwoo_updates_store_download_token"
                           name="reactwoo_updates_store_download_token"
                           value="<?php echo esc_attr( $store_download_token ); ?>"
                           class="regular-text"
                           autocomplete="new-password" />
                    <p class="description">
                        <?php esc_html_e( 'Must match UPDATES_STORE_DOWNLOAD_TOKEN on api.reactwoo.com. Enables My Account plugin ZIP links for entitled subscriptions.', 'reactwoo-api-manager' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php if ( $bundle_settings ) : ?>
            <h2><?php esc_html_e( 'Maintenance licence bundle', 'reactwoo-api-manager' ); ?></h2>
            <p>
                <?php esc_html_e( 'Maintenance subscribers receive a licence for each package in their tier. Every key is limited to the website on that subscription and is deactivated with the plan. Packages can be ids or slugs. Essential is empty by default, so the customer chooses one plugin in My Account. Leave Essential empty unless you want to issue a fixed package instead of offering that choice.', 'reactwoo-api-manager' ); ?>
            </p>
            <p>
                <?php esc_html_e( 'Default issue rule: annual billing receives keys as soon as the subscription is active, because the year is paid up front. Monthly billing receives keys after the first successful renewal. The checkout payment is not counted. This limits a monthly sign-up that would otherwise collect keys and cancel in the same period. Geo Optimise is included automatically only when its product can be bought, unless you force it on or off. It is waitlist-only until then. Business includes Decision Cloud on the plan code below. The default is Starter.', 'reactwoo-api-manager' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="reactwoo_bundle_trigger"><?php esc_html_e( 'When to issue keys', 'reactwoo-api-manager' ); ?></label></th>
                    <td>
                        <select id="reactwoo_bundle_trigger" name="reactwoo_maintenance_bundle[trigger]">
                            <?php
                            $trigger_options = array(
                                'annual_immediate_monthly_after_renewal' => __( 'Annual immediately, monthly after the first successful renewal', 'reactwoo-api-manager' ),
                                'immediately' => __( 'Immediately for every billing period', 'reactwoo-api-manager' ),
                                'annual_only' => __( 'Annual billing only', 'reactwoo-api-manager' ),
                                'after_renewal' => __( 'After paid renewals, for every billing period', 'reactwoo-api-manager' ),
                            );
                            foreach ( $trigger_options as $trigger_value => $trigger_label ) {
                                echo '<option value="' . esc_attr( $trigger_value ) . '" ' . selected( $bundle_settings['trigger'], $trigger_value, false ) . '>' . esc_html( $trigger_label ) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="reactwoo_bundle_paid_periods"><?php esc_html_e( 'Paid renewals required', 'reactwoo-api-manager' ); ?></label></th>
                    <td>
                        <input type="number" min="1" step="1" id="reactwoo_bundle_paid_periods" name="reactwoo_maintenance_bundle[paid_periods]" value="<?php echo esc_attr( (string) $bundle_settings['paid_periods'] ); ?>" class="small-text" />
                        <p class="description"><?php esc_html_e( 'Used by monthly plans on the default rule, and by After paid renewals. 1 means the first successful renewal. The first checkout payment is not counted.', 'reactwoo-api-manager' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="reactwoo_bundle_optimise"><?php esc_html_e( 'Geo Optimise', 'reactwoo-api-manager' ); ?></label></th>
                    <td>
                        <select id="reactwoo_bundle_optimise" name="reactwoo_maintenance_bundle[optimise_mode]">
                            <option value="auto" <?php selected( $bundle_settings['optimise_mode'], 'auto' ); ?>><?php esc_html_e( 'Include only when the product can be bought', 'reactwoo-api-manager' ); ?></option>
                            <option value="yes" <?php selected( $bundle_settings['optimise_mode'], 'yes' ); ?>><?php esc_html_e( 'Always include', 'reactwoo-api-manager' ); ?></option>
                            <option value="no" <?php selected( $bundle_settings['optimise_mode'], 'no' ); ?>><?php esc_html_e( 'Never include automatically', 'reactwoo-api-manager' ); ?></option>
                        </select>
                        <p class="description">
                            <label for="reactwoo_bundle_optimise_product"><?php esc_html_e( 'Product id', 'reactwoo-api-manager' ); ?></label>
                            <input type="number" min="0" step="1" id="reactwoo_bundle_optimise_product" name="reactwoo_maintenance_bundle[optimise_product_id]" value="<?php echo esc_attr( (string) $bundle_settings['optimise_product_id'] ); ?>" class="small-text" />
                            <label for="reactwoo_bundle_optimise_package"><?php esc_html_e( 'Package id', 'reactwoo-api-manager' ); ?></label>
                            <input type="number" min="0" step="1" id="reactwoo_bundle_optimise_package" name="reactwoo_maintenance_bundle[optimise_package_id]" value="<?php echo esc_attr( (string) $bundle_settings['optimise_package_id'] ); ?>" class="small-text" />
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="reactwoo_bundle_cloud_plan"><?php esc_html_e( 'Decision Cloud plan on Business', 'reactwoo-api-manager' ); ?></label></th>
                    <td>
                        <select id="reactwoo_bundle_cloud_plan" name="reactwoo_maintenance_bundle[decision_cloud_plan]">
                            <?php foreach ( array( 'starter', 'growth', 'scale' ) as $cloud_plan ) : ?>
                                <option value="<?php echo esc_attr( $cloud_plan ); ?>" <?php selected( $bundle_settings['decision_cloud_plan'], $cloud_plan ); ?>><?php echo esc_html( ucfirst( $cloud_plan ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Starter is the default. The hosted workspace uses the existing Decision Cloud activation. Plugin keys are still issued alongside it. This does not change a Decision Cloud subscription the customer bought separately.', 'reactwoo-api-manager' ); ?></p>
                    </td>
                </tr>
                <?php
                $plan_fields = array(
                    'essential' => __( 'Essential packages', 'reactwoo-api-manager' ),
                    'standard'  => __( 'Standard packages', 'reactwoo-api-manager' ),
                    'business'  => __( 'Business packages', 'reactwoo-api-manager' ),
                );
                foreach ( $plan_fields as $plan_key => $plan_label ) :
                    $plan_row = $bundle_settings['plans'][ $plan_key ];
                    ?>
                    <tr>
                        <th scope="row"><label for="reactwoo_bundle_<?php echo esc_attr( $plan_key ); ?>"><?php echo esc_html( $plan_label ); ?></label></th>
                        <td>
                            <input type="number" min="0" step="1" name="reactwoo_maintenance_bundle[plans][<?php echo esc_attr( $plan_key ); ?>][product_id]" value="<?php echo esc_attr( (string) $plan_row['product_id'] ); ?>" class="small-text" aria-label="<?php esc_attr_e( 'Product id', 'reactwoo-api-manager' ); ?>" />
                            <input type="text" class="regular-text" id="reactwoo_bundle_<?php echo esc_attr( $plan_key ); ?>" name="reactwoo_maintenance_bundle[plans][<?php echo esc_attr( $plan_key ); ?>][packages]" value="<?php echo esc_attr( $plan_row['packages'] ); ?>" />
                            <p class="description"><?php esc_html_e( 'Product id, then package ids or slugs separated by commas.', 'reactwoo-api-manager' ); ?></p>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th scope="row"><label for="reactwoo_bundle_choices"><?php esc_html_e( 'Essential choices', 'reactwoo-api-manager' ); ?></label></th>
                    <td>
                        <input type="text" class="regular-text" id="reactwoo_bundle_choices" name="reactwoo_maintenance_bundle[essential_choices]" value="<?php echo esc_attr( $bundle_settings['essential_choices'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Plugins the customer may choose when Essential packages above are empty. Decision Cloud is not offered here.', 'reactwoo-api-manager' ); ?></p>
                    </td>
                </tr>
            </table>
        <?php endif; ?>

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

