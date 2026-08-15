<?php
/**
 * Proof that the Cloud module does not load unless REACTWOO_CLOUD_BRIDGE_ENABLED is true.
 *
 * @package ReactWoo_API_Manager
 */

$root = dirname( __DIR__ );

rw_assert( is_dir( $root . '/includes/cloud-commerce' ), 'Cloud module directory exists' );
rw_assert( file_exists( $root . '/includes/cloud-commerce/class-rwcc-bootstrap.php' ), 'Cloud bootstrap file exists on disk' );
rw_assert( ! class_exists( 'RWCC_Bootstrap', false ), 'RWCC_Bootstrap is not loaded by the licence test bootstrap' );
rw_assert( ! class_exists( 'RWCC_Lifecycle', false ), 'RWCC_Lifecycle is not loaded by the licence test bootstrap' );
rw_assert( ! class_exists( 'RWCC_REST', false ), 'RWCC_REST is not loaded by the licence test bootstrap' );
rw_assert( ! defined( 'REACTWOO_CLOUD_BRIDGE_ENABLED' ), 'Isolation run does not define the Cloud flag' );

$main = file_get_contents( $root . '/includes/class-reactwoo-api-manager.php' );
rw_assert( strpos( $main, 'includes/cloud-commerce/class-rwcc-bootstrap.php' ) !== false, 'Main class can require the Cloud bootstrap' );
rw_assert( preg_match( '/if\s*\(\s*defined\(\s*\'REACTWOO_CLOUD_BRIDGE_ENABLED\'\s*\)\s*&&\s*REACTWOO_CLOUD_BRIDGE_ENABLED\s*\)/', $main ) === 1, 'Cloud bootstrap is inside the REACTWOO_CLOUD_BRIDGE_ENABLED gate' );
rw_assert( strpos( $main, 'do_action( \'reactwoo_api_manager_loaded\' )' ) !== false, 'Main class fires observe-only reactwoo_api_manager_loaded' );

$plugin = file_get_contents( $root . '/woocommerce-api-subscription-bridge.php' );
rw_assert( strpos( $plugin, "define( 'REACTWOO_CLOUD_BRIDGE_ENABLED'" ) === false, 'API Manager does not define the Cloud feature flag' );
rw_assert( strpos( $plugin, 'custom_order_tables' ) !== false, 'API Manager still declares HPOS compatibility' );
rw_assert( strpos( $plugin, 'WC_Subscriptions' ) !== false, 'API Manager still requires WooCommerce Subscriptions' );

$always_dirs = array(
	$root . '/admin',
	$root . '/templates',
	$root . '/assets',
);
$always_files = array( $root . '/woocommerce-api-subscription-bridge.php' );
$includes = new DirectoryIterator( $root . '/includes' );
foreach ( $includes as $item ) {
	if ( $item->isDot() ) {
		continue;
	}
	if ( $item->isDir() && $item->getFilename() === 'cloud-commerce' ) {
		continue;
	}
	if ( $item->isFile() && substr( $item->getFilename(), -4 ) === '.php' ) {
		$always_files[] = $item->getPathname();
	}
}
foreach ( $always_dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
	foreach ( $it as $file ) {
		if ( $file->isFile() && preg_match( '/\.(php|js)$/', $file->getFilename() ) ) {
			if ( $file->getFilename() === 'cloud-commerce.php' ) {
				continue;
			}
			$always_files[] = $file->getPathname();
		}
	}
}

$forbidden = array(
	'RWCC_Bootstrap::init',
	'class-rwcc-',
	'reactwoo-cloud/v1',
	'RWCC_Lifecycle',
	'RWCC_REST',
);
$hits = array();
foreach ( $always_files as $path ) {
	if ( basename( $path ) === 'class-reactwoo-api-manager.php' ) {
		continue;
	}
	$src = file_get_contents( $path );
	foreach ( $forbidden as $needle ) {
		if ( strpos( $src, $needle ) !== false ) {
			$hits[] = basename( $path ) . ':' . $needle;
		}
	}
}
rw_assert( $hits === array(), 'Always-loaded PHP has no Cloud identifiers: ' . implode( ', ', $hits ) );

$life = file_get_contents( $root . '/includes/cloud-commerce/class-rwcc-lifecycle.php' );
rw_assert( stripos( $life, 'wp_schedule' ) === false, 'Cloud lifecycle has no scheduled actions' );
rw_assert( stripos( $life, 'dbDelta' ) === false, 'Cloud lifecycle has no database migrations' );
rw_assert( strpos( $life, "add_action( 'reactwoo_license_generated'" ) !== false, 'Cloud module consumes reactwoo_license_generated' );
rw_assert( strpos( $life, "add_action( 'woocommerce_subscription_status_active'" ) === false, 'Cloud module does not re-hook subscription activation' );
rw_assert( strpos( $life, "add_action( 'woocommerce_order_status_completed'" ) === false, 'Cloud module does not re-hook order completed' );
