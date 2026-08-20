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

	const META_KEY           = '_rw_cloud_plan';
	const META_BILLING_CYCLE = '_rw_cloud_billing_cycle';
	const META_PRODUCT_TYPE  = '_rw_cloud_product_type';

	/**
	 * @var array<string,string> product/variation id => plan
	 */
	private $ids_to_plan = array();

	/**
	 * @var array<string,string> product/variation id => monthly|annual
	 */
	private $ids_to_cycle = array();

	/**
	 * @param array<string,string> $product_map plan => comma-separated product/variation ids.
	 */
	public function __construct( array $product_map = array() ) {
		foreach ( $product_map as $plan => $ids ) {
			$plan = self::normalize_plan( $plan );
			if ( ! $plan ) {
				continue;
			}
			$parsed = self::parse_ids( $ids );
			foreach ( $parsed as $id ) {
				$this->ids_to_plan[ $id ] = $plan;
			}
			// Settings convention when meta is absent: monthly,annual.
			if ( count( $parsed ) === 2 ) {
				$this->ids_to_cycle[ $parsed[0] ] = 'monthly';
				$this->ids_to_cycle[ $parsed[1] ] = 'annual';
			}
		}
	}

	/**
	 * @param string $raw Comma/space separated numeric ids.
	 * @return string[]
	 */
	public static function parse_ids( $raw ) {
		$parts = preg_split( '/[\s,]+/', trim( (string) $raw ) );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			if ( $part !== '' && $part !== '0' && preg_match( '/^[0-9]+$/', $part ) ) {
				$out[] = $part;
			}
		}
		return $out;
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
	 * @param string $cycle Candidate cadence.
	 * @return string monthly|annual|''
	 */
	public static function normalize_billing_cycle( $cycle ) {
		$cycle = strtolower( trim( (string) $cycle ) );
		if ( in_array( $cycle, array( 'month', 'monthly', 'm' ), true ) ) {
			return 'monthly';
		}
		if ( in_array( $cycle, array( 'year', 'annual', 'yearly', 'y' ), true ) ) {
			return 'annual';
		}
		return '';
	}

	/**
	 * @param string $type Candidate type.
	 * @return string
	 */
	public static function normalize_product_type( $type ) {
		$type = strtolower( trim( (string) $type ) );
		return $type === 'decision_cloud' ? 'decision_cloud' : '';
	}

	/**
	 * Register an explicit product/variation mapping.
	 *
	 * @param int|string $product_id Product or variation id.
	 * @param string     $plan       Internal plan.
	 * @param string     $cycle      Optional monthly|annual.
	 */
	public function map_id( $product_id, $plan, $cycle = '' ) {
		$plan = self::normalize_plan( $plan );
		$id   = (string) $product_id;
		if ( $plan && $id !== '' && $id !== '0' ) {
			$this->ids_to_plan[ $id ] = $plan;
			$normalized_cycle         = self::normalize_billing_cycle( $cycle );
			if ( $normalized_cycle ) {
				$this->ids_to_cycle[ $id ] = $normalized_cycle;
			}
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
	 * @param int|string $product_id Product or variation id.
	 * @return string
	 */
	public function billing_cycle_for_product_id( $product_id ) {
		$id = (string) $product_id;
		return isset( $this->ids_to_cycle[ $id ] ) ? $this->ids_to_cycle[ $id ] : '';
	}

	/**
	 * Resolve plan from variation, then parent, then settings map. Never from title or price.
	 *
	 * @param int|string    $product_id   Line product id.
	 * @param int|string    $variation_id Line variation id.
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
	 * Resolve billing cadence from meta, then the settings index convention.
	 *
	 * @param int|string    $product_id   Line product id.
	 * @param int|string    $variation_id Line variation id.
	 * @param callable|null $meta_reader  fn(id, key): string.
	 * @return string
	 */
	public function resolve_billing_cycle( $product_id, $variation_id = 0, $meta_reader = null ) {
		$variation_id = (int) $variation_id;
		$product_id   = (int) $product_id;
		foreach ( array( $variation_id, $product_id ) as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			if ( is_callable( $meta_reader ) ) {
				$from_meta = self::normalize_billing_cycle( $meta_reader( $id, self::META_BILLING_CYCLE ) );
				if ( $from_meta ) {
					return $from_meta;
				}
			}
			$from_map = $this->billing_cycle_for_product_id( $id );
			if ( $from_map ) {
				return $from_map;
			}
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
	 * All mapped variation/product ids for an internal plan.
	 *
	 * @param string $plan Internal plan.
	 * @return string[]
	 */
	public function product_ids_for_plan( $plan ) {
		$plan = self::normalize_plan( $plan );
		$ids  = array();
		foreach ( $this->ids_to_plan as $id => $mapped ) {
			if ( $mapped === $plan ) {
				$ids[] = (string) $id;
			}
		}
		return $ids;
	}

	/**
	 * Default checkout variation for a plan (monthly first when both are mapped).
	 *
	 * @param string $plan Internal plan.
	 * @param string $cycle Optional monthly|annual.
	 * @return string Product id from the settings fallback map.
	 */
	public function product_id_for_plan( $plan, $cycle = '' ) {
		$plan  = self::normalize_plan( $plan );
		$cycle = self::normalize_billing_cycle( $cycle );
		$ids   = $this->product_ids_for_plan( $plan );
		if ( $cycle ) {
			foreach ( $ids as $id ) {
				if ( $this->billing_cycle_for_product_id( $id ) === $cycle ) {
					return $id;
				}
			}
		}
		return $ids ? $ids[0] : '';
	}
}
