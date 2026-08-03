<?php
/**
 * License Display helpers
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReactWoo_License_Display {

	public function __construct() {
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'add_license_order_meta' ), 10, 3 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'maybe_add_license_to_email' ), 20, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'print_license_on_order_page' ), 15, 1 );
		add_action( 'woocommerce_subscription_details_after_order_table', array( $this, 'print_license_on_subscription_page' ), 15, 1 );
		add_action( 'wcs_view_subscription', array( $this, 'print_license_on_subscription_page' ), 15, 1 );
		add_action( 'init', array( $this, 'register_license_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'register_wc_query_var' ) );
		add_action( 'template_redirect', array( $this, 'log_account_request' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_account_root' ), 5 );
		add_action( 'template_redirect', array( $this, 'maybe_handle_plugin_download' ), 6 );
		add_action( 'template_redirect', array( $this, 'maybe_handle_license_download' ) );
		add_filter( 'woocommerce_customer_available_downloads', array( $this, 'inject_plugin_downloads' ), 20, 2 );
		add_filter( 'wp_redirect', array( $this, 'log_redirects_on_account' ), 10, 2 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_license_menu_item' ), 20 );
		add_action( 'woocommerce_account_license_endpoint', array( $this, 'render_license_endpoint' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_account_assets' ) );
		add_shortcode( 'reactwoo_license_keys', array( $this, 'render_license_shortcode' ) );
		add_shortcode( 'license_keys', array( $this, 'render_license_shortcode' ) );
	}

	/**
	 * Register rewrite endpoint for license display.
	 */
	public function register_license_endpoint() {
		add_rewrite_endpoint( 'license', EP_ROOT | EP_PAGES );
	}

	/**
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'reactwoo_license_download';
		$vars[] = ReactWoo_Plugin_Download_Service::QUERY_VAR;
		return $vars;
	}

	/**
	 * Inject entitled plugin ZIPs into WooCommerce Downloads.
	 *
	 * @param array<int, array<string, mixed>> $downloads Downloads.
	 * @param int                              $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function inject_plugin_downloads( $downloads, $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return $downloads;
		}
		if ( ! is_array( $downloads ) ) {
			$downloads = array();
		}

		$subscriptions = wcs_get_users_subscriptions( $user_id );
		if ( ! is_array( $subscriptions ) ) {
			return $downloads;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription instanceof WC_Subscription ) {
				continue;
			}
			if ( (int) $subscription->get_customer_id() !== $user_id ) {
				continue;
			}
			$file = ReactWoo_Plugin_Download_Service::build_synthetic_file( $subscription );
			if ( ! $file ) {
				continue;
			}

			$product_name = __( 'ReactWoo plugin', 'reactwoo-api-manager' );
			$product_id   = 0;
			foreach ( $subscription->get_items() as $item ) {
				$product_name = $item->get_name();
				$product      = $item->get_product();
				if ( $product ) {
					$product_id = (int) $product->get_id();
				}
				break;
			}

			$downloads[] = array(
				'download_url'         => $file['url'],
				'download_id'          => 'reactwoo-plugin-' . (int) $subscription->get_id(),
				'product_id'           => $product_id,
				'product_name'         => $product_name,
				'download_name'        => $file['name'],
				'order_id'             => 0,
				'downloads_remaining'  => '',
				'access_expires'       => null,
				'file'                 => array(
					'name' => $file['name'],
					'file' => $file['url'],
				),
			);
		}

		return $downloads;
	}

	/**
	 * Entitled My Account plugin ZIP: nonce + ownership → 302 to signed R2 URL.
	 */
	public function maybe_handle_plugin_download() {
		$subscription_id = absint( get_query_var( ReactWoo_Plugin_Download_Service::QUERY_VAR ) );
		if ( ! $subscription_id && isset( $_GET[ ReactWoo_Plugin_Download_Service::QUERY_VAR ] ) ) {
			$subscription_id = absint( wp_unslash( $_GET[ ReactWoo_Plugin_Download_Service::QUERY_VAR ] ) );
		}
		if ( ! $subscription_id ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, ReactWoo_Plugin_Download_Service::QUERY_VAR . '_' . $subscription_id ) ) {
			wp_die( esc_html__( 'Invalid download request.', 'reactwoo-api-manager' ), 403 );
		}

		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : null;
		if ( ! $subscription instanceof WC_Subscription ) {
			wp_die( esc_html__( 'Download not found.', 'reactwoo-api-manager' ), 404 );
		}
		if ( (int) $subscription->get_customer_id() !== (int) get_current_user_id() ) {
			wp_die( esc_html__( 'Download not found.', 'reactwoo-api-manager' ), 404 );
		}
		if ( ! ReactWoo_Plugin_Download_Service::subscription_can_download( $subscription ) ) {
			wp_die( esc_html__( 'Download not available for this subscription.', 'reactwoo-api-manager' ), 403 );
		}

		$slug = ReactWoo_Plugin_Download_Service::get_plugin_slug_for_subscription( $subscription );
		if ( $slug === '' ) {
			wp_die( esc_html__( 'Plugin download is not configured for this product.', 'reactwoo-api-manager' ), 404 );
		}

		$result = ReactWoo_Plugin_Download_Service::request_store_download( $slug );
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 502;
			wp_die( esc_html( $result->get_error_message() ), $status ? $status : 502 );
		}

		$download_url = esc_url_raw( (string) $result['download_url'] );
		if ( $download_url === '' || 0 !== strpos( $download_url, 'https://' ) ) {
			wp_die( esc_html__( 'Download unavailable.', 'reactwoo-api-manager' ), 502 );
		}

		nocache_headers();
		// Signed R2 URLs are off-site; wp_safe_redirect would reject them.
		wp_redirect( $download_url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Register with WooCommerce so is_wc_endpoint_url( 'license' ) works.
	 *
	 * Without this, theme/plugins that gate on is_wc_endpoint_url() treat
	 * /my-account/license/ as the account root and can redirect forever.
	 *
	 * @param array $vars Woo query vars.
	 * @return array
	 */
	public function register_wc_query_var( $vars ) {
		$vars['license'] = 'license';
		return $vars;
	}

	/**
	 * Log account page requests for loop diagnosis.
	 */
	public function log_account_request() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		ReactWoo_Account_Logger::log( 'account template_redirect', ReactWoo_Account_Logger::request_context() );
	}

	/**
	 * Log every wp_redirect while on My Account (catches theme/other plugins too).
	 *
	 * @param string $location Redirect URL.
	 * @param int    $status   HTTP status.
	 * @return string
	 */
	public function log_redirects_on_account( $location, $status = 302 ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$on_account = ( function_exists( 'is_account_page' ) && is_account_page() )
			|| ( false !== stripos( $uri, 'my-account' ) )
			|| ( false !== stripos( (string) $location, 'my-account' ) );

		if ( $on_account ) {
			$trace = array();
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 ) as $frame ) {
				if ( empty( $frame['file'] ) ) {
					continue;
				}
				$trace[] = basename( $frame['file'] ) . ':' . ( isset( $frame['line'] ) ? $frame['line'] : 0 );
			}
			ReactWoo_Account_Logger::log(
				'wp_redirect observed',
				array_merge(
					ReactWoo_Account_Logger::request_context(),
					array(
						'to'     => $location,
						'status' => (int) $status,
						'trace'  => $trace,
					)
				)
			);
		}

		return $location;
	}

	/**
	 * Root → Products & licences redirect.
	 *
	 * Disabled by default to stop redirect loops. Re-enable only via:
	 * define( 'REACTWOO_API_MANAGER_ACCOUNT_REDIRECT', true );
	 */
	public function maybe_redirect_account_root() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) {
			return;
		}

		$enabled = defined( 'REACTWOO_API_MANAGER_ACCOUNT_REDIRECT' ) && REACTWOO_API_MANAGER_ACCOUNT_REDIRECT;
		if ( ! $enabled ) {
			ReactWoo_Account_Logger::log(
				'account root redirect skipped (disabled)',
				ReactWoo_Account_Logger::request_context()
			);
			return;
		}

		if ( ! function_exists( 'reactwoo_api_manager_rewrites_ready' ) || ! reactwoo_api_manager_rewrites_ready() ) {
			ReactWoo_Account_Logger::log( 'account root redirect skipped (rewrites not ready)', ReactWoo_Account_Logger::request_context() );
			return;
		}

		if ( $this->is_license_endpoint_request() ) {
			ReactWoo_Account_Logger::log( 'account root redirect skipped (already on license)', ReactWoo_Account_Logger::request_context() );
			return;
		}

		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			ReactWoo_Account_Logger::log( 'account root redirect skipped (other endpoint)', ReactWoo_Account_Logger::request_context() );
			return;
		}

		global $wp;
		$query_vars = ( isset( $wp->query_vars ) && is_array( $wp->query_vars ) ) ? $wp->query_vars : array();
		$known      = array(
			'orders',
			'view-order',
			'downloads',
			'edit-address',
			'payment-methods',
			'edit-account',
			'customer-logout',
			'license',
			'subscriptions',
			'view-subscription',
		);

		foreach ( $known as $key ) {
			if ( array_key_exists( $key, $query_vars ) ) {
				ReactWoo_Account_Logger::log(
					'account root redirect skipped (query var present)',
					array_merge( ReactWoo_Account_Logger::request_context(), array( 'matched' => $key ) )
				);
				return;
			}
		}

		$target = wc_get_account_endpoint_url( 'license' );
		if ( ! $target ) {
			ReactWoo_Account_Logger::log( 'account root redirect skipped (no target url)', ReactWoo_Account_Logger::request_context() );
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$current     = home_url( $path ? $path : '/' );
		if ( untrailingslashit( $current ) === untrailingslashit( $target ) ) {
			ReactWoo_Account_Logger::log( 'account root redirect skipped (same url)', ReactWoo_Account_Logger::request_context() );
			return;
		}

		ReactWoo_Account_Logger::log(
			'account root redirect issuing',
			array_merge( ReactWoo_Account_Logger::request_context(), array( 'to' => $target ) )
		);
		wp_safe_redirect( $target, 302 );
		exit;
	}

	/**
	 * Whether the current request already targets the licence endpoint.
	 *
	 * @return bool
	 */
	private function is_license_endpoint_request() {
		global $wp;
		if ( isset( $wp->query_vars['license'] ) ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( $path === '' ) {
			return false;
		}

		$account_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'myaccount' ) : 0;
		$slug       = 'my-account';
		if ( $account_id > 0 ) {
			$account_post = get_post( $account_id );
			if ( $account_post && ! empty( $account_post->post_name ) ) {
				$slug = $account_post->post_name;
			}
		}

		$pattern = '#' . preg_quote( '/' . trim( $slug, '/' ) . '/license', '#' ) . '(?:/|$)#i';
		return (bool) preg_match( $pattern, trailingslashit( $path ) );
	}

	/**
	 * Enqueue account UI assets on the licence endpoint / shortcode contexts.
	 */
	public function enqueue_account_assets() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		$css = REACTWOO_API_MANAGER_PLUGIN_URL . 'assets/css/account.css';
		$js  = REACTWOO_API_MANAGER_PLUGIN_URL . 'assets/js/account.js';

		wp_enqueue_style(
			'reactwoo-api-manager-account',
			$css,
			array(),
			REACTWOO_API_MANAGER_VERSION
		);

		wp_enqueue_script(
			'reactwoo-api-manager-account',
			$js,
			array(),
			REACTWOO_API_MANAGER_VERSION,
			true
		);

		wp_localize_script(
			'reactwoo-api-manager-account',
			'reactwooAccount',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'reactwoo/v1/account/licenses/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'copy'        => __( 'Copy key', 'reactwoo-api-manager' ),
					'copied'      => __( 'Copied', 'reactwoo-api-manager' ),
					'copyFailed'  => __( 'Could not copy key. Please try again.', 'reactwoo-api-manager' ),
					'unavailable' => __( 'Key temporarily unavailable.', 'reactwoo-api-manager' ),
					'pending'     => __( 'Provisioning…', 'reactwoo-api-manager' ),
					'changeDomain'=> __( 'Contact support to change your registered website.', 'reactwoo-api-manager' ),
				),
			)
		);
	}

	/**
	 * Label and prioritise Products & licences; remove Dashboard when licence endpoint is available.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function add_license_menu_item( $items ) {
		// Keep Dashboard always — removing it can make WooCommerce redirect the
		// account root and contribute to redirect loops with /license/.
		$rebuilt = array();
		$rebuilt['license'] = __( 'Products & licences', 'reactwoo-api-manager' );

		foreach ( $items as $key => $label ) {
			if ( 'license' === $key ) {
				continue;
			}
			$rebuilt[ $key ] = $label;
		}

		return $rebuilt;
	}

	/**
	 * Display Products & licences endpoint.
	 */
	public function render_license_endpoint() {
		try {
			$records = reactwoo_api_manager_get_customer_account_records( get_current_user_id() );
		} catch ( Exception $e ) {
			$records = array();
			echo '<p>' . esc_html__( 'Unable to load your products right now. Please try again shortly.', 'reactwoo-api-manager' ) . '</p>';
			return;
		}

		wc_get_template(
			'myaccount/license.php',
			array(
				'reactwoo_records' => is_array( $records ) ? $records : array(),
			),
			'',
			REACTWOO_API_MANAGER_PLUGIN_DIR . 'templates/'
		);
	}

	/**
	 * Compact shortcode using the same service.
	 *
	 * @return string
	 */
	public function render_license_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your licences.', 'reactwoo-api-manager' ) . '</p>';
		}

		$this->enqueue_account_assets();

		$records = reactwoo_api_manager_get_customer_account_records( get_current_user_id() );

		ob_start();
		wc_get_template(
			'myaccount/license-compact.php',
			array(
				'reactwoo_records' => $records,
			),
			'',
			REACTWOO_API_MANAGER_PLUGIN_DIR . 'templates/'
		);
		return ob_get_clean();
	}

	/**
	 * Append licence notice to completed order email (local meta only; no domain lookup).
	 */
	public function maybe_add_license_to_email( $order, $sent_to_admin, $plain_text, $email ) {
		if ( ! $order instanceof WC_Order || 'customer_completed_order' !== $email->id ) {
			return;
		}

		$license = $this->get_local_order_license( $order );
		if ( ! $license ) {
			return;
		}

		$account_url = wc_get_account_endpoint_url( 'license' );
		echo '<div class="reactwoo-license-block" style="margin:24px 0;padding:16px;border:1px solid #c7c7c7;border-radius:6px;background:#fafbfc;">';
		echo '<h2 style="margin-top:0;font-size:18px;">' . esc_html__( 'Your licence', 'reactwoo-api-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'Your licence key is ready in My Account → Products & licences.', 'reactwoo-api-manager' ) . '</p>';
		if ( ! empty( $license['domain'] ) ) {
			echo '<p><strong>' . esc_html__( 'Registered website', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $license['domain'] ) . '</p>';
		}
		if ( $account_url ) {
			echo '<p><a href="' . esc_url( $account_url ) . '">' . esc_html__( 'Open Products & licences', 'reactwoo-api-manager' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Order view: masked key only.
	 *
	 * @param WC_Order $order Order.
	 */
	public function print_license_on_order_page( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$license = $this->get_local_order_license( $order );
		if ( ! $license ) {
			return;
		}

		$this->print_masked_license_block( $license );
	}

	/**
	 * Subscription view: masked key only.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 */
	public function print_license_on_subscription_page( $subscription ) {
		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		$key    = (string) $subscription->get_meta( '_reactwoo_license_key', true );
		$domain = (string) $subscription->get_meta( '_reactwoo_license_domain', true );
		if ( $domain === '' ) {
			$parent = $subscription->get_parent();
			if ( $parent instanceof WC_Order ) {
				$domain = (string) $parent->get_meta( '_reactwoo_domain', true );
			}
		}
		if ( $key === '' ) {
			$parent = $subscription->get_parent();
			if ( $parent instanceof WC_Order ) {
				$key = (string) $parent->get_meta( '_reactwoo_license_key', true );
			}
		}
		if ( $key === '' ) {
			return;
		}

		$this->print_masked_license_block(
			array(
				'key'             => $key,
				'domain'          => $domain,
				'subscription_id' => $subscription->get_id(),
			)
		);
	}

	/**
	 * Email meta: point customers to account; never embed full keys in HTML emails by default.
	 *
	 * @param array    $fields Fields.
	 * @param bool     $sent_to_admin Admin flag.
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public function add_license_order_meta( $fields, $sent_to_admin, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $fields;
		}

		$license = $this->get_local_order_license( $order );
		if ( empty( $license ) ) {
			$fields['reactwoo_license_key'] = array(
				'label' => __( 'Licence', 'reactwoo-api-manager' ),
				'value' => __( 'Pending — check My Account → Products & licences shortly.', 'reactwoo-api-manager' ),
			);
			return $fields;
		}

		$fields['reactwoo_license_key'] = array(
			'label' => __( 'Licence', 'reactwoo-api-manager' ),
			'value' => __( 'Available in My Account → Products & licences (secure copy).', 'reactwoo-api-manager' ),
		);

		if ( ! empty( $license['domain'] ) ) {
			$fields['reactwoo_license_domain'] = array(
				'label' => __( 'Registered website', 'reactwoo-api-manager' ),
				'value' => $license['domain'],
			);
		}

		return $fields;
	}

	/**
	 * Optional secure text download (nonce + ownership). Not linked from primary account UI.
	 */
	public function maybe_handle_license_download() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$download = get_query_var( 'reactwoo_license_download' );
		if ( ! $download ) {
			return;
		}

		$subscription_id = absint( $download );
		$nonce           = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! $subscription_id || ! $nonce || ! wp_verify_nonce( $nonce, 'reactwoo_license_download_' . $subscription_id ) ) {
			wp_die( esc_html__( 'Invalid download request.', 'reactwoo-api-manager' ), 403 );
		}

		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : null;
		if ( ! $subscription instanceof WC_Subscription ) {
			wp_die( esc_html__( 'Licence not found.', 'reactwoo-api-manager' ), 404 );
		}
		if ( (int) $subscription->get_customer_id() !== (int) get_current_user_id() ) {
			wp_die( esc_html__( 'Licence not found.', 'reactwoo-api-manager' ), 404 );
		}

		$key = ReactWoo_Customer_Account_Service::get_instance()->get_owned_license_key( $subscription );
		if ( is_wp_error( $key ) ) {
			$data   = $key->get_error_data();
			$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 404;
			wp_die( esc_html( $key->get_error_message() ), $status );
		}

		$domain = (string) $subscription->get_meta( '_reactwoo_license_domain', true );
		if ( $domain === '' ) {
			$parent = $subscription->get_parent();
			if ( $parent instanceof WC_Order ) {
				$domain = (string) $parent->get_meta( '_reactwoo_domain', true );
			}
		}

		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="reactwoo-license-subscription-' . $subscription_id . '.txt"' );

		echo "ReactWoo Licence Details\n\n";
		echo "Subscription ID: {$subscription_id}\n";
		echo 'Registered website: ' . ( $domain !== '' ? $domain : '(not set)' ) . "\n\n";
		echo "Licence key:\n{$key}\n\n";
		echo "Keep this file private. You can also copy your key from My Account → Products & licences.\n";
		exit;
	}

	/**
	 * Local order meta only — no public domain API lookup.
	 *
	 * @param WC_Order $order Order.
	 * @return array{key:string,domain:string}|null
	 */
	private function get_local_order_license( $order ) {
		$key    = (string) $order->get_meta( '_reactwoo_license_key', true );
		$domain = (string) $order->get_meta( '_reactwoo_license_domain', true );
		if ( $domain === '' ) {
			$domain = (string) $order->get_meta( '_reactwoo_domain', true );
		}
		if ( $key === '' ) {
			return null;
		}
		return array(
			'key'    => $key,
			'domain' => $domain,
		);
	}

	/**
	 * @param array $license License data with key/domain.
	 */
	private function print_masked_license_block( $license ) {
		if ( empty( $license['key'] ) ) {
			return;
		}

		$masked = ReactWoo_Customer_Account_Service::mask_license_key( $license['key'] );
		$account_url = wc_get_account_endpoint_url( 'license' );

		echo '<div class="reactwoo-license-block" style="margin:24px 0;padding:16px;border:1px solid #c7c7c7;border-radius:6px;background:#fafbfc;">';
		echo '<h2 style="margin-top:0;font-size:18px;">' . esc_html__( 'Your licence', 'reactwoo-api-manager' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Licence key', 'reactwoo-api-manager' ) . ':</strong> <code>' . esc_html( $masked ) . '</code></p>';
		if ( ! empty( $license['domain'] ) ) {
			echo '<p><strong>' . esc_html__( 'Registered website', 'reactwoo-api-manager' ) . ':</strong> ' . esc_html( $license['domain'] ) . '</p>';
		}
		if ( $account_url ) {
			echo '<p><a class="button" href="' . esc_url( $account_url ) . '">' . esc_html__( 'Manage in Products & licences', 'reactwoo-api-manager' ) . '</a></p>';
		}
		echo '</div>';
	}
}
