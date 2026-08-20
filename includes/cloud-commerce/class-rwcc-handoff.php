<?php
/**
 * Verify Decision Cloud → ReactWoo.com signed handoffs and map them to store URLs.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Handoff {

	const ACTIONS = array(
		'checkout',
		'upgrade',
		'account',
		'subscription',
		'invoices',
		'payment-method',
		'cancel',
		'downgrade',
	);

	/**
	 * @var RWCC_Settings
	 */
	private $settings;

	/**
	 * @var RWCC_Urls
	 */
	private $urls;

	/**
	 * @var RWCC_Plan_Map
	 */
	private $plans;

	/**
	 * @param RWCC_Settings $settings Settings.
	 * @param RWCC_Urls     $urls     Return URL allowlist.
	 * @param RWCC_Plan_Map $plans    Product map.
	 */
	public function __construct( RWCC_Settings $settings, RWCC_Urls $urls, RWCC_Plan_Map $plans ) {
		$this->settings = $settings;
		$this->urls     = $urls;
		$this->plans    = $plans;
	}

	/**
	 * Parse query-style parameters from an array (GET / REST).
	 *
	 * @param array $query Query params.
	 * @return array{ok:bool,error?:string,action?:string,org?:string,plan?:string,exp?:int,return?:string,product_id?:string}
	 */
	public function verify_request( array $query ) {
		$action = isset( $query['rw_action'] ) ? sanitize_key( (string) $query['rw_action'] ) : '';
		if ( $action === '' && isset( $query['action'] ) ) {
			$action = sanitize_key( (string) $query['action'] );
		}
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return array( 'ok' => false, 'error' => 'invalid_action' );
		}

		$org  = isset( $query['rw_cloud_org'] ) ? (string) $query['rw_cloud_org'] : '';
		$plan_raw = isset( $query['rw_cloud_plan'] ) ? (string) $query['rw_cloud_plan'] : '';
		$plan = RWCC_Plan_Map::normalize_plan( $plan_raw );
		$exp  = isset( $query['rw_exp'] ) ? (int) $query['rw_exp'] : 0;
		$ret  = isset( $query['rw_return'] ) ? (string) $query['rw_return'] : '';
		$sig  = isset( $query['rw_sig'] ) ? (string) $query['rw_sig'] : '';

		if ( $exp > 0 && $exp < time() ) {
			return array( 'ok' => false, 'error' => 'handoff_expired' );
		}

		$product_id = '';
		if ( isset( $query['add-to-cart'] ) ) {
			$product_id = preg_replace( '/[^0-9]/', '', (string) $query['add-to-cart'] );
		}

		$secret = (string) $this->settings->get( 'handoff_secret' );
		$params = array(
			'action'  => $action,
			'org'     => $org,
			'plan'    => $plan_raw,
			'exp'     => $exp ? (string) $exp : '',
			'return'  => $ret,
			'product' => $product_id,
		);

		if ( $secret === '' || ! RWCC_Crypto::verify_handoff( $params, $sig, $secret ) ) {
			// Legacy signatures used the normalised plan id.
			$params['plan'] = $plan;
			if ( $secret === '' || ! RWCC_Crypto::verify_handoff( $params, $sig, $secret ) ) {
				return array( 'ok' => false, 'error' => 'invalid_signature' );
			}
		}

		if ( $ret !== '' && ! $this->urls->is_allowed( $ret ) ) {
			return array( 'ok' => false, 'error' => 'invalid_return_url' );
		}

		if ( $product_id !== '' && in_array( $action, array( 'checkout', 'upgrade' ), true ) ) {
			$mapped = $this->plans->plan_for_product_id( $product_id );
			if ( $plan && $mapped && $mapped !== $plan ) {
				return array( 'ok' => false, 'error' => 'plan_product_mismatch' );
			}
			if ( $plan && $mapped === '' ) {
				return array( 'ok' => false, 'error' => 'plan_product_mismatch' );
			}
		}

		if ( $product_id === '' && $plan ) {
			$product_id = $this->plans->product_id_for_plan( $plan );
		}

		return array(
			'ok'         => true,
			'action'     => $action,
			'org'        => $org,
			'plan'       => $plan,
			'exp'        => $exp,
			'return'     => $ret,
			'product_id' => $product_id,
		);
	}

	/**
	 * Store path for a verified handoff. Never a payment-gateway host.
	 *
	 * @param array $verified Result of verify_request().
	 * @param array $context  Optional subscription_id, checkout_url, account_url, etc.
	 * @return string
	 */
	public function destination( array $verified, array $context = array() ) {
		if ( empty( $verified['ok'] ) ) {
			return '';
		}

		$home = isset( $context['home'] ) ? rtrim( (string) $context['home'], '/' ) : '';
		$action = $verified['action'];

		if ( $action === 'checkout' || $action === 'upgrade' ) {
			$path = isset( $context['checkout'] ) ? (string) $context['checkout'] : '/checkout/';
			$url  = $this->absolute( $home, $path );
			if ( ! empty( $verified['product_id'] ) ) {
				$url = $this->add_query( $url, 'add-to-cart', $verified['product_id'] );
			}
			return $url;
		}

		if ( $action === 'invoices' ) {
			$path = isset( $context['orders'] ) ? (string) $context['orders'] : '/my-account/orders/';
			return $this->absolute( $home, $path );
		}

		if ( $action === 'payment-method' ) {
			if ( ! empty( $context['payment_method_url'] ) ) {
				return (string) $context['payment_method_url'];
			}
			$path = isset( $context['payment_methods'] ) ? (string) $context['payment_methods'] : '/my-account/payment-methods/';
			return $this->absolute( $home, $path );
		}

		if ( $action === 'subscription' && ! empty( $context['subscription_url'] ) ) {
			return (string) $context['subscription_url'];
		}

		if ( $action === 'cancel' || $action === 'downgrade' ) {
			$base = ! empty( $context['subscription_url'] ) ? (string) $context['subscription_url'] : $this->absolute( $home, isset( $context['account'] ) ? (string) $context['account'] : '/my-account/' );
			return $this->add_query( $base, 'rwcc_downgrade', '1' );
		}

		$path = isset( $context['account'] ) ? (string) $context['account'] : '/my-account/';
		return $this->absolute( $home, $path );
	}

	/**
	 * @param string $home Home URL.
	 * @param string $path Path or absolute URL.
	 * @return string
	 */
	private function absolute( $home, $path ) {
		if ( preg_match( '#^https?://#i', $path ) ) {
			return $path;
		}
		if ( $path === '' ) {
			$path = '/';
		}
		if ( $path[0] !== '/' ) {
			$path = '/' . $path;
		}
		return rtrim( (string) $home, '/' ) . $path;
	}

	/**
	 * @param string $url   Base URL.
	 * @param string $key   Query key.
	 * @param string $value Query value.
	 * @return string
	 */
	private function add_query( $url, $key, $value ) {
		$sep = strpos( $url, '?' ) === false ? '?' : '&';
		return $url . $sep . rawurlencode( $key ) . '=' . rawurlencode( $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Minimal sanitize_key for offline tests.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
