<?php
/**
 * ReactWoo Commerce Bridge bootstrap.
 *
 * Isolated Decision Cloud companion: product→plan mapping, order meta,
 * hashed activation claims, signed WooCommerce webhooks, handoff + reconcile REST.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Bootstrap {

	/**
	 * @var RWCC_Bootstrap|null
	 */
	private static $instance = null;

	/**
	 * @var RWCC_Lifecycle
	 */
	public $lifecycle;

	/**
	 * Load isolated module classes and register hooks.
	 */
	public static function init() {
		if ( null !== self::$instance ) {
			return self::$instance;
		}
		$dir = __DIR__ . '/';
		require_once $dir . 'class-rwcc-crypto.php';
		require_once $dir . 'class-rwcc-identity.php';
		require_once $dir . 'class-rwcc-identity-client.php';
		require_once $dir . 'class-rwcc-settings.php';
		require_once $dir . 'class-rwcc-plan-map.php';
		require_once $dir . 'class-rwcc-coverage.php';
		require_once $dir . 'class-rwcc-transition.php';
		require_once $dir . 'class-rwcc-upgrade-credit.php';
		require_once $dir . 'class-rwcc-checkout-credit.php';
		require_once $dir . 'class-rwcc-downgrade.php';
		require_once $dir . 'class-rwcc-scheduled-subscription.php';
		require_once $dir . 'class-rwcc-entitlement-handover.php';
		require_once $dir . 'class-rwcc-licence-reuse.php';
		require_once $dir . 'class-rwcc-product-copy.php';
		require_once $dir . 'class-rwcc-overlap.php';
		require_once $dir . 'class-rwcc-supersession.php';
		require_once $dir . 'class-rwcc-urls.php';
		require_once $dir . 'class-rwcc-handoff.php';
		require_once $dir . 'class-rwcc-claims.php';
		require_once $dir . 'class-rwcc-replay.php';
		require_once $dir . 'class-rwcc-payload.php';
		require_once $dir . 'class-rwcc-webhooks.php';
		require_once $dir . 'class-rwcc-reconcile.php';
		require_once $dir . 'class-rwcc-store.php';
		require_once $dir . 'class-rwcc-order-meta.php';
		require_once $dir . 'class-rwcc-lifecycle.php';
		require_once $dir . 'class-rwcc-rest.php';
		require_once $dir . 'class-rwcc-product-fields.php';
		require_once $dir . 'class-rwcc-admin.php';
		require_once $dir . 'class-rwcc-account.php';

		self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		$settings = RWCC_Settings::from_wordpress();
		$plans    = new RWCC_Plan_Map( $settings->product_map() );
		$urls     = RWCC_Urls::from_settings( $settings );
		$store    = new RWCC_Store( true );
		$claims   = $store->claims_service( $settings );
		$replay   = $store->replay_service();
		$webhooks = new RWCC_Webhooks( $settings, $replay );
		$meta     = new RWCC_Order_Meta( $plans );
		$lifecycle = new RWCC_Lifecycle( $settings, $plans, $meta, $claims, $webhooks, $urls );
		$handoff   = new RWCC_Handoff( $settings, $urls, $plans );
		$identity_client = new RWCC_Identity_Client( $settings );
		$lifecycle->set_identity_client( $identity_client );

		$this->lifecycle = $lifecycle;
		$lifecycle->register();

		$rest = new RWCC_REST( $settings, $handoff, $lifecycle );
		$rest->register();

		$fields = new RWCC_Product_Fields();
		$fields->register();

		$account = new RWCC_Account( $lifecycle, $identity_client );
		$account->register();

		$checkout_credit = new RWCC_Checkout_Credit( $settings, $plans );
		$checkout_credit->register();

		$product_copy = new RWCC_Product_Copy();
		$product_copy->register();
		add_filter( 'reactwoo_license_provision_plan_code', array( 'RWCC_Licence_Reuse', 'provision_plan_code' ), 10, 4 );
		add_action( 'template_redirect', array( $account, 'maybe_redirect_activation' ), 6 );
		add_action( 'template_redirect', array( $account, 'maybe_redirect_open_cloud' ), 7 );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_cloud_host' ) );

		if ( is_admin() ) {
			$admin = new RWCC_Admin();
			$admin->register();
		}
	}

	/**
	 * Allow Decision Cloud origin for wp_safe_redirect (activation URLs).
	 *
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public function allow_cloud_host( $hosts ) {
		$settings = RWCC_Settings::from_wordpress();
		$origin   = $settings->cloud_origin();
		if ( $origin === '' ) {
			return $hosts;
		}
		$parts = parse_url( $origin );
		if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
			$hosts[] = $parts['host'];
		}
		return $hosts;
	}
}
