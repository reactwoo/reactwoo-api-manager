<?php
/**
 * Entitlement handover with no access gap (PLAN.md §17 / stop-ship).
 *
 * Cloud grant OR standalone grant. Covered downloads follow the commercially
 * valid source. A scheduled downgrade does not cut access before Cloud end.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Entitlement_Handover {

	const PHASE_STANDALONE            = 'standalone';
	const PHASE_CLOUD_ACTIVE          = 'cloud_active';
	const PHASE_SCHEDULED_DOWNGRADE   = 'scheduled_downgrade';
	const PHASE_CLOUD_ENDED_SELECTED  = 'cloud_ended_selected';
	const PHASE_CLOUD_ENDED_NONE      = 'cloud_ended_none';
	const PHASE_ACTIVATION_FAILED     = 'activation_failed';

	/**
	 * @param array $input plan, cloud_status, cloud_paid_through, now, activation_ok, downgrade, covered_skus.
	 * @return array
	 */
	public static function snapshot( array $input ) {
		$plan      = RWCC_Plan_Map::normalize_plan( isset( $input['plan'] ) ? $input['plan'] : '' );
		$now       = isset( $input['now'] ) ? (int) $input['now'] : time();
		$through   = self::ts( isset( $input['cloud_paid_through'] ) ? $input['cloud_paid_through'] : 0 );
		$status    = strtolower( (string) ( isset( $input['cloud_status'] ) ? $input['cloud_status'] : '' ) );
		$failed    = array_key_exists( 'activation_ok', $input ) && empty( $input['activation_ok'] );
		$downgrade = isset( $input['downgrade'] ) && is_array( $input['downgrade'] ) ? $input['downgrade'] : array();
		$covered   = $plan && class_exists( 'RWCC_Coverage' ) ? RWCC_Coverage::covered_skus( $plan ) : array();
		$selected  = array();
		if ( ! empty( $downgrade['selected'] ) && is_array( $downgrade['selected'] ) ) {
			foreach ( $downgrade['selected'] as $row ) {
				$slug = is_array( $row ) && isset( $row['slug'] ) ? (string) $row['slug'] : (string) $row;
				if ( $slug !== '' ) {
					$selected[] = $slug;
				}
			}
		}
		$none = ! empty( $downgrade['none_selected'] );

		if ( $failed ) {
			return self::result(
				self::PHASE_ACTIVATION_FAILED,
				array( 'reactwoo-geocore-pro' ),
				'standalone',
				false
			);
		}

		if ( ! $plan ) {
			return self::result( self::PHASE_STANDALONE, array( 'reactwoo-geocore-pro' ), 'standalone', false );
		}

		$cloud_live = in_array( $status, array( 'active', 'pending-cancel', 'on-hold' ), true )
			&& ( $through === 0 || $through >= $now );

		if ( $cloud_live ) {
			$phase = ( ! empty( $downgrade['state'] ) && $downgrade['state'] === RWCC_Downgrade::STATE_SCHEDULED )
				? self::PHASE_SCHEDULED_DOWNGRADE
				: self::PHASE_CLOUD_ACTIVE;
			return self::result( $phase, $covered, 'decision_cloud', false );
		}

		if ( $none || ( empty( $selected ) && ! empty( $downgrade['state'] ) && $downgrade['state'] === RWCC_Downgrade::STATE_NONE_SELECTED ) ) {
			return self::result( self::PHASE_CLOUD_ENDED_NONE, array( 'reactwoo-geocore' ), 'free_core', false );
		}

		if ( $selected ) {
			return self::result( self::PHASE_CLOUD_ENDED_SELECTED, $selected, 'standalone', false );
		}

		return self::result( self::PHASE_STANDALONE, array( 'reactwoo-geocore-pro' ), 'standalone', false );
	}

	/**
	 * Downloads during Cloud must come from the Cloud subscription, not superseded individuals.
	 *
	 * @param string $plan Internal plan.
	 * @param bool   $cloud_can_download Cloud subscription entitled.
	 * @param bool   $individual_superseded Individual is superseded.
	 * @return array{source:string,slugs:string[],gap:bool}
	 */
	public static function downloads( $plan, $cloud_can_download, $individual_superseded ) {
		$slugs = class_exists( 'RWCC_Coverage' ) ? RWCC_Coverage::covered_skus( $plan ) : array();
		if ( $cloud_can_download && $slugs ) {
			return array(
				'source' => 'decision_cloud',
				'slugs'  => $slugs,
				'gap'    => false,
			);
		}
		if ( $individual_superseded && ! $cloud_can_download ) {
			return array(
				'source' => 'none',
				'slugs'  => array(),
				'gap'    => true,
			);
		}
		return array(
			'source' => 'standalone',
			'slugs'  => $slugs ? array( $slugs[0] ) : array(),
			'gap'    => false,
		);
	}

	/**
	 * @param mixed $value Timestamp or ISO date.
	 * @return int
	 */
	private static function ts( $value ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		$value = (string) $value;
		if ( $value === '' ) {
			return 0;
		}
		$parsed = strtotime( $value );
		return $parsed ? (int) $parsed : 0;
	}

	/**
	 * @param string   $phase  Phase.
	 * @param string[] $slugs  Download slugs.
	 * @param string   $source Grant source.
	 * @param bool     $gap    Access gap.
	 * @return array
	 */
	private static function result( $phase, array $slugs, $source, $gap ) {
		return array(
			'phase'            => $phase,
			'download_slugs'   => array_values( $slugs ),
			'grant_source'     => $source,
			'gap'              => (bool) $gap,
			'local_config_kept'=> true,
		);
	}
}
