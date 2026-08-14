<?php
/**
 * Map WooCommerce products and variations onto Decision Cloud plan IDs.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Plan_Map {

	const META_KEY = '_rw_cloud_plan';

	/**
	 * @var array<string,string> product/variation id => plan
	 */
	private $ids_to_plan = array();

	/**
	 * @param array<string,string> $product_map plan => product id (settings fallback).
	 */
	public function __construct( array $product_map = array() ) {
		foreach ( $product_map as $plan => $id ) {
			$plan = self::normalize_plan( $plan );
			$id   = (string) $id;
			if ( $plan && $id !== '' ) {
				$this->ids_to_plan[ $id ] = $plan;
			}
		}
	}

	/**
	 * @param string $plan Candidate plan.
	 * @return string
	 */
	public static function normalize_plan( $plan ) {
		$plan = strtolower( trim( (string) $plan ) );
		return in_array( $plan, RWCC_Settings::PLANS, true ) ? $plan : '';
	}

	/**
	 * Register an explicit product/variation mapping.
	 *
	 * @param int|string $product_id Product or variation id.
	 * @param string     $plan       Internal plan.
	 */
	public function map_id( $product_id, $plan ) {
		$plan = self::normalize_plan( $plan );
		$id   = (string) $product_id;
		if ( $plan && $id !== '' ) {
			$this->ids_to_plan[ $id ] = $plan;
		}
	}

	/**
	 * @param int|string $product_id Product or variation id.
	 * @return string
	 */
	public function plan_for_product_id( $product_id ) {
		$id = (string) $product_id;
		if ( $id === '' || $id === '0' ) {
			return '';
		}
		if ( isset( $this->ids_to_plan[ $id ] ) ) {
			return $this->ids_to_plan[ $id ];
		}
		return '';
	}

	/**
	 * Resolve plan from product meta, then variation parent, then settings map.
	 *
	 * @param int|string   $product_id   Line product id.
	 * @param int|string   $variation_id Line variation id.
	 * @param callable|null $meta_reader  fn(id, key): string.
	 * @return string
	 */
	public function resolve( $product_id, $variation_id = 0, $meta_reader = null ) {
		$variation_id = (int) $variation_id;
		$product_id   = (int) $product_id;

		foreach ( array( $variation_id, $product_id ) as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			$from_map = $this->plan_for_product_id( $id );
			if ( $from_map ) {
				return $from_map;
			}
			if ( is_callable( $meta_reader ) ) {
				$from_meta = self::normalize_plan( $meta_reader( $id, self::META_KEY ) );
				if ( $from_meta ) {
					return $from_meta;
				}
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'rwcc_plan_for_product', '', $product_id, $variation_id );
			return self::normalize_plan( $filtered );
		}

		return '';
	}

	/**
	 * WordPress meta reader using get_post_meta (HPOS products still use post meta).
	 *
	 * @param int    $id  Product id.
	 * @param string $key Meta key.
	 * @return string
	 */
	public static function wp_meta_reader( $id, $key ) {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return '';
		}
		return (string) get_post_meta( (int) $id, $key, true );
	}

	/**
	 * @param string $plan Internal plan.
	 * @return string Product id from the settings fallback map.
	 */
	public function product_id_for_plan( $plan ) {
		$plan = self::normalize_plan( $plan );
		foreach ( $this->ids_to_plan as $id => $mapped ) {
			if ( $mapped === $plan ) {
				return (string) $id;
			}
		}
		return '';
	}
}
