<?php
/**
 * Regression contracts for standalone licensing. Cloud must not own these paths.
 *
 * @package ReactWoo_API_Manager
 */

$root = dirname( __DIR__ );
$handler = file_get_contents( $root . '/includes/class-subscription-handler.php' );
$api     = file_get_contents( $root . '/includes/class-license-server-api.php' );
$display = file_get_contents( $root . '/includes/class-license-display.php' );
$dl      = file_get_contents( $root . '/includes/class-plugin-download-service.php' );
$account = file_get_contents( $root . '/includes/class-customer-account-service.php' );
$product = file_get_contents( $root . '/includes/class-product-meta.php' );

$required_hooks = array(
	"add_action( 'woocommerce_subscription_status_active', array( \$this, 'handle_subscription_activated' ), 10, 1 )",
	"add_action( 'woocommerce_subscription_status_updated', array( \$this, 'handle_subscription_status_change' ), 10, 3 )",
	"add_action( 'woocommerce_order_status_completed', array( \$this, 'maybe_create_license_on_order_completion' ), 5, 1 )",
	"add_action( 'woocommerce_subscription_renewal_payment_complete', array( \$this, 'handle_subscription_renewal' ), 10, 2 )",
	"add_action( 'woocommerce_subscription_payment_failed', array( \$this, 'handle_payment_failure' ), 10, 1 )",
	"add_action( 'woocommerce_checkout_update_order_meta', array( \$this, 'save_checkout_domain_field' ), 10, 1 )",
	"add_action( 'woocommerce_before_trash_order', array( \$this, 'handle_wc_order_trashed' ), 10, 1 )",
	"add_action( 'woocommerce_delete_order', array( \$this, 'handle_wc_order_deleted' ), 10, 1 )",
);
foreach ( $required_hooks as $hook ) {
	rw_assert( strpos( $handler, $hook ) !== false, 'Licence handler still owns: ' . $hook );
}

rw_assert( strpos( $handler, 'create_license_for_subscription' ) !== false, 'Standalone purchase still generates licences' );
rw_assert( strpos( $handler, 'ReactWoo_License_Server_API' ) !== false, 'Licence generation still uses the licence server API' );
rw_assert( strpos( $handler, 'rw_cloud_' ) === false, 'Licence handler does not write Cloud order meta' );
rw_assert( strpos( $handler, 'RWCC_' ) === false, 'Licence handler has no Cloud class references' );

rw_assert( strpos( $api, 'function create_license' ) !== false, 'Licence server create_license remains' );
rw_assert( strpos( $api, 'function update_license_status' ) !== false, 'Licence activation/deactivation status API remains' );
rw_assert( strpos( $api, 'function sync_subscription_v1' ) !== false, 'Subscription status sync remains' );
rw_assert( strpos( $api, 'RWCC_' ) === false, 'Licence server API has no Cloud class references' );

rw_assert( strpos( $handler, 'handle_subscription_renewal' ) !== false, 'Renewals remain on the licence handler' );
rw_assert( strpos( $handler, 'handle_payment_failure' ) !== false, 'Failed payments remain on the licence handler' );
rw_assert( strpos( $handler, "update_license_status( \$license_id, 'inactive' )" ) !== false, 'Failed payments still mark the licence inactive' );
rw_assert( strpos( $handler, 'deactivate_order_license' ) !== false, 'Cancellations/refunds/deletes still deactivate licences' );
rw_assert( strpos( $handler, 'do_action( \'reactwoo_license_generated\'' ) !== false, 'Licence generation fires observe-only hook' );
rw_assert( strpos( $handler, 'do_action( \'reactwoo_license_status_synced\'' ) !== false, 'Status sync fires observe-only hook' );
rw_assert( strpos( $handler, 'do_action( \'reactwoo_license_renewed\'' ) !== false, 'Renewals fire observe-only hook' );
rw_assert( strpos( $handler, 'do_action( \'reactwoo_license_payment_failed\'' ) !== false, 'Failed payments fire observe-only hook' );

$plugin_src = file_get_contents( $root . '/woocommerce-api-subscription-bridge.php' );
rw_assert( strpos( $plugin_src, 'function reactwoo_api_manager_supports' ) !== false, 'Companion support helper is part of the licence contract' );

rw_assert( strpos( $display, 'woocommerce_account_license_endpoint' ) !== false, 'My Account licences endpoint remains' );
rw_assert( strpos( $display, 'maybe_handle_plugin_download' ) !== false, 'ZIP download proxy remains' );
rw_assert( strpos( $display, 'RWCC_' ) === false, 'My Account display has no Cloud class references' );

rw_assert( strpos( $dl, 'store-download' ) !== false, 'ZIP downloads still use store-download' );
rw_assert( in_array( 'active', ReactWoo_Plugin_Download_Service::entitled_statuses(), true ), 'Active subscriptions remain entitled to ZIPs' );
rw_assert( in_array( 'pending-cancel', ReactWoo_Plugin_Download_Service::entitled_statuses(), true ), 'Pending-cancel subscriptions remain entitled to ZIPs' );
rw_assert( ! in_array( 'cancelled', ReactWoo_Plugin_Download_Service::entitled_statuses(), true ), 'Cancelled subscriptions are not entitled to ZIPs' );
rw_assert( ! in_array( 'expired', ReactWoo_Plugin_Download_Service::entitled_statuses(), true ), 'Expired subscriptions are not entitled to ZIPs' );
rw_assert( ! in_array( 'on-hold', ReactWoo_Plugin_Download_Service::entitled_statuses(), true ), 'On-hold / failed-payment subscriptions are not entitled to ZIPs' );

rw_assert( strpos( $account, '_reactwoo_license_key' ) !== false, 'My Account still reads _reactwoo_license_key' );
rw_assert( strpos( $account, '_reactwoo_license_domain' ) !== false, 'My Account still reads registered domain' );
rw_assert( strpos( $account, 'rw_cloud_' ) === false, 'My Account does not depend on Cloud metadata' );

rw_assert( strpos( $product, 'add_license_type_field' ) !== false, 'Free and paid products still use licence type product meta' );
rw_assert( strpos( $product, 'RWCC_' ) === false, 'Product meta has no Cloud plan field' );
rw_assert( strpos( $product, 'rw_cloud_plan' ) === false, 'Product meta does not add Cloud plan mapping' );

rw_assert( strpos( $handler, 'save_checkout_domain_field' ) !== false, 'Domain checkout field remains' );
rw_assert( strpos( $handler, 'validate_domain_field' ) !== false, 'Domain validation remains' );

$refund_or_cancel = (
	strpos( $handler, 'handle_subscription_status_change' ) !== false
	&& strpos( $handler, 'sync_subscription_v1' ) !== false
	&& strpos( $handler, 'deactivate_order_license' ) !== false
);
rw_assert( $refund_or_cancel, 'Cancellations, refunds and deletes still sync or deactivate licences' );

$cloud_meta_keys = array(
	'rw_cloud_plan',
	'rw_cloud_org',
	'rw_cloud_provisioning_id',
	'rw_cloud_claim_hash',
);
foreach ( $cloud_meta_keys as $key ) {
	rw_assert( strpos( $handler, $key ) === false, 'Orders with no Cloud metadata stay untouched: ' . $key );
	rw_assert( strpos( $account, $key ) === false, 'Account records ignore Cloud metadata: ' . $key );
	rw_assert( strpos( $display, $key ) === false, 'My Account template path ignores Cloud metadata: ' . $key );
}
