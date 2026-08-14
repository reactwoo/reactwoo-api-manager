<?php
/**
 * Least-privilege subscription snapshot for Decision Cloud reconciliation.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Reconcile {

	/**
	 * Build a Cloud-safe snapshot. No payment tokens, no WC REST keys.
	 *
	 * @param array $subscription Normalized subscription.
	 * @return array
	 */
	public static function snapshot( array $subscription ) {
		$plan   = RWCC_Plan_Map::normalize_plan( $subscription['plan'] ?? '' );
		$status = strtolower( (string) ( $subscription['status'] ?? '' ) );
		return array(
			'subscription_id'       => (int) ( $subscription['subscription_id'] ?? 0 ),
			'customer_id'           => (int) ( $subscription['customer_id'] ?? 0 ),
			'order_id'              => (int) ( $subscription['order_id'] ?? 0 ),
			'status'                => $status,
			'plan'                  => $plan,
			'org_id'                => (string) ( $subscription['org_id'] ?? '' ),
			'provisioning_id'       => (string) ( $subscription['provisioning_id'] ?? '' ),
			'product_id'            => (string) ( $subscription['product_id'] ?? '' ),
			'variation_id'          => (int) ( $subscription['variation_id'] ?? 0 ),
			'next_payment_date_gmt' => isset( $subscription['next_payment_date_gmt'] ) ? (string) $subscription['next_payment_date_gmt'] : null,
			'identity'              => array(
				'user_id' => (int) ( $subscription['identity_user'] ?? 0 ),
				'email'   => (string) ( $subscription['identity_email'] ?? '' ),
			),
			'claim'                 => array(
				'hash_present' => ! empty( $subscription['claim_hash'] ),
				'expires_at'   => isset( $subscription['claim_expires'] ) ? (int) $subscription['claim_expires'] : 0,
				'used'         => ! empty( $subscription['claim_used'] ),
			),
		);
	}

	/**
	 * Timing-safe Bearer check.
	 *
	 * @param string $header   Authorization header.
	 * @param string $expected Shared reconcile token.
	 * @return bool
	 */
	public static function authorized( $header, $expected ) {
		$expected = (string) $expected;
		if ( $expected === '' ) {
			return false;
		}
		$header = trim( (string) $header );
		if ( stripos( $header, 'Bearer ' ) !== 0 ) {
			return false;
		}
		$token = trim( substr( $header, 7 ) );
		return RWCC_Crypto::equals( $expected, $token );
	}
}
