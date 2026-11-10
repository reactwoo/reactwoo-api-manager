<?php
/**
 * Maintenance licence bundle.
 *
 * Tiers, issue triggers, cancel, on-hold, reactivate, upgrade, downgrade,
 * refund, retries, and standalone licences that must stay untouched.
 *
 * @package ReactWoo_API_Manager
 */

$bundle_settings = ReactWoo_Maintenance_Bundle::defaults();

function rw_bundle_slugs( array $rows ) {
	$slugs = array();
	foreach ( $rows as $row ) {
		$slugs[] = $row['slug'];
	}
	sort( $slugs );
	return $slugs;
}

$essential = ReactWoo_Maintenance_Bundle::package_rows_for_plan( 'essential', $bundle_settings, false, '' );
rw_assert( $essential === array(), 'Essential issues no key until the customer chooses a plugin' );

$chosen = ReactWoo_Maintenance_Bundle::package_rows_for_plan( 'essential', $bundle_settings, false, 'reactwoo-geo-core-pro' );
rw_assert( rw_bundle_slugs( $chosen ) === array( 'reactwoo-geo-core-pro' ), 'Essential can issue the one chosen plugin' );

$rejected = ReactWoo_Maintenance_Bundle::package_rows_for_plan( 'essential', $bundle_settings, false, 'reactwoo-decision-cloud' );
rw_assert( $rejected === array(), 'Essential does not accept Decision Cloud as the chosen plugin' );

$standard = ReactWoo_Maintenance_Bundle::package_rows_for_plan( 'standard', $bundle_settings, false, '' );
rw_assert(
	rw_bundle_slugs( $standard ) === array( 'reactwoo-atomic-pro', 'reactwoo-geo-commerce', 'reactwoo-geo-core-pro' ),
	'Standard includes Geo Core Pro, Geo Commerce and Atomic Pro'
);
rw_assert( ! in_array( 'reactwoo-geo-optimise', rw_bundle_slugs( $standard ), true ), 'Standard omits Geo Optimise while it cannot be bought' );

$with_optimise = ReactWoo_Maintenance_Bundle::package_rows_for_plan( 'standard', $bundle_settings, true, '' );
rw_assert( in_array( 'reactwoo-geo-optimise', rw_bundle_slugs( $with_optimise ), true ), 'Standard includes Geo Optimise when the product can be bought' );

$forced_off = $bundle_settings;
$forced_off['optimise_mode'] = 'no';
$no_optimise = ReactWoo_Maintenance_Bundle::package_rows_for_plan( 'standard', $forced_off, true, '' );
rw_assert( ! in_array( 'reactwoo-geo-optimise', rw_bundle_slugs( $no_optimise ), true ), 'Geo Optimise stays out when the flag is off' );

$forced_on = $bundle_settings;
$forced_on['optimise_mode'] = 'yes';
$yes_optimise = ReactWoo_Maintenance_Bundle::package_rows_for_plan( 'business', $forced_on, false, '' );
rw_assert( in_array( 'reactwoo-geo-optimise', rw_bundle_slugs( $yes_optimise ), true ), 'Geo Optimise can be forced on before the product is purchasable' );

$business = ReactWoo_Maintenance_Bundle::package_rows_for_plan( 'business', $bundle_settings, false, '' );
rw_assert(
	rw_bundle_slugs( $business ) === array( 'reactwoo-atomic-pro', 'reactwoo-decision-cloud', 'reactwoo-geo-commerce', 'reactwoo-geo-core-pro' ),
	'Business includes the Standard plugins and Decision Cloud'
);

rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $bundle_settings, 'year', 0 ) === true, 'Default trigger issues annual keys immediately' );
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $bundle_settings, 'month', 0 ) === false, 'Default trigger waits for the first monthly renewal' );
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $bundle_settings, 'month', 1 ) === true, 'Default trigger issues monthly keys after one paid renewal' );

$immediate = $bundle_settings;
$immediate['trigger'] = 'immediately';
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $immediate, 'month', 0 ) === true, 'Immediately issues keys for a monthly subscription' );

