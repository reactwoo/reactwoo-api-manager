<?php
/**
 * Authenticated account REST endpoints.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReactWoo_Account_REST_Controller {

	const NS           = 'reactwoo/v1';
	const RATE_OPTION  = 'reactwoo_account_key_rate_';
	const RATE_WINDOW  = 60;
	const RATE_LIMIT   = 10;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/account/licenses/(?P<subscription_id>\d+)/key',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_license_key' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'subscription_id' => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return absint( $value ) > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * Logged-in + valid REST nonce (cookie auth).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'reactwoo-api-manager' ),
				array( 'status' => 401 )
			);
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Invalid or missing nonce.', 'reactwoo-api-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Return full key for an owned subscription.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_license_key( $request ) {
		$user_id = get_current_user_id();
		if ( $this->is_rate_limited( $user_id ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests. Please try again shortly.', 'reactwoo-api-manager' ),
				array( 'status' => 429 )
			);
		}

		$subscription_id = absint( $request['subscription_id'] );
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return new WP_Error( 'not_found', __( 'Licence not found.', 'reactwoo-api-manager' ), array( 'status' => 404 ) );
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof WC_Subscription ) {
			return new WP_Error( 'not_found', __( 'Licence not found.', 'reactwoo-api-manager' ), array( 'status' => 404 ) );
		}

		if ( (int) $subscription->get_customer_id() !== (int) $user_id ) {
			return new WP_Error( 'not_found', __( 'Licence not found.', 'reactwoo-api-manager' ), array( 'status' => 404 ) );
		}

		$key = ReactWoo_Customer_Account_Service::get_instance()->get_owned_license_key( $subscription );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$this->bump_rate_limit( $user_id );

		$response = new WP_REST_Response(
			array(
				'license_key' => $key,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function is_rate_limited( $user_id ) {
		$key  = self::RATE_OPTION . (int) $user_id;
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['count'] ) ) {
			return false;
		}
		return (int) $data['count'] >= self::RATE_LIMIT;
	}

	/**
	 * @param int $user_id User ID.
	 */
	private function bump_rate_limit( $user_id ) {
		$key  = self::RATE_OPTION . (int) $user_id;
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$data = array( 'count' => 0 );
		}
		$data['count'] = (int) $data['count'] + 1;
		set_transient( $key, $data, self::RATE_WINDOW );
	}
}
