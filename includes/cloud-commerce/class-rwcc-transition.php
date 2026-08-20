<?php
/**
 * Store-side Cloud upgrade/downgrade relationship records.
 *
 * Original individual subscriptions are never permanently deleted.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Transition {

	const META_KEY = '_rwcc_transition';

	const REASON_CLOUD_UPGRADE = 'cloud_upgrade';
	const REASON_CLOUD_DOWNGRADE = 'cloud_downgrade';
	const REASON_OVERLAP_CORRECTION = 'overlap_correction';

	const STATUS_PENDING    = 'pending';
	const STATUS_ACTIVATING = 'activating';
	const STATUS_COMMITTED  = 'committed';
	const STATUS_FAILED     = 'failed';
	const STATUS_ROLLED_BACK = 'rolled_back';
	const STATUS_SCHEDULED  = 'scheduled';

	/**
	 * @param array $fields Transition fields.
	 * @return array
	 */
	public static function record( array $fields ) {
		$now = isset( $fields['recorded_at'] ) ? (string) $fields['recorded_at'] : gmdate( 'c' );
		return array(
			'superseded_by_subscription_id' => isset( $fields['superseded_by_subscription_id'] ) ? (string) $fields['superseded_by_subscription_id'] : '',
			'superseded_at'                 => isset( $fields['superseded_at'] ) ? (string) $fields['superseded_at'] : '',
			'superseded_reason'             => isset( $fields['superseded_reason'] ) ? (string) $fields['superseded_reason'] : self::REASON_CLOUD_UPGRADE,
			'original_subscription_id'      => isset( $fields['original_subscription_id'] ) ? (string) $fields['original_subscription_id'] : '',
			'replacement_subscription_id'   => isset( $fields['replacement_subscription_id'] ) ? (string) $fields['replacement_subscription_id'] : '',
			'transition_effective_at'       => isset( $fields['transition_effective_at'] ) ? (string) $fields['transition_effective_at'] : '',
			'transition_status'             => isset( $fields['transition_status'] ) ? (string) $fields['transition_status'] : self::STATUS_PENDING,
			'covered_product_ids'           => isset( $fields['covered_product_ids'] ) && is_array( $fields['covered_product_ids'] ) ? array_values( $fields['covered_product_ids'] ) : array(),
			'credit_amount'                 => isset( $fields['credit_amount'] ) ? (string) $fields['credit_amount'] : '0',
			'credit_currency'               => isset( $fields['credit_currency'] ) ? (string) $fields['credit_currency'] : '',
			'idempotency_key'               => isset( $fields['idempotency_key'] ) ? (string) $fields['idempotency_key'] : '',
			'actor'                         => isset( $fields['actor'] ) ? (string) $fields['actor'] : '',
			'recorded_at'                   => $now,
		);
	}

	/**
	 * Mark covered individuals superseded only after Cloud activation succeeded.
	 *
	 * @param array $pending Pending record.
	 * @param array $activation {ok:bool, cloud_subscription_id:string, at?:string}.
	 * @return array
	 */
	public static function commit_after_cloud_activation( array $pending, array $activation ) {
		$record = self::record( $pending );
		if ( empty( $activation['ok'] ) ) {
			$record['transition_status'] = self::STATUS_FAILED;
			$record['superseded_at']     = '';
			$record['superseded_by_subscription_id'] = '';
			return $record;
		}
		$at = isset( $activation['at'] ) ? (string) $activation['at'] : gmdate( 'c' );
		$cloud_id = isset( $activation['cloud_subscription_id'] ) ? (string) $activation['cloud_subscription_id'] : '';
		$record['transition_status']               = self::STATUS_COMMITTED;
		$record['superseded_at']                   = $at;
		$record['transition_effective_at']         = $at;
		$record['superseded_by_subscription_id']   = $cloud_id;
		$record['replacement_subscription_id']     = $cloud_id;
		$record['superseded_reason']               = self::REASON_CLOUD_UPGRADE;
		return $record;
	}

	/**
	 * Schedule individual subscriptions to start when Cloud ends.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function schedule_downgrade( array $fields ) {
		$record = self::record( $fields );
		$record['superseded_reason']   = self::REASON_CLOUD_DOWNGRADE;
		$record['transition_status']   = self::STATUS_SCHEDULED;
		$record['superseded_at']       = '';
		return $record;
	}

	/**
	 * Idempotent merge: same key returns the existing committed/scheduled row.
	 *
	 * @param array|null $existing Existing record.
	 * @param array      $incoming Incoming record.
	 * @return array
	 */
	public static function idempotent_merge( $existing, array $incoming ) {
		$incoming = self::record( $incoming );
		if ( ! is_array( $existing ) || empty( $existing['idempotency_key'] ) ) {
			return $incoming;
		}
		if ( $existing['idempotency_key'] !== $incoming['idempotency_key'] ) {
			return $incoming;
		}
		if ( in_array( $existing['transition_status'], array( self::STATUS_COMMITTED, self::STATUS_SCHEDULED ), true ) ) {
			return $existing;
		}
		return $incoming;
	}

	/**
	 * Persist onto a subscription-like object with get_meta/update_meta_data.
	 *
	 * @param object $subscription Subscription.
	 * @param array  $record       Record.
	 * @return array
	 */
	public static function save_on_subscription( $subscription, array $record ) {
		$record = self::record( $record );
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_meta' ) ) {
			$existing = $subscription->get_meta( self::META_KEY, true );
			if ( is_array( $existing ) ) {
				$record = self::idempotent_merge( $existing, $record );
			}
		}
		if ( is_object( $subscription ) && method_exists( $subscription, 'update_meta_data' ) ) {
			$subscription->update_meta_data( self::META_KEY, $record );
			if ( method_exists( $subscription, 'save' ) ) {
				$subscription->save();
			}
		}
		return $record;
	}
}
