<?php
/**
 * Products & licences lists licensed items only.
 *
 * Maintenance plans stay out of the account menu and list through
 * reactwoo_api_manager_unlicensed_product_ids. The /license/ endpoint stays registered.
 *
 * @package ReactWoo_API_Manager
 */

$root = dirname( __DIR__ );

$account_src = file_get_contents( $root . '/includes/class-customer-account-service.php' );
$display_src = file_get_contents( $root . '/includes/class-license-display.php' );

$build_pos = strpos( $account_src, 'private function build_record(' );
rw_assert( false !== $build_pos, 'build_record exists' );
$build_doc_start = strrpos( substr( $account_src, 0, $build_pos ), '/**' );
$build_doc       = substr( $account_src, $build_doc_start, $build_pos - $build_doc_start );
rw_assert( strpos( $build_doc, 'is_licensed_record' ) === false, 'build_record keeps its own docblock' );
rw_assert( strpos( $build_doc, '@param WC_Subscription' ) !== false, 'build_record docblock still describes the subscription' );
rw_assert( strpos( $build_doc, '@return array<string, mixed>|null' ) !== false, 'build_record docblock still describes the return' );

$licensed_pos = strpos( $account_src, 'private static function is_licensed_record(' );
rw_assert( false !== $licensed_pos, 'is_licensed_record exists' );
$licensed_doc = substr( $account_src, strrpos( substr( $account_src, 0, $licensed_pos ), '/**' ) );
$licensed_doc = substr( $licensed_doc, 0, strpos( $licensed_doc, 'private static function is_licensed_record(' ) );
rw_assert( strpos( $licensed_doc, 'Products & licences lists licensed items only' ) !== false, 'is_licensed_record has its own docblock' );
rw_assert( strpos( $account_src, "apply_filters( 'reactwoo_api_manager_unlicensed_product_ids', array( 3654, 3657, 3660 ) )" ) !== false, 'Maintenance plans 3654, 3657 and 3660 are the default unlicensed product ids' );

rw_assert( strpos( $display_src, "add_rewrite_endpoint( 'license', EP_ROOT | EP_PAGES )" ) !== false, 'Licence rewrite endpoint stays registered' );
rw_assert( strpos( $display_src, "add_action( 'woocommerce_account_license_endpoint', array( \$this, 'render_license_endpoint' ) )" ) !== false, 'Licence account endpoint callback stays registered' );
rw_assert( strpos( $display_src, 'the endpoint stays reachable by URL' ) !== false, 'Menu hide is documented as menu-only' );

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		$args = func_get_args();
		if ( empty( $GLOBALS['rw_test_filters'][ $hook ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['rw_test_filters'][ $hook ] as $callback ) {
			$value = call_user_func_array( $callback, array_slice( $args, 1 ) );
			$args[1] = $value;
		}
		return $value;
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $product_id ) {
		$product_id = (int) $product_id;
		return isset( $GLOBALS['rw_test_products'][ $product_id ] ) ? $GLOBALS['rw_test_products'][ $product_id ] : null;
	}
}

if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
	function wcs_get_users_subscriptions( $user_id ) {
		$user_id = (int) $user_id;
		$matched = array();
		foreach ( (array) $GLOBALS['rw_test_subscriptions'] as $subscription ) {
			if ( $subscription instanceof WC_Subscription && (int) $subscription->get_customer_id() === $user_id ) {
				$matched[] = $subscription;
			}
		}
		return $matched;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return ! empty( $GLOBALS['rw_test_logged_in'] );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return isset( $GLOBALS['rw_test_user_id'] ) ? (int) $GLOBALS['rw_test_user_id'] : 0;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['rw_test_actions'][] = $hook;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {
	}
}

if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
	function add_rewrite_endpoint( $name, $places ) {
		$GLOBALS['rw_test_endpoints'][] = $name;
	}
}

if ( ! defined( 'EP_ROOT' ) ) {
	define( 'EP_ROOT', 1 );
}
if ( ! defined( 'EP_PAGES' ) ) {
	define( 'EP_PAGES', 4096 );
}

