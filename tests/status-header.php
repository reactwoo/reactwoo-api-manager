<?php
/**
 * 2.1.23: update_license_status() sends X-RW-Master-Key, the same header
 * provision and sync already use. Fail if that header is missing.
 * Runs in its own process (other suites stub the API client).
 */

$rw_status_standalone = ! function_exists( 'rw_assert' );
if ( $rw_status_standalone ) {
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

$GLOBALS['rw_status_http'] = array();
$GLOBALS['rw_status_next'] = array();
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['rw_status_http'][] = array( 'url' => $url, 'args' => $args );
		$next = array_shift( $GLOBALS['rw_status_next'] );
		return $next ? $next : array( 'code' => 200, 'body' => '{"success":true}' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return isset( $r['code'] ) ? $r['code'] : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return isset( $r['body'] ) ? $r['body'] : '';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-license-server-api.php';

$GLOBALS['rw_status_next'][] = array( 'code' => 200, 'body' => json_encode( array( 'success' => true ) ) );
$api    = new ReactWoo_License_Server_API();
$result = $api->update_license_status( 666, 'refunded' );
$req    = $GLOBALS['rw_status_http'][0];
$body   = json_decode( isset( $req['args']['body'] ) ? $req['args']['body'] : '', true );
$headers = isset( $req['args']['headers'] ) && is_array( $req['args']['headers'] ) ? $req['args']['headers'] : array();

rw_assert( true === $result, 'update_license_status returns true on HTTP 200 success' );
rw_assert( isset( $req['args']['method'] ) && 'PUT' === $req['args']['method'], 'status update is PUT' );
rw_assert( $req['url'] === 'https://licence.example/api/licenses/666/status', 'status update hits api/licenses/{id}/status' );
rw_assert( isset( $headers['X-RW-Master-Key'] ) && $headers['X-RW-Master-Key'] === ReactWoo_API_Manager::$key, 'status update sends X-RW-Master-Key, the same header as provision and sync' );
rw_assert( is_array( $body ) && isset( $body['status'] ) && 'refunded' === $body['status'], 'status update body includes the new status' );

// Cancel uses the same header.
$GLOBALS['rw_status_next'][] = array( 'code' => 200, 'body' => json_encode( array( 'success' => true ) ) );
$api->update_license_status( 667, 'cancelled' );
$req2 = end( $GLOBALS['rw_status_http'] );
$h2   = isset( $req2['args']['headers'] ) && is_array( $req2['args']['headers'] ) ? $req2['args']['headers'] : array();
rw_assert( isset( $h2['X-RW-Master-Key'] ) && $h2['X-RW-Master-Key'] === ReactWoo_API_Manager::$key, 'cancel status update also sends X-RW-Master-Key' );

// No key: no header, request still goes out.
ReactWoo_API_Manager::$key = '';
$no_key                    = new ReactWoo_License_Server_API();
$GLOBALS['rw_status_next'][] = array( 'code' => 401, 'body' => json_encode( array( 'error' => 'Unauthorized' ) ) );
$err                       = $no_key->update_license_status( 668, 'refunded' );
$last                      = end( $GLOBALS['rw_status_http'] );
$last_headers              = isset( $last['args']['headers'] ) && is_array( $last['args']['headers'] ) ? $last['args']['headers'] : array();
rw_assert( ! isset( $last_headers['X-RW-Master-Key'] ) || '' === $last_headers['X-RW-Master-Key'], 'no X-RW-Master-Key header when no key is set' );
rw_assert( is_wp_error( $err ) && 401 === (int) ( is_array( $err->get_error_data() ) ? $err->get_error_data()['status'] : 0 ), '401 is returned as a WP_Error' );
ReactWoo_API_Manager::$key = 'test-store-key-abc123';

if ( $rw_status_standalone ) {
	exit( $failures > 0 ? 1 : 0 );
}
