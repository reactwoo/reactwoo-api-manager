<?php
/**
 * WooCommerce-shaped subscription payloads for Decision Cloud.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Payload {

	const EVENTS = array(
		'activation',
		'renewal',
		'plan_switch',
		'payment_failure',
		'cancellation',
		'expiry',
		'refund',
	);

	const TOPICS = array(
		'activation'      => 'subscription.created',
		'renewal'         => 'subscription.updated',
		'plan_switch'     => 'subscription.updated',
		'payment_failure' => 'subscription.updated',
		'cancellation'    => 'subscription.updated',
		'expiry'          => 'subscription.updated',
		'refund'          => 'order.refunded',
	);

	/**
	 * @param string $event Lifecycle event.
	 * @return string
	 */
	public static function topic_for_event( $event ) {
		$event = (string) $event;
		return isset( self::TOPICS[ $event ] ) ? self::TOPICS[ $event ] : 'subscription.updated';
	}

	/**
	 * Build the JSON object Cloud already parses (id, status, customer_id, line_items, meta_data).
	 *
	 * @param array $input Event fields.
	 * @return array
	 */
	public static function build( array $input ) {
		$event = (string) ( $input['event'] ?? '' );
		if ( ! in_array( $event, self::EVENTS, true ) ) {
			$event = 'activation';
		}

		$delivery_id = isset( $input['delivery_id'] ) && $input['delivery_id'] !== ''
			? (string) $input['delivery_id']
			: RWCC_Crypto::uuid();
		$timestamp = isset( $input['timestamp'] ) ? (int) $input['timestamp'] : time();
		$window    = isset( $input['replay_window_sec'] ) ? (int) $input['replay_window_sec'] : 300;

		$status = strtolower( (string) ( $input['status'] ?? 'active' ) );
		$plan   = RWCC_Plan_Map::normalize_plan( $input['plan'] ?? '' );
		$org    = isset( $input['org_id'] ) ? (string) $input['org_id'] : '';
		$product_id = isset( $input['product_id'] ) ? (string) $input['product_id'] : '';
		$variation_id = isset( $input['variation_id'] ) ? (int) $input['variation_id'] : 0;

		$meta = array(
			array( 'key' => 'rw_cloud_org', 'value' => $org ),
			array( 'key' => 'rw_cloud_plan', 'value' => $plan ),
			array( 'key' => '_reactwoo_cloud_org_id', 'value' => $org ),
			array( 'key' => '_reactwoo_cloud_plan', 'value' => $plan ),
			array( 'key' => '_reactwoo_cloud_product_id', 'value' => $product_id ),
			array( 'key' => 'rw_cloud_provisioning_id', 'value' => (string) ( $input['provisioning_id'] ?? '' ) ),
			array( 'key' => 'rw_cloud_identity_user', 'value' => (string) ( $input['identity_user'] ?? '' ) ),
			array( 'key' => 'rw_cloud_identity_email', 'value' => (string) ( $input['identity_email'] ?? '' ) ),
			array( 'key' => 'rw_cloud_claim_hash', 'value' => (string) ( $input['claim_hash'] ?? '' ) ),
			array( 'key' => 'rw_cloud_claim_expires', 'value' => (string) ( $input['claim_expires'] ?? '' ) ),
		);

		$payload = array(
			'id'                    => (int) ( $input['subscription_id'] ?? 0 ),
			'subscription_id'       => (int) ( $input['subscription_id'] ?? 0 ),
			'status'                => $status,
			'customer_id'           => (int) ( $input['customer_id'] ?? 0 ),
			'parent_id'             => (int) ( $input['order_id'] ?? 0 ),
			'next_payment_date_gmt' => isset( $input['next_payment_date_gmt'] ) ? (string) $input['next_payment_date_gmt'] : null,
			'line_items'            => array(
				array(
					'product_id'   => (int) $product_id,
					'variation_id' => $variation_id,
				),
			),
			'meta_data'             => $meta,
			'rwcc'                  => array(
				'event'             => $event,
				'delivery_id'       => $delivery_id,
				'timestamp'         => $timestamp,
				'replay_window_sec' => $window,
			),
		);

		if ( function_exists( 'apply_filters' ) ) {
			$payload = apply_filters( 'rwcc_webhook_payload', $payload, $event, $input );
		}

		return $payload;
	}

	/**
	 * Encode payload as raw JSON (stable enough for HMAC).
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	public static function encode( array $payload ) {
		$flags = JSON_UNESCAPED_SLASHES;
		if ( defined( 'JSON_UNESCAPED_UNICODE' ) ) {
			$flags |= JSON_UNESCAPED_UNICODE;
		}
		$json = wp_json_encode( $payload, $flags );
		if ( ! is_string( $json ) ) {
			$json = json_encode( $payload );
		}
		return is_string( $json ) ? $json : '{}';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 * @param int   $flags Flags.
	 * @return string|false
	 */
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}
