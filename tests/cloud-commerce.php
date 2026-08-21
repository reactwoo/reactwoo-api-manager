<?php
/**
 * ReactWoo Commerce Bridge tests (no WordPress / WooCommerce required).
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$dir = dirname( __DIR__ ) . '/includes/cloud-commerce/';
require_once $dir . 'class-rwcc-crypto.php';
require_once $dir . 'class-rwcc-identity.php';
require_once $dir . 'class-rwcc-identity-client.php';
require_once $dir . 'class-rwcc-settings.php';
require_once $dir . 'class-rwcc-plan-map.php';
require_once $dir . 'class-rwcc-coverage.php';
require_once $dir . 'class-rwcc-transition.php';
require_once $dir . 'class-rwcc-upgrade-credit.php';
require_once $dir . 'class-rwcc-checkout-credit.php';
require_once $dir . 'class-rwcc-downgrade.php';
require_once $dir . 'class-rwcc-scheduled-subscription.php';
require_once $dir . 'class-rwcc-entitlement-handover.php';
require_once $dir . 'class-rwcc-licence-reuse.php';
require_once $dir . 'class-rwcc-product-copy.php';
require_once $dir . 'class-rwcc-overlap.php';
require_once $dir . 'class-rwcc-supersession.php';
require_once $dir . 'class-rwcc-urls.php';
require_once $dir . 'class-rwcc-handoff.php';
require_once $dir . 'class-rwcc-claims.php';
require_once $dir . 'class-rwcc-replay.php';
require_once $dir . 'class-rwcc-payload.php';
require_once $dir . 'class-rwcc-webhooks.php';
require_once $dir . 'class-rwcc-reconcile.php';
require_once $dir . 'class-rwcc-store.php';
require_once $dir . 'class-rwcc-order-meta.php';
require_once $dir . 'class-rwcc-lifecycle.php';

if ( ! function_exists( 'rw_assert' ) ) {
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
}

class RWCC_Test_Item {
	private $product_id;
	private $variation_id;
	public function __construct( $product_id, $variation_id = 0 ) {
		$this->product_id   = $product_id;
		$this->variation_id = $variation_id;
	}
	public function get_product_id() {
		return $this->product_id;
	}
	public function get_variation_id() {
		return $this->variation_id;
	}
}

class RWCC_Test_Order {
	public $id = 88;
	public $customer_id = 12;
	public $email = 'paul@reactwoo.com';
	public $meta = array();
	public $items = array();
	public function get_id() {
		return $this->id;
	}
	public function get_customer_id() {
		return $this->customer_id;
	}
	public function get_billing_email() {
		return $this->email;
	}
	public function get_items() {
		return $this->items;
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function get_meta( $key, $single = true ) {
		unset( $single );
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function save() {}
}

class RWCC_Test_Subscription extends RWCC_Test_Order {
	public $status = 'active';
	public $parent = null;
	public $dates = array( 'next_payment' => '2026-09-14T12:00:00' );
	public function get_status() {
		return $this->status;
	}
	public function get_parent() {
		return $this->parent;
	}
	public function get_parent_id() {
		return $this->parent ? $this->parent->get_id() : 0;
	}
	public function get_date( $type = '', $zone = '' ) {
		unset( $zone );
		return isset( $this->dates[ $type ] ) ? $this->dates[ $type ] : '2026-09-14T12:00:00';
	}
	public function update_dates( $dates ) {
		foreach ( (array) $dates as $key => $value ) {
			$this->dates[ $key ] = $value;
		}
	}
}

if ( defined( 'RWCC_LOAD_ONLY' ) && RWCC_LOAD_ONLY ) {
	return;
}

$secret   = 'whsec_store_test';
$handoff  = 'handoff_store_test';
$settings = new RWCC_Settings(
	array(
		'cloud_origin'       => 'https://cloud.reactwoo.com',
		'webhook_url'        => 'https://cloud.reactwoo.com/api/v1/billing/webhooks/woocommerce',
		'webhook_secret'     => $secret,
		'handoff_secret'     => $handoff,
		'reconcile_token'    => 'recon_token_test',
		'claim_ttl_sec'      => 1800,
		'replay_window_sec'  => 300,
		'return_origins'     => "https://cloud.reactwoo.com\nhttps://reactwoo.com",
		'product_starter'    => '101',
		'product_growth'     => '202',
		'product_scale'      => '303',
		'product_geocore_pro'  => '501',
		'product_geo_commerce' => '502',
		'product_geo_optimise' => '503',
		'allow_http_local'   => true,
	)
);

$plans = new RWCC_Plan_Map( $settings->product_map() );
$plans->map_id( 909, 'growth' );
$urls  = RWCC_Urls::from_settings( $settings );
$store = new RWCC_Store( false );
$claims = $store->claims_service( $settings );
$replay = $store->replay_service();

$delivered = array();
$webhooks  = new RWCC_Webhooks(
	$settings,
	$replay,
	function ( $url, $raw, $headers ) use ( &$delivered ) {
		$delivered[] = array(
			'url'     => $url,
			'raw'     => $raw,
			'headers' => $headers,
		);
		return array( 'ok' => true, 'status' => 200, 'body' => '{"processed":true,"organisation_id":"org_from_cloud"}' );
	}
);

$meta      = new RWCC_Order_Meta( $plans );
$lifecycle = new RWCC_Lifecycle( $settings, $plans, $meta, $claims, $webhooks, $urls );
$handoffs  = new RWCC_Handoff( $settings, $urls, $plans );

rw_assert( $plans->plan_for_product_id( 101 ) === 'starter', 'Product 101 maps to starter' );
rw_assert( $plans->plan_for_product_id( 202 ) === 'growth', 'Product 202 maps to growth' );
rw_assert( $plans->plan_for_product_id( 303 ) === 'scale', 'Product 303 maps to scale' );
rw_assert( $plans->plan_for_product_id( 909 ) === 'growth', 'Variation 909 maps to growth' );
rw_assert( $plans->plan_for_product_id( 999 ) === '', 'Unmapped product has no plan' );
rw_assert( strpos( file_get_contents( $dir . 'class-rwcc-plan-map.php' ), 'get_price' ) === false, 'Plan identity is not derived from price' );
rw_assert( strpos( file_get_contents( $dir . 'class-rwcc-plan-map.php' ), 'get_name' ) === false, 'Plan identity is not derived from product title' );
rw_assert( strpos( file_get_contents( $dir . 'class-rwcc-plan-map.php' ), '_downloadable_files' ) === false, 'Plan map does not attach variation ZIP files' );
rw_assert( strpos( file_get_contents( $dir . 'class-rwcc-product-fields.php' ), '_downloadable_files' ) === false, 'Product fields do not attach satellite ZIPs' );

$inspected = new RWCC_Plan_Map(
	array(
		'starter' => '3172,3173',
		'growth'  => '3174,3175',
		'scale'   => '3176,3177',
	)
);
rw_assert( $inspected->plan_for_product_id( 3172 ) === 'starter' && $inspected->plan_for_product_id( 3173 ) === 'starter', 'Starter monthly and annual map to starter' );
rw_assert( $inspected->billing_cycle_for_product_id( 3172 ) === 'monthly' && $inspected->billing_cycle_for_product_id( 3173 ) === 'annual', 'Starter cadence is stored separately from plan' );
rw_assert( $inspected->plan_for_product_id( 3174 ) === 'growth' && $inspected->plan_for_product_id( 3175 ) === 'growth', 'Growth monthly and annual map to growth' );
rw_assert( $inspected->plan_for_product_id( 3176 ) === 'scale' && $inspected->plan_for_product_id( 3177 ) === 'scale', 'Scale monthly and annual map to scale' );
rw_assert( $inspected->plan_for_product_id( 3166 ) === '', 'Parent Decision Cloud product is not a plan id' );
rw_assert( $inspected->plan_for_product_id( 39 ) === '', 'Monetary price is not a plan identifier' );
rw_assert( $plans->plan_for_product_id( 3172 ) === '', 'Sandbox/test map does not accept production variation IDs' );
rw_assert( $inspected->plan_for_product_id( 101 ) === '', 'Production map does not accept sandbox variation IDs' );
rw_assert( $inspected->product_id_for_plan( 'starter' ) === '3172', 'Default checkout variation is monthly first' );
rw_assert( $inspected->product_id_for_plan( 'starter', 'annual' ) === '3173', 'Annual checkout can select the annual variation' );

$meta_reader = static function ( $id, $key ) {
	if ( (int) $id === 3173 && $key === RWCC_Plan_Map::META_KEY ) {
		return 'starter';
	}
	if ( (int) $id === 3173 && $key === RWCC_Plan_Map::META_BILLING_CYCLE ) {
		return 'annual';
	}
	return '';
};
rw_assert( $inspected->resolve( 3166, 3173, $meta_reader ) === 'starter', 'Variation meta maps plan without using display name' );
rw_assert( $inspected->resolve_billing_cycle( 3166, 3173, $meta_reader ) === 'annual', 'Variation meta maps billing cycle separately' );

$starter_rows = RWCC_Coverage::download_rows( 'starter' );
$starter_slugs = array_map(
	static function ( $row ) {
		return $row['slug'];
	},
	$starter_rows
);
rw_assert( $starter_slugs === array( 'reactwoo-geocore-pro' ), 'Starter Cloud downloads are Geo Core Pro only' );
rw_assert( strpos( $starter_rows[0]['name'], 'Included with Decision Cloud' ) !== false, 'Cloud downloads use Included with Decision Cloud copy' );
$growth_slugs = RWCC_Coverage::covered_skus( 'growth' );
$scale_slugs  = RWCC_Coverage::covered_skus( 'scale' );
rw_assert( $growth_slugs === array( 'reactwoo-geocore-pro', 'reactwoo-geo-commerce', 'reactwoo-geo-optimise' ), 'Growth receives Geo Core Pro, Geo Commerce and Geo Optimise' );
rw_assert( $scale_slugs === $growth_slugs, 'Scale receives the full defined geo suite' );
rw_assert( ! in_array( 'reactwoo-whmcs-bridge', $growth_slugs, true ), 'WHMCS Bridge is not a Cloud download' );
rw_assert( ! in_array( 'reactwoo-reviews', $growth_slugs, true ), 'Google Reviews Pro is not a Cloud download' );
rw_assert( in_array( 'reactwoo-whmcs-bridge', RWCC_Coverage::UNCOVERED, true ), 'WHMCS Bridge remains an uncovered product' );

$cloud_sub = new RWCC_Test_Subscription();
$cloud_sub->id = 55;
$cloud_sub->update_meta_data( RWCC_Order_Meta::META_PLAN, 'starter' );
require_once dirname( __DIR__ ) . '/includes/class-plugin-download-service.php';
rw_assert( ReactWoo_Plugin_Download_Service::entitled_plugin_slugs( $cloud_sub ) === array( 'reactwoo-geocore-pro' ), 'Starter subscription entitles Geo Core Pro only' );
$cloud_sub->update_meta_data( RWCC_Order_Meta::META_PLAN, 'growth' );
rw_assert( count( ReactWoo_Plugin_Download_Service::entitled_plugin_slugs( $cloud_sub ) ) === 3, 'Growth subscription entitles three geo plugins' );

$superseded_ind = new RWCC_Test_Subscription();
$superseded_ind->id = 91;
$superseded_ind->update_meta_data( RWCC_Supersession::META_SUPERSEDED, '1' );
rw_assert( ReactWoo_Plugin_Download_Service::should_hide_downloads( $superseded_ind ), 'Superseded individuals hide My Account ZIPs' );
rw_assert( ReactWoo_Plugin_Download_Service::entitled_plugin_slugs( $superseded_ind ) === array(), 'Superseded individuals do not list covered plugin slugs' );

$hold_cloud = class_exists( 'WC_Subscription' ) ? new WC_Subscription( 92, 10, 'on-hold', array( RWCC_Order_Meta::META_PLAN => 'growth' ) ) : null;
$hold_ind   = class_exists( 'WC_Subscription' ) ? new WC_Subscription( 93, 10, 'on-hold', array() ) : null;
if ( $hold_cloud && $hold_ind ) {
	rw_assert( ReactWoo_Plugin_Download_Service::subscription_can_download( $hold_cloud ), 'Cloud on-hold (payment grace) can still download' );
	rw_assert( ! ReactWoo_Plugin_Download_Service::subscription_can_download( $hold_ind ), 'Standalone on-hold still cannot download' );
}

$handover_slugs = ReactWoo_Plugin_Download_Service::entitled_plugin_slugs( $cloud_sub );
rw_assert( $handover_slugs === RWCC_Coverage::covered_skus( 'growth' ), 'Download service uses handover covered SKUs for live Cloud' );

$plan_from_sub = RWCC_Licence_Reuse::provision_plan_code( '', $cloud_sub );
rw_assert( $plan_from_sub === 'growth', 'Licence provision filter reads Cloud plan from subscription meta' );

$plans->map_id( 3172, 'starter', 'monthly' );
$plans->map_id( 3173, 'starter', 'annual' );
$cadence_order = new RWCC_Test_Order();
$cadence_order->id    = 188;
$cadence_order->items = array( new RWCC_Test_Item( 3166, 3172 ) );
$cadence_sub = new RWCC_Test_Subscription();
$cadence_sub->id     = 155;
$cadence_sub->items  = $cadence_order->items;
$cadence_sub->parent = $cadence_order;
$cadence_act = $lifecycle->activate( $cadence_sub, $cadence_order );
rw_assert( ! empty( $cadence_act['ok'] ), 'Starter monthly variation activates Cloud' );
rw_assert( RWCC_Order_Meta::get( $cadence_sub, RWCC_Order_Meta::META_PLAN ) === 'starter', 'Monthly variation stamps starter' );
rw_assert( RWCC_Order_Meta::get( $cadence_sub, RWCC_Order_Meta::META_BILLING_CYCLE ) === 'monthly', 'Monthly variation stamps monthly cadence' );
$cadence_prov = $cadence_act['provisioning_id'];
$cadence_sub->items  = array( new RWCC_Test_Item( 3166, 3173 ) );
$cadence_order->items = $cadence_sub->items;
$cadence_switch = $lifecycle->activate( $cadence_sub, $cadence_order );
rw_assert( $cadence_switch['provisioning_id'] === $cadence_prov, 'Billing cadence switch does not mint a new organisation key' );
rw_assert( RWCC_Order_Meta::get( $cadence_sub, RWCC_Order_Meta::META_PLAN ) === 'starter', 'Cadence switch keeps the same internal plan' );
rw_assert( RWCC_Order_Meta::get( $cadence_sub, RWCC_Order_Meta::META_BILLING_CYCLE ) === 'annual', 'Cadence switch updates billing cycle only' );

$standalone = new RWCC_Test_Subscription();
$standalone->id    = 77;
$standalone->items = array( new RWCC_Test_Item( 999 ) );
$standalone_order  = new RWCC_Test_Order();
$standalone_order->items = $standalone->items;
$skipped = $lifecycle->on_license_generated( $standalone, $standalone_order, array( 'license_key' => 'RW-TEST' ) );
rw_assert( empty( $skipped['ok'] ) && $skipped['error'] === 'not_cloud_product', 'Unmapped standalone purchase stays off the Cloud path' );

$order = new RWCC_Test_Order();
$order->items = array( new RWCC_Test_Item( 202 ) );
$sub = new RWCC_Test_Subscription();
$sub->id     = 55;
$sub->items  = $order->items;
$sub->parent = $order;

$activation = $lifecycle->activate( $sub, $order );
rw_assert( ! empty( $activation['ok'] ), 'Activation succeeds for Cloud product' );
rw_assert( ! empty( $activation['claim']['token'] ), 'Activation issues a plaintext claim once' );
rw_assert( $activation['claim']['token'] !== $activation['claim']['hash'], 'Claim is stored hashed, not plaintext' );
rw_assert( strpos( $activation['activation_url'], 'https://cloud.reactwoo.com/activate#claim=' ) === 0, 'Activation URL points at Decision Cloud with a fragment token' );
rw_assert( strpos( $activation['activation_url'], '?claim=' ) === false, 'Raw claim is not placed in the query string' );
rw_assert( RWCC_Order_Meta::get( $sub, 'rw_cloud_plan' ) === 'growth', 'Subscription stamped with rw_cloud_plan' );
rw_assert( RWCC_Order_Meta::get( $order, 'rw_cloud_plan' ) === 'growth', 'Order stamped with rw_cloud_plan' );
rw_assert( RWCC_Order_Meta::get( $sub, 'rw_cloud_provisioning_id' ) !== '', 'Provisioning id stamped' );
rw_assert( RWCC_Order_Meta::get( $sub, 'rw_cloud_identity_email' ) === 'paul@reactwoo.com', 'Existing ReactWoo identity email attached' );
rw_assert( RWCC_Order_Meta::get( $sub, 'rw_cloud_identity_user' ) === '12', 'Existing ReactWoo user id attached' );
rw_assert( RWCC_Order_Meta::get( $sub, 'rw_cloud_identity_issuer' ) === 'https://reactwoo.com', 'Identity issuer is ReactWoo.com' );
$subject = RWCC_Order_Meta::get( $sub, 'rw_cloud_identity_subject' );
rw_assert( $subject !== '' && $subject !== '12', 'Stable identity subject is not the email or numeric user id' );
rw_assert( RWCC_Identity::subject_for_user( 12 ) === $subject, 'Identity subject is generated once and reused' );
$activation_payload = json_decode( $activation['webhook']['raw'], true );
$claim_meta = array();
foreach ( $activation_payload['meta_data'] as $row ) {
	$claim_meta[ $row['key'] ] = $row['value'];
}
rw_assert( $claim_meta['rw_cloud_identity_subject'] === $subject, 'Webhook payload includes the identity subject' );
rw_assert( $claim_meta['rw_cloud_claim_hash'] === $activation['claim']['hash'], 'Webhook payload includes the claim hash' );
rw_assert( $claim_meta['rw_cloud_claim_expires'] !== '', 'Webhook payload includes claim expiry' );
rw_assert( RWCC_Order_Meta::get( $sub, 'rw_cloud_org' ) === 'org_from_cloud', 'Successful Cloud webhook stamps the organisation id' );

$first_prov = $activation['provisioning_id'];
$first_hash = $activation['claim']['hash'];
$retry      = $lifecycle->activate( $sub, $order );
rw_assert( $retry['provisioning_id'] === $first_prov, 'Retry activation reuses provisioning id' );
rw_assert( $retry['claim']['hash'] !== $first_hash, 'Retry rotates the unused claim token' );
rw_assert( $store->get_claim( $first_hash )['revoked_at'] > 0, 'Previous unused claim is revoked on retry' );

$inspect = $claims->inspect( $retry['claim']['token'] );
rw_assert( ! empty( $inspect['ok'] ), 'Fresh claim inspects as valid' );
$consumed = $claims->consume( $retry['claim']['token'] );
rw_assert( ! empty( $consumed['ok'] ), 'Claim can be consumed once' );
$second = $claims->consume( $retry['claim']['token'] );
rw_assert( empty( $second['ok'] ) && $second['error'] === 'claim_used', 'Consumed claim is single-use' );

$expired_settings = new RWCC_Settings( array_merge( $settings->all(), array( 'claim_ttl_sec' => 1 ) ) );
$expired_store    = new RWCC_Store( false );
$expired_claims   = $expired_store->claims_service( $expired_settings );
$now              = 1700000000;
$issued_exp       = $expired_claims->issue(
	array(
		'customer_id'     => 12,
		'order_id'        => 88,
		'subscription_id' => 77,
		'plan'            => 'starter',
		'now'             => $now,
		'blog_id'         => 1,
	)
);
$expired_check = $expired_claims->inspect( $issued_exp['token'], $now + 2 );
rw_assert( empty( $expired_check['ok'] ) && $expired_check['error'] === 'claim_expired', 'Expired activation claim is rejected' );

$dup_org = $expired_claims->issue(
	array(
		'customer_id'         => 12,
		'order_id'            => 88,
		'subscription_id'     => 77,
		'plan'                => 'starter',
		'org_id'              => 'org_existing',
		'provisioning_id'     => $issued_exp['provisioning_id'],
		'already_provisioned' => true,
		'now'                 => $now + 10,
	)
);
rw_assert( ! empty( $dup_org['already_provisioned'] ), 'Duplicate provisioning is refused once the org exists' );
rw_assert( $dup_org['provisioning_id'] === $issued_exp['provisioning_id'], 'Duplicate provisioning keeps the same org key' );

$events = array(
	'renewal'         => 'active',
	'plan_switch'     => 'active',
	'payment_failure' => 'on-hold',
	'cancellation'    => 'cancelled',
	'expiry'          => 'expired',
	'refund'          => 'on-hold',
);
foreach ( $events as $event => $status ) {
	$sub->status = $status;
	$result      = $lifecycle->emit( $event, $sub, $order, array( 'status' => $status, 'plan' => 'growth' ) );
	rw_assert( ! empty( $result['ok'] ) && empty( $result['duplicate'] ), "Lifecycle event {$event} is delivered" );
	$decoded = json_decode( $result['raw'], true );
	rw_assert( is_array( $decoded ) && $decoded['rwcc']['event'] === $event, "Payload event field is {$event}" );
	rw_assert( $decoded['status'] === $status, "Payload status for {$event} is {$status}" );
	$expected_sig = RWCC_Crypto::sign_woocommerce_body( $result['raw'], $secret );
	rw_assert( $result['signature'] === $expected_sig, "{$event} uses X-WC-Webhook-Signature HMAC-SHA256 base64" );
}

$last = $delivered[ count( $delivered ) - 1 ];
rw_assert( isset( $last['headers']['X-WC-Webhook-Signature'] ), 'Delivery includes X-WC-Webhook-Signature' );
rw_assert( isset( $last['headers']['X-WC-Webhook-Delivery-ID'] ), 'Delivery includes X-WC-Webhook-Delivery-ID' );
rw_assert( isset( $last['headers']['X-WC-Webhook-Topic'] ), 'Delivery includes X-WC-Webhook-Topic' );

$bad_sig = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
rw_assert( $bad_sig !== RWCC_Crypto::sign_woocommerce_body( $last['raw'], $secret ), 'Invalid signatures do not match the contract' );
rw_assert( ! RWCC_Crypto::equals( $last['headers']['X-WC-Webhook-Signature'], $bad_sig ), 'Timing-safe compare rejects forged signatures' );

$payload  = json_decode( $activation['webhook']['raw'], true );
$replayed = $webhooks->deliver( $payload, 'subscription.created' );
rw_assert( ! empty( $replayed['duplicate'] ), 'Replayed delivery UUID is rejected as duplicate' );

$stale                        = $payload;
$stale['rwcc']['delivery_id'] = RWCC_Crypto::uuid();
$stale['rwcc']['timestamp']   = time() - 3600;
$stale_result                 = $webhooks->deliver( $stale, 'subscription.created' );
rw_assert( empty( $stale_result['ok'] ) && $stale_result['error'] === 'replay_window_exceeded', 'Stale timestamp fails the replay-protection window' );

rw_assert( $urls->is_allowed( 'https://cloud.reactwoo.com/portal/#/billing' ), 'Allowlisted Cloud return URL is accepted' );
rw_assert( $urls->is_allowed( 'https://reactwoo.com/my-account/' ), 'Allowlisted store return URL is accepted' );
rw_assert( ! $urls->is_allowed( 'https://evil.example/phish' ), 'Arbitrary return URL is rejected' );
rw_assert( ! $urls->is_allowed( 'javascript:alert(1)' ), 'javascript: return URL is rejected' );
rw_assert( ! $urls->is_allowed( 'https://user:pass@evil.example/' ), 'Credential-injection return URL is rejected' );

$exp    = (string) ( time() + 900 );
$params = array(
	'action' => 'checkout',
	'org'    => 'org_1',
	'plan'   => 'growth',
	'exp'    => $exp,
	'return' => 'https://cloud.reactwoo.com/portal/#/billing?checkout=success',
);
$ok_handoff = $handoffs->verify_request(
	array(
		'rw_action'     => 'checkout',
		'rw_cloud_org'  => 'org_1',
		'rw_cloud_plan' => 'growth',
		'rw_exp'        => $exp,
		'rw_return'     => $params['return'],
		'rw_sig'        => RWCC_Crypto::sign_handoff( $params, $handoff ),
		'add-to-cart'   => '202',
	)
);
rw_assert( ! empty( $ok_handoff['ok'] ), 'Valid checkout handoff is accepted' );
rw_assert( $ok_handoff['product_id'] === '202', 'Checkout handoff preserves add-to-cart product' );

$bound = array(
	'action'  => 'checkout',
	'org'     => 'org_1',
	'plan'    => 'growth',
	'exp'     => $exp,
	'return'  => $params['return'],
	'product' => '202',
);
$ok_bound = $handoffs->verify_request(
	array(
		'rw_action'     => 'checkout',
		'rw_cloud_org'  => 'org_1',
		'rw_cloud_plan' => 'growth',
		'rw_exp'        => $exp,
		'rw_return'     => $params['return'],
		'rw_sig'        => RWCC_Crypto::sign_handoff( $bound, $handoff, true ),
		'add-to-cart'   => '202',
	)
);
rw_assert( ! empty( $ok_bound['ok'] ), 'Product-bound checkout handoff is accepted' );

$swapped = $handoffs->verify_request(
	array(
		'rw_action'     => 'checkout',
		'rw_cloud_org'  => 'org_1',
		'rw_cloud_plan' => 'growth',
		'rw_exp'        => $exp,
		'rw_return'     => $params['return'],
		'rw_sig'        => RWCC_Crypto::sign_handoff( $bound, $handoff, true ),
		'add-to-cart'   => '101',
	)
);
rw_assert( empty( $swapped['ok'] ) && $swapped['error'] === 'invalid_signature', 'Tampered add-to-cart breaks a product-bound signature' );

$swapped_individual = $handoffs->verify_request(
	array(
		'rw_action'     => 'checkout',
		'rw_cloud_org'  => 'org_1',
		'rw_cloud_plan' => 'growth',
		'rw_exp'        => $exp,
		'rw_return'     => $params['return'],
		'rw_sig'        => RWCC_Crypto::sign_handoff( $bound, $handoff, true ),
		'add-to-cart'   => '501',
	)
);
rw_assert( empty( $swapped_individual['ok'] ) && $swapped_individual['error'] === 'invalid_signature', 'Handoff cannot be retargeted at an individual plugin product' );

$legacy_swap = $handoffs->verify_request(
	array(
		'rw_action'     => 'checkout',
		'rw_cloud_org'  => 'org_1',
		'rw_cloud_plan' => 'growth',
		'rw_exp'        => $exp,
		'rw_return'     => $params['return'],
		'rw_sig'        => RWCC_Crypto::sign_handoff( $params, $handoff ),
		'add-to-cart'   => '101',
	)
);
rw_assert( empty( $legacy_swap['ok'] ) && $legacy_swap['error'] === 'plan_product_mismatch', 'Legacy signatures still cannot switch Cloud plans via add-to-cart' );

$bad_return = $handoffs->verify_request(
	array(
		'rw_action'     => 'upgrade',
		'rw_cloud_org'  => 'org_1',
		'rw_cloud_plan' => 'scale',
		'rw_exp'        => $exp,
		'rw_return'     => 'https://evil.example/steal',
		'rw_sig'        => RWCC_Crypto::sign_handoff(
			array(
				'action' => 'upgrade',
				'org'    => 'org_1',
				'plan'   => 'scale',
				'exp'    => $exp,
				'return' => 'https://evil.example/steal',
			),
			$handoff
		),
	)
);
rw_assert( empty( $bad_return['ok'] ) && $bad_return['error'] === 'invalid_return_url', 'Handoff with invalid return URL is rejected even if signed' );

$forged = $handoffs->verify_request(
	array(
		'rw_action'     => 'invoices',
		'rw_cloud_org'  => 'org_1',
		'rw_exp'        => $exp,
		'rw_return'     => 'https://cloud.reactwoo.com/portal/',
		'rw_sig'        => 'deadbeef',
	)
);
rw_assert( empty( $forged['ok'] ) && $forged['error'] === 'invalid_signature', 'Forged handoff signature is rejected' );

foreach ( array( 'checkout', 'upgrade', 'subscription', 'invoices', 'payment-method', 'cancel', 'downgrade' ) as $action ) {
	$p = array(
		'action' => $action,
		'org'    => 'org_1',
		'plan'   => 'growth',
		'exp'    => $exp,
		'return' => 'https://cloud.reactwoo.com/portal/',
	);
	$verified = $handoffs->verify_request(
		array(
			'rw_action'     => $action,
			'rw_cloud_org'  => 'org_1',
			'rw_cloud_plan' => 'growth',
			'rw_exp'        => $exp,
			'rw_return'     => $p['return'],
			'rw_sig'        => RWCC_Crypto::sign_handoff( $p, $handoff ),
		)
	);
	$url = $handoffs->destination(
		$verified,
		array(
			'home'     => 'https://reactwoo.com',
			'checkout' => '/checkout/',
			'account'  => '/my-account/',
			'orders'   => '/my-account/orders/',
		)
	);
	rw_assert( ! empty( $verified['ok'] ) && $url !== '', "Secure handoff endpoint exists for {$action}" );
	rw_assert( strpos( $url, 'stripe' ) === false && strpos( $url, 'paystack' ) === false, "{$action} handoff stays on the store" );
	if ( $action === 'cancel' || $action === 'downgrade' ) {
		rw_assert( strpos( $url, 'rwcc_downgrade=1' ) !== false, "{$action} handoff opens the store downgrade selection" );
	}
}

rw_assert( RWCC_Reconcile::authorized( 'Bearer recon_token_test', 'recon_token_test' ), 'Reconcile bearer token is accepted' );
rw_assert( ! RWCC_Reconcile::authorized( 'Bearer wrong', 'recon_token_test' ), 'Wrong reconcile token is rejected' );
rw_assert( ! RWCC_Reconcile::authorized( 'Basic abc', 'recon_token_test' ), 'Non-bearer reconcile auth is rejected' );

$snapshot = $lifecycle->reconcile_from_subscription( $sub );
rw_assert( $snapshot['plan'] === 'growth', 'Reconcile snapshot reports growth after missed-webhook recovery' );
rw_assert( $snapshot['status'] === 'on-hold', 'Reconcile snapshot reports current subscription status' );
rw_assert( $snapshot['subscription_id'] === 55, 'Reconcile snapshot includes subscription id' );
rw_assert( $snapshot['provisioning_id'] === $first_prov, 'Reconcile snapshot includes stable provisioning id' );
rw_assert( ! isset( $snapshot['consumer_key'] ), 'Reconcile snapshot has no WooCommerce REST credentials' );
rw_assert( array_key_exists( 'billing_overlap', $snapshot ), 'Reconcile snapshot reports billing overlap state' );

rw_assert( RWCC_Coverage::sku_covered( 'starter', 'geo-elementor' ), 'Starter covers Geo Core Pro alias' );
rw_assert( ! RWCC_Coverage::sku_covered( 'starter', 'reactwoo-geo-commerce' ), 'Starter does not cover Geo Commerce' );
rw_assert( RWCC_Coverage::sku_covered( 'growth', 'reactwoo-geo-ai' ), 'Growth covers Geo Optimise alias' );

$summary = RWCC_Coverage::upgrade_summary(
	'growth',
	array(
		array( 'id' => 11, 'product_id' => 501, 'status' => 'active', 'renewing' => true ),
		array( 'id' => 12, 'product_id' => 999, 'status' => 'active', 'renewing' => true, 'slug' => 'reactwoo-reviews' ),
	),
	$settings,
	$plans
);
rw_assert( count( $summary['will_stop_renewing'] ) === 1 && $summary['will_stop_renewing'][0]['slug'] === 'reactwoo-geocore-pro', 'Growth upgrade supersedes Geo Core Pro' );
rw_assert( count( $summary['remain_separately_billed'] ) === 1 && $summary['remain_separately_billed'][0]['separate_reason'] === 'not_a_reactwoo_suite_plugin', 'Reviews stays separately billed' );

$whmcs_summary = RWCC_Coverage::upgrade_summary(
	'scale',
	array(
		array( 'id' => 31, 'product_id' => 888, 'status' => 'active', 'renewing' => true, 'slug' => 'reactwoo-whmcs-bridge' ),
	),
	$settings,
	$plans
);
rw_assert( count( $whmcs_summary['remain_separately_billed'] ) === 1, 'WHMCS Bridge stays separately billed on Scale' );
rw_assert( ! empty( $summary['block_unexplained_full_price'] ), 'Covered renewing individuals block unexplained full-price Cloud checkout' );

$starter_summary = RWCC_Coverage::upgrade_summary(
	'starter',
	array(
		array( 'id' => 21, 'product_id' => 501, 'status' => 'active', 'renewing' => true ),
		array( 'id' => 22, 'product_id' => 502, 'status' => 'active', 'renewing' => true ),
	),
	$settings,
	$plans
);
rw_assert( count( $starter_summary['will_stop_renewing'] ) === 1, 'Starter upgrade supersedes only Geo Core Pro' );
rw_assert( $starter_summary['remain_separately_billed'][0]['slug'] === 'reactwoo-geo-commerce', 'Starter leaves Geo Commerce separately billed' );

$now    = 1700000000;
$day    = 86400;
$credit = RWCC_Upgrade_Credit::calculate(
	array(
		array(
			'id'           => 11,
			'slug'         => 'reactwoo-geocore-pro',
			'covered'      => true,
			'status'       => 'active',
			'amount_paid'  => 120,
			'currency'     => 'USD',
			'period_start' => $now - ( 30 * $day ),
			'period_end'   => $now + ( 30 * $day ),
		),
		array(
			'id'           => 22,
			'slug'         => 'reactwoo-geo-commerce',
			'covered'      => true,
			'status'       => 'active',
			'amount_paid'  => 60,
			'currency'     => 'USD',
			'period_start' => $now - 10,
			'period_end'   => $now + 10,
		),
	),
	array(
		'now'                  => $now,
		'cloud_checkout_value' => 80,
		'currency'             => 'USD',
	)
);
rw_assert( (float) $credit['gross_credit'] > 80, 'Gross remaining-term credit sums eligible lines' );
rw_assert( $credit['applied_credit'] === '80.00' && ! empty( $credit['capped'] ), 'Upgrade credit is capped at the Cloud checkout value' );
rw_assert( $credit['lines'][0]['eligible'] && $credit['lines'][1]['eligible'], 'Covered active lines are eligible for credit' );

$trial_credit = RWCC_Upgrade_Credit::line_credit(
	array(
		'id'      => 9,
		'covered' => true,
		'trial'   => true,
		'status'  => 'active',
	),
	$now,
	'USD'
);
rw_assert( empty( $trial_credit['eligible'] ) && $trial_credit['reason'] === 'trial', 'Trial subscriptions receive no remaining-term credit' );

$growth_quote = RWCC_Checkout_Credit::quote(
	'growth',
	array(
		array(
			'id'           => 11,
			'product_id'   => 501,
			'status'       => 'active',
			'renewing'     => true,
			'amount_paid'  => 120,
			'currency'     => 'USD',
			'period_start' => $now - ( 30 * $day ),
			'period_end'   => $now + ( 30 * $day ),
		),
		array(
			'id'         => 12,
			'product_id' => 999,
			'status'     => 'active',
			'renewing'   => true,
			'slug'       => 'reactwoo-reviews',
		),
	),
	99,
	'USD',
	$settings,
	$plans,
	$now
);
rw_assert( empty( $growth_quote['block'] ), 'Covered remaining-term credit unblocks Cloud checkout' );
rw_assert( $growth_quote['fee_amount'] === '-60.00' || (float) $growth_quote['fee_amount'] < 0, 'Eligible upgrade applies a negative cart fee' );
rw_assert( in_array( 'Geo Core Pro', $growth_quote['notices']['included'], true ), 'Checkout lists Geo Core Pro as included' );
rw_assert( in_array( 'Geo Core Pro', $growth_quote['notices']['will_stop'], true ), 'Checkout lists Geo Core Pro renewal as stopping' );
rw_assert( $growth_quote['notices']['separate'][0]['reason'] === 'not_a_reactwoo_suite_plugin', 'Reviews stays separately billed on checkout' );
$growth_html = RWCC_Checkout_Credit::render_html( $growth_quote );
rw_assert( strpos( $growth_html, 'Remaining-term credit' ) !== false, 'Checkout HTML shows remaining-term credit' );
rw_assert( strpos( $growth_html, 'Applied upgrade credit' ) !== false, 'Checkout HTML shows the applied credit amount' );

$missing_quote = RWCC_Checkout_Credit::quote(
	'growth',
	array(
		array(
			'id'         => 11,
			'product_id' => 501,
			'status'     => 'active',
			'renewing'   => true,
		),
	),
	99,
	'USD',
	$settings,
	$plans,
	$now
);
rw_assert( ! empty( $missing_quote['block'] ), 'Missing period data blocks unexplained full-price Cloud checkout' );
rw_assert( $missing_quote['fee_amount'] === '0.00', 'Blocked checkout does not apply a credit fee' );

$starter_quote = RWCC_Checkout_Credit::quote(
	'starter',
	array(
		array(
			'id'           => 21,
			'product_id'   => 501,
			'status'       => 'active',
			'renewing'     => true,
			'amount_paid'  => 40,
			'currency'     => 'USD',
			'period_start' => $now - ( 15 * $day ),
			'period_end'   => $now + ( 15 * $day ),
		),
		array(
			'id'         => 22,
			'product_id' => 502,
			'status'     => 'active',
			'renewing'   => true,
		),
	),
	39,
	'USD',
	$settings,
	$plans,
	$now
);
rw_assert( empty( $starter_quote['block'] ), 'Starter credit from Geo Core Pro does not block checkout' );
rw_assert( $starter_quote['notices']['separate'][0]['reason'] === 'not_in_selected_cloud_plan', 'Starter checkout explains Geo Commerce stays billed' );
rw_assert( $starter_quote['notices']['separate'][0]['label'] === 'Geo Commerce', 'Starter checkout names Geo Commerce as separately billed' );
rw_assert( strpos( RWCC_Checkout_Credit::render_html( $starter_quote ), 'Not included in this Cloud plan' ) !== false, 'Starter checkout copy explains Commerce is not covered' );

$trial_quote = RWCC_Checkout_Credit::quote(
	'growth',
	array(
		array(
			'id'         => 9,
			'product_id' => 501,
			'status'     => 'active',
			'renewing'   => true,
			'trial'      => true,
		),
	),
	99,
	'USD',
	$settings,
	$plans,
	$now
);
rw_assert( empty( $trial_quote['block'] ), 'Trial-only covered lines do not block checkout' );
rw_assert( $trial_quote['credit']['applied_credit'] === '0.00', 'Trial-only covered lines apply no credit' );

$none_quote = RWCC_Checkout_Credit::quote( 'growth', array(), 99, 'USD', $settings, $plans, $now );
rw_assert( empty( $none_quote['block'] ) && $none_quote['fee_amount'] === '0.00', 'Cloud checkout with no individuals is not blocked' );

$boot_src = file_get_contents( $dir . 'class-rwcc-bootstrap.php' );
rw_assert( strpos( $boot_src, 'RWCC_Checkout_Credit' ) !== false, 'Checkout credit is registered from the Cloud bootstrap' );

$failed_activation = RWCC_Transition::commit_after_cloud_activation(
	array( 'original_subscription_id' => '11' ),
	array( 'ok' => false )
);
rw_assert( $failed_activation['transition_status'] === RWCC_Transition::STATUS_FAILED, 'Failed Cloud activation does not commit supersession' );

$pro_sub = new RWCC_Test_Subscription();
$pro_sub->id    = 5011;
$pro_sub->items = array( new RWCC_Test_Item( 501 ) );
$commerce_sub = new RWCC_Test_Subscription();
$commerce_sub->id    = 5022;
$commerce_sub->items = array( new RWCC_Test_Item( 502 ) );
$other_sub = new RWCC_Test_Subscription();
$other_sub->id    = 9999;
$other_sub->items = array( new RWCC_Test_Item( 999 ) );

$failed_cover = RWCC_Supersession::commit_covered(
	$sub,
	array( $pro_sub, $commerce_sub ),
	$settings,
	$plans,
	array( 'ok' => false, 'plan' => 'growth' )
);
rw_assert( ! empty( $failed_cover['failed'] ), 'Supersession aborts when Cloud activation failed' );
rw_assert( empty( $pro_sub->get_meta( RWCC_Supersession::META_SUPERSEDED, true ) ), 'Failed activation leaves Geo Core Pro renewing' );

$lifecycle->set_subscription_finder(
	static function () use ( $pro_sub, $commerce_sub, $other_sub, $sub ) {
		return array( $pro_sub, $commerce_sub, $other_sub, $sub );
	}
);
$after_ok = $lifecycle->activate( $sub, $order );
rw_assert( count( $after_ok['supersession']['committed'] ) === 2, 'Successful Cloud activation supersedes covered individuals' );
rw_assert( RWCC_Supersession::is_superseded( $pro_sub ), 'Geo Core Pro is superseded after growth activation' );
rw_assert( RWCC_Supersession::is_superseded( $commerce_sub ), 'Geo Commerce is superseded after growth activation' );
rw_assert( ! RWCC_Supersession::is_superseded( $other_sub ), 'Uncovered products stay independently billed' );
rw_assert( $pro_sub->dates['next_payment'] === 0, 'Superseded subscriptions have no next automatic payment' );

$renew_blocked = $lifecycle->on_renewal( $pro_sub );
rw_assert( empty( $renew_blocked['ok'] ) && $renew_blocked['error'] === 'superseded', 'Superseded individuals do not emit Cloud renewals' );

$overlap = RWCC_Overlap::detect(
	array( 'plan' => 'growth', 'status' => 'active', 'renewing' => true ),
	array(
		array( 'id' => 11, 'product_id' => 501, 'status' => 'active', 'renewing' => true ),
	),
	$settings,
	$plans
);
rw_assert( ! empty( $overlap['overlap'] ) && $overlap['state'] === 'cloud_active_with_legacy_overlapping_individual_billing', 'State 6 overlap is detected when covered individuals still renew' );

$clean_overlap = RWCC_Overlap::detect(
	array( 'plan' => 'growth', 'status' => 'active', 'renewing' => true ),
	array(
		array( 'id' => 11, 'product_id' => 501, 'status' => 'active', 'renewing' => false, 'superseded' => true ),
	),
	$settings,
	$plans
);
rw_assert( empty( $clean_overlap['overlap'] ), 'Superseded individuals are not treated as double billing' );

$downgrade = RWCC_Transition::schedule_downgrade(
	array(
		'original_subscription_id'    => '55',
		'replacement_subscription_id' => '5011',
		'transition_effective_at'     => '2026-12-01T00:00:00+00:00',
		'idempotency_key'             => 'downgrade:55:pro',
	)
);
rw_assert( $downgrade['transition_status'] === RWCC_Transition::STATUS_SCHEDULED, 'Downgrade records schedule individuals at Cloud end' );
$merged = RWCC_Transition::idempotent_merge( $downgrade, array_merge( $downgrade, array( 'replacement_subscription_id' => 'other' ) ) );
rw_assert( $merged['replacement_subscription_id'] === '5011', 'Webhook retries do not duplicate a scheduled downgrade' );

$prices = static function ( $id ) {
	$map = array(
		'501' => '49',
		'502' => '29',
		'503' => '39',
	);
	return isset( $map[ (string) $id ] ) ? $map[ (string) $id ] : '0';
};
$pending_quote = RWCC_Downgrade::quote( 'growth', array(), '2026-12-01T00:00:00+00:00', $settings, $prices, false );
rw_assert( $pending_quote['state'] === RWCC_Downgrade::STATE_SELECTION_PENDING, 'Empty downgrade without none-selected stays pending' );
$denied = RWCC_Downgrade::confirm( $pending_quote, true, '55' );
rw_assert( empty( $denied['ok'] ) && $denied['error'] === 'selection_required', 'Downgrade confirm without a selection is refused' );
$unconfirmed = RWCC_Downgrade::confirm( $pending_quote, false, '55' );
rw_assert( empty( $unconfirmed['ok'] ) && $unconfirmed['error'] === 'confirmation_required', 'Downgrade requires explicit confirmation' );

$none_quote = RWCC_Downgrade::quote( 'growth', array(), '2026-12-01T00:00:00+00:00', $settings, $prices, true );
$none_ok    = RWCC_Downgrade::confirm( $none_quote, true, '55' );
rw_assert( ! empty( $none_ok['ok'] ) && $none_ok['payload']['none_selected'], 'Customer can continue with no paid plugins' );
rw_assert( $none_ok['planned'] === array(), 'No-plugin downgrade creates no scheduled individual subscriptions' );

$multi_quote = RWCC_Downgrade::quote(
	'growth',
	array( 'reactwoo-geocore-pro', 'reactwoo-geo-commerce' ),
	'2026-12-01T00:00:00+00:00',
	$settings,
	$prices,
	false
);
rw_assert( $multi_quote['combined_price'] === '78.00', 'Downgrade combined price sums selected plugins' );
rw_assert( count( $multi_quote['selected'] ) === 2, 'Multiple plugins can be selected for downgrade' );
$multi_ok = RWCC_Downgrade::confirm( $multi_quote, true, '55' );
rw_assert( count( $multi_ok['planned'] ) === 2 && $multi_ok['planned'][0]['charge_now'] === false, 'Scheduled individuals are not charged now' );
rw_assert( $multi_ok['planned'][0]['start_date'] === '2026-12-01T00:00:00+00:00', 'Scheduled individuals start at Cloud end' );

$starter_down = RWCC_Downgrade::quote( 'starter', array( 'reactwoo-geo-commerce' ), '2026-12-01T00:00:00+00:00', $settings, $prices, false );
rw_assert( $starter_down['selected'][0]['slug'] === 'reactwoo-geo-commerce', 'Starter downgrade can still select Geo Commerce as a new individual bill' );
$starter_included = array();
foreach ( $starter_down['options'] as $option ) {
	$starter_included[ $option['slug'] ] = ! empty( $option['currently_included'] );
}
rw_assert( ! empty( $starter_included['reactwoo-geocore-pro'] ), 'Starter marks Geo Core Pro as currently included' );
rw_assert( empty( $starter_included['reactwoo-geo-commerce'] ), 'Starter does not mark Geo Commerce as currently included' );

$reactivate = RWCC_Downgrade::cancel_schedule( $multi_ok['payload'] );
rw_assert( $reactivate['state'] === RWCC_Downgrade::STATE_CANCELLED && $reactivate['planned_subscriptions'] === array(), 'Cloud reactivation cancels scheduled individual charges' );
rw_assert( count( $reactivate['cancelled_planned'] ) === 2, 'Cancelled downgrade keeps planned rows for Woo cancellation' );

$created_ids = array();
$charged_now = 0;
$fake_create = static function ( $spec ) use ( &$created_ids, &$charged_now ) {
	if ( ! empty( $spec['charge_now'] ) ) {
		++$charged_now;
		return array( 'ok' => false, 'error' => 'charge_now_forbidden', 'charged' => true );
	}
	$id = (string) ( 9100 + count( $created_ids ) );
	$created_ids[] = $id;
	return array( 'ok' => true, 'subscription_id' => $id, 'charged' => false );
};
$materialized = RWCC_Scheduled_Subscription::materialize(
	$multi_ok['payload'],
	array( 'customer_id' => 12, 'cloud_subscription_id' => '55' ),
	$fake_create
);
rw_assert( count( $materialized['created_subscription_ids'] ) === 2, 'Confirmed downgrade materializes one pending Woo subscription per selected plugin' );
rw_assert( $charged_now === 0 && $materialized['charges_now'] === false, 'Native scheduled-sub creation never charges at confirm time' );
rw_assert( $materialized['planned_subscriptions'][0]['start_date'] === '2026-12-01T00:00:00+00:00', 'Materialized individuals keep Cloud end as start_date' );
$again = RWCC_Scheduled_Subscription::materialize(
	$materialized,
	array( 'customer_id' => 12, 'cloud_subscription_id' => '55' ),
	$fake_create
);
rw_assert( count( $created_ids ) === 2, 'Materialize is idempotent once Woo ids exist' );

$none_mat = RWCC_Scheduled_Subscription::materialize(
	$none_ok['payload'],
	array( 'customer_id' => 12, 'cloud_subscription_id' => '55' ),
	$fake_create
);
rw_assert( empty( $none_mat['created_subscription_ids'] ), 'No-plugin downgrade does not create Woo subscriptions' );

$rogue = $multi_ok['payload'];
$rogue['planned_subscriptions'][0]['charge_now'] = true;
$blocked = RWCC_Scheduled_Subscription::materialize(
	$rogue,
	array( 'customer_id' => 12, 'cloud_subscription_id' => '55' ),
	$fake_create
);
rw_assert( $blocked['planned_subscriptions'][0]['error'] === 'charge_now_forbidden', 'Creator that would charge now is blocked' );

$cancelled_woo = array();
$cancelled_payload = RWCC_Downgrade::cancel_schedule( $materialized );
$after_cancel = RWCC_Scheduled_Subscription::cancel_created(
	$cancelled_payload,
	static function ( $id ) use ( &$cancelled_woo ) {
		$cancelled_woo[] = $id;
		return array( 'ok' => true );
	}
);
rw_assert( count( $cancelled_woo ) === 2, 'Cloud reactivation cancels materialized pending Woo subscriptions' );
rw_assert( count( $after_cancel['cancelled_subscription_ids'] ) === 2, 'Cancelled Woo ids are recorded' );

rw_assert( RWCC_Scheduled_Subscription::woo_start_date( '2026-12-01T00:00:00+00:00' ) === '2026-12-01 00:00:00', 'ISO-8601 start dates convert for WooCommerce Subscriptions' );
$denied_charge = RWCC_Scheduled_Subscription::default_creator( array( 'charge_now' => true, 'product_id' => '501' ) );
rw_assert( $denied_charge['error'] === 'charge_now_forbidden' && empty( $denied_charge['charged'] ), 'Default Woo creator refuses charge_now' );
$missing_product = RWCC_Scheduled_Subscription::default_creator( array( 'product_id' => '', 'start_date' => '2026-12-01T00:00:00+00:00' ) );
rw_assert( $missing_product['error'] === 'missing_product_id', 'Default Woo creator requires a product id' );
$sched_src = file_get_contents( $dir . 'class-rwcc-scheduled-subscription.php' );
rw_assert( strpos( $sched_src, 'process_payment' ) === false, 'Scheduled-sub creator never processes payment' );
rw_assert( strpos( $sched_src, 'wcs_create_subscription' ) !== false, 'Native creator uses wcs_create_subscription when WCS is present' );

$life_src = file_get_contents( $dir . 'class-rwcc-lifecycle.php' );
rw_assert( strpos( $life_src, 'RWCC_Scheduled_Subscription::cancel_created' ) !== false, 'Lifecycle cancels materialized individuals on Cloud reactivation' );
rw_assert( stripos( $life_src, 'wp_schedule' ) === false, 'Scheduled-sub creation does not add Action Scheduler to lifecycle' );

$hold_ctx = RWCC_Downgrade::context_for_status( 'on-hold' );
rw_assert( ! empty( $hold_ctx['repair_billing'] ), 'Failed-payment downgrade offers billing repair plus fallback selection' );

$form = RWCC_Downgrade::form_html( $multi_quote, RWCC_Downgrade::context_for_status( 'active' ), '/my-account/', 55 );
rw_assert( strpos( $form, 'Continue with no paid plugins' ) !== false, 'Downgrade form offers no-plugin continuation' );
rw_assert( strpos( $form, 'I confirm these individual subscriptions' ) !== false, 'Downgrade form requires confirmation copy' );

$copy = RWCC_Product_Copy::html();
rw_assert( strpos( $copy, 'Starter does not include Geo Commerce or Geo Optimise' ) !== false, 'Product copy states Starter exclusions' );
rw_assert( strpos( $copy, 'Cloud does not place content for you' ) !== false, 'Product copy states Cloud does not auto-place content' );

$overlap_cloud = new RWCC_Test_Subscription();
$overlap_cloud->id    = 700;
$overlap_cloud->items = array( new RWCC_Test_Item( 202 ) );
$overlap_cloud->meta[ RWCC_Order_Meta::META_PLAN ] = 'growth';
$overlap_pro = new RWCC_Test_Subscription();
$overlap_pro->id    = 701;
$overlap_pro->items = array( new RWCC_Test_Item( 501 ) );
$overlap_blocked = RWCC_Overlap::correct( $overlap_cloud, array( $overlap_pro ), $settings, $plans, false, 'ops' );
rw_assert( empty( $overlap_blocked['ok'] ) && $overlap_blocked['error'] === 'confirmation_required', 'Overlap correction requires operator confirm' );
rw_assert( ! RWCC_Supersession::is_superseded( $overlap_pro ), 'Unconfirmed overlap correction leaves individuals renewing' );
$overlap_ok = RWCC_Overlap::correct( $overlap_cloud, array( $overlap_pro ), $settings, $plans, true, 'ops' );
rw_assert( ! empty( $overlap_ok['ok'] ) && count( $overlap_ok['corrected'] ) === 1, 'Confirmed overlap correction supersedes covered individuals' );
rw_assert( RWCC_Supersession::is_superseded( $overlap_pro ), 'Corrected overlapping Geo Core Pro is superseded' );
rw_assert( $overlap_pro->dates['next_payment'] === 0, 'Corrected overlap clears the next automatic payment' );

$boot_src = file_get_contents( $dir . 'class-rwcc-bootstrap.php' ) . file_get_contents( $dir . 'class-rwcc-account.php' );
rw_assert( strpos( $boot_src, 'RWCC_Downgrade' ) !== false && strpos( $boot_src, 'RWCC_Product_Copy' ) !== false, 'Downgrade and product copy are registered' );
rw_assert( strpos( $boot_src, 'RWCC_Scheduled_Subscription' ) !== false, 'Scheduled Woo subscription creator is registered' );
rw_assert( strpos( $boot_src, 'Save downgrade selection' ) !== false || strpos( file_get_contents( $dir . 'class-rwcc-downgrade.php' ), 'Save downgrade selection' ) !== false, 'My Account downgrade save control exists' );

$src = file_get_contents( $dir . 'class-rwcc-webhooks.php' ) . file_get_contents( $dir . 'class-rwcc-rest.php' );
rw_assert( strpos( $src, 'consumer_key' ) === false, 'Bridge source does not embed WooCommerce REST consumer keys' );
$life_src = file_get_contents( $dir . 'class-rwcc-lifecycle.php' );
rw_assert( strpos( $life_src, "add_action( 'reactwoo_license_generated'" ) !== false, 'Consumes API Manager licence generated' );
rw_assert( strpos( $life_src, "add_action( 'reactwoo_license_renewed'" ) !== false, 'Consumes API Manager renewal' );
rw_assert( strpos( $life_src, "add_action( 'reactwoo_license_payment_failed'" ) !== false, 'Consumes API Manager payment failure' );
rw_assert( strpos( $life_src, "add_action( 'reactwoo_license_status_synced'" ) !== false, 'Consumes API Manager status sync' );
rw_assert( strpos( $life_src, "add_action( 'woocommerce_subscription_status_active'" ) === false, 'Does not re-hook WooCommerce subscription activation' );
rw_assert( strpos( $life_src, "add_action( 'woocommerce_order_status_completed'" ) === false, 'Does not re-hook order completed' );
rw_assert( strpos( $life_src, "add_action( 'woocommerce_subscription_renewal_payment_complete'" ) === false, 'Does not re-hook WooCommerce renewal' );
rw_assert( strpos( $life_src, "add_action( 'woocommerce_scheduled_subscription_payment'" ) !== false, 'Blocks automatic payment on superseded individuals' );
rw_assert( strpos( $life_src, "add_action( 'woocommerce_subscription_payment_failed'" ) === false, 'Does not re-hook WooCommerce payment failure' );
rw_assert( strpos( $life_src, 'woocommerce_subscriptions_switch_completed' ) !== false, 'Hooks plan switch' );
rw_assert( strpos( $life_src, 'get_current_blog_id' ) !== false, 'Provisioning is blog-scoped for multisite' );

$identity_posts = array();
$identity_client = new RWCC_Identity_Client(
	$settings,
	function ( $url, $raw, $headers ) use ( &$identity_posts ) {
		$identity_posts[] = array( 'url' => $url, 'raw' => $raw, 'headers' => $headers );
		return array( 'ok' => true, 'status' => 201, 'body' => '{"ok":true}' );
	}
);
$login = $identity_client->issue_login( 12, 'paul@reactwoo.com', 'org_from_cloud' );
rw_assert( ! empty( $login['ok'] ), 'Returning login issues a signed Cloud claim' );
rw_assert( strpos( $login['url'], '#claim=' ) !== false, 'Login URL keeps the raw token in the fragment' );
rw_assert( count( $identity_posts ) === 1, 'Login claim is registered server-to-server' );
$login_body = json_decode( $identity_posts[0]['raw'], true );
rw_assert( $login_body['purpose'] === 'login', 'Login claim purpose is login' );
rw_assert( $login_body['issuer'] === 'https://reactwoo.com', 'Login claim issuer is ReactWoo.com' );
rw_assert( $login_body['subject'] === $subject, 'Login claim uses the same identity subject' );
rw_assert( empty( $login_body['token'] ), 'Raw login token is not sent to Cloud registration' );
rw_assert( $login_body['hash'] !== $login['token'], 'Cloud registration stores only the hash' );

$account_src = file_get_contents( $dir . 'class-rwcc-account.php' );
rw_assert( strpos( $account_src, 'Open Decision Cloud' ) !== false, 'My Account offers Open Decision Cloud without replacing licence UI' );
rw_assert( strpos( $account_src, 'Included in your Decision Cloud subscription' ) !== false, 'Superseded individuals show Included in Decision Cloud' );
rw_assert( strpos( $account_src, 'render_open_cloud' ) !== false, 'Returning login is offered on the dashboard without requiring a Cloud subscription' );
rw_assert( strpos( $account_src, 'woocommerce_login_redirect' ) !== false, 'Store login preserves Cloud SSO after WooCommerce login' );
rw_assert( strpos( $account_src, 'capture_open_cloud_intent' ) !== false, 'Logged-out Cloud SSO stores a short-lived intent cookie' );
rw_assert( strpos( $account_src, "if ( \$nonce !== '' &&" ) !== false, 'Cloud SSO does not require a WordPress nonce that Cloud cannot mint' );
$license_ui = dirname( __DIR__ ) . '/includes';
rw_assert( is_dir( $license_ui ), 'Existing API Manager licence includes remain in place' );

$mismatch = RWCC_Upgrade_Credit::line_credit(
	array(
		'id'           => 9,
		'covered'      => true,
		'status'       => 'active',
		'currency'     => 'USD',
		'period_start' => time() - 86400,
		'period_end'   => time() + 86400,
		'amount_paid'  => 49,
	),
	time(),
	'GBP'
);
rw_assert( empty( $mismatch['eligible'] ) && $mismatch['reason'] === 'currency_mismatch', 'Mismatched currency is not converted into Cloud credit' );

$handover_fail = RWCC_Entitlement_Handover::snapshot(
	array(
		'plan'          => 'growth',
		'activation_ok' => false,
		'cloud_status'  => '',
	)
);
rw_assert( $handover_fail['phase'] === RWCC_Entitlement_Handover::PHASE_ACTIVATION_FAILED && $handover_fail['gap'] === false, 'Activation failure keeps standalone access with no gap' );

$handover_cloud = RWCC_Entitlement_Handover::snapshot(
	array(
		'plan'               => 'growth',
		'cloud_status'       => 'active',
		'cloud_paid_through' => time() + 86400 * 20,
		'now'                => time(),
		'downgrade'          => $multi_ok['payload'],
	)
);
rw_assert( $handover_cloud['phase'] === RWCC_Entitlement_Handover::PHASE_SCHEDULED_DOWNGRADE, 'Scheduled downgrade keeps Cloud access until paid-through' );
rw_assert( in_array( 'reactwoo-geo-commerce', $handover_cloud['download_slugs'], true ), 'Downloads during Cloud still include covered plugins' );
rw_assert( $handover_cloud['gap'] === false && $handover_cloud['local_config_kept'] === true, 'No entitlement gap and local config is kept' );

$handover_none = RWCC_Entitlement_Handover::snapshot(
	array(
		'plan'               => 'growth',
		'cloud_status'       => 'expired',
		'cloud_paid_through' => time() - 10,
		'now'                => time(),
		'downgrade'          => $none_ok['payload'],
	)
);
rw_assert( $handover_none['phase'] === RWCC_Entitlement_Handover::PHASE_CLOUD_ENDED_NONE, 'Cloud ended with none selected returns free Geo Core only' );

$dl_cloud = RWCC_Entitlement_Handover::downloads( 'growth', true, true );
rw_assert( $dl_cloud['source'] === 'decision_cloud' && $dl_cloud['gap'] === false, 'Covered ZIPs download from Cloud while Cloud can download' );
$dl_gap = RWCC_Entitlement_Handover::downloads( 'growth', false, true );
rw_assert( $dl_gap['gap'] === true, 'Superseded individual without Cloud download is an access gap' );

$reuse_deny = RWCC_Licence_Reuse::decide(
	array(
		'cloud_key'      => 'RW-CLOUD',
		'historical_key' => 'RW-PRO',
		'slug'           => 'reactwoo-geocore-pro',
		'selected'       => true,
		'cloud_ended'    => false,
		'cloud_package'  => true,
	)
);
rw_assert( $reuse_deny['action'] === RWCC_Licence_Reuse::ACTION_DENY, 'Cloud key is not treated as an individual licence while Cloud is active' );
$reuse_ok = RWCC_Licence_Reuse::decide(
	array(
		'cloud_key'      => 'RW-CLOUD',
		'historical_key' => 'RW-PRO',
		'slug'           => 'reactwoo-geocore-pro',
		'selected'       => true,
		'cloud_ended'    => true,
		'cloud_package'  => true,
	)
);
rw_assert( $reuse_ok['action'] === RWCC_Licence_Reuse::ACTION_REUSE && $reuse_ok['use_key'] === 'RW-PRO', 'Historical individual key is reused after Cloud ends' );
$reuse_none = RWCC_Licence_Reuse::decide(
	array(
		'cloud_key'     => 'RW-CLOUD',
		'slug'          => 'reactwoo-geocore-pro',
		'selected'      => false,
		'cloud_ended'   => true,
		'cloud_package' => true,
	)
);
rw_assert( $reuse_none['action'] === RWCC_Licence_Reuse::ACTION_NONE, 'No-plugin continuation does not mint or reuse a paid key' );

$overlap_credit = RWCC_Overlap::quote_credit(
	array(
		array(
			'id'           => 11,
			'covered'      => true,
			'status'       => 'active',
			'currency'     => 'GBP',
			'period_start' => time() - 10,
			'period_end'   => time() + 100000,
			'amount_paid'  => 40,
		),
	)
);
rw_assert( $overlap_credit['refund'] === false && ! empty( $overlap_credit['requires_finance'] ), 'State 6 overlap quotes credit but does not auto-refund' );
rw_assert( (float) $overlap_credit['applied_credit'] > 0, 'Overlap credit audit records remaining-term amount' );

$checkout_src = file_get_contents( $dir . 'class-rwcc-checkout-credit.php' );
rw_assert( strpos( $checkout_src, 'non-taxable' ) !== false, 'Interim upgrade credit is documented as non-taxable until §20' );
rw_assert( strpos( $checkout_src, "add_fee( self::text( 'Upgrade credit' ), \$fee, false )" ) !== false, 'Interim upgrade credit fee is non-taxable' );

// PLAN.md §17 executable matrix (named checks; remaining Woo E2E is staging).
rw_assert( class_exists( 'RWCC_Entitlement_Handover' ) && class_exists( 'RWCC_Licence_Reuse' ), '§17 handover and licence-reuse classes exist' );
rw_assert( $handover_cloud['gap'] === false && $handover_fail['gap'] === false && $handover_none['gap'] === false, '§17 no entitlement gap on upgrade, failed activation, or none-selected downgrade' );
rw_assert( $reuse_ok['action'] === RWCC_Licence_Reuse::ACTION_REUSE && $reuse_none['action'] === RWCC_Licence_Reuse::ACTION_NONE, '§17 historical reuse vs none after Cloud ends' );
rw_assert( $mismatch['reason'] === 'currency_mismatch', '§17 tax/currency: mismatched currency is not converted' );

rw_assert( $handover_fail['local_config_kept'] === true, 'Existing plugin configuration is kept after failed activation' );
rw_assert( $handover_none['local_config_kept'] === true, 'Existing plugin configuration is kept after downgrade to none' );
rw_assert( ReactWoo_Plugin_Download_Service::should_hide_downloads( $superseded_ind ), '§17 downloads during Cloud come from Cloud, not superseded individuals' );

$merge_current = array(
	'webhook_secret'  => 'keep-me',
	'handoff_secret'  => 'keep-handoff',
	'product_starter' => '3172,3173',
);
$merge_fill = array(
	'webhook_secret'         => 'attacker',
	'product_starter'        => '9999',
	'product_growth'         => '3174,3175',
	'product_geocore_pro'    => '2294',
	'allow_http_local'       => true,
);
$merged_settings = RWCC_Settings::merge_empty( $merge_current, $merge_fill );
rw_assert( $merged_settings['webhook_secret'] === 'keep-me', 'merge_empty never copies secrets from the fill map' );
rw_assert( $merged_settings['product_starter'] === '3172,3173', 'merge_empty does not overwrite a filled product map' );
rw_assert( $merged_settings['product_growth'] === '3174,3175', 'merge_empty fills an empty product map key' );
rw_assert( empty( $merged_settings['allow_http_local'] ), 'merge_empty never enables allow_http_local' );
rw_assert( RWCC_Settings::catalogue_gaps( $merged_settings ) === array( 'product_decision_cloud', 'product_scale', 'product_geo_commerce', 'product_geo_optimise' ), 'catalogue_gaps lists remaining empty product keys' );

if ( $failures > 0 ) {
	echo "\n{$failures} assertion(s) failed\n";
	exit( 1 );
}
echo "\nAll ReactWoo Commerce Bridge tests passed\n";

