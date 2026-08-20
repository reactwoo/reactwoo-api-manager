<?php
/**
 * Detect Cloud + covered individual subscriptions still renewing (PLAN.md state 6).
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Overlap {

	/**
	 * @param array         $cloud         Cloud subscription row or empty.
	 * @param array[]       $individuals   Individual subscription rows.
	 * @param RWCC_Settings $settings      Settings.
	 * @param RWCC_Plan_Map $plans         Plan map.
	 * @return array{overlap:bool,plan:string,offenders:array,state:string}
	 */
	public static function detect( $cloud, array $individuals, RWCC_Settings $settings, RWCC_Plan_Map $plans ) {
		$cloud = is_array( $cloud ) ? $cloud : array();
		$plan  = '';
		if ( ! empty( $cloud['plan'] ) ) {
			$plan = RWCC_Plan_Map::normalize_plan( $cloud['plan'] );
		} elseif ( ! empty( $cloud['product_id'] ) ) {
			$classified = RWCC_Coverage::classify( $settings, $plans, $cloud );
			$plan       = $classified['plan'];
		}

		$cloud_active = $plan && RWCC_Coverage::is_active_renewing( $cloud );
		if ( ! $cloud_active ) {
			return array(
				'overlap'   => false,
				'plan'      => $plan,
				'offenders' => array(),
				'state'     => $plan ? 'cloud_inactive' : 'no_cloud',
			);
		}

		$offenders = array();
		foreach ( $individuals as $row ) {
			if ( ! RWCC_Coverage::is_active_renewing( $row ) ) {
				continue;
			}
			$classified = RWCC_Coverage::classify( $settings, $plans, $row );
			if ( $classified['type'] !== 'individual' ) {
				continue;
			}
			if ( ! RWCC_Coverage::sku_covered( $plan, $classified['slug'] ) ) {
				continue;
			}
			if ( ! empty( $row['superseded'] ) ) {
				continue;
			}
			$offenders[] = array_merge( $row, $classified );
		}

		return array(
			'overlap'   => (bool) $offenders,
			'plan'      => $plan,
			'offenders' => $offenders,
			'state'     => $offenders ? 'cloud_active_with_legacy_overlapping_individual_billing' : 'cloud_active',
		);
	}

	/**
	 * Operator correction for state 6. Stops overlapping renewals after explicit confirm.
	 * Does not delete subscription history or refund automatically.
	 *
	 * @param object        $cloud      Cloud subscription.
	 * @param object[]      $candidates Customer subscriptions.
	 * @param RWCC_Settings $settings   Settings.
	 * @param RWCC_Plan_Map $plans      Plan map.
	 * @param bool          $confirm    Explicit operator confirm.
	 * @param string        $actor      Operator identity.
	 * @return array
	 */
	public static function correct( $cloud, array $candidates, RWCC_Settings $settings, RWCC_Plan_Map $plans, $confirm, $actor = '' ) {
		if ( empty( $confirm ) ) {
			return array(
				'ok'        => false,
				'error'     => 'confirmation_required',
				'corrected' => array(),
			);
		}

		$cloud_row = array();
		if ( is_object( $cloud ) ) {
			$cloud_row = RWCC_Supersession::row_from_subscription( $cloud );
			$cloud_row['plan'] = RWCC_Plan_Map::normalize_plan( RWCC_Order_Meta::get( $cloud, RWCC_Order_Meta::META_PLAN ) );
			$cloud_row['renewing'] = true;
		}

		$rows = array();
		foreach ( $candidates as $candidate ) {
			if ( is_object( $candidate ) ) {
				$rows[] = RWCC_Supersession::row_from_subscription( $candidate );
			}
		}

		$detected = self::detect( $cloud_row, $rows, $settings, $plans );
		if ( empty( $detected['overlap'] ) ) {
			return array(
				'ok'        => true,
				'error'     => '',
				'corrected' => array(),
				'state'     => isset( $detected['state'] ) ? $detected['state'] : 'cloud_active',
			);
		}

		$offender_ids = array();
		foreach ( $detected['offenders'] as $offender ) {
			if ( isset( $offender['id'] ) ) {
				$offender_ids[ (string) $offender['id'] ] = true;
			}
		}

		$cloud_id = is_object( $cloud ) && method_exists( $cloud, 'get_id' ) ? (string) $cloud->get_id() : '';
		$corrected = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_object( $candidate ) || ! method_exists( $candidate, 'get_id' ) ) {
				continue;
			}
			$id = (string) $candidate->get_id();
			if ( empty( $offender_ids[ $id ] ) ) {
				continue;
			}
			$row = RWCC_Supersession::row_from_subscription( $candidate );
			$record = RWCC_Transition::record(
				array(
					'superseded_reason'             => RWCC_Transition::REASON_OVERLAP_CORRECTION,
					'original_subscription_id'      => $id,
					'superseded_by_subscription_id' => $cloud_id,
					'replacement_subscription_id'   => $cloud_id,
					'transition_status'             => RWCC_Transition::STATUS_COMMITTED,
					'superseded_at'                 => gmdate( 'c' ),
					'transition_effective_at'       => gmdate( 'c' ),
					'covered_product_ids'           => array( (string) $row['product_id'] ),
					'idempotency_key'               => 'overlap-correction:' . $cloud_id . ':' . $id,
					'actor'                         => (string) $actor,
				)
			);
			RWCC_Supersession::mark( $candidate, $record );
			$corrected[] = array(
				'id'     => $id,
				'slug'   => isset( $row['slug'] ) ? (string) $row['slug'] : '',
				'record' => $record,
			);
		}

		return array(
			'ok'        => true,
			'error'     => '',
			'corrected' => $corrected,
			'state'     => $corrected ? 'cloud_active' : $detected['state'],
			'refund'    => false,
			'credit'    => self::quote_credit( $detected['offenders'] ),
		);
	}

	/**
	 * Remaining-term amounts for overlapping individuals. Does not refund.
	 *
	 * @param array[] $offenders Overlap rows.
	 * @return array
	 */
	public static function quote_credit( array $offenders ) {
		$lines = array();
		foreach ( $offenders as $row ) {
			$line = $row;
			$line['covered'] = true;
			$lines[]         = $line;
		}
		$currency = '';
		if ( isset( $offenders[0]['currency'] ) ) {
			$currency = strtoupper( (string) $offenders[0]['currency'] );
		}
		$credit = class_exists( 'RWCC_Upgrade_Credit' )
			? RWCC_Upgrade_Credit::calculate( $lines, array( 'currency' => $currency ) )
			: array( 'applied_credit' => '0.00', 'lines' => array() );
		$credit['refund']           = false;
		$credit['requires_finance'] = true;
		$credit['reason']           = 'state_6_overlap_not_auto_refunded';
		return $credit;
	}
}
