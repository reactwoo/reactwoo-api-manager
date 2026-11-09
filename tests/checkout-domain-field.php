<?php
/**
 * Checkout Licence Domain is shown and validated only for plugin-licence products.
 *
 * Licensed products are subscription products with _reactwoo_license_package_id
 * (on the product or its parent). Maintenance plans 3654, 3657 and 3660,
 * including subscription variations, stay services even with a stale package
 * id. Support packs 3663-3666 and hosting have no licence package, so they
 * do not collect a domain. Renewals and subscription switches follow the
 * product in the cart.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @param int    $post_id Post ID.
	 * @param string $key Meta key.
	 * @param bool   $single Single value.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$post_id = (int) $post_id;
		$meta    = isset( $GLOBALS['rw_test_post_meta'][ $post_id ] ) ? $GLOBALS['rw_test_post_meta'][ $post_id ] : array();
		if ( '' === $key ) {
			return $meta;
		}
		if ( ! isset( $meta[ $key ] ) ) {
			return $single ? '' : array();
		}
		return $meta[ $key ];
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str Raw text.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	/**
	 * @param string $message Notice.
	 * @param string $notice_type Notice type.
	 */
	function wc_add_notice( $message, $notice_type = 'success' ) {
		$GLOBALS['rw_test_notices'][] = array(
			'message' => $message,
			'type'    => $notice_type,
		);
	}
}

if ( ! function_exists( 'woocommerce_form_field' ) ) {
	/**
	 * @param string               $key Field key.
	 * @param array<string, mixed> $args Field args.
	 * @param mixed                $value Current value.
	 */
	function woocommerce_form_field( $key, $args, $value = null ) {
		$required = ! empty( $args['required'] ) ? 'validate-required' : '';
		echo '<p id="' . $key . '_field" class="' . $required . '">';
		echo '<label for="' . $key . '">' . $args['label'] . '</label>';
		echo '<span class="description">' . $args['description'] . '</span>';
		echo '<input type="text" name="' . $key . '" placeholder="' . $args['placeholder'] . '" value="' . (string) $value . '" />';
		echo '</p>';
	}
}

if ( ! class_exists( 'RW_Checkout_Product', false ) ) {
	/**
	 * Subscription-like product for checkout domain tests.
	 */
	class RW_Checkout_Product {
		/**
		 * @var int
		 */
		private $id;

		/**
		 * @var int
		 */
		private $parent_id;

		/**
		 * @var string
		 */
		private $type;

		/**
		 * @param int    $id Product ID.
		 * @param string $type Product type.
		 * @param int    $parent_id Parent ID.
		 */
		public function __construct( $id, $type, $parent_id = 0 ) {
			$this->id        = (int) $id;
			$this->parent_id = (int) $parent_id;
			$this->type      = (string) $type;
		}

		/**
		 * @return int
		 */
		public function get_id() {
			return $this->id;
		}

		/**
		 * @return int
		 */
		public function get_parent_id() {
			return $this->parent_id;
		}

		/**
		 * @param string|array<int, string> $type Type or types.
		 * @return bool
		 */
		public function is_type( $type ) {
			$types = (array) $type;
			return in_array( $this->type, $types, true );
		}
	}
}

if ( ! class_exists( 'RW_Checkout_Cart', false ) ) {
	/**
	 * Cart double.
	 */
	class RW_Checkout_Cart {
		/**
		 * @var array<int, array<string, mixed>>
		 */
		private $items;

		/**
		 * @param array<int, array<string, mixed>> $items Cart lines.
		 */
		public function __construct( $items ) {
			$this->items = $items;
		}

		/**
		 * @return array<int, array<string, mixed>>
		 */
		public function get_cart() {
			return $this->items;
		}
	}
}

if ( ! class_exists( 'RW_Checkout_Form', false ) ) {
	/**
	 * Checkout form double.
	 */
	class RW_Checkout_Form {
		/**
		 * @param string $key Field key.
		 * @return string
		 */
		public function get_value( $key ) {
			unset( $key );
			return '';
		}
	}
}

if ( ! class_exists( 'RW_Checkout_WC', false ) ) {
	/**
	 * WooCommerce container double.
	 */
	class RW_Checkout_WC {
		/**
		 * @var object|null
		 */
		public $cart;

		/**
		 * @var RW_Checkout_Form
		 */
		public $checkout;

		public function __construct() {
			$this->cart     = null;
			$this->checkout = new RW_Checkout_Form();
		}

		/**
		 * @return RW_Checkout_Form
		 */
		public function checkout() {
			return $this->checkout;
		}
	}
}

