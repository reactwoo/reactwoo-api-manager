<?php
/**
 * Flag off / module not required: existing store behaviour is unchanged.
 *
 * @package ReactWoo_API_Manager
 */

$root = dirname( __DIR__ );

rw_assert( ! class_exists( 'RWCC_Bootstrap', false ), 'Flag-off tests do not load RWCC_Bootstrap' );
rw_assert( ! class_exists( 'RWCC_REST', false ), 'Flag-off tests do not load RWCC_REST' );
rw_assert( ! defined( 'REACTWOO_CLOUD_BRIDGE_ENABLED' ), 'Cloud flag remains undefined during licence tests' );

$main = file_get_contents( $root . '/includes/class-reactwoo-api-manager.php' );
$handler = file_get_contents( $root . '/includes/class-subscription-handler.php' );
$boot = file_get_contents( $root . '/woocommerce-api-subscription-bridge.php' );

rw_assert( strpos( $boot, 'includes/cloud-commerce/' ) === false, 'Main plugin file does not require Cloud PHP' );
rw_assert( preg_match( '/if\s*\(\s*defined\(\s*\'REACTWOO_CLOUD_BRIDGE_ENABLED\'\s*\)\s*&&\s*REACTWOO_CLOUD_BRIDGE_ENABLED\s*\)/', $main ) === 1, 'Module require is flag-gated' );

$licence_hooks = array(
	'handle_subscription_activated',
	'maybe_create_license_on_order_completion',
	'handle_subscription_renewal',
	'handle_payment_failure',
	'save_checkout_domain_field',
);
foreach ( $licence_hooks as $method ) {
	rw_assert( strpos( $handler, $method ) !== false, 'Flag off leaves API Manager method: ' . $method );
}

rw_assert( strpos( $handler, 'rw_cloud_' ) === false, 'Licence handler writes no Cloud meta when the module is not loaded' );
rw_assert( strpos( $handler, 'RWCC_' ) === false, 'Licence handler has no Cloud class references' );
rw_assert( strpos( $handler, 'do_action( \'reactwoo_license_generated\'' ) !== false, 'Observe-only licence generated action remains' );
