<?php
/**
 * Stamp Cloud meta onto qualifying orders and subscriptions.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Order_Meta {

	const META_ORG             = 'rw_cloud_org';
	const META_PLAN            = 'rw_cloud_plan';
	const META_PROVISIONING    = 'rw_cloud_provisioning_id';
	const META_IDENTITY_USER   = 'rw_cloud_identity_user';
	const META_IDENTITY_EMAIL  = 'rw_cloud_identity_email';
	const META_CLAIM_HASH      = 'rw_cloud_claim_hash';
	const META_CLAIM_EXPIRES   = 'rw_cloud_claim_expires';
	const META_CLAIM_USED      = 'rw_cloud_claim_used';
	const META_PROVISIONED     = 'rw_cloud_provisioned';
	const META_PRODUCT         = '_reactwoo_cloud_product_id';

	/**
	 * @var RWCC_Plan_Map
	 */
	private $plans;

	/**
	 * @param RWCC_Plan_Map $plans Plan map.
	 */
	public function __construct( RWCC_Plan_Map $plans ) {
		$this->plans = $plans;
	}

	/**
	 * First Cloud plan found on order/subscription line items.
	 *
	 * @param object $order Order or subscription with get_items().
	 * @return array{plan:string,product_id:int,variation_id:int}
	 */
	public function qualifying_line( $order ) {
		$empty = array( 'plan' => '', 'product_id' => 0, 'variation_id' => 0 );
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return $empty;
		}
		foreach ( $order->get_items() as $item ) {
			$product_id   = 0;
			$variation_id = 0;
			if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
				$product_id   = (int) $item->get_product_id();
				$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
			} elseif ( is_array( $item ) ) {
				$product_id   = (int) ( $item['product_id'] ?? 0 );
				$variation_id = (int) ( $item['variation_id'] ?? 0 );
			}
			$plan = $this->plans->resolve( $product_id, $variation_id, array( 'RWCC_Plan_Map', 'wp_meta_reader' ) );
			if ( $plan ) {
				return array(
					'plan'         => $plan,
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
				);
			}
		}
		return $empty;
	}

	/**
	 * @param object $object Order or subscription.
	 * @param array  $meta   Key => value.
	 */
	public static function stamp( $object, array $meta ) {
		if ( ! is_object( $object ) || ! method_exists( $object, 'update_meta_data' ) ) {
			return;
		}
		foreach ( $meta as $key => $value ) {
			if ( $value === '' || $value === null ) {
				continue;
			}
			$object->update_meta_data( $key, $value );
		}
		if ( method_exists( $object, 'save' ) ) {
			$object->save();
		}
	}

	/**
	 * Read a meta value from a WC object.
	 *
	 * @param object $object Object.
	 * @param string $key    Key.
	 * @return string
	 */
	public static function get( $object, $key ) {
		if ( is_object( $object ) && method_exists( $object, 'get_meta' ) ) {
			return (string) $object->get_meta( $key, true );
		}
		return '';
	}

	/**
	 * Apply Cloud identity + plan + org onto order and subscription.
	 *
	 * @param object      $order        Order.
	 * @param object|null $subscription Subscription.
	 * @param array       $context      org_id, plan, product_id, identity, provisioning_id, blog_id.
	 * @return array Applied meta.
	 */
	public function apply_qualifying_meta( $order, $subscription, array $context ) {
		$customer_id = 0;
		if ( is_object( $order ) && method_exists( $order, 'get_customer_id' ) ) {
			$customer_id = (int) $order->get_customer_id();
		} elseif ( is_object( $subscription ) && method_exists( $subscription, 'get_customer_id' ) ) {
			$customer_id = (int) $subscription->get_customer_id();
		}

		$sub_id = is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ? (int) $subscription->get_id() : 0;
		$blog_id = isset( $context['blog_id'] ) ? (int) $context['blog_id'] : 1;

		$provisioning_id = isset( $context['provisioning_id'] ) ? (string) $context['provisioning_id'] : '';
		if ( $provisioning_id === '' && $sub_id && $customer_id ) {
			$existing = $subscription ? self::get( $subscription, self::META_PROVISIONING ) : '';
			$provisioning_id = $existing !== '' ? $existing : RWCC_Crypto::provisioning_id( $blog_id, $customer_id, $sub_id );
		}

		$identity_user  = isset( $context['identity_user'] ) ? (int) $context['identity_user'] : $customer_id;
		$identity_email = isset( $context['identity_email'] ) ? (string) $context['identity_email'] : '';
		if ( $identity_email === '' && is_object( $order ) && method_exists( $order, 'get_billing_email' ) ) {
			$identity_email = (string) $order->get_billing_email();
		}

		$meta = array(
			self::META_ORG            => isset( $context['org_id'] ) ? (string) $context['org_id'] : '',
			self::META_PLAN           => RWCC_Plan_Map::normalize_plan( $context['plan'] ?? '' ),
			self::META_PROVISIONING   => $provisioning_id,
			self::META_IDENTITY_USER  => $identity_user ? (string) $identity_user : '',
			self::META_IDENTITY_EMAIL => $identity_email,
			self::META_PRODUCT        => isset( $context['product_id'] ) ? (string) $context['product_id'] : '',
		);

		self::stamp( $order, $meta );
		if ( $subscription ) {
			self::stamp( $subscription, $meta );
		}

		return $meta;
	}
}
