<?php
/**
 * WooCommerce Subscriptions lifecycle → signed Cloud events + activation claims.
 *
 * @package ReactWoo_Cloud_Commerce_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Lifecycle {

	/**
	 * @var RWCC_Settings
	 */
	private $settings;

	/**
	 * @var RWCC_Plan_Map
	 */
	private $plans;

	/**
	 * @var RWCC_Order_Meta
	 */
	private $meta;

	/**
	 * @var RWCC_Claims
	 */
	private $claims;

	/**
	 * @var RWCC_Webhooks
	 */
	private $webhooks;

	/**
	 * @var RWCC_Urls
	 */
	private $urls;

	/**
	 * @var RWCC_Identity_Client|null
	 */
	private $identity_client;

	/**
	 * @param RWCC_Settings   $settings Settings.
	 * @param RWCC_Plan_Map   $plans    Plan map.
	 * @param RWCC_Order_Meta $meta     Meta helper.
	 * @param RWCC_Claims     $claims   Claims.
	 * @param RWCC_Webhooks   $webhooks Webhooks.
	 * @param RWCC_Urls       $urls     URLs.
	 */
	public function __construct( RWCC_Settings $settings, RWCC_Plan_Map $plans, RWCC_Order_Meta $meta, RWCC_Claims $claims, RWCC_Webhooks $webhooks, RWCC_Urls $urls ) {
		$this->settings = $settings;
		$this->plans    = $plans;
		$this->meta     = $meta;
		$this->claims   = $claims;
		$this->webhooks = $webhooks;
		$this->urls     = $urls;
	}

	/**
	 * @param RWCC_Identity_Client $client Identity client.
	 */
	public function set_identity_client( RWCC_Identity_Client $client ) {
		$this->identity_client = $client;
	}

	/**
	 * Register Cloud-only WooCommerce hooks and API Manager observe-only listeners.
	 */
	public function register() {
		$this->register_cloud_only_hooks();
		$this->register_api_manager_listeners();
	}

	/**
	 * Surfaces API Manager does not own. Mapped products only at runtime.
	 */
	public function register_cloud_only_hooks() {
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'capture_handoff_on_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'capture_handoff_on_order' ), 20, 1 );
		add_action( 'woocommerce_subscriptions_switch_completed', array( $this, 'on_switch_completed' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 20, 2 );
		add_action( 'template_redirect', array( $this, 'intercept_handoff_query' ), 5 );
	}

	/**
	 * Consume API Manager licence actions. Do not re-hook the same WooCommerce events.
	 */
	public function register_api_manager_listeners() {
		add_action( 'reactwoo_license_generated', array( $this, 'on_license_generated' ), 10, 3 );
		add_action( 'reactwoo_license_renewed', array( $this, 'on_renewal' ), 10, 2 );
		add_action( 'reactwoo_license_payment_failed', array( $this, 'on_payment_failed' ), 10, 1 );
		add_action( 'reactwoo_license_status_synced', array( $this, 'on_license_status_synced' ), 10, 3 );
	}

	/**
	 * Cloud activate after API Manager stored a licence key.
	 *
	 * @param object      $subscription Subscription.
	 * @param object|null $order        Parent order.
	 * @param array       $license      Licence payload (unused).
	 */
	public function on_license_generated( $subscription, $order = null, $license = array() ) {
		unset( $license );
		return $this->activate( $subscription, $order );
	}

	/**
	 * Map API Manager status sync onto Cloud events. Skip active (activation
	 * already ran) and on-hold (payment_failed already ran).
	 *
	 * @param object $subscription Subscription.
	 * @param string $old_status   Previous status.
	 * @param string $new_status   New status.
	 */
	public function on_license_status_synced( $subscription, $old_status, $new_status ) {
		unset( $old_status );
		$new_status = strtolower( (string) $new_status );
		if ( $new_status === 'cancelled' || $new_status === 'canceled' ) {
			$this->on_cancelled( $subscription );
			return;
		}
		if ( $new_status === 'expired' ) {
			$this->on_expired( $subscription );
			return;
		}
		if ( $new_status === 'pending-cancel' ) {
			$this->emit( 'cancellation', $subscription, null, array( 'status' => 'pending-cancel' ) );
		}
	}

	/**
	 * Copy signed Cloud handoff onto the order at checkout.
	 *
	 * @param int|object $order Order id or object.
	 */
	public function capture_handoff_on_order( $order ) {
		$order = $this->as_order( $order );
		if ( ! $order ) {
			return;
		}
		$org  = isset( $_GET['rw_cloud_org'] ) ? sanitize_text_field( wp_unslash( $_GET['rw_cloud_org'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plan = isset( $_GET['rw_cloud_plan'] ) ? sanitize_text_field( wp_unslash( $_GET['rw_cloud_plan'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( function_exists( 'WC' ) && WC()->session ) {
			if ( $org === '' ) {
				$org = (string) WC()->session->get( 'rw_cloud_org' );
			}
			if ( $plan === '' ) {
				$plan = (string) WC()->session->get( 'rw_cloud_plan' );
			}
		}
		$line = $this->meta->qualifying_line( $order );
		if ( ! $line['plan'] && ! RWCC_Plan_Map::normalize_plan( $plan ) ) {
			return;
		}
		$this->meta->apply_qualifying_meta(
			$order,
			null,
			array(
				'org_id'     => $org,
				'plan'       => $line['plan'] ? $line['plan'] : $plan,
				'product_id' => $line['product_id'],
				'blog_id'    => $this->blog_id(),
			)
		);
	}

	/**
	 * @param int $order_id Order id.
	 */
	public function on_order_completed( $order_id ) {
		$order = $this->as_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$subscription = $this->subscription_for_order( $order );
		if ( $subscription ) {
			$this->activate( $subscription, $order );
		}
	}

	/**
	 * @param object $subscription Subscription.
	 */
	public function on_subscription_active( $subscription ) {
		$order = method_exists( $subscription, 'get_parent' ) ? $subscription->get_parent() : null;
		$this->activate( $subscription, $order );
	}

	/**
	 * @param object      $subscription Subscription.
	 * @param object|null $order        Renewal order.
	 */
	public function on_renewal( $subscription, $order = null ) {
		$this->emit( 'renewal', $subscription, $order );
	}

	/**
	 * @param object $subscription Subscription.
	 */
	public function on_payment_failed( $subscription ) {
		$this->emit( 'payment_failure', $subscription, null, array( 'status' => 'on-hold' ) );
	}

	/**
	 * @param object $subscription Subscription.
	 */
	public function on_cancelled( $subscription ) {
		$this->emit( 'cancellation', $subscription, null, array( 'status' => 'cancelled' ) );
	}

	/**
	 * @param object $subscription Subscription.
	 */
	public function on_expired( $subscription ) {
		$this->emit( 'expiry', $subscription, null, array( 'status' => 'expired' ) );
	}

	/**
	 * @param object $subscription Subscription.
	 * @param string $old_status   Previous status.
	 * @param string $new_status   New status.
	 */
	public function on_status_updated( $subscription, $old_status, $new_status ) {
		$new_status = strtolower( (string) $new_status );
		if ( in_array( $new_status, array( 'active', 'on-hold', 'cancelled', 'canceled', 'expired' ), true ) ) {
			return;
		}
		if ( $new_status === 'pending-cancel' ) {
			$this->emit( 'cancellation', $subscription, null, array( 'status' => 'pending-cancel' ) );
		}
	}

	/**
	 * @param object $order Switch order.
	 */
	public function on_switch_completed( $order ) {
		$order        = $this->as_order( $order );
		$subscription = $this->subscription_for_order( $order );
		if ( ! $subscription ) {
			return;
		}
		$line = $this->meta->qualifying_line( $subscription );
		if ( $line['plan'] ) {
			RWCC_Order_Meta::stamp(
				$subscription,
				array(
					RWCC_Order_Meta::META_PLAN    => $line['plan'],
					RWCC_Order_Meta::META_PRODUCT => (string) $line['product_id'],
				)
			);
		}
		$this->emit( 'plan_switch', $subscription, $order, array( 'plan' => $line['plan'], 'status' => method_exists( $subscription, 'get_status' ) ? $subscription->get_status() : 'active' ) );
	}

	/**
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 */
	public function on_refunded( $order_id, $refund_id = 0 ) {
		unset( $refund_id );
		$order        = $this->as_order( $order_id );
		$subscription = $this->subscription_for_order( $order );
		if ( ! $subscription ) {
			return;
		}
		$status = method_exists( $subscription, 'get_status' ) ? $subscription->get_status() : 'on-hold';
		$this->emit( 'refund', $subscription, $order, array( 'status' => $status ) );
	}

	/**
	 * Intercept Cloud-signed query params on checkout / My Account.
	 */
	public function intercept_handoff_query() {
		if ( empty( $_GET['rw_action'] ) || empty( $_GET['rw_sig'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$handoff = new RWCC_Handoff( $this->settings, $this->urls, $this->plans );
		$result  = $handoff->verify_request( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $result['ok'] ) ) {
			return;
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'rw_cloud_org', $result['org'] );
			WC()->session->set( 'rw_cloud_plan', $result['plan'] );
		}
		$dest = $handoff->destination(
			$result,
			array(
				'home'     => home_url(),
				'checkout' => wc_get_checkout_url(),
				'account'  => wc_get_page_permalink( 'myaccount' ),
				'orders'   => wc_get_account_endpoint_url( 'orders' ),
			)
		);
		$current = home_url( add_query_arg( array() ) );
		if ( $dest && strtok( $dest, '?' ) !== strtok( $current, '?' ) ) {
			wp_safe_redirect( $dest );
			exit;
		}
	}

	/**
	 * Core activation: meta + claim + webhook. Safe to retry.
	 *
	 * @param object      $subscription Subscription.
	 * @param object|null $order        Parent/related order.
	 * @return array
	 */
	public function activate( $subscription, $order = null ) {
		$line = $this->meta->qualifying_line( $subscription );
		if ( ! $line['plan'] && $order ) {
			$line = $this->meta->qualifying_line( $order );
		}
		if ( ! $line['plan'] ) {
			return array( 'ok' => false, 'error' => 'not_cloud_product' );
		}

		$org = RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_ORG );
		if ( $org === '' && $order ) {
			$org = RWCC_Order_Meta::get( $order, RWCC_Order_Meta::META_ORG );
		}

		$customer_id = method_exists( $subscription, 'get_customer_id' ) ? (int) $subscription->get_customer_id() : 0;
		$order_id    = $order && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		if ( ! $order_id && method_exists( $subscription, 'get_parent_id' ) ) {
			$order_id = (int) $subscription->get_parent_id();
		}

		$identity_email = RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_IDENTITY_EMAIL );
		if ( $identity_email === '' && $order && method_exists( $order, 'get_billing_email' ) ) {
			$identity_email = (string) $order->get_billing_email();
		}

		$applied = $this->meta->apply_qualifying_meta(
			$order,
			$subscription,
			array(
				'org_id'            => $org,
				'plan'              => $line['plan'],
				'product_id'        => $line['product_id'],
				'identity_user'     => $customer_id,
				'identity_email'    => $identity_email,
				'identity_subject'  => RWCC_Identity::subject_for_user( $customer_id ),
				'blog_id'           => $this->blog_id(),
			)
		);

		$issued = $this->claims->issue(
			array(
				'customer_id'         => $customer_id,
				'order_id'            => $order_id,
				'subscription_id'     => method_exists( $subscription, 'get_id' ) ? (int) $subscription->get_id() : 0,
				'plan'                => $line['plan'],
				'org_id'              => $org,
				'identity_user'       => $customer_id,
				'identity_email'      => $identity_email,
				'identity_subject'    => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_IDENTITY_SUBJECT ),
				'provisioning_id'     => $applied[ RWCC_Order_Meta::META_PROVISIONING ],
				'blog_id'             => $this->blog_id(),
				'already_provisioned' => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_PROVISIONED ) === '1',
			)
		);

		if ( ! empty( $issued['hash'] ) ) {
			RWCC_Order_Meta::stamp(
				$subscription,
				array(
					RWCC_Order_Meta::META_CLAIM_HASH    => $issued['hash'],
					RWCC_Order_Meta::META_CLAIM_EXPIRES => isset( $issued['expires_at'] ) ? (string) $issued['expires_at'] : '',
				)
			);
		}

		$activation_url = '';
		if ( ! empty( $issued['token'] ) ) {
			$return = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
			$activation_url = $this->urls->activation_url(
				$this->settings->cloud_origin(),
				(string) $this->settings->get( 'activation_path' ),
				$issued['token'],
				$return
			);
			if ( function_exists( 'do_action' ) ) {
				do_action( 'rwcc_claim_issued', $issued, $subscription, $activation_url );
			}
			if ( $this->identity_client ) {
				$this->identity_client->register_claim(
					RWCC_Identity::registration_body(
						array(
							'purpose'         => 'activation',
							'subject'         => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_IDENTITY_SUBJECT ),
							'hash'            => $issued['hash'],
							'email'           => $identity_email,
							'organisation_id' => $org,
							'intended_role'   => 'owner',
							'customer_id'     => (string) $customer_id,
							'order_id'        => (string) $order_id,
							'subscription_id' => (string) ( method_exists( $subscription, 'get_id' ) ? $subscription->get_id() : 0 ),
							'secret'          => (string) $this->settings->get( 'handoff_secret' ),
							'ttl'             => (int) $this->settings->get( 'claim_ttl_sec' ),
						)
					)
				);
			}
		}

		$delivery = $this->emit(
			'activation',
			$subscription,
			$order,
			array(
				'plan'          => $line['plan'],
				'status'        => 'active',
				'claim_hash'    => isset( $issued['hash'] ) ? $issued['hash'] : '',
				'claim_expires' => isset( $issued['expires_at'] ) ? $issued['expires_at'] : '',
			)
		);
		$this->stamp_org_from_delivery( $subscription, $order, $delivery );

		return array(
			'ok'              => true,
			'claim'           => $issued,
			'activation_url'  => $activation_url,
			'webhook'         => $delivery,
			'provisioning_id' => $applied[ RWCC_Order_Meta::META_PROVISIONING ],
		);
	}

	/**
	 * @param string      $event        Lifecycle event.
	 * @param object      $subscription Subscription.
	 * @param object|null $order        Related order.
	 * @param array       $overrides    Status/plan overrides.
	 * @return array
	 */
	public function emit( $event, $subscription, $order = null, array $overrides = array() ) {
		$line = $this->meta->qualifying_line( $subscription );
		if ( ! $line['plan'] && $order ) {
			$line = $this->meta->qualifying_line( $order );
		}
		$plan = isset( $overrides['plan'] ) && $overrides['plan'] ? $overrides['plan'] : $line['plan'];
		if ( ! $plan ) {
			$plan = RWCC_Plan_Map::normalize_plan( RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_PLAN ) );
		}
		if ( ! $plan ) {
			return array( 'ok' => false, 'error' => 'not_cloud_product' );
		}

		$next = null;
		if ( method_exists( $subscription, 'get_date' ) ) {
			$next = $subscription->get_date( 'next_payment', 'gmt' );
		}

		$payload = RWCC_Payload::build(
			array(
				'event'                 => $event,
				'status'                => isset( $overrides['status'] ) ? $overrides['status'] : ( method_exists( $subscription, 'get_status' ) ? $subscription->get_status() : 'active' ),
				'plan'                  => $plan,
				'org_id'                => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_ORG ),
				'subscription_id'       => method_exists( $subscription, 'get_id' ) ? $subscription->get_id() : 0,
				'customer_id'           => method_exists( $subscription, 'get_customer_id' ) ? $subscription->get_customer_id() : 0,
				'order_id'              => $order && method_exists( $order, 'get_id' ) ? $order->get_id() : 0,
				'product_id'            => $line['product_id'] ? $line['product_id'] : RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_PRODUCT ),
				'variation_id'          => $line['variation_id'],
				'provisioning_id'       => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_PROVISIONING ),
				'identity_user'         => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_IDENTITY_USER ),
				'identity_email'        => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_IDENTITY_EMAIL ),
				'identity_subject'      => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_IDENTITY_SUBJECT ),
				'identity_issuer'       => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_IDENTITY_ISSUER ),
				'claim_hash'            => isset( $overrides['claim_hash'] ) ? $overrides['claim_hash'] : RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_CLAIM_HASH ),
				'claim_expires'         => isset( $overrides['claim_expires'] ) ? $overrides['claim_expires'] : RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_CLAIM_EXPIRES ),
				'next_payment_date_gmt' => $next,
				'replay_window_sec'     => $this->settings->replay_window_sec(),
			)
		);

		return $this->webhooks->deliver( $payload, RWCC_Payload::topic_for_event( $event ) );
	}

	/**
	 * Persist the Cloud organisation id returned by a successful webhook.
	 *
	 * @param object      $subscription Subscription.
	 * @param object|null $order        Related order.
	 * @param array       $delivery     Webhook result.
	 */
	private function stamp_org_from_delivery( $subscription, $order, array $delivery ) {
		$body = isset( $delivery['body'] ) ? $delivery['body'] : '';
		if ( ! is_string( $body ) || $body === '' ) {
			return;
		}
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return;
		}
		$org = isset( $decoded['organisation_id'] ) ? (string) $decoded['organisation_id'] : '';
		if ( $org === '' ) {
			return;
		}
		RWCC_Order_Meta::stamp( $subscription, array( RWCC_Order_Meta::META_ORG => $org ) );
		if ( $order ) {
			RWCC_Order_Meta::stamp( $order, array( RWCC_Order_Meta::META_ORG => $org ) );
		}
	}

	/**
	 * @param object $subscription Subscription.
	 * @return array
	 */
	public function reconcile_from_subscription( $subscription ) {
		$line = $this->meta->qualifying_line( $subscription );
		$next = method_exists( $subscription, 'get_date' ) ? $subscription->get_date( 'next_payment', 'gmt' ) : null;
		$parent_id = 0;
		if ( method_exists( $subscription, 'get_parent_id' ) ) {
			$parent_id = (int) $subscription->get_parent_id();
		}
		return RWCC_Reconcile::snapshot(
			array(
				'subscription_id'       => method_exists( $subscription, 'get_id' ) ? $subscription->get_id() : 0,
				'customer_id'           => method_exists( $subscription, 'get_customer_id' ) ? $subscription->get_customer_id() : 0,
				'order_id'              => $parent_id,
				'status'                => method_exists( $subscription, 'get_status' ) ? $subscription->get_status() : '',
				'plan'                  => $line['plan'] ? $line['plan'] : RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_PLAN ),
				'org_id'                => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_ORG ),
				'provisioning_id'       => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_PROVISIONING ),
				'product_id'            => $line['product_id'] ? $line['product_id'] : RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_PRODUCT ),
				'variation_id'          => $line['variation_id'],
				'next_payment_date_gmt' => $next,
				'identity_user'         => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_IDENTITY_USER ),
				'identity_email'        => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_IDENTITY_EMAIL ),
				'claim_hash'            => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_CLAIM_HASH ),
				'claim_expires'         => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_CLAIM_EXPIRES ),
				'claim_used'            => RWCC_Order_Meta::get( $subscription, RWCC_Order_Meta::META_CLAIM_USED ),
			)
		);
	}

	/**
	 * @return int
	 */
	private function blog_id() {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
	}

	/**
	 * @param mixed $order Order.
	 * @return object|null
	 */
	private function as_order( $order ) {
		if ( is_object( $order ) ) {
			return $order;
		}
		if ( function_exists( 'wc_get_order' ) ) {
			$obj = wc_get_order( (int) $order );
			return $obj ? $obj : null;
		}
		return null;
	}

	/**
	 * @param object $order Order.
	 * @return object|null
	 */
	private function subscription_for_order( $order ) {
		if ( ! $order ) {
			return null;
		}
		if ( is_object( $order ) && ( is_a( $order, 'WC_Subscription' ) || ( method_exists( $order, 'get_type' ) && $order->get_type() === 'shop_subscription' ) ) ) {
			return $order;
		}
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subs = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
			if ( is_array( $subs ) ) {
				return reset( $subs ) ? reset( $subs ) : null;
			}
		}
		return null;
	}
}