if ( ! function_exists( 'WC' ) ) {
	/**
	 * @return RW_Checkout_WC
	 */
	function WC() {
		return $GLOBALS['rw_checkout_wc'];
	}
}

require_once dirname( __DIR__ ) . '/includes/class-subscription-handler.php';

$GLOBALS['rw_checkout_wc']    = new RW_Checkout_WC();
$GLOBALS['rw_test_post_meta'] = isset( $GLOBALS['rw_test_post_meta'] ) ? $GLOBALS['rw_test_post_meta'] : array();
$GLOBALS['rw_test_filters']   = array();
$GLOBALS['rw_test_notices']   = array();

$checkout_handler = new ReactWoo_Subscription_Handler();

rw_assert( in_array( 'woocommerce_after_order_notes', $GLOBALS['rw_test_actions'], true ), 'Domain field stays on woocommerce_after_order_notes' );
rw_assert( in_array( 'woocommerce_checkout_process', $GLOBALS['rw_test_actions'], true ), 'Domain validation stays on woocommerce_checkout_process' );
rw_assert( in_array( 'woocommerce_subscription_renewal_payment_complete', $GLOBALS['rw_test_actions'], true ), 'Paid renewal licence handling stays registered' );
rw_assert( in_array( 'woocommerce_checkout_update_order_meta', $GLOBALS['rw_test_actions'], true ), 'Saving a posted licence domain stays registered' );

/**
 * @param int    $post_id Post ID.
 * @param string $key Meta key.
 * @param mixed  $value Meta value.
 */
function rw_checkout_meta( $post_id, $key, $value ) {
	$GLOBALS['rw_test_post_meta'][ (int) $post_id ][ $key ] = $value;
}

/**
 * @param int    $id Product ID.
 * @param string $type Product type.
 * @param int    $parent_id Parent ID.
 * @return RW_Checkout_Product
 */
function rw_checkout_product( $id, $type, $parent_id = 0 ) {
	$product = new RW_Checkout_Product( $id, $type, $parent_id );
	$GLOBALS['rw_test_products'][ (int) $id ] = $product;
	return $product;
}

/**
 * @param RW_Checkout_Product  $product Product.
 * @param array<string, mixed> $extra Cart item data.
 * @return array<string, mixed>
 */
function rw_checkout_line( $product, $extra = array() ) {
	$line = array(
		'product_id'   => $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(),
		'variation_id' => $product->get_parent_id() ? $product->get_id() : 0,
		'data'         => $product,
		'quantity'     => 1,
	);
	return array_merge( $line, $extra );
}

/**
 * @param array<int, array<string, mixed>> $items Cart lines.
 * @return RW_Checkout_Cart
 */
function rw_checkout_use_cart( $items ) {
	$cart                         = new RW_Checkout_Cart( $items );
	$GLOBALS['rw_checkout_wc']->cart = $cart;
	return $cart;
}

/**
 * @return void
 */
function rw_checkout_reset_post() {
	$GLOBALS['rw_test_notices'] = array();
	unset( $_POST['reactwoo_domain'] );
}

/**
 * @param ReactWoo_Subscription_Handler $handler Handler.
 * @return string
 */
function rw_checkout_render( $handler ) {
	ob_start();
	$handler->add_domain_field_to_checkout();
	return (string) ob_get_clean();
}

/**
 * @return array<int, string>
 */
function rw_checkout_notice_messages() {
	$messages = array();
	foreach ( $GLOBALS['rw_test_notices'] as $notice ) {
		$messages[] = $notice['message'];
	}
	return $messages;
}

$geo = rw_checkout_product( 5101, 'subscription' );
rw_checkout_meta( 5101, '_reactwoo_license_package_id', 21 );

$atomic_parent = rw_checkout_product( 5100, 'variable-subscription' );
rw_checkout_meta( 5100, '_reactwoo_license_package_id', 22 );
$atomic = rw_checkout_product( 5102, 'subscription_variation', 5100 );

$other_licensed = rw_checkout_product( 5103, 'subscription' );
rw_checkout_meta( 5103, '_reactwoo_license_package_id', 23 );

$maintenance_essential = rw_checkout_product( 3654, 'subscription' );
rw_checkout_meta( 3654, '_rw_maintenance_plan_key', 'essential' );
rw_checkout_meta( 3654, '_reactwoo_license_package_id', 99 );

