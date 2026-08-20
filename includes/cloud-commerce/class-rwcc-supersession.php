<?php
/**
 * Mark covered individual subscriptions superseded after Cloud activation.
 *
 * Woo status changes (pending-cancel vs on-hold) remain a PLAN.md §20 decision.
 * This class stops renewals by meta + clearing next_payment, and is idempotent.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Supersession {

	const META_SUPERSEDED = '_rwcc_superseded';

	/**
	 * @param object $subscription Subscription-like object.
	 * @return bool
	 */
	public static function is_superseded( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_meta' ) ) {
			return false;
		}
		$flag = $subscription->get_meta( self::META_SUPERSEDED, true );
		if ( $flag === '1' || $flag === 1 || $flag === true ) {
			return true;
		}
		$record = $subscription->get_meta( RWCC_Transition::META_KEY, true );
		return is_array( $record ) && isset( $record['transition_status'] ) && $record['transition_status'] === RWCC_Transition::STATUS_COMMITTED;
	}

	/**
	 * Covered individuals must not generate another renewal charge.
	 *
	 * @param object $subscription Subscription.
	 * @return bool
	 */
	public static function block_renewal( $subscription ) {
		return self::is_superseded( $subscription );
	}

	/**
	 * After Cloud activation succeeds, supersede covered individuals only.
	 * Activation failure leaves candidates unchanged.
	 *
	 * @param object        $cloud_subscription Newly active Cloud subscription.
	 * @param object[]      $candidates         Customer's other subscriptions.
	 * @param RWCC_Settings $settings           Settings.
	 * @param RWCC_Plan_Map $plans              Cloud product map.
	 * @param array         $activation         {ok:bool, cloud_subscription_id?:string, at?:string, webhook_ok?:bool}.
	 * @return array{committed:array, skipped:array, failed:bool}
	 */
	public static function commit_covered( $cloud_subscription, array $candidates, RWCC_Settings $settings, RWCC_Plan_Map $plans, array $activation ) {
		$plan = '';
		if ( is_object( $cloud_subscription ) ) {
			$plan = RWCC_Plan_Map::normalize_plan( RWCC_Order_Meta::get( $cloud_subscription, RWCC_Order_Meta::META_PLAN ) );
		}
		if ( $plan === '' && ! empty( $activation['plan'] ) ) {
			$plan = RWCC_Plan_Map::normalize_plan( $activation['plan'] );
		}

		$cloud_id = '';
		if ( is_object( $cloud_subscription ) && method_exists( $cloud_subscription, 'get_id' ) ) {
			$cloud_id = (string) $cloud_subscription->get_id();
		}
		if ( $cloud_id === '' && ! empty( $activation['cloud_subscription_id'] ) ) {
			$cloud_id = (string) $activation['cloud_subscription_id'];
		}

		$ok = ! empty( $activation['ok'] ) && ( ! array_key_exists( 'webhook_ok', $activation ) || ! empty( $activation['webhook_ok'] ) );
		if ( ! $ok || $plan === '' ) {
			return array(
				'committed' => array(),
				'skipped'   => array(),
				'failed'    => true,
			);
		}

		$committed = array();
		$skipped   = array();
		$at        = isset( $activation['at'] ) ? (string) $activation['at'] : gmdate( 'c' );

		foreach ( $candidates as $candidate ) {
			if ( ! is_object( $candidate ) ) {
				continue;
			}
			$candidate_id = method_exists( $candidate, 'get_id' ) ? (string) $candidate->get_id() : '';
			if ( $candidate_id !== '' && $candidate_id === $cloud_id ) {
				$skipped[] = array( 'id' => $candidate_id, 'reason' => 'cloud_subscription' );
				continue;
			}
			if ( self::is_superseded( $candidate ) ) {
				$skipped[] = array( 'id' => $candidate_id, 'reason' => 'already_superseded' );
				continue;
			}

			$row        = self::row_from_subscription( $candidate );
			$classified = RWCC_Coverage::classify( $settings, $plans, $row );
			if ( $classified['type'] !== 'individual' || ! RWCC_Coverage::sku_covered( $plan, $classified['slug'] ) ) {
				$skipped[] = array(
					'id'     => $candidate_id,
					'reason' => $classified['type'] === 'individual' ? 'not_in_selected_cloud_plan' : 'not_covered',
					'slug'   => $classified['slug'],
				);
				continue;
			}

			$record = RWCC_Transition::commit_after_cloud_activation(
				array(
					'original_subscription_id' => $candidate_id,
					'covered_product_ids'      => array( (string) $row['product_id'] ),
					'idempotency_key'          => 'cloud-upgrade:' . $cloud_id . ':' . $candidate_id,
					'credit_amount'            => isset( $activation['credit_amount'] ) ? (string) $activation['credit_amount'] : '0',
					'credit_currency'          => isset( $activation['credit_currency'] ) ? (string) $activation['credit_currency'] : '',
				),
				array(
					'ok'                    => true,
					'cloud_subscription_id' => $cloud_id,
					'at'                    => $at,
				)
			);
			self::mark( $candidate, $record );
			$committed[] = array(
				'id'     => $candidate_id,
				'slug'   => $classified['slug'],
				'record' => $record,
			);
		}

		return array(
			'committed' => $committed,
			'skipped'   => $skipped,
			'failed'    => false,
		);
	}

	/**
	 * Persist superseded meta and stop the next automatic payment without deleting history.
	 *
	 * @param object $subscription Subscription.
	 * @param array  $record       Transition record.
	 */
	public static function mark( $subscription, array $record ) {
		RWCC_Transition::save_on_subscription( $subscription, $record );
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'update_meta_data' ) ) {
			return;
		}
		$subscription->update_meta_data( self::META_SUPERSEDED, '1' );
		if ( method_exists( $subscription, 'update_dates' ) ) {
			$subscription->update_dates( array( 'next_payment' => 0 ) );
		}
		if ( method_exists( $subscription, 'save' ) ) {
			$subscription->save();
		}
	}

	/**
	 * @param object $subscription Subscription.
	 * @return array
	 */
	public static function row_from_subscription( $subscription ) {
		$product_id   = 0;
		$variation_id = 0;
		$slug         = '';
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_items' ) ) {
			foreach ( $subscription->get_items() as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
					$product_id   = (int) $item->get_product_id();
					$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
					break;
				}
			}
		}
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_meta' ) ) {
			$slug = (string) $subscription->get_meta( '_rwcc_plugin_slug', true );
		}
		$status = is_object( $subscription ) && method_exists( $subscription, 'get_status' ) ? $subscription->get_status() : '';
		return array(
			'id'           => is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ? $subscription->get_id() : 0,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'slug'         => $slug,
			'status'       => $status,
			'renewing'     => $status === 'active' || $status === 'on-hold',
			'superseded'   => self::is_superseded( $subscription ),
		);
	}
}
