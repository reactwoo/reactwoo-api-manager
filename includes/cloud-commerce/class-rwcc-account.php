<?php
/**
 * My Account CTA: send the customer from ReactWoo.com into Decision Cloud.
 * Cloud console screens are not implemented here (Sprint 3+).
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Account {

	const INTENT_COOKIE = 'rwcc_open_cloud';

	/**
	 * @var RWCC_Lifecycle
	 */
	private $lifecycle;

	/**
	 * @var RWCC_Identity_Client|null
	 */
	private $identity;

	/**
	 * @param RWCC_Lifecycle            $lifecycle Lifecycle.
	 * @param RWCC_Identity_Client|null $identity  Login handoff client.
	 */
	public function __construct( RWCC_Lifecycle $lifecycle, $identity = null ) {
		$this->lifecycle = $lifecycle;
		$this->identity  = $identity;
	}

	public function register() {
		add_action( 'woocommerce_account_dashboard', array( $this, 'render_open_cloud' ), 14 );
		add_action( 'woocommerce_account_dashboard', array( $this, 'render' ), 15 );
		add_action( 'woocommerce_subscription_details_after_order_table', array( $this, 'render_for_subscription' ), 20, 1 );
		add_filter( 'woocommerce_login_redirect', array( $this, 'redirect_after_store_login' ), 20, 2 );
		add_filter( 'login_redirect', array( $this, 'redirect_after_wp_login' ), 20, 3 );
	}

	/**
	 * Returning login does not require a Cloud subscription.
	 * Membership on Cloud decides which workspaces open.
	 */
	public function render_open_cloud() {
		if ( ! is_user_logged_in() || ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			return;
		}
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'rwcc_open_cloud' => 1,
				),
				wc_get_account_endpoint_url( 'dashboard' )
			),
			'rwcc_open_cloud'
		);
		echo '<div class="rwcc-cloud-open" style="margin:16px 0;padding:16px;border:1px solid #dcdcde;border-radius:8px;">';
		echo '<h3>' . esc_html__( 'Decision Cloud', 'reactwoo-api-manager' ) . '</h3>';
		echo '<p>' . esc_html__( 'Open your authorised Decision Cloud workspace from this ReactWoo account. No extra Cloud password is required.', 'reactwoo-api-manager' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Open Decision Cloud', 'reactwoo-api-manager' ) . '</a></p>';
		echo '</div>';
	}

	public function render() {
		if ( ! is_user_logged_in() || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return;
		}
		$subs = wcs_get_users_subscriptions( get_current_user_id() );
		if ( ! is_array( $subs ) ) {
			return;
		}
		foreach ( $subs as $subscription ) {
			$this->render_for_subscription( $subscription );
		}
	}

	/**
	 * @param object $subscription Subscription.
	 */
	public function render_for_subscription( $subscription ) {
		if ( ! is_object( $subscription ) ) {
			return;
		}
		$plan = RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_PLAN );
		if ( $plan === '' ) {
			return;
		}
		$expires = (int) RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_CLAIM_EXPIRES );
		$used    = RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_CLAIM_USED );
		$ttl_min = max( 1, (int) ceil( ( $expires ? $expires - time() : 1800 ) / 60 ) );

		echo '<div class="rwcc-cloud-activate" style="margin:16px 0;padding:16px;border:1px solid #dcdcde;border-radius:8px;">';
		echo '<h3>' . esc_html__( 'Activate Decision Cloud', 'reactwoo-api-manager' ) . '</h3>';
		echo '<p>' . esc_html__( 'Your ReactWoo purchase is ready. Confirm the account that will own this workspace.', 'reactwoo-api-manager' ) . '</p>';
		if ( $used ) {
			echo '<p>' . esc_html__( 'This workspace is already linked. Open Decision Cloud from the button below.', 'reactwoo-api-manager' ) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: minutes */
					__( 'The activation link expires in %d minutes. Retrying uses the same organisation.', 'reactwoo-api-manager' ),
					$ttl_min
				)
			) . '</p>';
		}
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'rwcc_open_cloud' => 1,
					'subscription_id' => method_exists( $subscription, 'get_id' ) ? (int) $subscription->get_id() : 0,
				),
				wc_get_account_endpoint_url( 'dashboard' )
			),
			'rwcc_open_cloud'
		);
		$label = $used
			? __( 'Open Decision Cloud', 'reactwoo-api-manager' )
			: __( 'Continue to Decision Cloud', 'reactwoo-api-manager' );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Purchased securely on ReactWoo.com', 'reactwoo-api-manager' ) . '</p>';
		echo '</div>';
	}

	public function maybe_redirect_activation() {
		if ( empty( $_GET['rwcc_activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rwcc_activate' ) ) {
			return;
		}
		$subscription_id = isset( $_GET['subscription_id'] ) ? absint( $_GET['subscription_id'] ) : 0;
		if ( ! $subscription_id || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription || (int) $subscription->get_customer_id() !== (int) get_current_user_id() ) {
			return;
		}
		$order  = method_exists( $subscription, 'get_parent' ) ? $subscription->get_parent() : null;
		$result = $this->lifecycle->activate( $subscription, $order );
		if ( ! empty( $result['activation_url'] ) ) {
			wp_safe_redirect( $result['activation_url'] );
			exit;
		}
	}

	/**
	 * Returning login: WordPress session → signed login claim → Cloud fragment URL.
	 * WooCommerce webhooks never establish a browser session.
	 *
	 * Cloud cannot mint a WordPress nonce, so `?rwcc_open_cloud=1` without `_wpnonce`
	 * is the SSO start. A present nonce must still verify (dashboard button).
	 */
	public function maybe_redirect_open_cloud() {
		if ( empty( $_GET['rwcc_open_cloud'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! is_user_logged_in() ) {
			$this->capture_open_cloud_intent();
			if ( function_exists( 'is_account_page' ) && is_account_page() ) {
				return;
			}
			if ( function_exists( 'wp_safe_redirect' ) ) {
				wp_safe_redirect( $this->continue_url() );
				exit;
			}
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( $nonce !== '' && ( ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, 'rwcc_open_cloud' ) ) ) {
			return;
		}
		$subscription_id = isset( $_GET['subscription_id'] ) ? absint( $_GET['subscription_id'] ) : 0;
		$org_id          = '';
		if ( $subscription_id && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( $subscription && (int) $subscription->get_customer_id() === (int) get_current_user_id() ) {
				$used = RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_CLAIM_USED );
				if ( ! $used ) {
					$order  = method_exists( $subscription, 'get_parent' ) ? $subscription->get_parent() : null;
					$result = $this->lifecycle->activate( $subscription, $order );
					if ( ! empty( $result['activation_url'] ) ) {
						wp_safe_redirect( $result['activation_url'] );
						exit;
					}
				}
				$org_id = RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_ORG );
			}
		}
		if ( ! $this->identity ) {
			return;
		}
		$user   = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$email  = $user && isset( $user->user_email ) ? (string) $user->user_email : '';
		$issued = $this->identity->issue_login( get_current_user_id(), $email, $org_id );
		if ( ! empty( $issued['url'] ) ) {
			$this->clear_open_cloud_intent();
			wp_safe_redirect( $issued['url'] );
			exit;
		}
	}

	/**
	 * After WooCommerce login, keep the Cloud SSO query instead of a bare My Account URL.
	 *
	 * @param string       $redirect Redirect URL.
	 * @param object|null  $user     Logged-in user.
	 * @return string
	 */
	public function redirect_after_store_login( $redirect, $user = null ) {
		if ( $this->has_open_cloud_intent() ) {
			return $this->continue_url();
		}
		return $redirect;
	}

	/**
	 * @param string           $redirect_to           Default redirect.
	 * @param string           $requested_redirect_to Requested redirect.
	 * @param object|\WP_Error $user                  User or error.
	 * @return string
	 */
	public function redirect_after_wp_login( $redirect_to, $requested_redirect_to, $user ) {
		unset( $requested_redirect_to );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $user ) ) {
			return $redirect_to;
		}
		return $this->redirect_after_store_login( $redirect_to, $user );
	}

	/**
	 * My Account URL that continues the Cloud handoff after store authentication.
	 *
	 * @return string
	 */
	public function continue_url() {
		$base = function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( 'dashboard' )
			: ( function_exists( 'home_url' ) ? home_url( '/my-account/' ) : '/my-account/' );
		if ( function_exists( 'add_query_arg' ) ) {
			return add_query_arg( 'rwcc_open_cloud', '1', $base );
		}
		$joiner = strpos( (string) $base, '?' ) === false ? '?' : '&';
		return $base . $joiner . 'rwcc_open_cloud=1';
	}

	/**
	 * @return bool
	 */
	private function has_open_cloud_intent() {
		if ( ! empty( $_GET['rwcc_open_cloud'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		return ! empty( $_COOKIE[ self::INTENT_COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	private function capture_open_cloud_intent() {
		$this->set_open_cloud_cookie( '1', 600 );
	}

	private function clear_open_cloud_intent() {
		$this->set_open_cloud_cookie( '', -3600 );
		unset( $_COOKIE[ self::INTENT_COOKIE ] );
	}

	/**
	 * @param string $value  Cookie value.
	 * @param int    $ttl    Seconds from now, or negative to expire.
	 */
	private function set_open_cloud_cookie( $value, $ttl ) {
		$expire = time() + (int) $ttl;
		$path   = ( defined( 'COOKIEPATH' ) && COOKIEPATH ) ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '';
		$secure = function_exists( 'is_ssl' ) ? is_ssl() : true;
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				self::INTENT_COOKIE,
				(string) $value,
				array(
					'expires'  => $expire,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( self::INTENT_COOKIE, (string) $value, $expire, $path, $domain, $secure, true );
		}
		if ( $value !== '' && (int) $ttl > 0 ) {
			$_COOKIE[ self::INTENT_COOKIE ] = (string) $value;
		}
	}
}
