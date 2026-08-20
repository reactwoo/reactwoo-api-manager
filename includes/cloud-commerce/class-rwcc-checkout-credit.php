<?php
/**
 * Cart/checkout remaining-term credit display and unexplained-full-price block (PLAN.md §5 / §19 step 5).
 *
 * Credit is calculated on ReactWoo.com. The application mechanic remains a PLAN.md §20
 * decision; this class uses a non-taxable negative cart fee as the interim store mechanic
 * so the customer never pays unexplained full price while covered individuals exist.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Checkout_Credit {

	const FEE_ID      = 'rwcc_upgrade_credit';
	const SESSION_KEY = 'rwcc_upgrade_quote';
	const ORDER_META  = '_rwcc_upgrade_credit';
	const ORDER_AUDIT = '_rwcc_upgrade_credit_audit';

	/**
	 * Ineligible reasons that explain a £0 credit line (do not block checkout).
	 */
	const EXPLAINED_REASONS = array(
		'trial',
		'refunded',
		'already_superseded',
		'not_active',
		'not_covered_by_plan',
		'no_unused_period',
		'currency_mismatch',
	);

	/**
	 * @var RWCC_Settings
	 */
	private $settings;

	/**
	 * @var RWCC_Plan_Map
	 */
	private $plans;

	/**
	 * @var callable|null fn(int $customer_id): array[]
	 */
	private $subscription_finder;

	/**
	 * @var bool
	 */
	private $notice_rendered = false;

	/**
	 * @param RWCC_Settings $settings Settings.
	 * @param RWCC_Plan_Map $plans    Plan map.
	 */
	public function __construct( RWCC_Settings $settings, RWCC_Plan_Map $plans ) {
		$this->settings = $settings;
		$this->plans    = $plans;
	}

	/**
	 * @param callable $finder fn(int $customer_id): array[] subscription rows.
	 */
	public function set_subscription_finder( $finder ) {
		$this->subscription_finder = is_callable( $finder ) ? $finder : null;
	}

	public function register() {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_cart_fee' ), 20, 1 );
		add_action( 'woocommerce_before_cart', array( $this, 'render_notice' ), 20 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_notice' ), 20 );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_notice' ), 20 );
		add_action( 'woocommerce_checkout_process', array( $this, 'block_unexplained_checkout' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'block_unexplained_checkout' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'block_store_api_checkout' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'stamp_order' ), 20, 1 );
	}

	/**
	 * Build the auditable upgrade quote shown before the customer confirms.
	 *
	 * @param string        $plan                 Internal plan.
	 * @param array[]       $subscriptions        Customer subscription rows.
	 * @param float         $cloud_checkout_value Permitted Cloud checkout value (ex-tax line).
	 * @param string        $currency             ISO currency.
	 * @param RWCC_Settings $settings             Settings.
	 * @param RWCC_Plan_Map $plans                Plan map.
	 * @param int           $now                  Unix time.
	 * @return array
	 */
	public static function quote( $plan, array $subscriptions, $cloud_checkout_value, $currency, RWCC_Settings $settings, RWCC_Plan_Map $plans, $now = 0 ) {
		$plan    = RWCC_Plan_Map::normalize_plan( $plan );
		$summary = RWCC_Coverage::upgrade_summary( $plan, $subscriptions, $settings, $plans );
		$credit  = RWCC_Upgrade_Credit::calculate(
			$summary['will_stop_renewing'],
			array(
				'now'                  => $now ? (int) $now : time(),
				'cloud_checkout_value' => (float) $cloud_checkout_value,
				'currency'             => strtoupper( (string) $currency ),
			)
		);
		$block = self::should_block( $summary, $credit );

		return array(
			'plan'         => $plan,
			'summary'      => $summary,
			'credit'       => $credit,
			'block'        => $block,
			'block_reason' => $block ? self::block_message() : '',
			'fee_amount'   => self::fee_amount( $credit, $block ),
			'notices'      => self::notice_items( $summary, $credit, $block ),
		);
	}

	/**
	 * Block unexplained full-price Cloud checkout when covered individuals exist.
	 *
	 * @param array $summary Coverage summary.
	 * @param array $credit  Credit calculation.
	 * @return bool
	 */
	public static function should_block( array $summary, array $credit ) {
		if ( empty( $summary['block_unexplained_full_price'] ) ) {
			return false;
		}
		if ( (float) $credit['applied_credit'] > 0 ) {
			return false;
		}
		$lines = isset( $credit['lines'] ) && is_array( $credit['lines'] ) ? $credit['lines'] : array();
		if ( ! $lines ) {
			return true;
		}
		foreach ( $lines as $line ) {
			if ( ! empty( $line['eligible'] ) ) {
				continue;
			}
			$reason = isset( $line['reason'] ) ? (string) $line['reason'] : '';
			if ( $reason === '' || ! in_array( $reason, self::EXPLAINED_REASONS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Negative fee to apply, or 0.00 when blocked / no credit.
	 *
	 * @param array $credit Credit calculation.
	 * @param bool  $block  Whether checkout is blocked.
	 * @return string
	 */
	public static function fee_amount( array $credit, $block ) {
		if ( $block ) {
			return '0.00';
		}
		$applied = isset( $credit['applied_credit'] ) ? (float) $credit['applied_credit'] : 0.0;
		if ( $applied <= 0 ) {
			return '0.00';
		}
		return number_format( 0 - $applied, 2, '.', '' );
	}

	/**
	 * Structured notice payload for tests and HTML.
	 *
	 * @param array $summary Coverage summary.
	 * @param array $credit  Credit calculation.
	 * @param bool  $block   Whether checkout is blocked.
	 * @return array
	 */
	public static function notice_items( array $summary, array $credit, $block ) {
		$included = array();
		foreach ( $summary['will_be_included'] as $row ) {
			$included[] = self::label_for_row( $row );
		}
		$stop = array();
		foreach ( $summary['will_stop_renewing'] as $row ) {
			$stop[] = self::label_for_row( $row );
		}
		$separate = array();
		foreach ( $summary['remain_separately_billed'] as $row ) {
			$reason = isset( $row['separate_reason'] ) ? (string) $row['separate_reason'] : '';
			$separate[] = array(
				'label'   => self::label_for_row( $row ),
				'reason'  => $reason,
				'message' => self::separate_message( $reason ),
			);
		}

		return array(
			'plan'            => isset( $summary['plan'] ) ? (string) $summary['plan'] : '',
			'included'        => $included,
			'will_stop'       => $stop,
			'separate'        => $separate,
			'credit_lines'    => isset( $credit['lines'] ) && is_array( $credit['lines'] ) ? $credit['lines'] : array(),
			'applied_credit'  => isset( $credit['applied_credit'] ) ? (string) $credit['applied_credit'] : '0.00',
			'gross_credit'    => isset( $credit['gross_credit'] ) ? (string) $credit['gross_credit'] : '0.00',
			'capped'          => ! empty( $credit['capped'] ),
			'currency'        => isset( $credit['currency'] ) ? (string) $credit['currency'] : '',
			'block'           => (bool) $block,
			'block_reason'    => $block ? self::block_message() : '',
		);
	}

	/**
	 * HTML shown on cart/checkout before confirm.
	 *
	 * @param array $quote Quote from quote().
	 * @return string
	 */
	public static function render_html( array $quote ) {
		$notices = isset( $quote['notices'] ) && is_array( $quote['notices'] ) ? $quote['notices'] : array();
		$plan    = isset( $notices['plan'] ) ? (string) $notices['plan'] : '';
		$title   = $plan ? sprintf( self::text( 'Upgrading to Decision Cloud %s' ), ucfirst( $plan ) ) : self::text( 'Decision Cloud upgrade' );

		$html  = '<div class="rwcc-upgrade-summary" style="margin:16px 0;padding:16px;border:1px solid #dcdcde;border-radius:8px;">';
		$html .= '<h3>' . self::esc( $title ) . '</h3>';

		if ( ! empty( $notices['included'] ) ) {
			$html .= '<p><strong>' . self::esc( self::text( 'Included in this Cloud plan' ) ) . '</strong></p><ul>';
			foreach ( $notices['included'] as $label ) {
				$html .= '<li>' . self::esc( (string) $label ) . '</li>';
			}
			$html .= '</ul>';
		}

		if ( ! empty( $notices['will_stop'] ) ) {
			$html .= '<p><strong>' . self::esc( self::text( 'Individual renewals that will stop after Cloud activates' ) ) . '</strong></p><ul>';
			foreach ( $notices['will_stop'] as $label ) {
				$html .= '<li>' . self::esc( (string) $label ) . '</li>';
			}
			$html .= '</ul>';
		}

		if ( ! empty( $notices['separate'] ) ) {
			$html .= '<p><strong>' . self::esc( self::text( 'Remains separately billed' ) ) . '</strong></p><ul>';
			foreach ( $notices['separate'] as $row ) {
				$label   = isset( $row['label'] ) ? (string) $row['label'] : '';
				$message = isset( $row['message'] ) ? (string) $row['message'] : '';
				$html   .= '<li>' . self::esc( $label );
				if ( $message !== '' ) {
					$html .= ' — ' . self::esc( $message );
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
		}

		if ( ! empty( $notices['credit_lines'] ) ) {
			$html .= '<p><strong>' . self::esc( self::text( 'Remaining-term credit' ) ) . '</strong></p><ul>';
			foreach ( $notices['credit_lines'] as $line ) {
				$html .= '<li>' . self::esc( self::credit_line_text( $line, isset( $notices['currency'] ) ? (string) $notices['currency'] : '' ) ) . '</li>';
			}
			$html .= '</ul>';
			$applied = isset( $notices['applied_credit'] ) ? (string) $notices['applied_credit'] : '0.00';
			$currency = isset( $notices['currency'] ) ? (string) $notices['currency'] : '';
			$html    .= '<p>' . self::esc( sprintf( self::text( 'Applied upgrade credit: %s %s' ), $applied, $currency ) ) . '</p>';
			if ( ! empty( $notices['capped'] ) ) {
				$html .= '<p>' . self::esc( self::text( 'Credit is capped at the Cloud checkout value. Unused credit is not paid out as cash.' ) ) . '</p>';
			}
		}

		if ( ! empty( $notices['block'] ) ) {
			$html .= '<p class="rwcc-upgrade-block" style="color:#b32d2e;"><strong>' . self::esc( isset( $notices['block_reason'] ) ? (string) $notices['block_reason'] : self::block_message() ) . '</strong></p>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * @param object $cart Woo cart.
	 */
	public function apply_cart_fee( $cart ) {
		$quote = $this->quote_for_cart( $cart );
		if ( ! $quote ) {
			$this->store_quote( null );
			return;
		}
		$this->store_quote( $quote );
		if ( empty( $cart ) || ! is_object( $cart ) || ! method_exists( $cart, 'add_fee' ) ) {
			return;
		}
		$fee = (float) $quote['fee_amount'];
		if ( $fee >= 0 ) {
			return;
		}
		$cart->add_fee( self::text( 'Upgrade credit' ), $fee, false );
	}

	public function render_notice() {
		if ( $this->notice_rendered ) {
			return;
		}
		$quote = $this->current_quote();
		if ( ! $quote ) {
			return;
		}
		$this->notice_rendered = true;
		echo self::render_html( $quote ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_html escapes.
	}

	public function block_unexplained_checkout() {
		$quote = $this->current_quote();
		if ( ! $quote || empty( $quote['block'] ) ) {
			return;
		}
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $quote['block_reason'], 'error' );
		}
	}

	/**
	 * @param object $order   Order.
	 * @param mixed  $request Store API request.
	 */
	public function block_store_api_checkout( $order, $request ) {
		unset( $order, $request );
		$quote = $this->current_quote();
		if ( ! $quote || empty( $quote['block'] ) ) {
			return;
		}
		$message = $quote['block_reason'];
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'rwcc_upgrade_credit', $message, 400 );
		}
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * @param object $order Order.
	 */
	public function stamp_order( $order ) {
		$quote = $this->current_quote();
		if ( ! $quote || ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$applied = isset( $quote['credit']['applied_credit'] ) ? (string) $quote['credit']['applied_credit'] : '0.00';
		$order->update_meta_data( self::ORDER_META, $applied );
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $quote['credit'] ) : json_encode( $quote['credit'] );
		$order->update_meta_data( self::ORDER_AUDIT, $encoded );
	}

	/**
	 * @param object|null $cart Cart.
	 * @return array|null
	 */
	public function quote_for_cart( $cart ) {
		$context = self::cloud_cart_context( $cart, $this->plans );
		if ( ! $context ) {
			return null;
		}
		$customer_id = 0;
		if ( function_exists( 'get_current_user_id' ) ) {
			$customer_id = (int) get_current_user_id();
		}
		$rows = $this->customer_subscription_rows( $customer_id );
		return self::quote(
			$context['plan'],
			$rows,
			$context['line_total'],
			$context['currency'],
			$this->settings,
			$this->plans
		);
	}

	/**
	 * @param object|null  $cart  Cart.
	 * @param RWCC_Plan_Map $plans Plan map.
	 * @return array{plan:string,product_id:int,variation_id:int,line_total:float,currency:string}|null
	 */
	public static function cloud_cart_context( $cart, RWCC_Plan_Map $plans ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return null;
		}
		$currency = '';
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = (string) get_woocommerce_currency();
		}
		foreach ( (array) $cart->get_cart() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			$plan         = $plans->resolve( $product_id, $variation_id, array( 'RWCC_Plan_Map', 'wp_meta_reader' ) );
			if ( ! $plan ) {
				continue;
			}
			$line_total = isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0;
			return array(
				'plan'          => $plan,
				'product_id'    => $product_id,
				'variation_id'  => $variation_id,
				'line_total'    => $line_total,
				'currency'      => $currency,
			);
		}
		return null;
	}

	/**
	 * Convert a WooCommerce Subscriptions object into a credit/coverage row.
	 *
	 * @param object        $subscription Subscription.
	 * @param RWCC_Settings $settings     Settings.
	 * @return array
	 */
	public static function subscription_row( $subscription, RWCC_Settings $settings ) {
		$product_id   = 0;
		$variation_id = 0;
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_items' ) ) {
			foreach ( $subscription->get_items() as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
					$product_id   = (int) $item->get_product_id();
					$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
					break;
				}
			}
		}

		$status = is_object( $subscription ) && method_exists( $subscription, 'get_status' ) ? (string) $subscription->get_status() : '';
		$superseded = class_exists( 'RWCC_Supersession' ) && RWCC_Supersession::is_superseded( $subscription );
		$trial      = false;
		$period_start = 0;
		$period_end   = 0;
		$amount_paid  = 0.0;
		$currency     = '';

		if ( is_object( $subscription ) && method_exists( $subscription, 'get_time' ) ) {
			$period_end   = (int) $subscription->get_time( 'next_payment' );
			$period_start = (int) $subscription->get_time( 'last_order_date_created' );
			if ( $period_start <= 0 ) {
				$period_start = (int) $subscription->get_time( 'start' );
			}
			$trial_end = (int) $subscription->get_time( 'trial_end' );
			$trial     = $trial_end > time();
		}
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_currency' ) ) {
			$currency = (string) $subscription->get_currency();
		}
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_last_order' ) ) {
			$last = $subscription->get_last_order( 'all', array( 'parent', 'renewal' ) );
			if ( is_object( $last ) && method_exists( $last, 'get_total' ) ) {
				$amount_paid = (float) $last->get_total();
			}
		}
		if ( $amount_paid <= 0 && is_object( $subscription ) && method_exists( $subscription, 'get_total' ) ) {
			$amount_paid = (float) $subscription->get_total();
		}

		$slug = RWCC_Coverage::slug_for_product_id( $settings, $variation_id ? $variation_id : $product_id );

		return array(
			'id'           => is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ? (string) $subscription->get_id() : '',
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'slug'         => $slug,
			'status'       => $status,
			'renewing'     => ! in_array( strtolower( $status ), array( 'cancelled', 'canceled', 'expired', 'trash' ), true ) && ! $superseded,
			'trial'        => $trial,
			'refunded'     => false,
			'superseded'   => $superseded,
			'amount_paid'  => $amount_paid,
			'currency'     => $currency,
			'period_start' => $period_start,
			'period_end'   => $period_end,
		);
	}

	/**
	 * @param int $customer_id Customer id.
	 * @return array[]
	 */
	private function customer_subscription_rows( $customer_id ) {
		$customer_id = (int) $customer_id;
		if ( is_callable( $this->subscription_finder ) ) {
			$found = call_user_func( $this->subscription_finder, $customer_id );
			return is_array( $found ) ? $found : array();
		}
		if ( $customer_id <= 0 || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return array();
		}
		$found = wcs_get_users_subscriptions( $customer_id );
		$rows  = array();
		foreach ( (array) $found as $subscription ) {
			$rows[] = self::subscription_row( $subscription, $this->settings );
		}
		return $rows;
	}

	/**
	 * @param array|null $quote Quote.
	 */
	private function store_quote( $quote ) {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$wc = WC();
		if ( ! $wc || empty( $wc->session ) || ! method_exists( $wc->session, 'set' ) ) {
			return;
		}
		$wc->session->set( self::SESSION_KEY, $quote );
	}

	/**
	 * @return array|null
	 */
	private function current_quote() {
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( $wc && ! empty( $wc->session ) && method_exists( $wc->session, 'get' ) ) {
				$stored = $wc->session->get( self::SESSION_KEY );
				if ( is_array( $stored ) ) {
					return $stored;
				}
			}
			if ( $wc && ! empty( $wc->cart ) ) {
				return $this->quote_for_cart( $wc->cart );
			}
		}
		return null;
	}

	/**
	 * @param array $row Subscription row.
	 * @return string
	 */
	private static function label_for_row( array $row ) {
		$slug = isset( $row['slug'] ) ? RWCC_Coverage::canonical_slug( $row['slug'] ) : '';
		if ( $slug && isset( RWCC_Coverage::INDIVIDUAL[ $slug ]['label'] ) ) {
			return RWCC_Coverage::INDIVIDUAL[ $slug ]['label'];
		}
		return $slug ? $slug : self::text( 'Plugin subscription' );
	}

	/**
	 * @param string $reason Separate-billing reason.
	 * @return string
	 */
	private static function separate_message( $reason ) {
		if ( $reason === 'not_in_selected_cloud_plan' ) {
			return self::text( 'Not included in this Cloud plan — remains separately billed.' );
		}
		if ( $reason === 'not_a_reactwoo_suite_plugin' ) {
			return self::text( 'Not part of the Decision Cloud bundle — remains separately billed.' );
		}
		return self::text( 'Remains separately billed.' );
	}

	/**
	 * @param array  $line     Credit line.
	 * @param string $currency Currency.
	 * @return string
	 */
	private static function credit_line_text( array $line, $currency ) {
		$id = isset( $line['subscription_id'] ) ? (string) $line['subscription_id'] : '';
		if ( ! empty( $line['eligible'] ) ) {
			$amount = isset( $line['line_credit'] ) ? (string) $line['line_credit'] : '0.00';
			return sprintf( self::text( 'Subscription %s: %s %s remaining-term credit' ), $id, $amount, $currency );
		}
		$reason = isset( $line['reason'] ) ? (string) $line['reason'] : '';
		return sprintf( self::text( 'Subscription %s: no credit (%s)' ), $id, $reason ? $reason : 'unknown' );
	}

	/**
	 * @return string
	 */
	private static function block_message() {
		return self::text( 'This Cloud checkout cannot continue at full price while you have covered individual subscriptions. Remaining-term credit could not be calculated. Contact support or wait until period dates are available.' );
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private static function text( $text ) {
		if ( function_exists( '__' ) ) {
			return __( $text, 'reactwoo-api-manager' );
		}
		return $text;
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc( $text ) {
		if ( function_exists( 'esc_html' ) ) {
			return esc_html( $text );
		}
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