$maintenance_standard = rw_checkout_product( 3657, 'subscription' );
rw_checkout_meta( 3657, '_reactwoo_license_package_id', 77 );

$maintenance_business = rw_checkout_product( 3660, 'subscription' );
rw_checkout_meta( 3660, '_rw_maintenance_plan_key', 'business' );

$maintenance_variation = rw_checkout_product( 8002, 'subscription_variation', 3654 );
rw_checkout_meta( 8002, '_reactwoo_license_package_id', 88 );

$maintenance_variation_by_parent = rw_checkout_product( 8003, 'subscription_variation', 3657 );
rw_checkout_meta( 8003, '_reactwoo_license_package_id', 5 );

$support_packs = array();
foreach ( array( 3663, 3664, 3665 ) as $support_id ) {
	$support_packs[] = rw_checkout_product( $support_id, 'subscription' );
}
$support_packs[] = rw_checkout_product( 3666, 'simple' );

$hosting = rw_checkout_product( 3701, 'subscription' );
$hosting_simple = rw_checkout_product( 3702, 'simple' );

$custom_maintenance = rw_checkout_product( 7777, 'subscription' );
rw_checkout_meta( 7777, '_rw_maintenance_plan_key', 'standard' );
rw_checkout_meta( 7777, '_reactwoo_license_package_id', 3 );

rw_assert( $checkout_handler->product_issues_plugin_licence( $geo ) === true, 'Geo Core Pro issues a plugin licence via its package id' );
rw_assert( $checkout_handler->product_issues_plugin_licence( $atomic_parent ) === true, 'A variable subscription with a package id issues a plugin licence' );
rw_assert( $checkout_handler->product_issues_plugin_licence( $atomic ) === true, 'Atomic Pro inherits the parent licence package id' );
rw_assert( $checkout_handler->product_issues_plugin_licence( $other_licensed ) === true, 'Any other subscription with a licence package id is licensed' );
rw_assert( $checkout_handler->product_issues_plugin_licence( $maintenance_essential ) === false, 'Maintenance 3654 does not issue a plugin licence when a stale package id is stored' );
rw_assert( $checkout_handler->product_issues_plugin_licence( $maintenance_standard ) === false, 'Maintenance 3657 is an unlicensed service even with a stale package id' );
rw_assert( $checkout_handler->product_issues_plugin_licence( $maintenance_business ) === false, 'Maintenance 3660 does not issue a plugin licence' );
rw_assert( $checkout_handler->product_issues_plugin_licence( $maintenance_variation ) === false, 'A subscription variation of maintenance 3654 does not issue a plugin licence' );
rw_assert( $checkout_handler->product_issues_plugin_licence( $maintenance_variation_by_parent ) === false, 'A variation of maintenance 3657 is excluded with its parent' );
rw_assert( $checkout_handler->product_issues_plugin_licence( $custom_maintenance ) === false, 'A maintenance plan key blocks licence checkout without hardcoding that product id' );
foreach ( $support_packs as $support_pack ) {
	rw_assert(
		$checkout_handler->product_issues_plugin_licence( $support_pack ) === false,
		'Support pack ' . $support_pack->get_id() . ' does not issue a plugin licence'
	);
}
rw_assert( $checkout_handler->product_issues_plugin_licence( $hosting ) === false, 'Hosting without a licence package does not issue a plugin licence' );
rw_assert( $checkout_handler->product_issues_plugin_licence( $hosting_simple ) === false, 'A simple hosting product does not issue a plugin licence' );

$one_time_with_package = rw_checkout_product( 6000, 'simple' );
rw_checkout_meta( 6000, '_reactwoo_license_package_id', 30 );
rw_assert( $checkout_handler->product_issues_plugin_licence( $one_time_with_package ) === false, 'A non-subscription product does not issue a plugin licence' );

$licensed_cart = array( rw_checkout_line( $geo ) );
$service_cart  = array(
	rw_checkout_line( $maintenance_essential ),
	rw_checkout_line( $maintenance_standard ),
	rw_checkout_line( $maintenance_business ),
	rw_checkout_line( $maintenance_variation ),
	rw_checkout_line( $maintenance_variation_by_parent ),
);
foreach ( $support_packs as $support_pack ) {
	$service_cart[] = rw_checkout_line( $support_pack );
}
$service_cart[] = rw_checkout_line( $hosting );
$service_cart[] = rw_checkout_line( $hosting_simple );
$service_cart[] = rw_checkout_line( $custom_maintenance );

