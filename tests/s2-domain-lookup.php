<?php
/**
 * 2.1.22 (RG-81 S2): the domain lookup sends the store key in a header, never
 * in the URL, and accepts masked keys. The licence sync matches by licence id
 * and never saves a masked key. Uses fake HTTP and subscription stubs.
 */

// Runs in its own PHP process (staging-fixes.php declares a fake API class).
$rw_s2_standalone = ! function_exists( 'rw_assert' );
if ( $rw_s2_standalone ) {
	require_once __DIR__ . '/bootstrap.php';
	$failures = 0;
	function rw_assert( $condition, $message ) {
		global $failures;
		echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
		if ( ! $condition ) {
			++$failures;
		}
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'ReactWoo_API_Manager', false ) ) {
	class ReactWoo_API_Manager {
		public static $key = 'test-store-key-abc123';
		public static function get_license_server_url() {
			return 'https://licence.example';
		}
		public static function get_api_key() {
			return self::$key;
		}
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $s ) {
		return rtrim( $s, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) {
		return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args );
	}
}
$GLOBALS['rw_s2_http'] = array();
$GLOBALS['rw_s2_next'] = array();
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['rw_s2_http'][] = array( 'url' => $url, 'args' => $args );
		return array_shift( $GLOBALS['rw_s2_next'] );
	}
	function wp_remote_retrieve_response_code( $r ) {
		return $r['code'];
	}
	function wp_remote_retrieve_body( $r ) {
		return $r['body'];
	}
}
if ( ! function_exists( 'error_log_stub_s2' ) ) {
	function error_log_stub_s2() {}
}
require_once dirname( __DIR__ ) . '/includes/class-license-server-api.php';
require_once dirname( __DIR__ ) . '/includes/class-license-sync.php';

$s2_full_key = 'RWGC-AAAA-BBBB-9Z9Z';

// Domain lookup: header, not URL; masked rows normalised.
$GLOBALS['rw_s2_next'][] = array(
	'code' => 200,
	'body' => json_encode( array( 'success' => true, 'licenses' => array(
		array( 'id' => 77, 'domain' => 'x.example', 'package_id' => 3, 'package_type' => 'geo-commerce', 'status' => 'active', 'license_key' => '****9Z9Z', 'license_key_last4' => '9Z9Z' ),
	) ) ),
);
$s2_api  = new ReactWoo_License_Server_API();
$s2_rows = $s2_api->get_licenses_by_domain( 'x.example' );
$s2_req  = $GLOBALS['rw_s2_http'][0];
rw_assert( $s2_req['url'] === 'https://licence.example/api/licenses/x.example', 'S2: domain lookup URL has no api_key' );
rw_assert( isset( $s2_req['args']['headers']['X-RW-Store-Key'] ) && $s2_req['args']['headers']['X-RW-Store-Key'] === ReactWoo_API_Manager::$key, 'S2: domain lookup sends the store key in X-RW-Store-Key' );
rw_assert( is_array( $s2_rows ) && count( $s2_rows ) === 1 && (int) $s2_rows[0]['id'] === 77, 'S2: masked response still returns the licence id' );
rw_assert( ! isset( $s2_rows[0]['license_key'] ), 'S2: a masked key is not returned as license_key' );
rw_assert( $s2_rows[0]['license_key_masked'] === '****9Z9Z' && $s2_rows[0]['license_key_last4'] === '9Z9Z', 'S2: masked key and last 4 kept for display' );

// Old servers (full keys) keep working; refresh tokens are dropped.
$GLOBALS['rw_s2_next'][] = array(
	'code' => 200,
	'body' => json_encode( array( 'success' => true, 'licenses' => array(
		array( 'id' => 78, 'license_key' => $s2_full_key, 'refresh_token' => 'rt-secret' ),
	) ) ),
);
$s2_rows = $s2_api->get_licenses_by_domain( 'y.example' );
rw_assert( $s2_rows[0]['license_key'] === $s2_full_key && ! isset( $s2_rows[0]['refresh_token'] ), 'S2: an old full-key response still works and drops the refresh token' );

// 401 and 404.
$GLOBALS['rw_s2_next'][] = array( 'code' => 401, 'body' => json_encode( array( 'error' => 'Invalid or missing API key' ) ) );
$s2_err = $s2_api->get_licenses_by_domain( 'z.example' );
rw_assert( is_wp_error( $s2_err ) && $s2_err->get_error_code() === 'api_auth_error', 'S2: 401 maps to api_auth_error' );
$GLOBALS['rw_s2_next'][] = array( 'code' => 404, 'body' => '{}' );
rw_assert( $s2_api->get_licenses_by_domain( 'none.example' ) === array(), 'S2: 404 is no licences' );

