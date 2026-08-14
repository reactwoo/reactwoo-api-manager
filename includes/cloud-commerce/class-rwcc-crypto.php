<?php
/**
 * HMAC, hashing and identifiers for the ReactWoo Commerce Bridge.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Crypto {

	/**
	 * WooCommerce webhook signature: base64( HMAC-SHA256( raw body, secret ) ).
	 *
	 * @param string $raw_body Raw JSON body.
	 * @param string $secret   Shared webhook secret.
	 * @return string
	 */
	public static function sign_woocommerce_body( $raw_body, $secret ) {
		return base64_encode( hash_hmac( 'sha256', (string) $raw_body, (string) $secret, true ) );
	}

	/**
	 * Timing-safe compare for signatures / tokens.
	 *
	 * @param string $known Known value.
	 * @param string $given User-supplied value.
	 * @return bool
	 */
	public static function equals( $known, $given ) {
		$known = (string) $known;
		$given = (string) $given;
		if ( function_exists( 'hash_equals' ) ) {
			return hash_equals( $known, $given );
		}
		if ( strlen( $known ) !== strlen( $given ) ) {
			return false;
		}
		$diff = 0;
		$len  = strlen( $known );
		for ( $i = 0; $i < $len; $i++ ) {
			$diff |= ord( $known[ $i ] ) ^ ord( $given[ $i ] );
		}
		return 0 === $diff;
	}

	/**
	 * RFC 4122 UUID v4.
	 *
	 * @return string
	 */
	public static function uuid() {
		$bytes = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}

	/**
	 * Unhashed single-use claim token (hex).
	 *
	 * @return string
	 */
	public static function claim_token() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Store-only hash of an activation claim. The plaintext token is never persisted.
	 *
	 * @param string $token  Plaintext claim.
	 * @param string $secret HMAC secret.
	 * @return string
	 */
	public static function hash_claim( $token, $secret ) {
		return hash_hmac( 'sha256', (string) $token, (string) $secret );
	}

	/**
	 * Canonical Decision Cloud handoff string (must match storeHandoff.js).
	 *
	 * @param array $params Keys: action, org, plan, exp, return.
	 * @return string
	 */
	public static function handoff_canonical( array $params ) {
		$keys = array( 'action', 'org', 'plan', 'exp', 'return' );
		$lines = array();
		foreach ( $keys as $key ) {
			$value    = isset( $params[ $key ] ) ? (string) $params[ $key ] : '';
			$lines[]  = $key . '=' . $value;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Hex HMAC of a Cloud → store handoff.
	 *
	 * @param array  $params Canonical params.
	 * @param string $secret Handoff secret.
	 * @return string
	 */
	public static function sign_handoff( array $params, $secret ) {
		return hash_hmac( 'sha256', self::handoff_canonical( $params ), (string) $secret );
	}

	/**
	 * @param array  $params    Canonical params.
	 * @param string $signature Hex signature.
	 * @param string $secret    Handoff secret.
	 * @return bool
	 */
	public static function verify_handoff( array $params, $signature, $secret ) {
		if ( '' === (string) $secret || '' === (string) $signature ) {
			return false;
		}
		return self::equals( self::sign_handoff( $params, $secret ), (string) $signature );
	}

	/**
	 * Stable provisioning key so retries never mint a second organisation.
	 *
	 * @param int $blog_id         Site id (multisite-safe).
	 * @param int $customer_id     WooCommerce customer.
	 * @param int $subscription_id Subscription id.
	 * @return string
	 */
	public static function provisioning_id( $blog_id, $customer_id, $subscription_id ) {
		$material = (int) $blog_id . ':' . (int) $customer_id . ':' . (int) $subscription_id;
		return 'rwcc_' . hash( 'sha256', $material );
	}
}