$mixed_cart = array_merge( array( rw_checkout_line( $atomic ) ), $service_cart );

$renewal_licensed = array(
	rw_checkout_line(
		$geo,
		array(
			'subscription_renewal' => array(
				'subscription_id' => 900,
				'renewal_order_id' => 901,
			),
		)
	),
);
$renewal_service = array(
	rw_checkout_line(
		$maintenance_essential,
		array(
			'subscription_renewal' => array(
				'subscription_id' => 910,
				'renewal_order_id' => 911,
			),
		)
	),
);
$switch_licensed = array(
	rw_checkout_line(
		$atomic,
		array(
			'subscription_switch' => array(
				'subscription_id' => 902,
				'item_id'         => 3,
			),
		)
	),
);
$switch_service = array(
	rw_checkout_line(
		$maintenance_variation,
		array(
			'subscription_switch' => array(
				'subscription_id' => 912,
				'item_id'         => 4,
			),
		)
	),
);

rw_assert( $checkout_handler->cart_needs_license_domain( new RW_Checkout_Cart( $licensed_cart ) ) === true, 'A licensed-only cart needs a domain' );
rw_assert( $checkout_handler->cart_needs_license_domain( new RW_Checkout_Cart( $service_cart ) ) === false, 'A service-only cart does not need a domain' );
rw_assert( $checkout_handler->cart_needs_license_domain( new RW_Checkout_Cart( $mixed_cart ) ) === true, 'A mixed cart needs a domain for the licensed item' );
rw_assert( $checkout_handler->cart_needs_license_domain( new RW_Checkout_Cart( $renewal_licensed ) ) === true, 'Renewing a licensed subscription still needs a domain' );
rw_assert( $checkout_handler->cart_needs_license_domain( new RW_Checkout_Cart( $renewal_service ) ) === false, 'Renewing a maintenance plan does not need a domain' );
rw_assert( $checkout_handler->cart_needs_license_domain( new RW_Checkout_Cart( $switch_licensed ) ) === true, 'Switching a licensed subscription still needs a domain' );
rw_assert( $checkout_handler->cart_needs_license_domain( new RW_Checkout_Cart( $switch_service ) ) === false, 'Switching a maintenance variation does not need a domain' );
rw_assert( $checkout_handler->cart_needs_license_domain( new RW_Checkout_Cart( array() ) ) === false, 'An empty cart does not need a domain' );

$lookup_line = array(
	'product_id'   => 5103,
	'variation_id' => 0,
	'quantity'     => 1,
);
rw_assert(
	$checkout_handler->cart_needs_license_domain( new RW_Checkout_Cart( array( $lookup_line ) ) ) === true,
	'A cart line without a product object still resolves a licensed product by id'
);

rw_checkout_use_cart( $licensed_cart );
$licensed_html = rw_checkout_render( $checkout_handler );
rw_assert( strpos( $licensed_html, 'License Domain' ) !== false, 'Licensed checkout prints the License Domain field' );
rw_assert( strpos( $licensed_html, 'id="reactwoo_domain_field"' ) !== false, 'Licensed checkout keeps the domain field wrapper' );
rw_assert( strpos( $licensed_html, 'name="reactwoo_domain"' ) !== false, 'Licensed checkout keeps the reactwoo_domain input' );
rw_assert( strpos( $licensed_html, 'example.com' ) !== false, 'Licensed checkout keeps the domain placeholder' );
rw_assert( strpos( $licensed_html, 'Enter the domain where you will use the license key.' ) !== false, 'Licensed checkout keeps the domain description' );
rw_assert( strpos( $licensed_html, 'validate-required' ) !== false, 'Licensed checkout keeps the domain field required' );
rw_assert( substr_count( $licensed_html, 'License Domain' ) === 1, 'Licensed checkout prints the domain field once' );

rw_checkout_reset_post();
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array( 'Please enter your license domain.' ), 'Licensed checkout rejects an empty domain' );

rw_checkout_reset_post();
$_POST['reactwoo_domain'] = 'not a domain';
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array( 'Please enter a valid domain name.' ), 'Licensed checkout rejects an invalid domain' );

rw_checkout_reset_post();
$_POST['reactwoo_domain'] = 'example.com';
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array(), 'Licensed checkout accepts example.com' );

