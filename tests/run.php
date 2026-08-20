<?php
/**
 * Lightweight test runner (no PHPUnit required).
 *
 * @package ReactWoo_API_Manager
 */

require_once __DIR__ . '/bootstrap.php';

$failures = 0;

function rw_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		echo "FAIL: {$message}\n";
		++$failures;
		return;
	}
	echo "PASS: {$message}\n";
}

// 1) Masking never invents activation limits and never echoes full key.
$full = 'RWGC-ABCD-EFGH-7F2A';
$masked = ReactWoo_Customer_Account_Service::mask_license_key( $full );
rw_assert( $masked !== $full, 'Masked key differs from full key' );
rw_assert( strpos( $masked, 'ABCD' ) === false, 'Middle segment not exposed' );
rw_assert( substr( $masked, -4 ) === '7F2A', 'Last four preserved' );
rw_assert( strpos( $masked, 'of' ) === false, 'No activation meter language in mask' );

// 2) Status mapping
$sub_active = new WC_Subscription( 1, 10, 'active', array( '_reactwoo_license_key' => $full ) );
rw_assert( ReactWoo_Customer_Account_Service::map_status( $sub_active, $full ) === 'active', 'active maps to active' );

$sub_pending_cancel = new WC_Subscription( 2, 10, 'pending-cancel', array( '_reactwoo_license_key' => $full ) );
rw_assert( ReactWoo_Customer_Account_Service::map_status( $sub_pending_cancel, $full ) === 'expiring', 'pending-cancel maps to expiring' );

$sub_hold = new WC_Subscription( 3, 10, 'on-hold', array( '_reactwoo_license_key' => $full ) );
rw_assert( ReactWoo_Customer_Account_Service::map_status( $sub_hold, $full ) === 'inactive', 'on-hold maps to inactive' );

$sub_expired = new WC_Subscription( 4, 10, 'expired', array( '_reactwoo_license_key' => $full ) );
rw_assert( ReactWoo_Customer_Account_Service::map_status( $sub_expired, $full ) === 'expired', 'expired maps to expired' );

$sub_pending = new WC_Subscription( 5, 10, 'active', array() );
rw_assert( ReactWoo_Customer_Account_Service::map_status( $sub_pending, '' ) === 'pending', 'missing key maps to pending' );

// 3) Account records use existing meta keys (contract smoke via reflection of build path).
$service = ReactWoo_Customer_Account_Service::get_instance();
rw_assert( method_exists( $service, 'get_account_records' ), 'Service exposes get_account_records' );
rw_assert( method_exists( $service, 'get_owned_license_key' ), 'Service exposes get_owned_license_key' );
rw_assert( function_exists( 'reactwoo_api_manager_get_customer_account_records' ), 'Public wrapper exists' );

// 4) Template must not embed full keys as data attributes (static scan).
$template = file_get_contents( dirname( __DIR__ ) . '/templates/myaccount/license.php' );
rw_assert( strpos( $template, 'data-license-key' ) === false, 'Template has no data-license-key' );
rw_assert( strpos( $template, 'masked_key' ) !== false, 'Template renders masked_key' );
rw_assert( stripos( $template, 'activation' ) === false || stripos( $template, 'Registered website' ) !== false, 'Registered website shown instead of activation meter' );
rw_assert( stripos( $template, '1 of' ) === false, 'No invented activation counts in template' );

// 5) Master key not committed.
$api_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-license-server-api.php' );
rw_assert( strpos( $api_src, 'V3tJYMQovxmDHI3IGnqZdVeBRyzCg91I4YgVyN1X4ZN' ) === false, 'Committed master key removed' );
rw_assert( strpos( $api_src, 'get_api_key' ) !== false, 'Shared API key used for provisioning auth' );