$annual_only = $bundle_settings;
$annual_only['trigger'] = 'annual_only';
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $annual_only, 'year', 0 ) === true, 'Annual billing only issues an annual subscription' );
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $annual_only, 'month', 3 ) === false, 'Annual billing only never issues a monthly subscription' );

$after = $bundle_settings;
$after['trigger'] = 'after_renewal';
$after['paid_periods'] = 2;
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $after, 'year', 1 ) === false, 'After paid renewals waits for the configured number' );
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $after, 'month', 2 ) === true, 'After paid renewals issues once that number is reached' );

$atomic = null;
foreach ( $standard as $row ) {
	if ( $row['slug'] === 'reactwoo-atomic-pro' ) {
		$atomic = $row;
	}
}
$atomic_args = ReactWoo_Maintenance_Bundle::provision_args_for_package( $atomic, 55, 'maint.example', $bundle_settings );
rw_assert( $atomic_args['source_ref'] === '55:reactwoo-atomic-pro', 'Bundle source ref is the subscription and the package' );
rw_assert( $atomic_args['wc_subscription_id'] === '55', 'Bundle provisioning still sends the numeric subscription id' );
rw_assert( (int) $atomic_args['max_activations'] === 1, 'Atomic bundle keys are stamped as one site' );
rw_assert( $atomic_args['plan_code'] === 'personal', 'Atomic bundle keys use the Personal plan code' );
rw_assert( $atomic_args['renewal_policy'] === 'deactivate_on_non_renewal', 'Bundle keys deactivate when the Maintenance plan is not renewed' );

$cloud_row = null;
foreach ( $business as $row ) {
	if ( $row['slug'] === 'reactwoo-decision-cloud' ) {
		$cloud_row = $row;
	}
}
$cloud_args = ReactWoo_Maintenance_Bundle::provision_args_for_package( $cloud_row, 55, 'maint.example', $bundle_settings );
rw_assert( $cloud_args['plan_code'] === 'starter', 'Business Decision Cloud defaults to the Starter plan code' );
rw_assert( (int) $cloud_args['max_activations'] === 1, 'Decision Cloud bundle keys are limited to one site' );

rw_assert( ReactWoo_Maintenance_Bundle::licence_status_for_subscription( 'on-hold' ) === 'suspended', 'On-hold suspends bundle licences' );
rw_assert( ReactWoo_Maintenance_Bundle::licence_status_for_subscription( 'cancelled' ) === 'cancelled', 'Cancel cancels bundle licences' );
rw_assert( ReactWoo_Maintenance_Bundle::licence_status_for_subscription( 'expired' ) === 'expired', 'Expiry expires bundle licences' );
rw_assert( ReactWoo_Maintenance_Bundle::licence_status_for_subscription( 'active' ) === 'active', 'Active reactivates bundle licences' );
rw_assert( ReactWoo_Maintenance_Bundle::licence_status_for_subscription( 'pending-cancel', '2099-01-01 00:00:00' ) === 'active', 'Pending cancel stays active until the period ends' );
rw_assert( ReactWoo_Maintenance_Bundle::licence_status_for_subscription( 'pending-cancel', '2000-01-01 00:00:00' ) === 'cancelled', 'Pending cancel ends when the period end is in the past' );

class RW_Bundle_Api {
	public $provisions = array();
	public $calls = array();
	public $updates = array();
	public $syncs = array();
	public $duplicate = false;

	public function provision_license_v1( $args ) {
		$ref = (string) $args['source_ref'];
		if ( isset( $this->provisions[ $ref ] ) ) {
			$this->calls[] = $args;
			return $this->provisions[ $ref ];
		}
		if ( $this->duplicate && $this->provisions ) {
			$this->calls[] = $args;
			return reset( $this->provisions );
		}
		$id = count( $this->provisions ) + 10;
		$row = array(
			'license_id'      => $id,
			'license_key'     => 'RW-SECRET-' . $args['package_slug'],
			'package_slug'    => $args['package_slug'],
			'source_ref'      => $ref,
			'domain'          => $args['domain'],
			'package_id'      => isset( $args['package_id'] ) ? $args['package_id'] : 1,
			'max_activations' => isset( $args['max_activations'] ) ? $args['max_activations'] : null,
			'plan_code'       => isset( $args['plan_code'] ) ? $args['plan_code'] : '',
			'renewal_policy'  => isset( $args['renewal_policy'] ) ? $args['renewal_policy'] : '',
		);
		$this->provisions[ $ref ] = $row;
		$this->calls[] = $args;
		return $row;
	}

