<?php
/**
 * Immutable ReactWoo identity subject for Decision Cloud matching.
 *
 * Issuer: https://reactwoo.com
 * Subject: UUID stored once on the WordPress user. Email is an attribute only.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Identity {

	const ISSUER     = 'https://reactwoo.com';
	const META_KEY   = '_rw_cloud_identity_subject';

	/**
	 * @var array<int,string>
	 */
	private static $subjects = array();

	/**
	 * @return string
	 */
	public static function issuer() {
		return self::ISSUER;
	}

	/**
	 * Return the immutable subject for a WordPress / WooCommerce user id.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function subject_for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		if ( isset( self::$subjects[ $user_id ] ) && self::$subjects[ $user_id ] !== '' ) {
			return self::$subjects[ $user_id ];
		}

		if ( function_exists( 'get_user_meta' ) ) {
			$existing = (string) get_user_meta( $user_id, self::META_KEY, true );
			if ( $existing !== '' ) {
				self::$subjects[ $user_id ] = $existing;
				return $existing;
			}
			$created = RWCC_Crypto::uuid();
			if ( function_exists( 'add_user_meta' ) ) {
				add_user_meta( $user_id, self::META_KEY, $created, true );
				$existing = (string) get_user_meta( $user_id, self::META_KEY, true );
				$created  = $existing !== '' ? $existing : $created;
			}
			self::$subjects[ $user_id ] = $created;
			return $created;
		}

		$created = RWCC_Crypto::uuid();
		self::$subjects[ $user_id ] = $created;
		return $created;
	}

	/**
	 * @param array  $params Keys: purpose, issuer, subject, exp, nonce, hash.
	 * @param string $secret HMAC secret.
	 * @return string
	 */
	public static function sign_claim( array $params, $secret ) {
		$keys  = array( 'purpose', 'issuer', 'subject', 'exp', 'nonce', 'hash' );
		$lines = array();
		foreach ( $keys as $key ) {
			$lines[] = $key . '=' . ( isset( $params[ $key ] ) ? (string) $params[ $key ] : '' );
		}
		return hash_hmac( 'sha256', implode( "\n", $lines ), (string) $secret );
	}

	/**
	 * Build a signed identity-claim registration body. Raw token is not included.
	 *
	 * @param array  $context purpose, subject, hash, email, organisation_id, intended_role, customer_id, order_id, subscription_id, ttl, secret.
	 * @return array
	 */
	public static function registration_body( array $context ) {
		$now     = isset( $context['now'] ) ? (int) $context['now'] : time();
		$ttl     = isset( $context['ttl'] ) ? (int) $context['ttl'] : 900;
		$purpose = isset( $context['purpose'] ) ? (string) $context['purpose'] : 'login';
		$params  = array(
			'purpose' => $purpose,
			'issuer'  => self::issuer(),
			'subject' => (string) ( $context['subject'] ?? '' ),
			'exp'     => (string) ( $now + $ttl ),
			'nonce'   => isset( $context['nonce'] ) ? (string) $context['nonce'] : RWCC_Crypto::uuid(),
			'hash'    => (string) ( $context['hash'] ?? '' ),
		);
		return array_merge(
			$params,
			array(
				'email'            => (string) ( $context['email'] ?? '' ),
				'organisation_id'  => (string) ( $context['organisation_id'] ?? '' ),
				'intended_role'    => (string) ( $context['intended_role'] ?? 'owner' ),
				'customer_id'      => (string) ( $context['customer_id'] ?? '' ),
				'order_id'         => (string) ( $context['order_id'] ?? '' ),
				'subscription_id'  => (string) ( $context['subscription_id'] ?? '' ),
				'signature'        => self::sign_claim( $params, (string) ( $context['secret'] ?? '' ) ),
			)
		);
	}
}
