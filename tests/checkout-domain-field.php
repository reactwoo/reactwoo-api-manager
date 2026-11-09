<?php
/**
 * Checkout Licence Domain is shown and validated only for plugin-licence products.
 *
 * Licensed products are subscription products with _reactwoo_license_package_id
 * (on the product or its parent). They keep the License Domain field.
 * Maintenance plans and support packs 3663-3666 collect a separate website,
 * stored as _rw_service_domain. Hosting with no licence package collects
 * neither. Service renewals and switches keep the stored site.
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
		 * @var string
		 */
		private $name;

		/**
		 * @param int    $id Product ID.
		 * @param string $type Product type.
		 * @param int    $parent_id Parent ID.
		 * @param string $name Product name.
		 */
		public function __construct( $id, $type, $parent_id = 0, $name = '' ) {
			$this->id        = (int) $id;
			$this->parent_id = (int) $parent_id;
			$this->type      = (string) $type;
			$this->name      = '' !== $name ? (string) $name : 'Product ' . (int) $id;
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

		/**
		 * @return string
		 */
		public function get_name() {
			return $this->name;
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

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * @param string $text Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = null ) {
		unset( $domain );
		return (string) $text;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * @param string $text Text.
	 * @param string $domain Domain.
	 */
	function esc_html_e( $text, $domain = null ) {
		unset( $domain );
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'wcs_get_subscription' ) ) {
	/**
	 * @param int $subscription_id Subscription ID.
	 * @return object|false
	 */
	function wcs_get_subscription( $subscription_id ) {
		$subscription_id = (int) $subscription_id;
		return isset( $GLOBALS['rw_test_wcs'][ $subscription_id ] ) ? $GLOBALS['rw_test_wcs'][ $subscription_id ] : false;
	}
}

if ( ! class_exists( 'RW_Checkout_Meta_Bag', false ) ) {
	/**
	 * Order, line, or subscription double with meta.
	 */
	class RW_Checkout_Meta_Bag {
		/**
		 * @var array<string,mixed>
		 */
		public $meta = array();

		/**
		 * @var array<int,object>
		 */
		public $items = array();

		/**
		 * @var object|null
		 */
		public $product;

		/**
		 * @var string
		 */
		public $name = '';

		/**
		 * @var bool
		 */
		public $saved = false;

		/**
		 * @param string $key Meta key.
		 * @param mixed  $value Meta value.
		 * @param bool   $unique Unique.
		 */
		public function add_meta_data( $key, $value, $unique = false ) {
			unset( $unique );
			$this->meta[ $key ] = $value;
		}

		/**
		 * @param string $key Meta key.
		 * @param mixed  $value Meta value.
		 */
		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		/**
		 * @param string $key Meta key.
		 * @param bool   $single Single.
		 * @return mixed
		 */
		public function get_meta( $key, $single = true ) {
			unset( $single );
			return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
		}

		/**
		 * @return object|null
		 */
		public function get_product() {
			return $this->product;
		}

		/**
		 * @return string
		 */
		public function get_name() {
			return $this->name;
		}

		/**
		 * @return array<int,object>
		 */
		public function get_items() {
			return $this->items;
		}

		public function save() {
			$this->saved = true;
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
	unset( $_POST['reactwoo_domain'], $_POST['rw_service_domain'] );
}

/**
 * @param ReactWoo_Subscription_Handler $handler Handler.
 * @return string
 */
function rw_checkout_render( $handler ) {
	ob_start();
	$handler->add_domain_field_to_checkout();
	$handler->add_service_website_fields_to_checkout();
	return (string) ob_get_clean();
}

/**
 * @param ReactWoo_Subscription_Handler $handler Handler.
 */
function rw_checkout_validate_all( $handler ) {
	$handler->validate_domain_field();
	$handler->validate_service_website_fields();
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
rw_assert( strpos( $licensed_html, 'Website for this service' ) === false, 'A licensed-only checkout does not ask for a service website' );

rw_checkout_reset_post();
rw_checkout_validate_all( $checkout_handler );
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

$hosting_only = array(
	'hosting'        => rw_checkout_line( $hosting ),
	'hosting_simple' => rw_checkout_line( $hosting_simple ),
);
rw_checkout_use_cart( $hosting_only );
rw_assert( rw_checkout_render( $checkout_handler ) === '', 'A hosting cart with no licence package prints no checkout field' );
rw_checkout_reset_post();
rw_checkout_validate_all( $checkout_handler );
rw_assert( rw_checkout_notice_messages() === array(), 'A hosting cart does not validate a website or a licence domain' );

$maintenance_only = array(
	'plan' => rw_checkout_line( $maintenance_essential ),
);
rw_checkout_use_cart( $maintenance_only );
$maintenance_html = rw_checkout_render( $checkout_handler );
rw_assert( strpos( $maintenance_html, 'License Domain' ) === false, 'A maintenance-only checkout does not use licence wording' );
rw_assert( strpos( $maintenance_html, 'Website for this service' ) !== false, 'A maintenance-only checkout asks for the service website' );
rw_assert( strpos( $maintenance_html, 'This plan applies to that one site.' ) !== false, 'Maintenance help text says the plan applies to that one site' );
rw_assert( strpos( $maintenance_html, 'name="rw_service_domain[plan]"' ) !== false, 'The maintenance website is its own field' );
rw_assert( substr_count( $maintenance_html, 'rw-service-domain-field' ) === 1, 'One maintenance line asks for one website' );
rw_checkout_reset_post();
rw_checkout_validate_all( $checkout_handler );
rw_assert( rw_checkout_notice_messages() === array( 'Please enter the website for Product 3654.' ), 'Maintenance checkout requires a website' );
rw_assert( strpos( implode( ' ', rw_checkout_notice_messages() ), 'licen' ) === false, 'The maintenance website error does not use licence wording' );
$_POST['rw_service_domain'] = array( 'plan' => 'not a domain' );
$GLOBALS['rw_test_notices'] = array();
rw_checkout_validate_all( $checkout_handler );
rw_assert( rw_checkout_notice_messages() === array( 'Please enter a valid website for Product 3654.' ), 'Maintenance checkout rejects an invalid website' );
$_POST['rw_service_domain'] = array( 'plan' => 'https://example.com' );
$GLOBALS['rw_test_notices'] = array();
rw_checkout_validate_all( $checkout_handler );
rw_assert( rw_checkout_notice_messages() === array( 'Please enter a valid website for Product 3654.' ), 'Maintenance checkout uses the same domain pattern as the licence field' );
$_POST['rw_service_domain'] = array( 'plan' => ' plan.example.com ' );
$GLOBALS['rw_test_notices'] = array();
rw_checkout_validate_all( $checkout_handler );
rw_assert( rw_checkout_notice_messages() === array(), 'Maintenance checkout accepts a normalised website' );
rw_assert( $checkout_handler->normalize_checkout_domain( ' plan.example.com ' ) === 'plan.example.com', 'Service websites are normalised with the licence domain check' );
rw_assert( $checkout_handler->normalize_checkout_domain( 'not a domain' ) === '', 'Invalid websites normalise to empty' );

$support_only = array(
	'support' => rw_checkout_line( $support_packs[0] ),
);
rw_checkout_use_cart( $support_only );
$support_html = rw_checkout_render( $checkout_handler );
rw_assert( strpos( $support_html, 'License Domain' ) === false, 'A support-only checkout does not use licence wording' );
rw_assert( strpos( $support_html, 'Website for this service' ) !== false, 'A support-only checkout asks for the service website' );
rw_assert( strpos( $support_html, 'This support applies to that one site.' ) !== false, 'Support help text says the support applies to that one site' );
rw_checkout_reset_post();
$_POST['reactwoo_domain'] = 'licensed.example';
rw_checkout_validate_all( $checkout_handler );
rw_assert( rw_checkout_notice_messages() === array( 'Please enter the website for Product 3663.' ), 'Support checkout ignores a posted licence domain' );

$two_services = array(
	'essential' => rw_checkout_line( $maintenance_essential ),
	'support'   => rw_checkout_line( $support_packs[0] ),
);
rw_checkout_use_cart( $two_services );
$two_html = rw_checkout_render( $checkout_handler );
rw_assert( substr_count( $two_html, 'rw-service-domain-field' ) === 2, 'Each service line has its own website field' );
rw_assert( strpos( $two_html, 'Website for this service (Product 3654)' ) !== false, 'The maintenance line is labelled with its product' );
rw_assert( strpos( $two_html, 'Website for this service (Product 3663)' ) !== false, 'The support line is labelled with its product' );
rw_assert( strpos( $two_html, 'License Domain' ) === false, 'Two service lines do not create a licence domain' );

$support_variation = rw_checkout_product( 9100, 'subscription_variation', 3663, 'Support variation' );
rw_assert( $checkout_handler->product_needs_service_website( $support_variation ) === true, 'A variation of support pack 3663 needs a website' );
rw_assert( $checkout_handler->product_needs_service_website( $hosting ) === false, 'Hosting is not given a service website field' );
rw_assert( $checkout_handler->product_needs_service_website( $maintenance_variation ) === true, 'A maintenance variation needs a website' );
rw_assert( $checkout_handler->product_needs_service_website( $custom_maintenance ) === true, 'A maintenance plan key needs a website without hardcoding that product id' );
rw_checkout_meta( 3663, '_reactwoo_license_package_id', 44 );
rw_assert( $checkout_handler->product_issues_plugin_licence( $support_packs[0] ) === false, 'A support pack with a stale package id is still not a plugin licence' );

rw_checkout_use_cart( $mixed_cart );
$mixed_html = rw_checkout_render( $checkout_handler );
rw_assert( strpos( $mixed_html, 'License Domain' ) !== false, 'A mixed checkout keeps the licence domain field' );
rw_assert( strpos( $mixed_html, 'Website for this service' ) !== false, 'A mixed checkout also asks for each service website' );
rw_assert( strpos( $mixed_html, 'name="reactwoo_domain"' ) !== false, 'The licence domain keeps its own input' );
rw_assert( strpos( $mixed_html, 'name="rw_service_domain[' ) !== false, 'Service websites are not posted as the licence domain' );
rw_assert( strpos( $mixed_html, 'Product 3701' ) === false, 'Hosting in a mixed cart does not get a service website' );
rw_assert( strpos( $mixed_html, 'Product 3702' ) === false, 'Simple hosting in a mixed cart does not get a service website' );
rw_checkout_reset_post();
rw_checkout_validate_all( $checkout_handler );
$mixed_notices = rw_checkout_notice_messages();
rw_assert( in_array( 'Please enter your license domain.', $mixed_notices, true ), 'A mixed checkout still requires a licence domain' );
rw_assert( count( $mixed_notices ) > 1, 'A mixed checkout also requires a website for each service line' );

rw_checkout_use_cart( $renewal_licensed );
$licensed_renewal_html = rw_checkout_render( $checkout_handler );
rw_assert( strpos( $licensed_renewal_html, 'License Domain' ) !== false, 'A licensed renewal checkout prints the domain field' );
rw_assert( strpos( $licensed_renewal_html, 'Website for this service' ) === false, 'A licensed renewal does not ask for a service website' );
rw_checkout_reset_post();
rw_checkout_validate_all( $checkout_handler );
rw_assert( rw_checkout_notice_messages() === array( 'Please enter your license domain.' ), 'A licensed renewal still requires a domain' );
$_POST['reactwoo_domain'] = 'example.com';
$GLOBALS['rw_test_notices'] = array();
rw_checkout_validate_all( $checkout_handler );
rw_assert( rw_checkout_notice_messages() === array(), 'A licensed renewal accepts a valid domain' );

rw_checkout_use_cart( $renewal_service );
rw_assert( rw_checkout_render( $checkout_handler ) === '', 'A maintenance renewal does not ask for a website again' );
rw_checkout_reset_post();
$_POST['reactwoo_domain'] = 'licensed.example';
$_POST['rw_service_domain'] = array( '0' => 'posted.example' );
rw_checkout_validate_all( $checkout_handler );
rw_assert( rw_checkout_notice_messages() === array(), 'A maintenance renewal does not validate a new website' );

rw_checkout_use_cart( $switch_licensed );
$licensed_switch_html = rw_checkout_render( $checkout_handler );
rw_assert( strpos( $licensed_switch_html, 'name="reactwoo_domain"' ) !== false, 'A licensed subscription switch prints the domain field' );
rw_assert( strpos( $licensed_switch_html, 'Website for this service' ) === false, 'A licensed switch does not ask for a service website' );
rw_checkout_reset_post();
$_POST['reactwoo_domain'] = 'bad domain';
rw_checkout_validate_all( $checkout_handler );
rw_assert( rw_checkout_notice_messages() === array( 'Please enter a valid domain name.' ), 'A licensed subscription switch still rejects an invalid domain' );

rw_checkout_use_cart( $switch_service );
rw_assert( rw_checkout_render( $checkout_handler ) === '', 'A maintenance subscription switch does not ask for a website again' );
rw_checkout_reset_post();
$_POST['reactwoo_domain'] = 'bad domain';
$_POST['rw_service_domain'] = array( '0' => 'posted.example' );
rw_checkout_validate_all( $checkout_handler );
rw_assert( rw_checkout_notice_messages() === array(), 'A maintenance subscription switch does not validate a new website' );

$meta_key = ReactWoo_Subscription_Handler::SERVICE_DOMAIN_META;
rw_assert( $meta_key === '_rw_service_domain', 'Service websites use the _rw_service_domain meta key' );

$plan_item = new RW_Checkout_Meta_Bag();
$plan_item->product = $maintenance_essential;
$_POST['rw_service_domain'] = array( 'plan' => ' plan.example.com ' );
$_POST['reactwoo_domain'] = 'licensed.example';
$checkout_handler->save_checkout_service_domain_line_item( $plan_item, 'plan', rw_checkout_line( $maintenance_essential ), null );
rw_assert( isset( $plan_item->meta[ $meta_key ] ) && $plan_item->meta[ $meta_key ] === 'plan.example.com', 'The maintenance line stores the normalised service website' );
rw_assert( ! isset( $plan_item->meta['_reactwoo_domain'] ), 'A licence domain is not copied onto a service line' );

$support_item = new RW_Checkout_Meta_Bag();
$support_item->product = $support_packs[0];
$_POST['rw_service_domain'] = array( 'support' => 'support.example' );
$_POST['reactwoo_domain'] = 'licensed.example';
$checkout_handler->save_checkout_service_domain_line_item( $support_item, 'support', rw_checkout_line( $support_packs[0] ), null );
rw_assert( $support_item->meta[ $meta_key ] === 'support.example', 'The support line stores its own website' );

$host_item = new RW_Checkout_Meta_Bag();
$host_item->product = $hosting;
$_POST['rw_service_domain'] = array( 'hosting' => 'host.example' );
$_POST['reactwoo_domain'] = 'host.example';
$checkout_handler->save_checkout_service_domain_line_item( $host_item, 'hosting', rw_checkout_line( $hosting ), null );
rw_assert( $host_item->meta === array(), 'Hosting does not store a service website' );

$order_line = new RW_Checkout_Meta_Bag();
$order_line->product = $maintenance_essential;
$order_line->meta[ $meta_key ] = 'plan.example.com';
$order = new RW_Checkout_Meta_Bag();
$order->items = array( $order_line );
$sub_line = new RW_Checkout_Meta_Bag();
$sub_line->product = $maintenance_essential;
$subscription = new RW_Checkout_Meta_Bag();
$subscription->items = array( $sub_line );
$checkout_handler->save_service_domain_on_new_subscription( $subscription, $order, null );
rw_assert( $sub_line->meta[ $meta_key ] === 'plan.example.com', 'The maintenance subscription line keeps the website' );
rw_assert( $subscription->meta[ $meta_key ] === 'plan.example.com', 'The maintenance subscription stores _rw_service_domain' );

$support_order_line = new RW_Checkout_Meta_Bag();
$support_order_line->product = $support_packs[0];
$support_order_line->meta[ $meta_key ] = 'support.example';
$support_order = new RW_Checkout_Meta_Bag();
$support_order->items = array( $support_order_line );
$support_sub_line = new RW_Checkout_Meta_Bag();
$support_sub_line->product = $support_packs[0];
$support_sub = new RW_Checkout_Meta_Bag();
$support_sub->items = array( $support_sub_line );
$checkout_handler->save_service_domain_on_new_subscription( $support_sub, $support_order, null );
rw_assert( $support_sub_line->meta[ $meta_key ] === 'support.example', 'A support line on a subscription stores the website' );
rw_assert( ! isset( $support_sub->meta[ $meta_key ] ), 'A support pack does not write maintenance subscription meta' );

$second_order_line = new RW_Checkout_Meta_Bag();
$second_order_line->product = $maintenance_standard;
$second_order_line->meta[ $meta_key ] = 'other.example';
$split_order = new RW_Checkout_Meta_Bag();
$split_order->items = array( $order_line, $second_order_line );
$split_a = new RW_Checkout_Meta_Bag();
$split_a->product = $maintenance_essential;
$split_b = new RW_Checkout_Meta_Bag();
$split_b->product = $maintenance_standard;
$split_sub = new RW_Checkout_Meta_Bag();
$split_sub->items = array( $split_a, $split_b );
$checkout_handler->save_service_domain_on_new_subscription( $split_sub, $split_order, null );
rw_assert( $split_a->meta[ $meta_key ] === 'plan.example.com', 'Each maintenance line keeps its own website' );
rw_assert( $split_b->meta[ $meta_key ] === 'other.example', 'A second maintenance line keeps a different website' );
rw_assert( ! isset( $split_sub->meta[ $meta_key ] ), 'Different maintenance sites are not collapsed onto one subscription value' );

$GLOBALS['rw_test_wcs'][910] = $subscription;
$renewal_item = new RW_Checkout_Meta_Bag();
$renewal_item->product = $maintenance_essential;
$_POST['reactwoo_domain'] = 'licensed.example';
$_POST['rw_service_domain'] = array( '0' => 'posted.example' );
$checkout_handler->save_checkout_service_domain_line_item( $renewal_item, '0', $renewal_service[0], null );
rw_assert( $renewal_item->meta[ $meta_key ] === 'plan.example.com', 'A maintenance renewal keeps the stored site instead of the posted value' );

$geo_renewal_item = new RW_Checkout_Meta_Bag();
$geo_renewal_item->product = $geo;
$service_renewal_item = new RW_Checkout_Meta_Bag();
$service_renewal_item->product = $maintenance_essential;
$renewal_order = new RW_Checkout_Meta_Bag();
$renewal_order->items = array( $geo_renewal_item, $service_renewal_item );
$checkout_handler->copy_service_domain_to_renewal_order( $renewal_order, $subscription );
rw_assert( ! isset( $geo_renewal_item->meta[ $meta_key ] ), 'Automatic renewal does not put the service website on a licensed line' );
rw_assert( $service_renewal_item->meta[ $meta_key ] === 'plan.example.com', 'Automatic renewal copies the stored site onto the maintenance line' );

$old_line = new RW_Checkout_Meta_Bag();
$old_line->meta[ $meta_key ] = 'switch.example';
$new_line = new RW_Checkout_Meta_Bag();
$new_line->product = $maintenance_standard;
$switch_sub = new RW_Checkout_Meta_Bag();
$checkout_handler->keep_service_domain_on_switched_item( null, $switch_sub, $new_line, $old_line );
rw_assert( $new_line->meta[ $meta_key ] === 'switch.example', 'A maintenance switch copies the stored site onto the new line' );
rw_assert( $switch_sub->meta[ $meta_key ] === 'switch.example', 'A maintenance switch keeps the website on the subscription' );

$GLOBALS['rw_test_wcs'][912] = $switch_sub;
$switch_order_item = new RW_Checkout_Meta_Bag();
$switch_order_item->product = $maintenance_variation;
$_POST['reactwoo_domain'] = 'licensed.example';
$_POST['rw_service_domain'] = array( '0' => 'posted.example' );
$checkout_handler->save_checkout_service_domain_line_item( $switch_order_item, '0', $switch_service[0], null );
rw_assert( $switch_order_item->meta[ $meta_key ] === 'switch.example', 'Switch checkout stores the existing site and ignores the licence domain' );

$admin_line = new RW_Checkout_Meta_Bag();
$admin_line->name = 'Essential maintenance';
$admin_line->meta[ $meta_key ] = 'plan.example.com';
$admin_order = new RW_Checkout_Meta_Bag();
$admin_order->items = array( $admin_line );
ob_start();
$checkout_handler->display_service_domain_admin( $admin_order );
$admin_html = ob_get_clean();
rw_assert( strpos( $admin_html, 'Website for this service' ) !== false, 'Admin orders and subscriptions show the service website' );
rw_assert( strpos( $admin_html, 'Essential maintenance: plan.example.com' ) !== false, 'Admin shows which line the website belongs to' );
rw_assert( stripos( $admin_html, 'licen' ) === false, 'The service admin block does not use licence wording' );
ob_start();
$checkout_handler->display_service_domain_order_item( 1, $admin_line, $maintenance_essential );
$item_html = ob_get_clean();
rw_assert( strpos( $item_html, 'Website for this service:' ) !== false && strpos( $item_html, 'plan.example.com' ) !== false, 'Admin order items show the service website' );
$hidden_meta = $checkout_handler->hide_service_domain_item_meta( array( '_qty' ) );
rw_assert( in_array( '_rw_service_domain', $hidden_meta, true ), 'Service website meta is hidden from customer-facing item meta' );
rw_assert( in_array( 'woocommerce_checkout_create_order_line_item', $GLOBALS['rw_test_actions'], true ), 'Checkout stores the service website on the order line' );
rw_assert( in_array( 'woocommerce_checkout_subscription_created', $GLOBALS['rw_test_actions'], true ), 'New maintenance subscriptions receive the service website' );
rw_assert( in_array( 'wcs_renewal_order_created', $GLOBALS['rw_test_actions'], true ), 'Automatic renewals copy the stored service website' );
rw_assert( in_array( 'woocommerce_subscription_item_switched', $GLOBALS['rw_test_actions'], true ), 'Subscription switches keep the stored service website' );
rw_assert( in_array( 'woocommerce_after_order_itemmeta', $GLOBALS['rw_test_actions'], true ), 'Admin order items can show the service website' );

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