// No key configured: no header sent.
ReactWoo_API_Manager::$key = '';
$GLOBALS['rw_s2_next'][] = array( 'code' => 404, 'body' => '{}' );
$s2_nokey = new ReactWoo_License_Server_API();
$s2_nokey->get_licenses_by_domain( 'k.example' );
$s2_last = end( $GLOBALS['rw_s2_http'] );
rw_assert( ! isset( $s2_last['args']['headers']['X-RW-Store-Key'] ), 'S2: no header when no key is set' );
ReactWoo_API_Manager::$key = 'test-store-key-abc123';

// List keeps api_key in the query for older servers and adds the header.
$GLOBALS['rw_s2_next'][] = array( 'code' => 200, 'body' => json_encode( array( 'success' => true, 'licenses' => array() ) ) );
$s2_api->get_all_licenses( array( 'domain' => 'x.example' ) );
$s2_last = end( $GLOBALS['rw_s2_http'] );
rw_assert( strpos( $s2_last['url'], 'api_key=' ) !== false && strpos( $s2_last['url'], 'domain=x.example' ) !== false, 'S2: list still sends api_key and the domain filter' );
rw_assert( isset( $s2_last['args']['headers']['X-RW-Store-Key'] ), 'S2: list also sends X-RW-Store-Key' );

// Log redaction.
rw_assert( ReactWoo_License_Server_API::redact_url( 'https://l/api/licenses?api_key=SECRET&domain=a' ) === 'https://l/api/licenses?api_key=[redacted]&domain=a', 'S2: logged URLs redact api_key' );
$s2_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-license-server-api.php' );
rw_assert( strpos( $s2_src, "' | Response: ' . \$response_body" ) === false, 'S2: list failures no longer log the response body' );
rw_assert( ReactWoo_License_Server_API::is_masked_license_key( '****ab12' ) && ReactWoo_License_Server_API::is_masked_license_key( '' ) && ! ReactWoo_License_Server_API::is_masked_license_key( $s2_full_key ), 'S2: masked key detection' );

// Admin domain sync uses the store-key list, not the public lookup.
$s2_view = file_get_contents( dirname( __DIR__ ) . '/admin/views/license-manager.php' );
rw_assert( strpos( $s2_view, "get_all_licenses( array( 'domain' => \$domain ) )" ) !== false && strpos( $s2_view, 'get_licenses_by_domain' ) === false, 'S2: admin domain sync uses the authenticated list' );

// Sync: match by id, never save a masked key.
class RW_S2_Sub {
	public $id;
	public $meta;
	public $saved = 0;
	public function __construct( $id, $meta ) {
		$this->id   = $id;
		$this->meta = $meta;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_meta( $k, $single = true ) {
		return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : '';
	}
	public function update_meta_data( $k, $v ) {
		$this->meta[ $k ] = $v;
	}
	public function save() {
		++$this->saved;
	}
	public function get_parent() {
		return null;
	}
}
$GLOBALS['rw_s2_subs'] = array(
	new RW_S2_Sub( 501, array( '_reactwoo_license_id' => 77, '_reactwoo_license_key' => $s2_full_key ) ),
);
if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
	function wcs_get_subscriptions( $args = array() ) {
		return $GLOBALS['rw_s2_subs'];
	}
}
$s2_res = ReactWoo_License_Sync::match_licenses_to_subscriptions( array(
	array( 'id' => 77, 'domain' => 'x.example', 'license_key' => '****9Z9Z', 'license_key_last4' => '9Z9Z' ),
) );
$s2_sub = $GLOBALS['rw_s2_subs'][0];
rw_assert( $s2_res['matched'] === 1 && $s2_res['matched_details'][0]['match_method'] === 'license_id', 'S2 sync: matched by licence id' );
rw_assert( $s2_sub->meta['_reactwoo_license_key'] === $s2_full_key, 'S2 sync: a masked key never overwrites the saved full key' );
rw_assert( $s2_res['matched_details'][0]['license_key'] === '****9Z9Z', 'S2 sync: results show the masked key only' );
$s2_res = ReactWoo_License_Sync::match_licenses_to_subscriptions( array(
	array( 'id' => 99, 'domain' => 'q.example', 'license_key' => '****QQQQ' ),
) );
rw_assert( $s2_res['unmatched'] === 1 && $s2_res['unmatched_details'][0]['license_key'] === '****QQQQ', 'S2 sync: unmatched masked row stays masked' );

if ( $rw_s2_standalone ) {
	exit( $failures > 0 ? 1 : 0 );
}