if ( ! class_exists( 'RW_Test_Product', false ) ) {
	class RW_Test_Product {
		private $id;
		private $parent_id;

		public function __construct( $id, $parent_id = 0 ) {
			$this->id        = (int) $id;
			$this->parent_id = (int) $parent_id;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_parent_id() {
			return $this->parent_id;
		}
	}
}

if ( ! class_exists( 'RW_Test_Order_Item', false ) ) {
	class RW_Test_Order_Item {
		private $product;
		private $name;

		public function __construct( $product, $name ) {
			$this->product = $product;
			$this->name    = $name;
		}

		public function get_product() {
			return $this->product;
		}

		public function get_name() {
			return $this->name;
		}
	}
}

if ( ! class_exists( 'RW_Test_Subscription', false ) ) {
	class RW_Test_Subscription extends WC_Subscription {
		private $items;

		public function __construct( $id, $customer_id, $status, $meta, $items ) {
			parent::__construct( $id, $customer_id, $status, $meta );
			$this->items = $items;
		}

		public function get_items() {
			return $this->items;
		}
	}
}

$GLOBALS['rw_test_filters']       = array();
$GLOBALS['rw_test_products']      = array();
$GLOBALS['rw_test_subscriptions'] = array();
$GLOBALS['rw_test_actions']       = array();
$GLOBALS['rw_test_endpoints']     = array();

/**
 * @param int                  $id Subscription ID.
 * @param int                  $user_id Customer ID.
 * @param int                  $product_id Product ID.
 * @param array<string, mixed> $meta Subscription meta.
 * @param int                  $parent_id Parent product ID.
 * @return RW_Test_Subscription
 */
function rw_test_licensed_subscription( $id, $user_id, $product_id, $meta, $parent_id = 0 ) {
	$product = new RW_Test_Product( $product_id, $parent_id );
	$GLOBALS['rw_test_products'][ (int) $product_id ] = $product;
	if ( $parent_id && ! isset( $GLOBALS['rw_test_products'][ (int) $parent_id ] ) ) {
		$GLOBALS['rw_test_products'][ (int) $parent_id ] = new RW_Test_Product( $parent_id, 0 );
	}
	if ( ! isset( $meta['_reactwoo_license_package_id'] ) ) {
		$meta['_reactwoo_license_package_id'] = 1;
	}
	return new RW_Test_Subscription(
		$id,
		$user_id,
		'active',
		$meta,
		array( new RW_Test_Order_Item( $product, 'Product ' . $product_id ) )
	);
}

$licensed_method = new ReflectionMethod( 'ReactWoo_Customer_Account_Service', 'is_licensed_record' );
$licensed_method->setAccessible( true );

rw_assert( $licensed_method->invoke( null, 'nope' ) === false, 'Non-array records are not licensed' );
rw_assert(
	$licensed_method->invoke(
		null,
		array(
			'product_id'    => 100,
			'license_id'    => 0,
			'key_available' => false,
		)
	) === false,
	'A product with no licence id and no key is omitted'
);
rw_assert(
	$licensed_method->invoke(
		null,
		array(
			'product_id'    => 100,
			'license_id'    => 44,
			'key_available' => false,
		)
	) === true,
	'A licence id is enough to list a product'
);
rw_assert(
	$licensed_method->invoke(
		null,
		array(
			'product_id'    => 100,
			'license_id'    => 0,
			'key_available' => true,
		)
	) === true,
	'An available key is enough to list a product'
);

foreach ( array( 3654, 3657, 3660 ) as $maintenance_id ) {
	rw_assert(
		$licensed_method->invoke(
			null,
			array(
				'product_id'    => $maintenance_id,
				'license_id'    => 90,
				'key_available' => true,
			)
		) === false,
		'Maintenance product ' . $maintenance_id . ' is excluded by default'
	);
}

$GLOBALS['rw_test_products'][8001] = new RW_Test_Product( 8001, 3654 );
rw_assert(
	$licensed_method->invoke(
		null,
		array(
			'product_id'    => 8001,
			'license_id'    => 91,
			'key_available' => true,
		)
	) === false,
	'A variation of maintenance product 3654 is excluded'
);

$GLOBALS['rw_test_filters']['reactwoo_api_manager_unlicensed_product_ids'] = array(
	function ( $ids ) {
		$ids[] = 4242;
		return $ids;
	},
);
rw_assert(
	$licensed_method->invoke(
		null,
		array(
			'product_id'    => 4242,
			'license_id'    => 12,
			'key_available' => true,
		)
	) === false,
	'reactwoo_api_manager_unlicensed_product_ids can exclude another product'
);
rw_assert(
	$licensed_method->invoke(
		null,
		array(
			'product_id'    => 3657,
			'license_id'    => 12,
			'key_available' => true,
		)
	) === false,
	'The filter still excludes the default maintenance plans when it appends an id'
);

$GLOBALS['rw_test_filters']['reactwoo_api_manager_unlicensed_product_ids'] = array(
	function () {
		return array( '9991' );
	},
);
rw_assert(
	$licensed_method->invoke(
		null,
		array(
			'product_id'    => 9991,
			'license_id'    => 12,
			'key_available' => true,
		)
	) === false,
	'String product ids from the filter are normalised with intval'
);
rw_assert(
	$licensed_method->invoke(
		null,
		array(
			'product_id'    => 3654,
			'license_id'    => 12,
			'key_available' => true,
		)
	) === true,
	'Replacing the filter list is what drops a maintenance plan from the exclusion set'
);
$GLOBALS['rw_test_filters'] = array();

$user_id = 42;
$GLOBALS['rw_test_subscriptions'] = array(
	rw_test_licensed_subscription(
		11,
		$user_id,
		100,
		array(
			'_reactwoo_license_key' => 'RWGC-AAAA-BBBB-7F2A',
			'_reactwoo_license_id'  => 501,
		)
	),
	rw_test_licensed_subscription(
		12,
		$user_id,
		200,
		array(
			'_reactwoo_license_id' => 502,
		)
	),
	rw_test_licensed_subscription(
		13,
		$user_id,
		300,
		array(
			'_reactwoo_license_key' => 'RWGC-CCCC-DDDD-9A1B',
		)
	),
	rw_test_licensed_subscription( 14, $user_id, 400, array() ),
	rw_test_licensed_subscription(
		15,
		$user_id,
		3654,
		array(
			'_reactwoo_license_key' => 'RWGC-MAIN-TAIN-3654',
			'_reactwoo_license_id'  => 654,
		)
	),
	rw_test_licensed_subscription(
		16,
		$user_id,
		3657,
		array(
			'_reactwoo_license_key' => 'RWGC-MAIN-TAIN-3657',
			'_reactwoo_license_id'  => 657,
		)
	),
	rw_test_licensed_subscription(
		17,
		$user_id,
		3660,
		array(
			'_reactwoo_license_key' => 'RWGC-MAIN-TAIN-3660',
			'_reactwoo_license_id'  => 660,
		)
	),
	rw_test_licensed_subscription(
		18,
		$user_id,
		8001,
		array(
			'_reactwoo_license_key' => 'RWGC-VARI-ANT-8001',
			'_reactwoo_license_id'  => 801,
		),
		3654
	),
	rw_test_licensed_subscription(
		19,
		99,
		100,
		array(
			'_reactwoo_license_key' => 'RWGC-OTHR-USER-0001',
			'_reactwoo_license_id'  => 777,
		)
	),
);

$records = ReactWoo_Customer_Account_Service::get_instance()->get_account_records( $user_id );
$listed  = array();
foreach ( $records as $record ) {
	$listed[] = (int) $record['product_id'];
}
sort( $listed );
rw_assert( $listed === array( 100, 200, 300 ), 'Account list keeps licensed products and drops maintenance, variations, and keyless rows' );
rw_assert( count( $records ) === 3, 'Account list does not include another customer subscription' );

$GLOBALS['rw_test_filters']['reactwoo_api_manager_unlicensed_product_ids'] = array(
	function ( $ids ) {
		$ids[] = 100;
		return $ids;
	},
);
$filtered = ReactWoo_Customer_Account_Service::get_instance()->get_account_records( $user_id );
$filtered_ids = array();
foreach ( $filtered as $record ) {
	$filtered_ids[] = (int) $record['product_id'];
}
sort( $filtered_ids );
rw_assert( $filtered_ids === array( 200, 300 ), 'Account list honours an extra unlicensed product id from the filter' );
$GLOBALS['rw_test_filters'] = array();

require_once $root . '/includes/class-license-display.php';

$display = new ReactWoo_License_Display();
rw_assert( in_array( 'init', $GLOBALS['rw_test_actions'], true ), 'Licence rewrite registration stays on init' );
rw_assert( in_array( 'woocommerce_account_license_endpoint', $GLOBALS['rw_test_actions'], true ), 'Licence endpoint callback is hooked for direct URLs' );
$display->register_license_endpoint();
rw_assert( $GLOBALS['rw_test_endpoints'] === array( 'license' ), 'register_license_endpoint still adds the license rewrite' );

$menu_items = array(
	'dashboard'       => 'Dashboard',
	'orders'          => 'Orders',
	'license'         => 'Old label',
	'customer-logout' => 'Logout',
);

$GLOBALS['rw_test_logged_in'] = false;
$GLOBALS['rw_test_user_id']   = $user_id;
$logged_out = $display->add_license_menu_item( $menu_items );
rw_assert( ! isset( $logged_out['license'] ), 'Logged-out account menu omits Products & licences' );
rw_assert(
	array_keys( $logged_out ) === array( 'dashboard', 'orders', 'customer-logout' ),
	'Logged-out menu keeps the other account items'
);
rw_assert( $GLOBALS['rw_test_endpoints'] === array( 'license' ), 'Hiding the logged-out menu leaves the rewrite endpoint registered' );

$GLOBALS['rw_test_logged_in'] = true;
$GLOBALS['rw_test_subscriptions'] = array(
	rw_test_licensed_subscription(
		21,
		$user_id,
		3654,
		array(
			'_reactwoo_license_key' => 'RWGC-MAIN-TAIN-3654',
			'_reactwoo_license_id'  => 654,
		)
	),
);
$maintenance_only = $display->add_license_menu_item( $menu_items );
rw_assert( ! isset( $maintenance_only['license'] ), 'Menu hides Products & licences when every subscription is a maintenance plan' );
rw_assert( isset( $maintenance_only['dashboard'] ), 'Dashboard stays in the menu when Products & licences is hidden' );

$GLOBALS['rw_test_subscriptions'] = array(
	rw_test_licensed_subscription(
		22,
		$user_id,
		100,
		array(
			'_reactwoo_license_key' => 'RWGC-AAAA-BBBB-7F2A',
			'_reactwoo_license_id'  => 501,
		)
	),
	rw_test_licensed_subscription(
		23,
		$user_id,
		3660,
		array(
			'_reactwoo_license_key' => 'RWGC-MAIN-TAIN-3660',
			'_reactwoo_license_id'  => 660,
		)
	),
);
$with_licence = $display->add_license_menu_item( $menu_items );
rw_assert( array_key_first( $with_licence ) === 'license', 'Products & licences is the first menu item when a licence exists' );
rw_assert( $with_licence['license'] === 'Products & licences', 'Menu label stays Products & licences' );
rw_assert(
	array_keys( $with_licence ) === array( 'license', 'dashboard', 'orders', 'customer-logout' ),
	'A licensed account keeps the rest of the menu and does not duplicate the licence item'
);
$display->register_license_endpoint();
rw_assert( $GLOBALS['rw_test_endpoints'] === array( 'license', 'license' ), 'The licence endpoint can still be registered after the menu is built' );
rw_assert( in_array( 'woocommerce_account_license_endpoint', $GLOBALS['rw_test_actions'], true ), 'Building the menu does not unhook the licence endpoint' );
