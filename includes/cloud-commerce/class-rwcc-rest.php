<?php
/**
 * REST: signed handoffs + least-privilege reconciliation.
 *
 * Namespace: reactwoo-cloud/v1
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_REST {

	const NS = 'reactwoo-cloud/v1';

	/**
	 * @var RWCC_Settings
	 */
	private $settings;

	/**
	 * @var RWCC_Handoff
	 */
	private $handoff;

	/**
	 * @var RWCC_Lifecycle
	 */
	private $lifecycle;

	/**
	 * @param RWCC_Settings  $settings  Settings.
	 * @param RWCC_Handoff   $handoff   Handoff verifier.
	 * @param RWCC_Lifecycle $lifecycle Lifecycle (reconcile).
	 */
	public function __construct( RWCC_Settings $settings, RWCC_Handoff $handoff, RWCC_Lifecycle $lifecycle ) {
		$this->settings  = $settings;
		$this->handoff   = $handoff;
		$this->lifecycle = $lifecycle;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$actions = array( 'checkout', 'upgrade', 'subscription', 'invoices', 'payment-method', 'account' );
		register_rest_route(
			self::NS,
			'/handoff/(?P<action>' . implode( '|', $actions ) . ')',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_handoff' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/reconcile',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_reconcile' ),
				'permission_callback' => array( $this, 'reconcile_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/activation/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_retry' ),
				'permission_callback' => array( $this, 'customer_permission' ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_handoff( $request ) {
		$params = $request->get_params();
		$params['rw_action'] = isset( $params['action'] ) ? $params['action'] : '';
		$verified = $this->handoff->verify_request( $params );
		if ( empty( $verified['ok'] ) ) {
			$code = isset( $verified['error'] ) ? $verified['error'] : 'invalid_handoff';
			$status = $code === 'invalid_return_url' ? 400 : 403;
			return new WP_Error( $code, $code, array( 'status' => $status ) );
		}

		$context = array(
			'home'     => home_url(),
			'checkout' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '/checkout/',
			'account'  => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '/my-account/',
			'orders'   => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : '/my-account/orders/',
		);

		if ( $verified['action'] === 'subscription' && ! empty( $verified['org'] ) && function_exists( 'wcs_get_subscriptions' ) ) {
			$subs = wcs_get_subscriptions(
				array(
					'subscription_status' => 'any',
					'meta_query'          => array(
						array(
							'key'   => RWCC_Order_Meta::META_ORG,
							'value' => $verified['org'],
						),
					),
					'subscriptions_per_page' => 1,
				)
			);
			if ( is_array( $subs ) && $subs ) {
				$sub = reset( $subs );
				if ( $sub && method_exists( $sub, 'get_view_order_url' ) ) {
					$context['subscription_url'] = $sub->get_view_order_url();
				}
				if ( $sub && method_exists( $sub, 'get_change_payment_method_url' ) ) {
					$context['payment_method_url'] = $sub->get_change_payment_method_url();
				}
			}
		}

		$url = $this->handoff->destination( $verified, $context );
		return rest_ensure_response(
			array(
				'url'    => $url,
				'action' => $verified['action'],
				'plan'   => $verified['plan'],
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function reconcile_permission( $request ) {
		$header = $request->get_header( 'authorization' );
		if ( ! $header ) {
			$header = $request->get_header( 'Authorization' );
		}
		$token = (string) $this->settings->get( 'reconcile_token' );
		if ( ! RWCC_Reconcile::authorized( (string) $header, $token ) ) {
			return new WP_Error( 'rest_forbidden', 'forbidden', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_reconcile( $request ) {
		$subscription_id = absint( $request->get_param( 'subscription_id' ) );
		$org_id          = sanitize_text_field( (string) $request->get_param( 'organisation_id' ) );
		$subscription    = null;

		if ( $subscription_id && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( $subscription_id );
		} elseif ( $org_id && function_exists( 'wcs_get_subscriptions' ) ) {
			$subs = wcs_get_subscriptions(
				array(
					'subscription_status'    => 'any',
					'subscriptions_per_page' => 1,
					'meta_query'             => array(
						array(
							'key'   => RWCC_Order_Meta::META_ORG,
							'value' => $org_id,
						),
					),
				)
			);
			$subscription = is_array( $subs ) && $subs ? reset( $subs ) : null;
		}

		if ( ! $subscription ) {
			return new WP_Error( 'not_found', 'subscription_not_found', array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->lifecycle->reconcile_from_subscription( $subscription ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function customer_permission( $request ) {
		unset( $request );
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Authentication required.', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Retry activation without creating a duplicate organisation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_retry( $request ) {
		$subscription_id = absint( $request->get_param( 'subscription_id' ) );
		if ( ! $subscription_id || ! function_exists( 'wcs_get_subscription' ) ) {
			return new WP_Error( 'not_found', 'subscription_not_found', array( 'status' => 404 ) );
		}
		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return new WP_Error( 'not_found', 'subscription_not_found', array( 'status' => 404 ) );
		}
		if ( (int) $subscription->get_customer_id() !== (int) get_current_user_id() ) {
			return new WP_Error( 'not_found', 'subscription_not_found', array( 'status' => 404 ) );
		}
		$order  = method_exists( $subscription, 'get_parent' ) ? $subscription->get_parent() : null;
		$result = $this->lifecycle->activate( $subscription, $order );
		if ( empty( $result['ok'] ) ) {
			return new WP_Error( 'not_cloud', isset( $result['error'] ) ? $result['error'] : 'failed', array( 'status' => 400 ) );
		}
		return rest_ensure_response(
			array(
				'activation_url'      => isset( $result['activation_url'] ) ? $result['activation_url'] : '',
				'already_provisioned' => ! empty( $result['claim']['already_provisioned'] ),
				'provisioning_id'     => isset( $result['provisioning_id'] ) ? $result['provisioning_id'] : '',
				'expires_at'          => isset( $result['claim']['expires_at'] ) ? (int) $result['claim']['expires_at'] : 0,
			)
		);
	}
}