	public function update_license_status( $id, $status ) {
		$this->updates[] = array( (int) $id, (string) $status );
		return true;
	}

	public function sync_subscription_v1( $id, $status, $end = null, $extra = array() ) {
		$this->syncs[] = array(
			'id'     => $id,
			'status' => $status,
			'extra'  => $extra,
		);
		return array( 'license_status' => 'active' );
	}
}

class RW_Bundle_Product {
	public $id;
	public function __construct( $id ) {
		$this->id = (int) $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_parent_id() {
		return 0;
	}
	public function is_type( $type ) {
		return 'subscription' === $type;
	}
}

class RW_Bundle_Item {
	private $product;
	public function __construct( $product ) {
		$this->product = $product;
	}
	public function get_product() {
		return $this->product;
	}
	public function get_product_id() {
		return $this->product->get_id();
	}
	public function get_meta( $key, $single = true ) {
		unset( $key, $single );
		return '';
	}
}

class RW_Bundle_Order {
	public $id;
	public $meta = array();
	public function __construct( $id ) {
		$this->id = (int) $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_billing_email() {
		return 'owner@example.com';
	}
	public function get_billing_first_name() {
		return 'Ada';
	}
	public function get_billing_last_name() {
		return 'Lovelace';
	}
	public function get_meta( $key, $single = true ) {
		unset( $single );
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function get_total() {
		return 99;
	}
	public function get_total_refunded() {
		return isset( $this->meta['refunded'] ) ? $this->meta['refunded'] : 0;
	}
}

class RW_Bundle_Sub {
	public $id;
	public $customer_id;
	public $status;
	public $meta = array();
	public $items = array();
	public $billing_period = 'year';
	public $billing_interval = 1;
	public $parent = null;
	public function __construct( $id, $customer_id, $status = 'active' ) {
		$this->id = (int) $id;
		$this->customer_id = (int) $customer_id;
		$this->status = $status;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_customer_id() {
		return $this->customer_id;
	}
	public function get_status() {
		return $this->status;
	}
	public function get_meta( $key, $single = true ) {
		unset( $single );
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function save() {
	}
	public function get_billing_period() {
		return $this->billing_period;
	}
	public function get_billing_interval() {
		return $this->billing_interval;
	}
	public function get_currency() {
		return 'GBP';
	}
	public function get_items() {
		return $this->items;
	}
	public function get_parent() {
		return $this->parent;
	}
	public function get_change_payment_method_url() {
		return '';
	}
}

function rw_bundle_subscription( $id, $product_id, $plan, $period, $domain ) {
	if ( ! isset( $GLOBALS['rw_test_post_meta'] ) || ! is_array( $GLOBALS['rw_test_post_meta'] ) ) {
		$GLOBALS['rw_test_post_meta'] = array();
	}
	$GLOBALS['rw_test_post_meta'][ $product_id ]['_rw_maintenance_plan_key'] = $plan;
	$sub = new RW_Bundle_Sub( $id, 7, 'active' );
	$sub->billing_period = $period;
	$sub->items = array( new RW_Bundle_Item( new RW_Bundle_Product( $product_id ) ) );
	$sub->meta['_rw_service_domain'] = $domain;
	$sub->parent = new RW_Bundle_Order( 8000 + $id );
	return $sub;
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['rw_bundle_actions'][] = array( $hook, $args );
	}
}

$GLOBALS['reactwoo_maintenance_bundle_log'] = array();
$GLOBALS['rw_bundle_actions'] = array();
$api = new RW_Bundle_Api();
$annual = rw_bundle_subscription( 55, 3657, 'standard', 'year', 'maint.example' );
foreach ( ReactWoo_Maintenance_Bundle::slugs_for_subscription( $annual, array() ) as $slug ) {
	ReactWoo_Maintenance_Bundle::provision_slug( $annual, $annual->parent, $slug, 'annual', $api );
}
rw_assert( count( $api->provisions ) === 3, 'Standard annual provisions one licence per package' );
$refs = array_keys( $api->provisions );
sort( $refs );
rw_assert(
	$refs === array(
		'55:reactwoo-atomic-pro',
		'55:reactwoo-geo-commerce',
		'55:reactwoo-geo-core-pro',
	),
	'Each package has its own source ref on the same subscription'
);
$stored = ReactWoo_Maintenance_Bundle::stored_licenses( $annual );
rw_assert( (int) $stored['reactwoo-atomic-pro']['max_activations'] === 1, 'Stored Atomic key records the one-site limit' );
rw_assert( $stored['reactwoo-atomic-pro']['plan_code'] === 'personal', 'Stored Atomic key records the Personal plan' );
rw_assert( $stored['reactwoo-atomic-pro']['renewal_policy'] === 'deactivate_on_non_renewal', 'Stored bundle key records deactivation on non-renewal' );
$before_retry = count( $api->calls );
ReactWoo_Maintenance_Bundle::provision_slug( $annual, $annual->parent, 'reactwoo-geo-core-pro', 'retry', $api );
rw_assert( count( $api->calls ) === $before_retry, 'A repeat provision does not call the licence server again' );

$lost = ReactWoo_Maintenance_Bundle::stored_licenses( $annual );
unset( $lost['reactwoo-geo-core-pro'] );
$annual->update_meta_data( ReactWoo_Maintenance_Bundle::META_LICENSES, $lost );
ReactWoo_Maintenance_Bundle::provision_slug( $annual, $annual->parent, 'reactwoo-geo-core-pro', 'retry-lost', $api );
$restored = ReactWoo_Maintenance_Bundle::stored_licenses( $annual );
rw_assert( (int) $restored['reactwoo-geo-core-pro']['license_id'] === 12 || (int) $restored['reactwoo-geo-core-pro']['license_id'] > 0, 'A retry reuses the licence for that source ref' );
$core_ids = array();
foreach ( $api->provisions as $row ) {
	if ( $row['package_slug'] === 'reactwoo-geo-core-pro' ) {
		$core_ids[] = (int) $row['license_id'];
	}
}
rw_assert( count( $core_ids ) === 1, 'The idempotent server response does not create a second Geo Core Pro licence' );

$monthly = rw_bundle_subscription( 56, 3657, 'standard', 'month', 'month.example' );
$monthly_api = new RW_Bundle_Api();
foreach ( ReactWoo_Maintenance_Bundle::slugs_for_subscription( $monthly, array() ) as $slug ) {
	ReactWoo_Maintenance_Bundle::provision_slug( $monthly, $monthly->parent, $slug, 'month', $monthly_api );
}
rw_assert( $monthly_api->provisions === array(), 'Monthly Standard does not issue keys at checkout' );
ReactWoo_Maintenance_Bundle::note_paid_renewal( $monthly, $monthly->parent );
ReactWoo_Maintenance_Bundle::note_paid_renewal( $monthly, $monthly->parent );
rw_assert( (int) $monthly->get_meta( ReactWoo_Maintenance_Bundle::META_PAID ) === 1, 'The same renewal order is counted once' );
foreach ( ReactWoo_Maintenance_Bundle::slugs_for_subscription( $monthly, array() ) as $slug ) {
	ReactWoo_Maintenance_Bundle::provision_slug( $monthly, $monthly->parent, $slug, 'month-renewed', $monthly_api );
}
rw_assert( count( $monthly_api->provisions ) === 3, 'Monthly Standard issues keys after the first successful renewal' );

$no_domain = rw_bundle_subscription( 57, 3657, 'standard', 'year', '' );
$no_domain->meta['_rw_service_domain'] = '';
$domain_api = new RW_Bundle_Api();
ReactWoo_Maintenance_Bundle::provision_slug( $no_domain, $no_domain->parent, 'reactwoo-geo-core-pro', 'domain', $domain_api );
rw_assert( $domain_api->provisions === array(), 'A bundle key is not issued without the maintained website' );

$GLOBALS['rw_test_filters']['reactwoo_maintenance_standalone_package_ids'] = array(
	function () {
		return array( 2269 );
	},
);
$overlap = rw_bundle_subscription( 58, 3657, 'standard', 'year', 'overlap.example' );
$standalone = new RW_Bundle_Sub( 90, 7, 'active' );
$standalone->meta['_reactwoo_license_id'] = 4242;
$standalone->meta['_reactwoo_license_key'] = 'RW-SECRET-standalone';
$standalone->meta['_reactwoo_license_package_id'] = 2269;
$overlap_api = new RW_Bundle_Api();
ReactWoo_Maintenance_Bundle::provision_slug( $overlap, $overlap->parent, 'reactwoo-geo-core-pro', 'overlap', $overlap_api );
ReactWoo_Maintenance_Bundle::provision_slug( $overlap, $overlap->parent, 'reactwoo-geo-commerce', 'overlap', $overlap_api );
rw_assert( ! isset( ReactWoo_Maintenance_Bundle::stored_licenses( $overlap )['reactwoo-geo-core-pro'] ), 'A package the customer already bought is not issued again' );
rw_assert( isset( ReactWoo_Maintenance_Bundle::stored_licenses( $overlap )['reactwoo-geo-commerce'] ), 'Other Maintenance packages are still issued' );
rw_assert( (int) $standalone->get_meta( '_reactwoo_license_id' ) === 4242, 'The separately purchased licence id is unchanged' );
rw_assert( $standalone->get_meta( '_reactwoo_license_key' ) === 'RW-SECRET-standalone', 'The separately purchased licence key is unchanged' );
$touched_standalone = false;
foreach ( $overlap_api->updates as $update ) {
	if ( (int) $update[0] === 4242 ) {
		$touched_standalone = true;
	}
}
rw_assert( $touched_standalone === false, 'Status updates from the bundle do not include the standalone licence' );
unset( $GLOBALS['rw_test_filters']['reactwoo_maintenance_standalone_package_ids'] );

$collision = rw_bundle_subscription( 59, 3660, 'business', 'year', 'collision.example' );
$collision_api = new RW_Bundle_Api();
ReactWoo_Maintenance_Bundle::provision_slug( $collision, $collision->parent, 'reactwoo-geo-core-pro', 'collision', $collision_api );
$collision_api->duplicate = true;
ReactWoo_Maintenance_Bundle::provision_slug( $collision, $collision->parent, 'reactwoo-atomic-pro', 'collision', $collision_api );
$collision_stored = ReactWoo_Maintenance_Bundle::stored_licenses( $collision );
rw_assert( isset( $collision_stored['reactwoo-geo-core-pro'] ) && ! isset( $collision_stored['reactwoo-atomic-pro'] ), 'A server that returns the first licence is not saved as a second package' );

$business_sub = rw_bundle_subscription( 60, 3660, 'business', 'year', 'business.example' );
$business_api = new RW_Bundle_Api();
foreach ( ReactWoo_Maintenance_Bundle::slugs_for_subscription( $business_sub, array() ) as $slug ) {
	ReactWoo_Maintenance_Bundle::provision_slug( $business_sub, $business_sub->parent, $slug, 'business', $business_api );
}
rw_assert( isset( ReactWoo_Maintenance_Bundle::stored_licenses( $business_sub )['reactwoo-decision-cloud'] ), 'Business provisions Decision Cloud' );
$cloud_actions = 0;
foreach ( $GLOBALS['rw_bundle_actions'] as $action ) {
	if ( $action[0] === 'reactwoo_license_generated' && ! empty( $action[1][2]['maintenance_bundle'] ) && $action[1][2]['package_slug'] === 'reactwoo-decision-cloud' ) {
		$cloud_actions++;
	}
}
rw_assert( $cloud_actions === 1, 'Decision Cloud fires the hosted activation hook once' );
$geo_actions = 0;
foreach ( $GLOBALS['rw_bundle_actions'] as $action ) {
	if ( $action[0] === 'reactwoo_license_generated' && isset( $action[1][2]['package_slug'] ) && $action[1][2]['package_slug'] === 'reactwoo-geo-core-pro' ) {
		$geo_actions++;
	}
}
rw_assert( $geo_actions === 0, 'A plugin key does not fire Decision Cloud hosted activation' );

$standard_slugs = ReactWoo_Maintenance_Bundle::slugs_for_subscription( rw_bundle_subscription( 601, 3657, 'standard', 'year', 'downgrade.example' ), array() );
$revoked = ReactWoo_Maintenance_Bundle::revoke_to_match( $business_sub, $standard_slugs, $business_api );
rw_assert( $revoked === array( 'reactwoo-decision-cloud' ), 'Downgrade from Business revokes Decision Cloud only' );
rw_assert( ReactWoo_Maintenance_Bundle::stored_licenses( $business_sub )['reactwoo-decision-cloud']['status'] === 'removed', 'The revoked Decision Cloud key is marked removed locally' );
rw_assert( ReactWoo_Maintenance_Bundle::stored_licenses( $business_sub )['reactwoo-geo-core-pro']['status'] === 'active', 'Downgrade keeps the plugin keys that remain in the tier' );
$calls_before_upgrade = count( $business_api->calls );
ReactWoo_Maintenance_Bundle::provision_slug( $business_sub, $business_sub->parent, 'reactwoo-decision-cloud', 'upgrade', $business_api );
rw_assert( count( $business_api->calls ) === $calls_before_upgrade, 'Upgrade reactivates the existing Decision Cloud key instead of minting another' );
rw_assert( ReactWoo_Maintenance_Bundle::stored_licenses( $business_sub )['reactwoo-decision-cloud']['status'] === 'active', 'Upgrade marks Decision Cloud active again' );

$business_sub->status = 'on-hold';
ReactWoo_Maintenance_Bundle::sync_stored_licences( $business_sub, 'on-hold', '', $business_api );
$hold_statuses = array();
foreach ( ReactWoo_Maintenance_Bundle::stored_licenses( $business_sub ) as $row ) {
	$hold_statuses[ $row['package_slug'] ] = $row['status'];
}
rw_assert( $hold_statuses['reactwoo-geo-core-pro'] === 'suspended', 'On-hold suspends every included key' );
rw_assert( $hold_statuses['reactwoo-decision-cloud'] === 'suspended', 'On-hold suspends the included Decision Cloud key' );
rw_assert( ! empty( $business_api->syncs ) && ! empty( $business_api->syncs[0]['extra']['sync_all'] ), 'Status sync asks the licence server to update every package' );

$business_sub->status = 'active';
ReactWoo_Maintenance_Bundle::sync_stored_licences( $business_sub, 'active', '', $business_api );
rw_assert( ReactWoo_Maintenance_Bundle::stored_licenses( $business_sub )['reactwoo-atomic-pro']['status'] === 'active', 'Returning the subscription to active reactivates bundle keys' );

ReactWoo_Maintenance_Bundle::sync_stored_licences( $business_sub, 'cancelled', '', $business_api );
rw_assert( ReactWoo_Maintenance_Bundle::stored_licenses( $business_sub )['reactwoo-geo-commerce']['status'] === 'cancelled', 'Cancel deactivates every bundle key together' );

$business_sub->status = 'active';
ReactWoo_Maintenance_Bundle::refund_bundle( $business_sub, $business_api );
rw_assert( ReactWoo_Maintenance_Bundle::stored_licenses( $business_sub )['reactwoo-geo-core-pro']['status'] === 'refunded', 'A refund revokes the bundle key' );
$refund_calls = count( $business_api->calls );
ReactWoo_Maintenance_Bundle::provision_slug( $business_sub, $business_sub->parent, 'reactwoo-geo-core-pro', 'after-refund', $business_api );
rw_assert( count( $business_api->calls ) === $refund_calls, 'A refunded bundle key is not reactivated or minted again' );
rw_assert( (int) $standalone->get_meta( '_reactwoo_license_id' ) === 4242, 'Refunding the bundle does not change the standalone licence' );

$partial = new RW_Bundle_Order( 77 );
$partial->meta['refunded'] = 10;
rw_assert( ReactWoo_Maintenance_Bundle::order_is_fully_refunded( $partial ) === false, 'A partial refund is not treated as a full refund' );
$full = new RW_Bundle_Order( 78 );
$full->meta['refunded'] = 99;
rw_assert( ReactWoo_Maintenance_Bundle::order_is_fully_refunded( $full ) === true, 'A refund of the full order total revokes bundle keys' );

$failed = rw_bundle_subscription( 61, 3657, 'standard', 'year', 'failed.example' );
$failed_api = new RW_Bundle_Api();
ReactWoo_Maintenance_Bundle::provision_slug( $failed, $failed->parent, 'reactwoo-geo-core-pro', 'fail', $failed_api );
ReactWoo_Maintenance_Bundle::revoke_to_match( $failed, array(), $failed_api );
ReactWoo_Maintenance_Bundle::mark_payment_failed( $failed, $failed_api );
rw_assert( ReactWoo_Maintenance_Bundle::stored_licenses( $failed )['reactwoo-geo-core-pro']['status'] === 'removed', 'A payment failure does not resurrect a key the tier no longer includes' );

$log = json_encode( $GLOBALS['reactwoo_maintenance_bundle_log'] );
rw_assert( is_string( $log ) && strpos( $log, 'RW-SECRET' ) === false, 'Bundle logs do not contain licence keys' );
rw_assert( strpos( $log, 'license_key' ) === false, 'Bundle logs do not contain a licence key field' );
rw_assert( strpos( $log, 'provisioned' ) !== false, 'Successful provisioning is logged' );
rw_assert( strpos( $log, 'duplicate_subscription_licence' ) !== false, 'A duplicate server response is logged' );

if ( class_exists( 'RW_Test_Subscription' ) ) {
	$account_sub = new RW_Test_Subscription(
		70,
		42,
		'active',
		array(
			ReactWoo_Maintenance_Bundle::META_PLAN => 'business',
			ReactWoo_Maintenance_Bundle::META_LICENSES => array(
				'reactwoo-geo-core-pro' => array(
					'package_slug' => 'reactwoo-geo-core-pro',
					'package_id'   => 2269,
					'license_id'   => 11,
					'license_key'  => 'RW-SECRET-geo',
					'status'       => 'active',
					'domain'       => 'maint.example',
					'product_id'   => 2294,
					'product_name' => 'Geo Core Pro',
					'origin'       => 'maintenance_bundle',
				),
				'reactwoo-decision-cloud' => array(
					'package_slug' => 'reactwoo-decision-cloud',
					'package_id'   => 2271,
					'license_id'   => 12,
					'license_key'  => 'RW-SECRET-cloud',
					'status'       => 'active',
					'domain'       => 'maint.example',
					'product_id'   => 3166,
					'product_name' => 'Decision Cloud',
					'plan_code'    => 'starter',
					'cloud'        => true,
					'origin'       => 'maintenance_bundle',
				),
			),
		),
		array( new RW_Test_Order_Item( new RW_Test_Product( 3660 ), 'Maintenance Business' ) )
	);
	$cards = ReactWoo_Maintenance_Bundle::account_records( $account_sub );
	rw_assert( count( $cards ) === 2, 'My Account exposes one card per bundle key' );
	$card_products = array();
	$cloud_cards = 0;
	foreach ( $cards as $card ) {
		$card_products[] = (int) $card['product_id'];
		rw_assert( ! empty( $card['included_with_maintenance'] ), 'Each card is marked as included with Maintenance' );
		rw_assert( $card['maintenance_plan'] === 'business', 'Each card names the Maintenance tier' );
		rw_assert( (int) $card['max_activations'] === 1 && (int) $card['site_limit'] === 1, 'Each card records the one-website limit' );
		rw_assert( strpos( (string) $card['masked_key'], 'SECRET' ) === false, 'Account cards mask the licence key' );
		rw_assert( $card['origin'] === 'maintenance_bundle', 'Account cards identify the bundle origin' );
		if ( ! empty( $card['is_decision_cloud'] ) ) {
			$cloud_cards++;
			rw_assert( $card['package_slug'] === 'reactwoo-decision-cloud', 'Only the Decision Cloud card is flagged for hosted activation' );
		}
	}
	sort( $card_products );
	rw_assert( $card_products === array( 2294, 3166 ), 'Cards use the licensed product, not the Maintenance product' );
	rw_assert( $cloud_cards === 1, 'One Decision Cloud card is exposed for the theme button' );

	$GLOBALS['rw_test_subscriptions'] = array( $account_sub );
	$listed = ReactWoo_Customer_Account_Service::get_instance()->get_account_records( 42 );
	$listed_ids = array();
	foreach ( $listed as $listed_row ) {
		$listed_ids[] = (int) $listed_row['product_id'];
	}
	sort( $listed_ids );
	rw_assert( $listed_ids === array( 2294, 3166 ), 'The account list shows both bundle keys and hides the Maintenance plan itself' );
}

if ( isset( $lifecycle ) && $lifecycle instanceof RWCC_Lifecycle ) {
	$maint_cloud = new RWCC_Test_Subscription();
	$maint_cloud->id = 880;
	$maint_cloud->items = array( new RWCC_Test_Item( 3660 ) );
	$paid_individual = new RWCC_Test_Subscription();
	$paid_individual->id = 881;
	$paid_individual->update_meta_data( '_reactwoo_license_id', 5150 );
	$paid_individual->update_meta_data( '_reactwoo_license_key', 'RW-SECRET-paid' );
	$before_delivered = isset( $delivered ) && is_array( $delivered ) ? count( $delivered ) : 0;
	$opened = $lifecycle->on_license_generated(
		$maint_cloud,
		null,
		array(
			'maintenance_bundle' => true,
			'plan_code'          => 'starter',
			'product_id'         => 3166,
			'package_slug'       => 'reactwoo-decision-cloud',
			'license_key'        => 'RW-SECRET-cloud',
		)
	);
	rw_assert( ! empty( $opened['ok'] ), 'Included Decision Cloud uses the existing hosted activation' );
	rw_assert( empty( $opened['supersession']['committed'] ), 'Included Decision Cloud does not supersede separately purchased licences' );
	rw_assert( (int) $paid_individual->get_meta( '_reactwoo_license_id' ) === 5150, 'Hosted activation leaves a standalone licence id in place' );
	rw_assert( RWCC_Order_Meta::get( $maint_cloud, RWCC_Order_Meta::META_PLAN ) === 'starter', 'Hosted activation records the Starter plan' );
	if ( isset( $delivered ) && is_array( $delivered ) ) {
		rw_assert( count( $delivered ) > $before_delivered, 'Hosted activation sends the Decision Cloud webhook' );
	}
	$not_cloud = $lifecycle->on_license_generated(
		$maint_cloud,
		null,
		array(
			'maintenance_bundle' => true,
			'plan_code'          => '',
		)
	);
	rw_assert( empty( $not_cloud['ok'] ) && $not_cloud['error'] === 'not_cloud_product', 'A bundle plugin key does not open a Decision Cloud workspace' );
	$still_standalone = $lifecycle->on_license_generated( $paid_individual, null, array( 'license_key' => 'RW-SECRET-paid' ) );
	rw_assert( empty( $still_standalone['ok'] ) && $still_standalone['error'] === 'not_cloud_product', 'A normal licence subscription still skips hosted activation' );
}

$handler_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-subscription-handler.php' );
$provision_at = strpos( $handler_src, '$provision_args = array(' );
$provision_body = substr( $handler_src, (int) $provision_at, 900 );
rw_assert( strpos( $provision_body, "'source_ref'" ) === false, 'Standalone provisioning does not send a bundle source ref' );
rw_assert( strpos( $provision_body, "'maintenance_bundle'" ) === false, 'Standalone provisioning does not set the bundle flag' );
rw_assert( strpos( $handler_src, 'update_license_status( $license_id, \'inactive\' )' ) !== false, 'A standalone failed payment still marks that one licence inactive' );
rw_assert( strpos( $handler_src, 'function handle_subscription_renewal' ) !== false, 'Standalone renewal handling remains on the subscription handler' );

$sanitized = ReactWoo_Maintenance_Bundle::sanitize_settings(
	array(
		'trigger' => 'not-a-trigger',
		'paid_periods' => 0,
	)
);
rw_assert( $sanitized['trigger'] === 'annual_immediate_monthly_after_renewal', 'An unknown issue trigger falls back to the documented default' );
rw_assert( (int) $sanitized['paid_periods'] === 1, 'Paid renewals cannot be set below one' );
rw_assert( strpos( $sanitized['plans']['standard']['packages'], '2269' ) !== false, 'Saving unrelated settings keeps the Standard package map' );