// 6) REST controller ownership / nonce / cache headers present in source.
$rest_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-account-rest-controller.php' );
rw_assert( strpos( $rest_src, "get_customer_id()" ) !== false, 'REST checks subscription ownership' );
rw_assert( strpos( $rest_src, 'wp_rest' ) !== false, 'REST verifies wp_rest nonce' );
rw_assert( strpos( $rest_src, 'no-store' ) !== false, 'REST sets Cache-Control no-store' );
rw_assert( strpos( $rest_src, "status' => 404" ) !== false, 'Foreign subscription returns 404' );
rw_assert( strpos( $rest_src, "status' => 401" ) !== false, 'Logged-out returns 401' );

// 7) Download matching uses Woo URLs + store-gated synthetic files.
$svc_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-customer-account-service.php' );
rw_assert( strpos( $svc_src, 'wc_get_customer_available_downloads' ) !== false, 'Uses Woo download permissions' );
rw_assert( strpos( $svc_src, 'ReactWoo_Plugin_Download_Service' ) !== false, 'Merges store plugin downloads' );
rw_assert( strpos( $svc_src, '_reactwoo_license_key' ) !== false, 'Uses _reactwoo_license_key meta' );
rw_assert( strpos( $svc_src, '_reactwoo_license_domain' ) !== false, 'Uses _reactwoo_license_domain meta' );
rw_assert( strpos( $svc_src, 'get_licenses_by_domain' ) === false, 'Account service avoids public domain lookup' );

$dl_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin-download-service.php' );
rw_assert( strpos( $dl_src, 'store-download' ) !== false, 'Plugin download service calls store-download' );
rw_assert( strpos( $dl_src, '_reactwoo_plugin_slug' ) !== false, 'Resolves product plugin slug meta' );
rw_assert( strpos( $dl_src, 'build_synthetic_files' ) !== false, 'Cloud plans can emit one download per entitled plugin' );
rw_assert( strpos( $dl_src, 'should_hide_downloads' ) !== false, 'Superseded individuals can hide plugin ZIPs' );
rw_assert( strpos( $dl_src, '_downloadable_files' ) === false, 'Download service does not write variation ZIP attachments' );
rw_assert( in_array( 'active', ReactWoo_Plugin_Download_Service::entitled_statuses(), true ), 'Active subscriptions are entitled' );
rw_assert( in_array( 'pending-cancel', ReactWoo_Plugin_Download_Service::entitled_statuses(), true ), 'Pending-cancel subscriptions are entitled' );
rw_assert( ! in_array( 'cancelled', ReactWoo_Plugin_Download_Service::entitled_statuses(), true ), 'Cancelled subscriptions are not entitled' );

// 8) Account root redirect to /license/
$display_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-license-display.php' );
rw_assert( strpos( $display_src, 'maybe_redirect_account_root' ) !== false, 'Account root redirect registered' );
rw_assert( strpos( $display_src, 'Products & licences' ) !== false, 'Menu label Products & licences' );
rw_assert( strpos( $display_src, 'REACTWOO_API_MANAGER_ACCOUNT_REDIRECT' ) !== false, 'Root redirect is feature-flagged off by default' );
rw_assert( strpos( $display_src, 'log_redirects_on_account' ) !== false, 'Redirect logging is registered' );
rw_assert( strpos( $display_src, 'maybe_handle_plugin_download' ) !== false, 'Plugin ZIP download proxy registered' );
rw_assert( strpos( $display_src, 'inject_plugin_downloads' ) !== false, 'Woo Downloads injection registered' );
rw_assert( file_exists( dirname( __DIR__ ) . '/includes/class-account-logger.php' ), 'Account logger class exists' );

require_once __DIR__ . '/isolation.php';
require_once __DIR__ . '/licensing-regression.php';
require_once __DIR__ . '/companion-deactivated.php';
require_once __DIR__ . '/cloud-commerce.php';

if ( $failures > 0 ) {
	echo "\n{$failures} failure(s)\n";
	exit( 1 );
}

echo "\nAll tests passed.\n";
exit( 0 );
