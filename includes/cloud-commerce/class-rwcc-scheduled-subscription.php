<?php
/**
 * Native Woo pending subscriptions at Cloud end (PLAN.md §8 / §20.4).
 *
 * Confirmed downgrade rows are materialized as pending WooCommerce Subscriptions
 * with start_date = Cloud paid-through. Nothing is charged at confirm time.
 * Tests inject a creator so this file never depends on live WCS.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Scheduled_Subscription {

	const META_FROM_CLOUD = '_rwcc_from_cloud_downgrade';
	const META_CHARGE_NOW = '_rwcc_charge_now';

	/**
	 * WooCommerce Subscriptions rejects ISO-8601 (`gmdate( 'c' )`).
	 *
	 * @param string $value ISO-8601 or MySQL datetime.
	 * @return string MySQL UTC datetime or empty.
	 */
	public static function woo_start_date( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}
		$ts = strtotime( $value );
		if ( ! $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Create pending individual subscriptions from a confirmed downgrade payload.
	 *
	 * @param array         $payload Confirmed RWCC_Downgrade payload.
	 * @param array         $context customer_id, cloud_subscription_id.
	 * @param callable|null $creator fn(array $spec): array{ok:bool,subscription_id?:string,charged?:bool,error?:string}.
	 * @return array Updated payload.
	 */
	public static function materialize( array $payload, array $context, $creator = null ) {
		if ( ! empty( $payload['none_selected'] ) || empty( $payload['planned_subscriptions'] ) || ! is_array( $payload['planned_subscriptions'] ) ) {
			$payload['materialized'] = true;
			return $payload;
		}

		$creator = is_callable( $creator ) ? $creator : array( __CLASS__, 'default_creator' );
		$customer_id = isset( $context['customer_id'] ) ? (int) $context['customer_id'] : 0;
		$cloud_id    = isset( $context['cloud_subscription_id'] ) ? (string) $context['cloud_subscription_id'] : '';
		$planned     = array();
		$created     = array();

		foreach ( $payload['planned_subscriptions'] as $row ) {
			$row = is_array( $row ) ? $row : array();
			if ( ! empty( $row['subscription_id'] ) ) {
				$planned[] = $row;
				$created[] = (string) $row['subscription_id'];
				continue;
			}
			$spec = array(
				'slug'                   => isset( $row['slug'] ) ? (string) $row['slug'] : '',
				'product_id'             => isset( $row['product_id'] ) ? (string) $row['product_id'] : '',
				'start_date'             => isset( $row['start_date'] ) ? (string) $row['start_date'] : ( isset( $payload['effective_at'] ) ? (string) $payload['effective_at'] : '' ),
				'charge_now'             => ! empty( $row['charge_now'] ) || ! empty( $payload['charges_now'] ),
				'price'                  => isset( $row['price'] ) ? (string) $row['price'] : '0.00',
				'customer_id'            => $customer_id,
				'cloud_subscription_id'  => $cloud_id,
			);
			$result = call_user_func( $creator, $spec );
			if ( ! is_array( $result ) ) {
				$result = array( 'ok' => false, 'error' => 'invalid_creator_result', 'charged' => false );
			}
			if ( ! empty( $result['charged'] ) ) {
				$row['error']  = 'charge_now_forbidden';
				$row['status'] = 'blocked';
				$planned[]     = $row;
				continue;
			}
			if ( empty( $result['ok'] ) ) {
				$row['error']  = isset( $result['error'] ) ? (string) $result['error'] : 'create_failed';
				$row['status'] = 'pending';
				$planned[]     = $row;
				continue;
			}
			$row['subscription_id'] = isset( $result['subscription_id'] ) ? (string) $result['subscription_id'] : '';
			$row['status']          = 'scheduled';
			$row['charge_now']      = false;
			$row['error']           = '';
			$planned[]              = $row;
			if ( $row['subscription_id'] !== '' ) {
				$created[] = $row['subscription_id'];
			}
		}

		$payload['planned_subscriptions'] = $planned;
		$payload['created_subscription_ids'] = $created;
		$payload['materialized'] = true;
		$payload['charges_now']  = false;
		return $payload;
	}

	/**
	 * Cancel pending individuals created for a Cloud downgrade (PLAN.md state 13).
	 *
	 * @param array         $payload  Downgrade payload (uses planned or cancelled_planned).
	 * @param callable|null $canceler fn(string $subscription_id, array $row): array{ok:bool}.
	 * @return array
	 */
	public static function cancel_created( array $payload, $canceler = null ) {
		$canceler = is_callable( $canceler ) ? $canceler : array( __CLASS__, 'default_canceler' );
		$rows     = array();
		if ( ! empty( $payload['cancelled_planned'] ) && is_array( $payload['cancelled_planned'] ) ) {
			$rows = $payload['cancelled_planned'];
		} elseif ( ! empty( $payload['planned_subscriptions'] ) && is_array( $payload['planned_subscriptions'] ) ) {
			$rows = $payload['planned_subscriptions'];
		}
		$cancelled_ids = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['subscription_id'] ) ) {
				continue;
			}
			$id = (string) $row['subscription_id'];
			$out = call_user_func( $canceler, $id, $row );
			if ( is_array( $out ) && ! empty( $out['ok'] ) ) {
				$cancelled_ids[] = $id;
			}
		}
		$payload['cancelled_subscription_ids'] = $cancelled_ids;
		return $payload;
	}

	/**
	 * Native WooCommerce Subscriptions creator. Never processes payment.
	 *
	 * @param array $spec Create spec.
	 * @return array
	 */
	public static function default_creator( array $spec ) {
		if ( ! empty( $spec['charge_now'] ) ) {
			return array(
				'ok'      => false,
				'error'   => 'charge_now_forbidden',
				'charged' => false,
			);
		}
		$product_id = isset( $spec['product_id'] ) ? (int) $spec['product_id'] : 0;
		if ( $product_id <= 0 ) {
			return array(
				'ok'      => false,
				'error'   => 'missing_product_id',
				'charged' => false,
			);
		}
		if ( ! function_exists( 'wcs_create_subscription' ) ) {
			return array(
				'ok'      => false,
				'error'   => 'woo_subscriptions_unavailable',
				'charged' => false,
			);
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return array(
				'ok'      => false,
				'error'   => 'product_not_found',
				'charged' => false,
			);
		}

		$period   = 'month';
		$interval = 1;
		if ( method_exists( $product, 'get_billing_period' ) ) {
			$got = (string) $product->get_billing_period();
			if ( $got !== '' ) {
				$period = $got;
			}
		}
		if ( method_exists( $product, 'get_billing_interval' ) ) {
			$got = (int) $product->get_billing_interval();
			if ( $got > 0 ) {
				$interval = $got;
			}
		}

		$args = array(
			'status'           => 'pending',
			'customer_id'      => isset( $spec['customer_id'] ) ? (int) $spec['customer_id'] : 0,
			'billing_period'   => $period,
			'billing_interval' => $interval,
			'start_date'       => self::woo_start_date( isset( $spec['start_date'] ) ? (string) $spec['start_date'] : '' ),
		);
		$created = wcs_create_subscription( $args );
		if ( is_wp_error( $created ) ) {
			return array(
				'ok'      => false,
				'error'   => $created->get_error_code(),
				'charged' => false,
			);
		}
		if ( ! is_object( $created ) || ! method_exists( $created, 'get_id' ) ) {
			return array(
				'ok'      => false,
				'error'   => 'create_failed',
				'charged' => false,
			);
		}

		if ( method_exists( $created, 'add_product' ) ) {
			$created->add_product( $product, 1 );
		}
		if ( method_exists( $created, 'update_meta_data' ) ) {
			$created->update_meta_data( self::META_FROM_CLOUD, isset( $spec['cloud_subscription_id'] ) ? (string) $spec['cloud_subscription_id'] : '' );
			$created->update_meta_data( self::META_CHARGE_NOW, '0' );
		}
		if ( method_exists( $created, 'calculate_totals' ) ) {
			$created->calculate_totals();
		}
		if ( method_exists( $created, 'save' ) ) {
			$created->save();
		}

		return array(
			'ok'              => true,
			'subscription_id' => (string) $created->get_id(),
			'charged'         => false,
		);
	}

	/**
	 * Cancel a pending individual created by this module only.
	 *
	 * @param string $subscription_id Woo subscription id.
	 * @param array  $row             Planned row.
	 * @return array
	 */
	public static function default_canceler( $subscription_id, array $row ) {
		unset( $row );
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return array( 'ok' => false, 'error' => 'woo_subscriptions_unavailable' );
		}
		$subscription = wcs_get_subscription( (int) $subscription_id );
		if ( ! $subscription || ! method_exists( $subscription, 'get_meta' ) ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		$from = (string) $subscription->get_meta( self::META_FROM_CLOUD, true );
		if ( $from === '' ) {
			return array( 'ok' => false, 'error' => 'not_cloud_downgrade' );
		}
		if ( method_exists( $subscription, 'update_status' ) ) {
			$subscription->update_status( 'cancelled', 'Decision Cloud reactivation cancelled this scheduled individual subscription.' );
		}
		return array( 'ok' => true );
	}
}
