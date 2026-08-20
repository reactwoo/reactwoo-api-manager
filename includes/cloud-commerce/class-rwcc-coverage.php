<?php
/**
 * Cloud plan → covered plugin SKUs and capability keys (PLAN.md).
 *
 * WooCommerce product IDs are bound in settings, never hard-coded.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Coverage {

	const SLUG_ALIASES = array(
		'geo-elementor'      => 'reactwoo-geocore-pro',
		'geoelementor'       => 'reactwoo-geocore-pro',
		'reactwoo-geo-ai'    => 'reactwoo-geo-optimise',
		'geo-ai'             => 'reactwoo-geo-optimise',
		'geo_ai'             => 'reactwoo-geo-optimise',
	);

	const INDIVIDUAL = array(
		'reactwoo-geocore-pro'  => array(
			'label'      => 'Geo Core Pro',
			'capability' => 'cloud.personalisation',
			'setting'    => 'product_geocore_pro',
		),
		'reactwoo-geo-commerce' => array(
			'label'      => 'Geo Commerce',
			'capability' => 'cloud.commerce',
			'setting'    => 'product_geo_commerce',
		),
		'reactwoo-geo-optimise' => array(
			'label'      => 'Geo Optimise',
			'capability' => 'cloud.optimise',
			'setting'    => 'product_geo_optimise',
		),
	);

	const UNCOVERED = array(
		'reactwoo-geocore',
		'reactwoo-reviews',
		'reactwoo-atomic',
		'reactwoo-atomic-pro',
		'reactwoo-linkedin-core',
		'reactwoo-flow',
		'reactwoo-whmcs-bridge',
	);

	const PLANS = array(
		'starter' => array(
			'skus'         => array( 'reactwoo-geocore-pro' ),
			'capabilities' => array( 'cloud.personalisation', 'cloud.components', 'cloud.insights', 'cloud.recommendations' ),
			'sites_max'    => 1,
			'team_max'     => 2,
			'history_days' => 14,
		),
		'growth'  => array(
			'skus'         => array( 'reactwoo-geocore-pro', 'reactwoo-geo-commerce', 'reactwoo-geo-optimise' ),
			'capabilities' => array( 'cloud.personalisation', 'cloud.commerce', 'cloud.optimise', 'cloud.components', 'cloud.insights', 'cloud.recommendations' ),
			'sites_max'    => 5,
			'team_max'     => 10,
			'history_days' => 90,
		),
		'scale'   => array(
			'skus'         => array( 'reactwoo-geocore-pro', 'reactwoo-geo-commerce', 'reactwoo-geo-optimise' ),
			'capabilities' => array( 'cloud.personalisation', 'cloud.commerce', 'cloud.optimise', 'cloud.components', 'cloud.insights', 'cloud.recommendations' ),
			'sites_max'    => 25,
			'team_max'     => 50,
			'history_days' => 365,
		),
	);

	/**
	 * @param string $slug Raw slug.
	 * @return string
	 */
	public static function canonical_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( $slug === '' ) {
			return '';
		}
		return isset( self::SLUG_ALIASES[ $slug ] ) ? self::SLUG_ALIASES[ $slug ] : $slug;
	}

	/**
	 * @param string $plan Internal plan.
	 * @return array{skus:string[],capabilities:string[],sites_max:int,team_max:int,history_days:int}|null
	 */
	public static function plan_coverage( $plan ) {
		$plan = RWCC_Plan_Map::normalize_plan( $plan );
		return $plan && isset( self::PLANS[ $plan ] ) ? self::PLANS[ $plan ] : null;
	}

	/**
	 * @param string $plan Internal plan.
	 * @return string[]
	 */
	public static function covered_skus( $plan ) {
		$row = self::plan_coverage( $plan );
		return $row ? $row['skus'] : array();
	}

	/**
	 * My Account download rows for a Cloud plan. Does not attach Woo variation files.
	 *
	 * @param string $plan Internal plan.
	 * @return array<int,array{slug:string,label:string,name:string,source:string}>
	 */
	public static function download_rows( $plan ) {
		$rows = array();
		foreach ( self::covered_skus( $plan ) as $slug ) {
			$label = isset( self::INDIVIDUAL[ $slug ]['label'] ) ? self::INDIVIDUAL[ $slug ]['label'] : $slug;
			$template = '%s — Included with Decision Cloud';
			if ( function_exists( '__' ) ) {
				$template = __( '%s — Included with Decision Cloud', 'reactwoo-api-manager' );
			}
			$rows[] = array(
				'slug'   => $slug,
				'label'  => $label,
				'name'   => sprintf( $template, $label ),
				'source' => 'decision_cloud',
			);
		}
		return $rows;
	}

	/**
	 * @param string $plan Internal plan.
	 * @param string $slug Plugin slug.
	 * @return bool
	 */
	public static function sku_covered( $plan, $slug ) {
		$canonical = self::canonical_slug( $slug );
		return $canonical !== '' && in_array( $canonical, self::covered_skus( $plan ), true );
	}

	/**
	 * Eligible individual plugins not included in the Cloud plan.
	 *
	 * @param string $plan Internal plan.
	 * @return string[]
	 */
	public static function uncovered_individual_skus( $plan ) {
		$covered = self::covered_skus( $plan );
		$out     = array();
		foreach ( array_keys( self::INDIVIDUAL ) as $slug ) {
			if ( ! in_array( $slug, $covered, true ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Map of individual slug => Woo product id from settings (empty until bound).
	 *
	 * @param RWCC_Settings $settings Settings.
	 * @return array<string,string>
	 */
	public static function individual_product_ids( RWCC_Settings $settings ) {
		$map = array();
		foreach ( self::INDIVIDUAL as $slug => $meta ) {
			$id = trim( (string) $settings->get( $meta['setting'] ) );
			if ( $id !== '' ) {
				$map[ $slug ] = $id;
			}
		}
		return $map;
	}

	/**
	 * Resolve an individual slug from a Woo product/variation id.
	 *
	 * @param RWCC_Settings $settings   Settings.
	 * @param int|string    $product_id Product or variation id.
	 * @return string
	 */
	public static function slug_for_product_id( RWCC_Settings $settings, $product_id ) {
		$id = (string) $product_id;
		if ( $id === '' || $id === '0' ) {
			return '';
		}
		foreach ( self::individual_product_ids( $settings ) as $slug => $mapped ) {
			if ( (string) $mapped === $id ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Classify a store subscription row.
	 *
	 * @param RWCC_Settings $settings Settings.
	 * @param RWCC_Plan_Map $plans    Cloud product map.
	 * @param array         $row      id, product_id, variation_id, slug, status, renewing.
	 * @return array{type:string,slug:string,plan:string,covered:bool}
	 */
	public static function classify( RWCC_Settings $settings, RWCC_Plan_Map $plans, array $row ) {
		$product_id   = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
		$variation_id = isset( $row['variation_id'] ) ? (int) $row['variation_id'] : 0;
		$cloud_plan   = $plans->resolve( $product_id, $variation_id );
		if ( $cloud_plan ) {
			return array(
				'type'    => 'cloud',
				'slug'    => '',
				'plan'    => $cloud_plan,
				'covered' => false,
			);
		}

		$slug = self::canonical_slug( isset( $row['slug'] ) ? $row['slug'] : '' );
		if ( $slug === '' ) {
			$slug = self::slug_for_product_id( $settings, $variation_id ? $variation_id : $product_id );
		}

		return array(
			'type'    => isset( self::INDIVIDUAL[ $slug ] ) ? 'individual' : 'other',
			'slug'    => $slug,
			'plan'    => '',
			'covered' => false,
		);
	}

	/**
	 * Upgrade summary for a selected Cloud plan.
	 *
	 * @param string        $plan          Target Cloud plan.
	 * @param array[]       $subscriptions Active subscription rows.
	 * @param RWCC_Settings $settings      Settings.
	 * @param RWCC_Plan_Map $plans         Cloud product map.
	 * @return array
	 */
	public static function upgrade_summary( $plan, array $subscriptions, RWCC_Settings $settings, RWCC_Plan_Map $plans ) {
		$plan     = RWCC_Plan_Map::normalize_plan( $plan );
		$covered  = array();
		$separate = array();
		$cloud    = array();

		foreach ( $subscriptions as $row ) {
			if ( ! self::is_active_renewing( $row ) ) {
				continue;
			}
			$classified = self::classify( $settings, $plans, $row );
			$row        = array_merge( $row, $classified );
			if ( $classified['type'] === 'cloud' ) {
				$cloud[] = $row;
				continue;
			}
			if ( $classified['type'] === 'individual' && self::sku_covered( $plan, $classified['slug'] ) ) {
				$row['covered']           = true;
				$row['will_stop_renewing'] = true;
				$covered[]                = $row;
				continue;
			}
			$row['covered']            = false;
			$row['will_stop_renewing'] = false;
			$row['separate_reason']    = $classified['type'] === 'individual'
				? 'not_in_selected_cloud_plan'
				: 'not_a_reactwoo_suite_plugin';
			$separate[]                = $row;
		}

		return array(
			'plan'                         => $plan,
			'covered_skus'                 => self::covered_skus( $plan ),
			'uncovered_individual_skus'    => self::uncovered_individual_skus( $plan ),
			'current_individual'           => array_merge( $covered, $separate ),
			'will_be_included'             => $covered,
			'will_stop_renewing'           => $covered,
			'remain_separately_billed'     => $separate,
			'existing_cloud_subscriptions' => $cloud,
			'requires_coverage_resolution' => $covered || $cloud,
			'block_unexplained_full_price' => (bool) $covered,
		);
	}

	/**
	 * @param array $row Subscription row.
	 * @return bool
	 */
	public static function is_active_renewing( array $row ) {
		$status = isset( $row['status'] ) ? strtolower( (string) $row['status'] ) : '';
		if ( in_array( $status, array( 'cancelled', 'canceled', 'expired', 'trash', 'pending-cancel' ), true ) ) {
			if ( $status === 'pending-cancel' ) {
				return ! empty( $row['renewing'] );
			}
			return false;
		}
		if ( ! empty( $row['trial'] ) || ! empty( $row['refunded'] ) || ! empty( $row['superseded'] ) ) {
			return false;
		}
		if ( array_key_exists( 'renewing', $row ) ) {
			return (bool) $row['renewing'];
		}
		return in_array( $status, array( 'active', 'on-hold', 'pending' ), true ) || $status === '';
	}
}
