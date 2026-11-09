<?php
/**
 * One Woo subscription can provision one licence per package.
 *
 * A single package still uses the plain subscription id. A bundle uses
 * "{subscription_id}:{package_slug}" for every package. Re-running completion
 * does not call the licence server again. Renewal, status sync, payment
 * failure and trash/delete cover every stored licence.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string $type Type.
	 * @return string
	 */
	function current_time( $type = 'mysql' ) {
		unset( $type );
		return '2026-01-15 12:00:00';
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * @param string $hook Hook.
	 */
	function do_action( $hook ) {
		$GLOBALS['rw_test_did_actions'][] = $hook;
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	/**
	 * @param array<int,object> $list List.
	 * @param string            $field Field.
	 * @return array<int,mixed>
	 */
	function wp_list_pluck( $list, $field ) {
		$values = array();
		$method = 'get_' . $field;
		foreach ( (array) $list as $item ) {
			if ( is_object( $item ) && method_exists( $item, $method ) ) {
				$values[] = $item->$method();
			}
		}
		return $values;
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int $order_id Order id.
	 * @return object|false
	 */
	function wc_get_order( $order_id ) {
		$order_id = (int) $order_id;
		return isset( $GLOBALS['rw_test_orders'][ $order_id ] ) ? $GLOBALS['rw_test_orders'][ $order_id ] : false;
	}
}

if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
	/**
	 * @param int $order_id Order id.
	 * @return array<int,object>
	 */
	function wcs_get_subscriptions_for_order( $order_id ) {
		$order_id = (int) $order_id;
		return isset( $GLOBALS['rw_test_order_subscriptions'][ $order_id ] ) ? $GLOBALS['rw_test_order_subscriptions'][ $order_id ] : array();
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * @param string $text Text.
	 * @param string $domain Domain.
	 */
	function esc_attr_e( $text, $domain = null ) {
		unset( $domain );
		echo esc_attr( $text );
	}
}

if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
	/**
	 * @param string $endpoint Endpoint.
	 * @return string
	 */
	function wc_get_account_endpoint_url( $endpoint ) {
		return 'https://example.test/my-account/' . $endpoint . '/';
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Order double for customer-facing licence output.
	 */
	class WC_Order {
		/**
		 * @var int
		 */
		public $id = 0;

		/**
		 * @var array<string,mixed>
		 */
		public $meta = array();

		/**
		 * @param int $id Order id.
		 */
		public function __construct( $id = 0 ) {
			$this->id = (int) $id;
		}

		/**
		 * @return int
		 */
		public function get_id() {
			return $this->id;
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
		 * @param string $key Meta key.
		 * @param mixed  $value Value.
		 */
		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'RW_Bundle_License_API', false ) ) {
	/**
	 * Licence server double. Records every provision, sync and status change.
	 */
	class RW_Bundle_License_API {
		/**
		 * @var array<int,array<string,mixed>>
		 */
		public $provisions = array();

		/**
		 * @var array<int,array<string,mixed>>
		 */
		public $syncs = array();

		/**
		 * @var array<int,array<string,mixed>>
		 */
		public $statuses = array();

		/**
		 * @var array<int,string>
		 */
		public $fail = array();

		/**
		 * @var array<int,array<string,mixed>>
		 */
		public $packages = array();

		/**
		 * @param int $id Package id.
		 * @return array<string,mixed>|null
		 */
		public function get_package_by_id( $id ) {
			$id = (int) $id;
			return isset( $this->packages[ $id ] ) ? $this->packages[ $id ] : null;
		}

		/**
		 * @param array<string,mixed> $args Provision args.
		 * @return array<string,mixed>|WP_Error
		 */
		public function provision_license_v1( $args ) {
			$this->provisions[] = $args;
			$slug = isset( $args['package_slug'] ) ? (string) $args['package_slug'] : '';
			if ( in_array( $slug, $this->fail, true ) ) {
				return new WP_Error( 'fail', 'failed ' . $slug );
			}
			$package_id = 0;
			foreach ( $this->packages as $package ) {
				if ( isset( $package['slug'] ) && $package['slug'] === $slug ) {
					$package_id = (int) $package['id'];
				}
			}
			return array(
				'license_key' => 'RW-' . strtoupper( str_replace( '-', '', $slug ) ),
				'license_id'  => 8000 + $package_id,
				'package_id'  => $package_id,
				'domain'      => isset( $args['domain'] ) ? $args['domain'] : '',
			);
		}

		/**
		 * @param string      $id External id.
		 * @param string      $status Status.
		 * @param string|null $end Period end.
		 * @return array<string,string>
		 */
		public function sync_subscription_v1( $id, $status, $end = null ) {
			$this->syncs[] = array(
				'id'     => (string) $id,
				'status' => (string) $status,
				'end'    => $end,
			);
			return array( 'license_status' => 'active' );
		}

		/**
		 * @param int    $id Licence id.
		 * @param string $status Status.
		 * @return bool
		 */
		public function update_license_status( $id, $status ) {
			$this->statuses[] = array(
				'id'     => $id,
				'status' => $status,
			);
			return true;
		}
	}
}

if ( ! class_exists( 'RW_Bundle_Record', false ) ) {
	/**
	 * Subscription or order double with the methods licence provisioning calls.
	 */
	class RW_Bundle_Record {
		/**
		 * @var int
		 */
		public $id;

		/**
		 * @var array<string,mixed>
		 */
		public $meta = array();

		/**
		 * @var array<int,object>
		 */
		public $items = array();

		/**
		 * @var string
		 */
		public $status = 'active';

		/**
		 * @var float
		 */
		public $total = 99;

		/**
		 * @var string
		 */
		public $currency = 'GBP';

		/**
		 * @var string
		 */
		public $period = 'year';

		/**
		 * @var int
		 */
		public $interval = 1;

		/**
		 * @var array<string,string>
		 */
		public $dates = array(
			'start' => '2026-01-01 00:00:00',
			'end'   => '2027-01-01 00:00:00',
		);

		/**
		 * @var object|null
		 */
		public $parent = null;

		/**
		 * @var int
		 */
		public $saved = 0;

		/**
		 * @param int $id Id.
		 */
		public function __construct( $id ) {
			$this->id = (int) $id;
		}

		/**
		 * @return int
		 */
		public function get_id() {
			return $this->id;
		}

		/**
		 * @param string $key Key.
		 * @param bool   $single Single.
		 * @return mixed
		 */
		public function get_meta( $key, $single = true ) {
			unset( $single );
			return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
		}

		/**
		 * @param string $key Key.
		 * @param mixed  $value Value.
		 */
		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		/**
		 * @param string $key Key.
		 * @param mixed  $value Value.
		 * @param bool   $unique Unique.
		 */
		public function add_meta_data( $key, $value, $unique = false ) {
			unset( $unique );
			$this->meta[ $key ] = $value;
		}

		public function save() {
			++$this->saved;
		}

		/**
		 * @return array<int,object>
		 */
		public function get_items() {
			return $this->items;
		}

		/**
		 * @return float
		 */
		public function get_total() {
			return $this->total;
		}

		/**
		 * @return string
		 */
		public function get_currency() {
			return $this->currency;
		}

		/**
		 * @return string
		 */
		public function get_billing_period() {
			return $this->period;
		}

		/**
		 * @return int
		 */
		public function get_billing_interval() {
			return $this->interval;
		}

		/**
		 * @param string $type Date type.
		 * @return string
		 */
		public function get_date( $type ) {
			return isset( $this->dates[ $type ] ) ? $this->dates[ $type ] : '';
		}

		/**
		 * @return string
		 */
		public function get_status() {
			return $this->status;
		}

		/**
		 * @return object|null
		 */
		public function get_parent() {
			return $this->parent;
		}

		/**
		 * @return string
		 */
		public function get_billing_email() {
			return 'buyer@example.com';
		}

		/**
		 * @return string
		 */
		public function get_billing_first_name() {
			return 'Ada';
		}

		/**
		 * @return string
		 */
		public function get_billing_last_name() {
			return 'Lovelace';
		}
	}
}

if ( ! class_exists( 'RW_Bundle_Item', false ) ) {
	/**
	 * Order line double.
	 */
	class RW_Bundle_Item {
		/**
		 * @var int
		 */
		private $id;

		/**
		 * @var object
		 */
		private $product;

		/**
		 * @var string
		 */
		private $name;

		/**
		 * @param int    $id Item id.
		 * @param object $product Product.
		 * @param string $name Line name.
		 */
		public function __construct( $id, $product, $name ) {
			$this->id      = (int) $id;
			$this->product = $product;
			$this->name    = (string) $name;
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
		public function get_product_id() {
			return $this->product->get_parent_id() ? $this->product->get_parent_id() : $this->product->get_id();
		}

		/**
		 * @return int
		 */
		public function get_variation_id() {
			return $this->product->get_parent_id() ? $this->product->get_id() : 0;
		}

		/**
		 * @return string
		 */
		public function get_name() {
			return $this->name;
		}

		/**
		 * @return string
		 */
		public function get_type() {
			return 'line_item';
		}

		/**
		 * @return object
		 */
		public function get_product() {
			return $this->product;
		}
	}
}

/**
 * @return RW_Bundle_License_API
 */
function rw_bundle_use_api() {
	$api = new RW_Bundle_License_API();
	$api->packages = array(
		21 => array(
			'id'   => 21,
			'slug' => 'geo-core-pro',
			'name' => 'Geo Core Pro',
		),
		22 => array(
			'id'   => 22,
			'slug' => 'geo-optimise',
			'name' => 'Geo Optimise',
		),
		5  => array(
			'id'   => 5,
			'slug' => 'parent-only',
			'name' => 'Parent Only',
		),
	);
	$GLOBALS['rw_fake_license_api'] = $api;
	$GLOBALS['rw_test_filters']['reactwoo_license_server_api_instance'] = array( 'rw_bundle_license_api_filter' );
	return $api;
}

/**
 * @return RW_Bundle_License_API
 */
function rw_bundle_license_api_filter() {
	return $GLOBALS['rw_fake_license_api'];
}

/**
 * @param int                  $order_id Order id.
 * @param int                  $subscription_id Subscription id.
 * @param array<int,object>    $items Lines.
 * @param array<string,mixed>  $subscription_meta Existing meta.
 * @return array{0:RW_Bundle_Record,1:RW_Bundle_Record}
 */
function rw_bundle_pair( $order_id, $subscription_id, $items, $subscription_meta = array() ) {
	$order = new RW_Bundle_Record( $order_id );
	$order->update_meta_data( '_reactwoo_domain', 'shop.example' );
	$order->items = $items;

	$subscription = new RW_Bundle_Record( $subscription_id );
	$subscription->items = $items;
	$subscription->parent = $order;
	foreach ( $subscription_meta as $key => $value ) {
		$subscription->update_meta_data( $key, $value );
	}

	$GLOBALS['rw_test_orders'][ $order_id ] = $order;
	$GLOBALS['rw_test_order_subscriptions'][ $order_id ] = array( $subscription );
	return array( $order, $subscription );
}

/**
 * @param RW_Bundle_License_API $api API.
 * @return array<int,string>
 */
function rw_bundle_external_ids( $api ) {
	$ids = array();
	foreach ( $api->provisions as $call ) {
		$ids[] = (string) $call['wc_subscription_id'];
	}
	return $ids;
}

$GLOBALS['rw_test_orders'] = array();
$GLOBALS['rw_test_order_subscriptions'] = array();
$GLOBALS['rw_test_did_actions'] = array();

rw_assert(
	ReactWoo_Subscription_Handler::provision_external_id( 4242, 'geo-core-pro', 1 ) === '4242',
	'A single package keeps the plain subscription id'
);
rw_assert(
	ReactWoo_Subscription_Handler::provision_external_id( 4242, 'geo-optimise', 2 ) === '4242:geo-optimise',
	'Each package on a bundle gets a unique external id'
);

$single_only = ReactWoo_Subscription_Handler::license_package_ids_for_post( 9201 );
rw_checkout_meta( 9201, '_reactwoo_license_package_id', 21 );
rw_assert(
	ReactWoo_Subscription_Handler::license_package_ids_for_post( 9201 ) === array( 21 ),
	'The legacy single package id is still read'
);
unset( $single_only );

rw_checkout_meta( 9202, '_reactwoo_license_package_ids', array( 22, 21 ) );
rw_checkout_meta( 9202, '_reactwoo_license_package_id', 99 );
rw_assert(
	ReactWoo_Subscription_Handler::license_package_ids_for_post( 9202 ) === array( 22, 21 ),
	'The multi-select list wins over a stale single package id'
);

$bundle_only = rw_checkout_product( 9400, 'subscription', 0, 'Geo Core Pro + Geo Optimise' );
rw_checkout_meta( 9400, '_reactwoo_license_package_ids', array( 21, 22 ) );
rw_assert(
	$checkout_handler->product_issues_plugin_licence( $bundle_only ) === true,
	'A bundle with only the multi package list still issues plugin licences'
);

$api = rw_bundle_use_api();
$single_product = rw_checkout_product( 9101, 'subscription', 0, 'Geo Core Pro' );
rw_checkout_meta( 9101, '_reactwoo_license_package_id', 21 );
list( $single_order, $single_subscription ) = rw_bundle_pair(
	7101,
	8101,
	array( new RW_Bundle_Item( 1, $single_product, 'Geo Core Pro' ) )
);
$checkout_handler->maybe_create_license_on_order_completion( 7101 );
rw_assert( count( $api->provisions ) === 1, 'A single-package product provisions one licence' );
rw_assert( rw_bundle_external_ids( $api ) === array( '8101' ), 'The single-package external id is the plain subscription id' );
rw_assert( $api->provisions[0]['package_slug'] === 'geo-core-pro', 'The single-package call still sends the package slug' );
rw_assert( $single_subscription->get_meta( '_reactwoo_license_key' ) === 'RW-GEOCOREPRO', 'The legacy licence key meta is the provisioned key' );
rw_assert( (int) $single_subscription->get_meta( '_reactwoo_license_id' ) === 8021, 'The legacy licence id meta is the provisioned id' );
rw_assert(
	$single_subscription->get_meta( '_reactwoo_license_keys' ) === array( 'geo-core-pro' => 'RW-GEOCOREPRO' ),
	'A single package is also stored in the slug map'
);
rw_assert( $single_order->get_meta( '_reactwoo_license_key' ) === 'RW-GEOCOREPRO', 'The order keeps the legacy licence key' );
$before = count( $api->provisions );
$checkout_handler->maybe_create_license_on_order_completion( 7101 );
rw_assert( count( $api->provisions ) === $before, 'Re-running completion does not provision the single package again' );

$api->syncs = array();
$checkout_handler->handle_subscription_renewal( $single_subscription, $single_order );
rw_assert( count( $api->syncs ) === 1 && $api->syncs[0]['id'] === '8101', 'Renewal of a single package syncs the plain subscription id once' );
rw_assert( $single_subscription->get_meta( '_reactwoo_last_renewal_price' ) == 99, 'Renewal still records the subscription price' );

$api = rw_bundle_use_api();
list( $bundle_order, $bundle_subscription ) = rw_bundle_pair(
	7102,
	8102,
	array( new RW_Bundle_Item( 2, $bundle_only, 'Geo Core Pro + Geo Optimise' ) )
);
$checkout_handler->maybe_create_license_on_order_completion( 7102 );
rw_assert( count( $api->provisions ) === 2, 'A bundle provisions one licence per package' );
rw_assert(
	rw_bundle_external_ids( $api ) === array( '8102:geo-core-pro', '8102:geo-optimise' ),
	'Bundle external ids are the subscription id plus each package slug'
);
rw_assert(
	$bundle_subscription->get_meta( '_reactwoo_license_keys' ) === array(
		'geo-core-pro' => 'RW-GEOCOREPRO',
		'geo-optimise' => 'RW-GEOOPTIMISE',
	),
	'The subscription map stores a key for each package'
);
rw_assert( $bundle_subscription->get_meta( '_reactwoo_license_key' ) === 'RW-GEOCOREPRO', 'The legacy single key is the first package key' );
rw_assert( (int) $bundle_subscription->get_meta( '_reactwoo_license_id' ) === 8021, 'The legacy single id is the first package id' );
rw_assert(
	$bundle_subscription->get_meta( '_reactwoo_license_key_labels' ) === array(
		'geo-core-pro' => 'Geo Core Pro',
		'geo-optimise' => 'Geo Optimise',
	),
	'Each key is labelled with the package product name'
);
rw_assert(
	$bundle_order->get_meta( '_reactwoo_license_keys' ) === $bundle_subscription->get_meta( '_reactwoo_license_keys' ),
	'The order stores the same package key map'
);
$before = count( $api->provisions );
$checkout_handler->maybe_create_license_on_order_completion( 7102 );
rw_assert( count( $api->provisions ) === $before, 'Re-running a completed bundle does not create duplicate licences' );

$api->syncs = array();
$checkout_handler->handle_subscription_renewal( $bundle_subscription, $bundle_order );
$renewal_ids = array();
foreach ( $api->syncs as $sync ) {
	$renewal_ids[] = $sync['id'];
}
rw_assert(
	$renewal_ids === array( '8102:geo-core-pro', '8102:geo-optimise' ),
	'Renewal syncs both bundle licences'
);
rw_assert( $api->syncs[0]['end'] === '2027-01-01 00:00:00', 'Renewal sends the subscription period end' );

$api->syncs = array();
$checkout_handler->handle_subscription_status_change( $bundle_subscription, 'active', 'on-hold' );
$status_ids = array();
foreach ( $api->syncs as $sync ) {
	$status_ids[] = $sync['id'] . '=' . $sync['status'];
}
rw_assert(
	$status_ids === array( '8102:geo-core-pro=on-hold', '8102:geo-optimise=on-hold' ),
	'A status change is sent for every licence in the map'
);

$api->statuses = array();
$checkout_handler->handle_payment_failure( $bundle_subscription );
$failed_ids = array();
foreach ( $api->statuses as $update ) {
	$failed_ids[] = (int) $update['id'] . ':' . $update['status'];
}
rw_assert(
	$failed_ids === array( '8021:inactive', '8022:inactive' ),
	'Payment failure marks both bundle licences inactive'
);

$api->statuses = array();
$checkout_handler->handle_wc_order_trashed( 7102 );
$revoked = array();
foreach ( $api->statuses as $update ) {
	$revoked[] = (int) $update['id'];
}
sort( $revoked );
rw_assert( $revoked === array( 8021, 8022 ), 'Trashing the order revokes both bundle licences' );

$api = rw_bundle_use_api();
$api->fail = array( 'geo-optimise' );
list( $partial_order, $partial_subscription ) = rw_bundle_pair(
	7103,
	8103,
	array( new RW_Bundle_Item( 3, $bundle_only, 'Geo Core Pro + Geo Optimise' ) )
);
unset( $partial_order );
$checkout_handler->maybe_create_license_on_order_completion( 7103 );
rw_assert( count( $api->provisions ) === 2, 'A bundle still attempts every package when one provision fails' );
rw_assert( $partial_subscription->get_meta( '_reactwoo_license_key' ) === 'RW-GEOCOREPRO', 'The successful package is stored before the failed one is retried' );
rw_assert(
	$partial_subscription->get_meta( '_reactwoo_license_keys' ) === array( 'geo-core-pro' => 'RW-GEOCOREPRO' ),
	'The failed package is absent from the key map'
);
$api->fail = array();
$before = count( $api->provisions );
$checkout_handler->maybe_create_license_on_order_completion( 7103 );
rw_assert( count( $api->provisions ) === $before + 1, 'Retrying completion provisions only the missing package' );
rw_assert(
	$api->provisions[ count( $api->provisions ) - 1 ]['wc_subscription_id'] === '8103:geo-optimise',
	'The retried package keeps its own external id'
);
rw_assert(
	$partial_subscription->get_meta( '_reactwoo_license_keys' ) === array(
		'geo-core-pro' => 'RW-GEOCOREPRO',
		'geo-optimise' => 'RW-GEOOPTIMISE',
	),
	'The retry fills the map without replacing the first key'
);

$core = rw_checkout_product( 9104, 'subscription', 0, 'Geo Core Pro' );
rw_checkout_meta( 9104, '_reactwoo_license_package_id', 21 );
$optimise = rw_checkout_product( 9105, 'subscription', 0, 'Geo Optimise' );
rw_checkout_meta( 9105, '_reactwoo_license_package_id', 22 );
$api = rw_bundle_use_api();
list( $lines_order, $lines_subscription ) = rw_bundle_pair(
	7104,
	8104,
	array(
		new RW_Bundle_Item( 4, $core, 'Geo Core Pro' ),
		new RW_Bundle_Item( 5, $optimise, 'Geo Optimise' ),
	)
);
unset( $lines_order );
$checkout_handler->maybe_create_license_on_order_completion( 7104 );
rw_assert( count( $api->provisions ) === 2, 'Two subscription lines provision two licences' );
rw_assert(
	array_values( $lines_subscription->get_meta( '_reactwoo_license_key_labels' ) ) === array( 'Geo Core Pro', 'Geo Optimise' ),
	'Keys from separate lines are labelled by their products'
);

$parent = rw_checkout_product( 9300, 'variable-subscription', 0, 'Bundle parent' );
rw_checkout_meta( 9300, '_reactwoo_license_package_id', 5 );
$variation = rw_checkout_product( 9301, 'subscription_variation', 9300, '1 site' );
rw_checkout_meta( 9301, '_reactwoo_license_package_ids', array( 21, 22 ) );
$api = rw_bundle_use_api();
list( $variation_order, $variation_subscription ) = rw_bundle_pair(
	7105,
	8105,
	array( new RW_Bundle_Item( 6, $variation, 'Geo Core Pro + Geo Optimise' ) )
);
unset( $variation_order, $variation_subscription );
$checkout_handler->maybe_create_license_on_order_completion( 7105 );
rw_assert(
	rw_bundle_external_ids( $api ) === array( '8105:geo-core-pro', '8105:geo-optimise' ),
	'A variation package list replaces the parent package'
);

$inherit = rw_checkout_product( 9302, 'subscription_variation', 9303, 'Inherited bundle' );
rw_checkout_meta( 9303, '_reactwoo_license_package_ids', array( 21, 22 ) );
$api = rw_bundle_use_api();
$checkout_handler->maybe_create_license_on_order_completion( 0 );
list( $inherit_order, $inherit_subscription ) = rw_bundle_pair(
	7106,
	8106,
	array( new RW_Bundle_Item( 7, $inherit, 'Inherited bundle' ) )
);
unset( $inherit_order, $inherit_subscription );
$checkout_handler->maybe_create_license_on_order_completion( 7106 );
rw_assert( count( $api->provisions ) === 2, 'An empty variation inherits the parent package list' );

$api = rw_bundle_use_api();
$existing_product = rw_checkout_product( 9106, 'subscription', 0, 'Geo Core Pro + Geo Optimise' );
rw_checkout_meta( 9106, '_reactwoo_license_package_ids', array( 21, 22 ) );
list( $existing_order, $existing_subscription ) = rw_bundle_pair(
	7107,
	8107,
	array( new RW_Bundle_Item( 8, $existing_product, 'Geo Core Pro + Geo Optimise' ) ),
	array(
		'_reactwoo_license_key'        => 'RW-LEGACYKEY',
		'_reactwoo_license_id'         => 4242,
		'_reactwoo_license_package_id' => 21,
	)
);
unset( $existing_order );
$checkout_handler->maybe_create_license_on_order_completion( 7107 );
rw_assert( count( $api->provisions ) === 1, 'An existing single licence is kept when a second package is added' );
rw_assert( $api->provisions[0]['package_slug'] === 'geo-optimise', 'Only the new package is provisioned' );
rw_assert( $api->provisions[0]['wc_subscription_id'] === '8107:geo-optimise', 'The new package does not reuse the legacy subscription id' );
rw_assert( $existing_subscription->get_meta( '_reactwoo_license_key' ) === 'RW-LEGACYKEY', 'The legacy key stays the first key' );
rw_assert(
	$existing_subscription->get_meta( '_reactwoo_license_external_ids' )['geo-core-pro'] === '8107',
	'The adopted licence keeps the plain subscription id for later sync'
);

$empty_subscription = new RW_Bundle_Record( 8108 );
$empty_order = new RW_Bundle_Record( 7108 );
$empty_order->update_meta_data( '_reactwoo_domain', 'shop.example' );
$empty_order->items = array( new RW_Bundle_Item( 9, $single_product, 'Geo Core Pro' ) );
$empty_subscription->parent = $empty_order;
$GLOBALS['rw_test_orders'][7108] = $empty_order;
$GLOBALS['rw_test_order_subscriptions'][7108] = array( $empty_subscription );
$api = rw_bundle_use_api();
$checkout_handler->maybe_create_license_on_order_completion( 7108 );
rw_assert( count( $api->provisions ) === 1 && rw_bundle_external_ids( $api ) === array( '8108' ), 'An item-less subscription still falls back to the order package' );

$account_product = new RW_Test_Product( 9100 );
$GLOBALS['rw_test_products'][9100] = $account_product;
$GLOBALS['rw_test_subscriptions'] = array(
	new RW_Test_Subscription(
		77,
		42,
		'active',
		array(
			'_reactwoo_license_key' => 'RW-GEOCOREPRO',
			'_reactwoo_license_id'  => 8021,
			'_reactwoo_license_keys' => array(
				'geo-core-pro' => 'RW-GEOCOREPRO',
				'geo-optimise' => 'RW-GEOOPTIMISE',
			),
			'_reactwoo_license_key_labels' => array(
				'geo-core-pro' => 'Geo Core Pro',
				'geo-optimise' => 'Geo Optimise',
			),
		),
		array( new RW_Test_Order_Item( $account_product, 'Geo Core Pro + Geo Optimise' ) )
	),
);
$account_records = ReactWoo_Customer_Account_Service::get_instance()->get_account_records( 42 );
rw_assert( count( $account_records ) === 1, 'A bundle is one subscription record' );
rw_assert( count( $account_records[0]['license_keys'] ) === 2, 'My Account receives both licence keys' );
rw_assert(
	$account_records[0]['license_keys'][0]['label'] === 'Geo Core Pro' && $account_records[0]['license_keys'][1]['label'] === 'Geo Optimise',
	'My Account labels each key with its product'
);
rw_assert( strpos( $account_records[0]['license_keys'][0]['masked_key'], 'RW-GEOCOREPRO' ) === false, 'My Account does not expose the full first key' );

$reactwoo_records = $account_records;
ob_start();
include dirname( __DIR__ ) . '/templates/myaccount/license.php';
$account_html = ob_get_clean();
rw_assert( strpos( $account_html, 'Geo Core Pro' ) !== false && strpos( $account_html, 'Geo Optimise' ) !== false, 'The account template prints both product labels' );
rw_assert( strpos( $account_html, 'data-rw-copy-package="geo-optimise"' ) !== false, 'Each bundle key can be copied on its own' );
rw_assert( strpos( $account_html, 'RW-GEOCOREPRO' ) === false, 'The account template does not print a full key' );

$single_email_order = new WC_Order( 7201 );
$single_email_order->update_meta_data( '_reactwoo_license_key', 'RW-GEOCOREPRO' );
$single_email_order->update_meta_data( '_reactwoo_domain', 'shop.example' );
$single_email = new stdClass();
$single_email->id = 'customer_completed_order';
ob_start();
$display->maybe_add_license_to_email( $single_email_order, false, false, $single_email );
$single_email_html = ob_get_clean();
rw_assert( strpos( $single_email_html, 'Your licence key is ready' ) !== false, 'A single-package email still points at My Account' );
rw_assert( strpos( $single_email_html, 'Geo Optimise' ) === false, 'A single-package email does not list a second product' );

$bundle_email_order = new WC_Order( 7202 );
$bundle_email_order->update_meta_data( '_reactwoo_license_key', 'RW-GEOCOREPRO' );
$bundle_email_order->update_meta_data( '_reactwoo_domain', 'shop.example' );
$bundle_email_order->update_meta_data(
	'_reactwoo_license_keys',
	array(
		'geo-core-pro' => 'RW-GEOCOREPRO',
		'geo-optimise' => 'RW-GEOOPTIMISE',
	)
);
$bundle_email_order->update_meta_data(
	'_reactwoo_license_key_labels',
	array(
		'geo-core-pro' => 'Geo Core Pro',
		'geo-optimise' => 'Geo Optimise',
	)
);
ob_start();
$display->maybe_add_license_to_email( $bundle_email_order, false, false, $single_email );
$bundle_email_html = ob_get_clean();
rw_assert( strpos( $bundle_email_html, 'Geo Core Pro' ) !== false && strpos( $bundle_email_html, 'Geo Optimise' ) !== false, 'The completed-order email labels both bundle keys' );
rw_assert( strpos( $bundle_email_html, 'RW-GEOCOREPRO' ) === false && strpos( $bundle_email_html, 'RW-GEOOPTIMISE' ) === false, 'The email shows masked keys, not the full keys' );

$subscription_view = new WC_Subscription(
	55,
	42,
	'active',
	array(
		'_reactwoo_license_key' => 'RW-GEOCOREPRO',
		'_reactwoo_license_domain' => 'shop.example',
		'_reactwoo_license_keys' => array(
			'geo-core-pro' => 'RW-GEOCOREPRO',
			'geo-optimise' => 'RW-GEOOPTIMISE',
		),
		'_reactwoo_license_key_labels' => array(
			'geo-core-pro' => 'Geo Core Pro',
			'geo-optimise' => 'Geo Optimise',
		),
	)
);
ob_start();
$display->print_license_on_subscription_page( $subscription_view );
$subscription_html = ob_get_clean();
rw_assert( strpos( $subscription_html, 'Geo Core Pro' ) !== false && strpos( $subscription_html, 'Geo Optimise' ) !== false, 'The subscription page shows both labelled keys' );