rw_checkout_reset_post();
$_POST['reactwoo_domain'] = 'shop.example.co.uk';
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array(), 'Licensed checkout accepts a subdomain' );

rw_checkout_use_cart( $service_cart );
rw_assert( rw_checkout_render( $checkout_handler ) === '', 'A service-only checkout prints no domain field' );
rw_checkout_reset_post();
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array(), 'A service-only checkout does not require a domain' );
$_POST['reactwoo_domain'] = 'not a domain';
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array(), 'A service-only checkout does not validate a posted domain' );

rw_checkout_use_cart( $mixed_cart );
$mixed_html = rw_checkout_render( $checkout_handler );
rw_assert( strpos( $mixed_html, 'License Domain' ) !== false, 'A mixed checkout keeps the domain field' );
rw_checkout_reset_post();
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array( 'Please enter your license domain.' ), 'A mixed checkout still requires a domain' );
$_POST['reactwoo_domain'] = 'example.com';
$GLOBALS['rw_test_notices'] = array();
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array(), 'A mixed checkout accepts a valid domain' );

rw_checkout_use_cart( $renewal_licensed );
rw_assert( strpos( rw_checkout_render( $checkout_handler ), 'License Domain' ) !== false, 'A licensed renewal checkout prints the domain field' );
rw_checkout_reset_post();
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array( 'Please enter your license domain.' ), 'A licensed renewal still requires a domain' );
$_POST['reactwoo_domain'] = 'example.com';
$GLOBALS['rw_test_notices'] = array();
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array(), 'A licensed renewal accepts a valid domain' );

rw_checkout_use_cart( $renewal_service );
rw_assert( rw_checkout_render( $checkout_handler ) === '', 'A maintenance renewal prints no domain field' );
rw_checkout_reset_post();
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array(), 'A maintenance renewal does not validate the domain' );

rw_checkout_use_cart( $switch_licensed );
rw_assert( strpos( rw_checkout_render( $checkout_handler ), 'name="reactwoo_domain"' ) !== false, 'A licensed subscription switch prints the domain field' );
rw_checkout_reset_post();
$_POST['reactwoo_domain'] = 'bad domain';
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array( 'Please enter a valid domain name.' ), 'A licensed subscription switch still rejects an invalid domain' );

rw_checkout_use_cart( $switch_service );
rw_assert( rw_checkout_render( $checkout_handler ) === '', 'A maintenance subscription switch prints no domain field' );
rw_checkout_reset_post();
$_POST['reactwoo_domain'] = 'bad domain';
$checkout_handler->validate_domain_field();
rw_assert( rw_checkout_notice_messages() === array(), 'A maintenance subscription switch does not validate the domain' );

$GLOBALS['rw_test_filters']['reactwoo_api_manager_unlicensed_product_ids'] = array(
	function ( $ids ) {
		$ids[] = 5101;
		return $ids;
	},
);
rw_assert(
	$checkout_handler->cart_needs_license_domain( new RW_Checkout_Cart( $licensed_cart ) ) === false,
	'The unlicensed product filter can treat another product as a service'
);
$GLOBALS['rw_test_filters'] = array();
rw_assert(
	$checkout_handler->cart_needs_license_domain( new RW_Checkout_Cart( $licensed_cart ) ) === true,
	'Clearing the unlicensed product filter restores the licensed cart'
);

$GLOBALS['rw_checkout_wc']->cart = null;
rw_assert( $checkout_handler->cart_needs_license_domain() === true, 'An unreadable cart keeps the historical domain requirement' );
rw_assert( strpos( rw_checkout_render( $checkout_handler ), 'License Domain' ) !== false, 'An unreadable cart still prints the domain field' );

$handler_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-subscription-handler.php' );
$create_pos  = strpos( $handler_src, 'function create_license_for_subscription' );
$create_body = substr( $handler_src, (int) $create_pos, 4000 );
rw_assert( false !== strpos( $create_body, 'maintenance_entitlement_slugs' ), 'Licence creation still skips maintenance plans before package lookup' );
rw_assert( false !== strpos( $create_body, '_reactwoo_license_package_id' ), 'Licence creation still reads the licence package flag' );
rw_assert( false !== strpos( $handler_src, 'function handle_subscription_renewal' ), 'Renewal licence updates stay on the subscription handler' );
rw_assert( false === strpos( $create_body, 'cart_needs_license_domain' ), 'The checkout gate does not change licence creation' );
