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
		unset( $type, $zone );
		return '2026-09-14T12:00:00';
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

foreach ( array( 'checkout', 'upgrade', 'subscription', 'invoices', 'payment-method' ) as $action ) {
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
rw_assert( strpos( $account_src, 'render_open_cloud' ) !== false, 'Returning login is offered on the dashboard without requiring a Cloud subscription' );
$license_ui = dirname( __DIR__ ) . '/includes';
rw_assert( is_dir( $license_ui ), 'Existing API Manager licence includes remain in place' );

if ( $failures > 0 ) {
	echo "\n{$failures} assertion(s) failed\n";
	exit( 1 );
}
echo "\nAll ReactWoo Commerce Bridge tests passed\n";

