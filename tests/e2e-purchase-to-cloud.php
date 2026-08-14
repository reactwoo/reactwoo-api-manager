<?php
/**
 * Local e2e: purchase → signed webhook → Cloud entitlement → activation claim.
 *
 * Usage: php tests/e2e-purchase-to-cloud.php
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

define( 'RWCC_LOAD_ONLY', true );
require_once __DIR__ . '/cloud-commerce.php';

$order = new RWCC_Test_Order();
$order->items = array( new RWCC_Test_Item( 202 ) );
$sub = new RWCC_Test_Subscription();
$sub->id     = 55;
$sub->items  = $order->items;
$sub->parent = $order;

$settings = new RWCC_Settings(
	array(
		'cloud_origin'      => 'https://cloud.reactwoo.com',
		'webhook_url'       => 'https://cloud.reactwoo.com/api/v1/billing/webhooks/woocommerce',
		'webhook_secret'    => 'whsec_e2e_local',
		'handoff_secret'    => 'handoff_e2e_local',
		'product_growth'    => '202',
		'claim_ttl_sec'     => 1800,
		'replay_window_sec' => 300,
		'allow_http_local'  => true,
	)
);
$plans    = new RWCC_Plan_Map( $settings->product_map() );
$urls     = RWCC_Urls::from_settings( $settings );
$store    = new RWCC_Store( false );
$claims   = $store->claims_service( $settings );
$replay   = $store->replay_service();
$captured = array();
$webhooks = new RWCC_Webhooks(
	$settings,
	$replay,
	function ( $url, $raw, $headers ) use ( &$captured ) {
		$captured = array(
			'url'     => $url,
			'raw'     => $raw,
			'headers' => $headers,
		);
		return array( 'ok' => true, 'status' => 200 );
	}
);
$meta      = new RWCC_Order_Meta( $plans );
$lifecycle = new RWCC_Lifecycle( $settings, $plans, $meta, $claims, $webhooks, $urls );

$result = $lifecycle->activate( $sub, $order );
if ( empty( $result['ok'] ) || empty( $captured['raw'] ) ) {
	fwrite( STDERR, "E2E store activation failed\n" );
	exit( 1 );
}

$payload = json_decode( $captured['raw'], true );
$fixture = array(
	'secret'          => 'whsec_e2e_local',
	'raw'             => $captured['raw'],
	'signature'       => $captured['headers']['X-WC-Webhook-Signature'],
	'topic'           => $captured['headers']['X-WC-Webhook-Topic'],
	'delivery_id'     => $captured['headers']['X-WC-Webhook-Delivery-ID'],
	'payload'         => $payload,
	'activation_url'  => $result['activation_url'],
	'claim_hash'      => $result['claim']['hash'],
	'provisioning_id' => $result['provisioning_id'],
	'reconcile'       => $lifecycle->reconcile_from_subscription( $sub ),
);

$fixture_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rwcc-e2e-webhook.json';
file_put_contents( $fixture_path, json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

echo "STORE purchase complete\n";
echo "  plan: growth\n";
echo "  provisioning_id: {$result['provisioning_id']}\n";
echo "  activation_url: {$result['activation_url']}\n";
echo "  claim stored hashed: {$result['claim']['hash']}\n";
echo "  webhook delivery: {$fixture['delivery_id']}\n";
echo "  fixture: {$fixture_path}\n";

$cloud = getenv( 'REACTWOO_DECISION_CLOUD_DIR' );
if ( ! $cloud ) {
	$cloud = 'C:\\Users\\User\\Local Sites\\wooalisync\\app\\public\\wp-content\\plugins\\reactwoo-decision-cloud';
}
$apply = __DIR__ . DIRECTORY_SEPARATOR . 'e2e-apply-webhook.js';
if ( ! is_dir( $cloud ) || ! is_file( $apply ) ) {
	echo "CLOUD apply skipped (set REACTWOO_DECISION_CLOUD_DIR)\n";
	exit( 0 );
}

$cmd = 'node ' . escapeshellarg( $apply ) . ' ' . escapeshellarg( $fixture_path ) . ' ' . escapeshellarg( $cloud );
passthru( $cmd, $code );
exit( (int) $code );
