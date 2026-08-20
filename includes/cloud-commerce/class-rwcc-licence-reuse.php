<?php
/**
 * Historical licence reuse vs mint (PLAN.md §20.7 — conservative default).
 *
 * A Decision Cloud key is never rewritten as an individual plugin key.
 * After Cloud ends, a prior individual key for the same domain + slug may be
 * reactivated only if the customer selected that plugin. Otherwise mint later
 * (store licence generation), never invent a key here.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Licence_Reuse {

	const ACTION_REUSE = 'reuse';
	const ACTION_MINT  = 'mint';
	const ACTION_NONE  = 'none';
	const ACTION_DENY  = 'deny';

	/**
	 * @param array $input cloud_key, historical_key, domain, slug, selected, cloud_ended, cloud_package.
	 * @return array{action:string,reason:string,use_key:string}
	 */
	public static function decide( array $input ) {
		$slug      = class_exists( 'RWCC_Coverage' ) ? RWCC_Coverage::canonical_slug( isset( $input['slug'] ) ? $input['slug'] : '' ) : (string) ( isset( $input['slug'] ) ? $input['slug'] : '' );
		$selected  = ! empty( $input['selected'] );
		$ended     = ! empty( $input['cloud_ended'] );
		$cloud_key = isset( $input['cloud_key'] ) ? trim( (string) $input['cloud_key'] ) : '';
		$hist      = isset( $input['historical_key'] ) ? trim( (string) $input['historical_key'] ) : '';
		$cloud_pkg = ! empty( $input['cloud_package'] );

		if ( $cloud_pkg && $slug !== '' && strpos( $slug, 'decision-cloud' ) === false && ! $ended ) {
			return array(
				'action'  => self::ACTION_DENY,
				'reason'  => 'cloud_key_is_not_an_individual_licence',
				'use_key' => '',
			);
		}

		if ( ! $ended ) {
			return array(
				'action'  => self::ACTION_NONE,
				'reason'  => 'cloud_still_covers_selected_plugins',
				'use_key' => $cloud_key,
			);
		}

		if ( ! $selected || $slug === '' || $slug === 'reactwoo-geocore' ) {
			return array(
				'action'  => self::ACTION_NONE,
				'reason'  => 'no_paid_plugin_selected',
				'use_key' => '',
			);
		}

		if ( $hist !== '' && $hist !== $cloud_key ) {
			return array(
				'action'  => self::ACTION_REUSE,
				'reason'  => 'historical_individual_key_same_domain_slug',
				'use_key' => $hist,
			);
		}

		return array(
			'action'  => self::ACTION_MINT,
			'reason'  => 'no_reusable_individual_key',
			'use_key' => '',
		);
	}
}
