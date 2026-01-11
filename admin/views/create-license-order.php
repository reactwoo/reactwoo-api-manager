<?php
/**
 * Create License Order Wizard
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/class-license-server-api.php';

/**
 * Helper to fetch POSTed value safely.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function reactwoo_old_value( $key, $default = '' ) {
    return isset( $_POST[ $key ] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : esc_attr( $default );
}

$get_post = function( $key, $default = '' ) {
    return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
};

$wizard_result = $this->handle_license_wizard_submission();
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

$products_with_packages = array();
foreach ( $subscription_products as $product ) {
    $package_id = get_post_meta( $product->get_id(), '_reactwoo_license_package_id', true );
    if ( $package_id ) {
        $products_with_packages[ $product->get_id() ] = $product;
    }
}

$default_product_id = key( $products_with_packages );
$selected_product_id = intval( $get_post( 'wizard_product', $default_product_id ) );
$default_product = isset( $products_with_packages[ $selected_product_id ] ) ? $products_with_packages[ $selected_product_id ] : ( $default_product_id ? $products_with_packages[ $default_product_id ] : null );
$default_price_placeholder = $default_product ? number_format_i18n( floatval( $default_product->get_price() ), wc_get_price_decimals() ) : '';

$billing_period_options = array(
    'day'   => __( 'Day', 'reactwoo-api-manager' ),
    'week'  => __( 'Week', 'reactwoo-api-manager' ),
    'month' => __( 'Month', 'reactwoo-api-manager' ),
    'year'  => __( 'Year', 'reactwoo-api-manager' ),
);

?>
<div class="wrap">
    <h1><?php esc_html_e( 'Create License Order', 'reactwoo-api-manager' ); ?></h1>
    <p><?php esc_html_e( 'Use this wizard to generate the WooCommerce order, subscription, and license in the proper sequence.', 'reactwoo-api-manager' ); ?></p>

    <?php if ( $wizard_result ) : ?>
        <div class="notice <?php echo is_wp_error( $wizard_result ) ? 'notice-error' : 'notice-success'; ?> inline">
            <p>
                <?php
                if ( is_wp_error( $wizard_result ) ) {
                    echo esc_html( $wizard_result->get_error_message() );
                } else {
                    echo esc_html( $wizard_result['message'] );
                    if ( isset( $wizard_result['order_id'] ) ) {
                        echo '<br />';
                        printf(
                            esc_html__( 'Order #%1$d and Subscription #%2$d were created. The license will sync as soon as the subscription becomes active.', 'reactwoo-api-manager' ),
                            intval( $wizard_result['order_id'] ),
                            intval( $wizard_result['subscription_id'] )
                        );
                    }
                }
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ( empty( $products_with_packages ) ) : ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e( 'No subscription product has a license package assigned yet. Please edit a product and select a package before using this wizard.', 'reactwoo-api-manager' ); ?></p>
        </div>
    <?php else : ?>
        <form method="post">
            <?php wp_nonce_field( 'reactwoo_license_wizard', 'reactwoo_license_wizard_nonce' ); ?>
            <input type="hidden" name="reactwoo_license_wizard_submit" value="1" />
            <div class="reactwoo-wizard-panel" style="background: #fff; border: 1px solid #dcdcdc; padding: 24px; border-radius: 6px; margin-bottom: 24px;">
                <h2><?php esc_html_e( 'Customer & Domain', 'reactwoo-api-manager' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wizard_customer_email"><?php esc_html_e( 'Customer Email', 'reactwoo-api-manager' ); ?></label></th>
                        <td>
                            <input type="email" id="wizard_customer_email" name="wizard_customer_email" class="regular-text" required value="<?php echo reactwoo_old_value( 'wizard_customer_email' ); ?>" />
                            <p class="description"><?php esc_html_e( 'Use an existing customer email or enter a new one. Accounts are created automatically if needed.', 'reactwoo-api-manager' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wizard_customer_first_name"><?php esc_html_e( 'First Name', 'reactwoo-api-manager' ); ?></label></th>
                        <td><input type="text" id="wizard_customer_first_name" name="wizard_customer_first_name" class="regular-text" required value="<?php echo reactwoo_old_value( 'wizard_customer_first_name' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wizard_customer_last_name"><?php esc_html_e( 'Last Name', 'reactwoo-api-manager' ); ?></label></th>
                        <td><input type="text" id="wizard_customer_last_name" name="wizard_customer_last_name" class="regular-text" required value="<?php echo reactwoo_old_value( 'wizard_customer_last_name' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wizard_domain"><?php esc_html_e( 'License Domain', 'reactwoo-api-manager' ); ?></label></th>
                        <td><input type="text" id="wizard_domain" name="wizard_domain" class="regular-text" required placeholder="example.com" value="<?php echo reactwoo_old_value( 'wizard_domain' ); ?>" /></td>
                    </tr>
                </table>
            </div>

            <div class="reactwoo-wizard-panel" style="background:#fff;border:1px solid #dcdcdc;padding:24px;border-radius:6px;margin-bottom:24px;">
                <h2><?php esc_html_e( 'Billing Address', 'reactwoo-api-manager' ); ?></h2>
                <table class="form-table">
                    <?php
                    $address_fields = array(
                        'company'    => __( 'Company', 'reactwoo-api-manager' ),
                        'address_1'  => __( 'Address line 1', 'reactwoo-api-manager' ),
                        'address_2'  => __( 'Address line 2', 'reactwoo-api-manager' ),
                        'city'       => __( 'City', 'reactwoo-api-manager' ),
                        'state'      => __( 'State', 'reactwoo-api-manager' ),
                        'postcode'   => __( 'Postcode', 'reactwoo-api-manager' ),
                        'country'    => __( 'Country', 'reactwoo-api-manager' ),
                    );
                    foreach ( $address_fields as $key => $label ) :
                    ?>
                        <tr>
                            <th scope="row"><label for="wizard_billing_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                            <td><input type="text" id="wizard_billing_<?php echo esc_attr( $key ); ?>" name="wizard_billing_<?php echo esc_attr( $key ); ?>" class="regular-text" value="<?php echo reactwoo_old_value( 'wizard_billing_' . $key ); ?>" <?php echo in_array( $key, array( 'address_1', 'city', 'postcode', 'country' ), true ) ? 'required' : ''; ?> /></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="reactwoo-wizard-panel" style="background:#fff;border:1px solid #dcdcdc;padding:24px;border-radius:6px;margin-bottom:24px;">
                <h2><?php esc_html_e( 'Subscription Details', 'reactwoo-api-manager' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wizard_product"><?php esc_html_e( 'Subscription Product', 'reactwoo-api-manager' ); ?></label></th>
                        <td>
                            <select id="wizard_product" name="wizard_product" required>
                            <?php foreach ( $products_with_packages as $product_id => $product ) : ?>
                                    <?php
                                    $package_id = get_post_meta( $product_id, '_reactwoo_license_package_id', true );
                                    if ( ! $package_id ) {
                                        continue;
                                    }
                                $package_label = isset( $package_map[ intval( $package_id ) ] ) ? $package_map[ intval( $package_id ) ]['name'] : __( 'License package', 'reactwoo-api-manager' );
                                    ?>
                                <option value="<?php echo esc_attr( $product_id ); ?>" <?php selected( intval( $selected_product_id ), $product_id, true ); ?>>
                                        <?php echo esc_html( $product->get_name() . ' — ' . $package_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Select the product that maps to the desired license package.', 'reactwoo-api-manager' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wizard_price"><?php esc_html_e( 'Price', 'reactwoo-api-manager' ); ?></label></th>
                        <td><input type="number" step="0.01" min="0" id="wizard_price" name="wizard_price" class="regular-text" value="<?php echo reactwoo_old_value( 'wizard_price', '' ); ?>" placeholder="<?php echo esc_attr( $default_price_placeholder ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wizard_billing_period"><?php esc_html_e( 'Billing Period', 'reactwoo-api-manager' ); ?></label></th>
                        <td>
                            <select id="wizard_billing_period" name="wizard_billing_period">
                                <?php foreach ( $billing_period_options as $period => $label ) : ?>
                                    <option value="<?php echo esc_attr( $period ); ?>" <?php selected( $get_post( 'wizard_billing_period', 'month' ), $period ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wizard_billing_interval"><?php esc_html_e( 'Billing Interval', 'reactwoo-api-manager' ); ?></label></th>
                        <td><input type="number" id="wizard_billing_interval" name="wizard_billing_interval" min="1" value="<?php echo esc_attr( intval( $get_post( 'wizard_billing_interval', 1 ) ) ); ?>" class="small-text" /></td>
                    </tr>
                </table>
            </div>

            <div class="reactwoo-wizard-panel" style="background:#fff;border:1px solid #dcdcdc;padding:24px;border-radius:6px;margin-bottom:24px;">
                <h2><?php esc_html_e( 'Optional Notes', 'reactwoo-api-manager' ); ?></h2>
                <p><?php esc_html_e( 'Add a note to the order to explain why this license was created manually.', 'reactwoo-api-manager' ); ?></p>
                <textarea name="wizard_order_note" class="large-text" rows="3"><?php echo reactwoo_old_value( 'wizard_order_note' ); ?></textarea>
            </div>

            <?php submit_button( __( 'Create Order & Subscription', 'reactwoo-api-manager' ), 'primary', 'reactwoo_license_wizard_submit' ); ?>
        </form>
    <?php endif; ?>
</div>
