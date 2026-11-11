<?php
/**
 * Maintenance plan licence bundle.
 *
 * One WooCommerce subscription can hold several package licences. Each key is
 * tied to that subscription, limited to one site, and deactivated with the plan.
 * Separately purchased licences are never changed.
 *
 * Issue trigger (ReactWoo License Manager, Settings):
 * - annual_immediate_monthly_after_renewal (default): annual billing is issued
 *   when the subscription becomes active, because the year is paid up front.
 *   Monthly billing is issued only after the first successful renewal, so a
 *   new monthly sign-up cannot collect keys and cancel in the same period.
 * - immediately: every billing period, as soon as the subscription is active.
 * - annual_only: annual billing only. Monthly subscriptions receive no keys.
 * - after_renewal: after N successful renewals, for every billing period.
 *   N defaults to 1. The checkout payment is not counted. 1 is the first renewal.
 *
 * Essential (product 3654) includes no key until the customer chooses one plugin
 * in My Account, unless an administrator sets a package list for that plan.
 *
 * Geo Optimise is added to Standard and Business only when the optimise mode is
 * "yes", or when the mode is "auto" and the Geo Optimise product is purchasable.
 * It is waitlist-only on the live catalogue, so auto includes nothing.
 *
 * The licence server must treat source_ref "{subscription id}:{package slug}" as
 * the idempotency key, and must apply sync-subscription to every licence for
 * that subscription id (honour package_slugs when it is sent). This store also
 * updates each stored licence id itself. If the server still returns the first
 * licence for a different package, that response is not saved.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReactWoo_Maintenance_Bundle {

	const OPTION            = 'reactwoo_maintenance_bundle';
	const META_LICENSES     = '_reactwoo_entitlement_licenses';
	const META_CHOICE       = '_reactwoo_maintenance_choice';
	const META_PAID         = '_reactwoo_maintenance_paid_periods';
	const META_COUNTED      = '_reactwoo_maintenance_counted_orders';
	const META_PLAN         = '_reactwoo_maintenance_bundle_plan';
	const RENEWAL_POLICY    = 'deactivate_on_non_renewal';
	const TRIGGER_DEFAULT   = 'annual_immediate_monthly_after_renewal';

	/**
	 * @return void
	 */
	public function __construct() {
		add_filter( 'reactwoo_maintenance_product_entitlements', array( $this, 'filter_entitlements' ), 10, 3 );
		add_action( 'woocommerce_subscription_item_switched', array( $this, 'reconcile_after_switch' ), 20, 4 );
		add_action( 'woocommerce_subscriptions_switch_completed', array( $this, 'reconcile_after_switch_completed' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_refund' ), 15, 2 );
		add_action( 'woocommerce_subscription_details_after_subscription_table', array( $this, 'render_essential_choice' ), 12, 1 );
		add_action( 'woocommerce_subscription_details_after_order_table', array( $this, 'render_essential_choice' ), 12, 1 );
		add_action( 'template_redirect', array( $this, 'save_essential_choice' ) );
	}

	/**
	 * Built-in catalogue and plan map. Saved settings replace the plan rows.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'trigger'              => self::TRIGGER_DEFAULT,
			'paid_periods'         => 1,
			'optimise_mode'        => 'auto',
			'optimise_product_id'  => 2891,
			'optimise_package_id'  => 2267,
			'decision_cloud_plan'  => 'starter',
			'plans'                => array(
				'essential' => array(
					'product_id' => 3654,
					'packages'   => '',
				),
				'standard'  => array(
					'product_id' => 3657,
					'packages'   => '2269, 2268, 2270',
				),
				'business'  => array(
					'product_id' => 3660,
					'packages'   => '2269, 2268, 2270, 2271',
				),
			),
			'essential_choices'    => '2269, 2268, 2270',
			'catalog'              => array(
				2269 => array(
					'slug'       => 'reactwoo-geo-core-pro',
					'name'       => 'Geo Core Pro',
					'product_id' => 2294,
				),
				2268 => array(
					'slug'       => 'reactwoo-geo-commerce',
					'name'       => 'Geo Commerce',
					'product_id' => 2893,
				),
				2267 => array(
					'slug'       => 'reactwoo-geo-optimise',
					'name'       => 'Geo Optimise',
					'product_id' => 2891,
				),
				2270 => array(
					'slug'       => 'reactwoo-atomic-pro',
					'name'       => 'Atomic Pro',
					'product_id' => 3494,
					'atomic'     => true,
				),
				2271 => array(
					'slug'       => 'reactwoo-decision-cloud',
					'name'       => 'Decision Cloud',
					'product_id' => 3166,
					'cloud'      => true,
				),
			),
		);
	}

	/**
	 * @param mixed $input Posted settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$triggers = array( 'immediately', 'annual_only', 'after_renewal', self::TRIGGER_DEFAULT );
		$trigger  = isset( $input['trigger'] ) ? (string) $input['trigger'] : $defaults['trigger'];
		if ( ! in_array( $trigger, $triggers, true ) ) {
			$trigger = $defaults['trigger'];
		}
		$modes = array( 'auto', 'yes', 'no' );
		$mode  = isset( $input['optimise_mode'] ) ? (string) $input['optimise_mode'] : 'auto';
		if ( ! in_array( $mode, $modes, true ) ) {
			$mode = 'auto';
		}
		$cloud_plans = array( 'starter', 'growth', 'scale' );
		$cloud_plan  = isset( $input['decision_cloud_plan'] ) ? strtolower( (string) $input['decision_cloud_plan'] ) : 'starter';
		if ( ! in_array( $cloud_plan, $cloud_plans, true ) ) {
			$cloud_plan = 'starter';
		}
		$plans = array();
		foreach ( array( 'essential', 'standard', 'business' ) as $plan ) {
			$row      = isset( $input['plans'][ $plan ] ) && is_array( $input['plans'][ $plan ] ) ? $input['plans'][ $plan ] : array();
			$packages = array_key_exists( 'packages', $row ) ? self::sanitize_package_list( $row['packages'] ) : (string) $defaults['plans'][ $plan ]['packages'];
			$plans[ $plan ] = array(
				'product_id' => isset( $row['product_id'] ) ? absint( $row['product_id'] ) : (int) $defaults['plans'][ $plan ]['product_id'],
				'packages'   => $packages,
			);
		}
		return array(
			'trigger'             => $trigger,
			'paid_periods'        => max( 1, absint( isset( $input['paid_periods'] ) ? $input['paid_periods'] : 1 ) ),
			'optimise_mode'       => $mode,
			'optimise_product_id' => isset( $input['optimise_product_id'] ) ? absint( $input['optimise_product_id'] ) : 2891,
			'optimise_package_id' => isset( $input['optimise_package_id'] ) ? absint( $input['optimise_package_id'] ) : 2267,
			'decision_cloud_plan' => $cloud_plan,
			'plans'               => $plans,
			'essential_choices'   => self::sanitize_package_list( isset( $input['essential_choices'] ) ? $input['essential_choices'] : $defaults['essential_choices'] ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function settings() {
		$settings = self::defaults();
		if ( function_exists( 'get_option' ) ) {
			$raw = get_option( self::OPTION, array() );
			if ( is_array( $raw ) && $raw ) {
				$settings = array_merge( $settings, self::sanitize_settings( $raw ) );
				$settings['catalog'] = self::defaults()['catalog'];
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'reactwoo_maintenance_bundle_settings', $settings );
			if ( is_array( $filtered ) ) {
				$settings = $filtered;
			}
			$catalog = apply_filters( 'reactwoo_maintenance_bundle_catalog', $settings['catalog'] );
			if ( is_array( $catalog ) ) {
				$settings['catalog'] = $catalog;
			}
		}
		return $settings;
	}

	/**
	 * Fill the Maintenance entitlement hook from the saved map.
	 *
	 * Support Portal 0.5.10 resolves reactwoo_product_entitlements to an empty
	 * list. This filter is the list the store provisions. A later filter can
	 * still replace it.
	 *
	 * @param mixed  $slugs      Incoming slugs.
	 * @param string $key        Plan key.
	 * @param int    $product_id Product id. Unused. The map is keyed by plan.
	 * @return array<int, string>
	 */
	public function filter_entitlements( $slugs, $key, $product_id ) {
		unset( $product_id );
		$key = self::normalise_key( $key );
		if ( ! in_array( $key, array( 'essential', 'standard', 'business' ), true ) ) {
			return is_array( $slugs ) ? $slugs : array();
		}
		$settings = self::settings();
		$rows     = self::package_rows_for_plan( $key, $settings, self::optimise_is_purchasable( $settings ), '' );
		$out      = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['slug'] ) ) {
				$out[] = $row['slug'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param string               $plan                 essential|standard|business.
	 * @param array<string, mixed> $settings             Settings.
	 * @param bool                 $optimise_purchasable Whether Geo Optimise can be sold.
	 * @param string               $choice               Essential selection.
	 * @return array<int, array<string, mixed>>
	 */
	public static function package_rows_for_plan( $plan, array $settings, $optimise_purchasable, $choice = '' ) {
		$plan = self::normalise_key( $plan );
		if ( ! isset( $settings['plans'][ $plan ] ) || ! is_array( $settings['plans'][ $plan ] ) ) {
			return array();
		}
		$catalog = isset( $settings['catalog'] ) && is_array( $settings['catalog'] ) ? $settings['catalog'] : array();
		$list    = isset( $settings['plans'][ $plan ]['packages'] ) ? (string) $settings['plans'][ $plan ]['packages'] : '';
		if ( 'essential' === $plan && trim( $list ) === '' ) {
			$choice = self::normalise_key( $choice );
			$allowed = self::choice_slugs( $settings, $optimise_purchasable );
			if ( $choice === '' || ! in_array( $choice, $allowed, true ) ) {
				return array();
			}
			$row = self::resolve_package( $choice, $catalog );
			return $row ? array( $row ) : array();
		}
		$rows = self::rows_from_list( $list, $catalog );
		if ( in_array( $plan, array( 'standard', 'business' ), true ) && self::optimise_allowed( $settings, $optimise_purchasable ) ) {
			$optimise_id = isset( $settings['optimise_package_id'] ) ? (int) $settings['optimise_package_id'] : 2267;
			$already     = false;
			foreach ( $rows as $row ) {
				if ( (int) $row['package_id'] === $optimise_id || $row['slug'] === 'reactwoo-geo-optimise' ) {
					$already = true;
					break;
				}
			}
			if ( ! $already ) {
				$extra = self::resolve_package( (string) $optimise_id, $catalog );
				if ( $extra ) {
					$rows[] = $extra;
				}
			}
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $settings             Settings.
	 * @param bool                 $optimise_purchasable Purchasable flag.
	 * @return array<int, string>
	 */
	public static function choice_slugs( array $settings, $optimise_purchasable ) {
		$catalog = isset( $settings['catalog'] ) && is_array( $settings['catalog'] ) ? $settings['catalog'] : array();
		$rows    = self::rows_from_list( isset( $settings['essential_choices'] ) ? (string) $settings['essential_choices'] : '', $catalog );
		if ( self::optimise_allowed( $settings, $optimise_purchasable ) ) {
			$optimise_id = isset( $settings['optimise_package_id'] ) ? (int) $settings['optimise_package_id'] : 2267;
			$extra       = self::resolve_package( (string) $optimise_id, $catalog );
			if ( $extra ) {
				$rows[] = $extra;
			}
		}
		$slugs = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['slug'] ) && empty( $row['cloud'] ) ) {
				$slugs[] = $row['slug'];
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * @param array<string, mixed> $settings             Settings.
	 * @param string               $billing_period       Woo billing period.
	 * @param int                  $paid_renewals        Successful renewals, not counting checkout.
	 * @return bool
	 */
	public static function trigger_allows( array $settings, $billing_period, $paid_renewals ) {
		$trigger = isset( $settings['trigger'] ) ? (string) $settings['trigger'] : self::TRIGGER_DEFAULT;
		$needed  = isset( $settings['paid_periods'] ) ? max( 1, (int) $settings['paid_periods'] ) : 1;
		$paid    = max( 0, (int) $paid_renewals );
		$annual  = self::is_annual( $billing_period );
		if ( 'immediately' === $trigger ) {
			return true;
		}
		if ( 'annual_only' === $trigger ) {
			return $annual;
		}
		if ( 'after_renewal' === $trigger ) {
			return $paid >= $needed;
		}
		if ( $annual ) {
			return true;
		}
		return $paid >= $needed;
	}

	/**
	 * @param string $billing_period Billing period.
	 * @return bool
	 */
	public static function is_annual( $billing_period ) {
		$period = strtolower( trim( (string) $billing_period ) );
		return in_array( $period, array( 'year', 'annual', 'yearly' ), true );
	}

	/**
	 * @param int    $subscription_id Subscription id.
	 * @param string $slug            Package slug.
	 * @return string
	 */
	public static function source_ref( $subscription_id, $slug ) {
		return absint( $subscription_id ) . ':' . self::normalise_key( $slug );
	}

	/**
	 * @param string $woo_status WooCommerce subscription status.
	 * @param string $period_end Period end, MySQL or parseable datetime. Empty means still inside the period.
	 * @return string Licence server status, or empty when the status is ignored.
	 */
	public static function licence_status_for_subscription( $woo_status, $period_end = '' ) {
		$status = strtolower( trim( (string) $woo_status ) );
		if ( 'active' === $status ) {
			return 'active';
		}
		if ( 'on-hold' === $status ) {
			return 'suspended';
		}
		if ( 'expired' === $status ) {
			return 'expired';
		}
		if ( in_array( $status, array( 'cancelled', 'canceled', 'switched' ), true ) ) {
			return 'cancelled';
		}
		if ( in_array( $status, array( 'pending-cancel', 'pending-cancellation' ), true ) ) {
			if ( $period_end !== '' ) {
				$ends = strtotime( (string) $period_end );
				if ( false !== $ends && $ends < time() ) {
					return 'cancelled';
				}
			}
			return 'active';
		}
		return '';
	}

	/**
	 * @param mixed $value Log context.
	 * @return mixed
	 */
	public static function redact( $value ) {
		$blocked = array( 'license_key', 'licence_key', 'licensekey', 'api_key', 'master_key', 'password', 'secret' );
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( in_array( strtolower( (string) $key ), $blocked, true ) ) {
					continue;
				}
				$out[ $key ] = self::redact( $item );
			}
			return $out;
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $package          Package row.
	 * @param int                  $subscription_id  Subscription id.
	 * @param string               $domain           Maintained site.
	 * @param array<string, mixed> $settings         Settings.
	 * @return array<string, mixed>
	 */
	public static function provision_args_for_package( array $package, $subscription_id, $domain, array $settings ) {
		$slug = isset( $package['slug'] ) ? self::normalise_key( $package['slug'] ) : '';
		$args = array(
			'package_slug'       => $slug,
			'wc_subscription_id' => (string) absint( $subscription_id ),
			'source_ref'         => self::source_ref( $subscription_id, $slug ),
			'domain'             => (string) $domain,
			'status'             => 'active',
			'max_activations'    => 1,
			'renewal_policy'     => self::RENEWAL_POLICY,
			'maintenance_bundle' => true,
		);
		if ( ! empty( $package['atomic'] ) ) {
			$args['plan_code'] = 'personal';
		}
		if ( ! empty( $package['cloud'] ) ) {
			$plan = isset( $settings['decision_cloud_plan'] ) ? strtolower( (string) $settings['decision_cloud_plan'] ) : 'starter';
			$args['plan_code'] = in_array( $plan, array( 'starter', 'growth', 'scale' ), true ) ? $plan : 'starter';
		}
		return $args;
	}

	/**
	 * @param object $subscription Subscription.
	 * @return bool
	 */
	public static function is_bundle_subscription( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_meta' ) ) {
			return false;
		}
		$plan = self::normalise_key( $subscription->get_meta( self::META_PLAN, true ) );
		return in_array( $plan, array( 'essential', 'standard', 'business' ), true );
	}

	/**
	 * @param object $subscription Subscription.
	 * @return bool
	 */
	public static function is_maintenance_subscription( $subscription ) {
		return self::plan_key_from_subscription( $subscription ) !== '';
	}

	/**
	 * @param object $subscription Subscription.
	 * @return array<string, array<string, mixed>>
	 */
	public static function stored_licenses( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_meta' ) ) {
			return array();
		}
		$raw = $subscription->get_meta( self::META_LICENSES, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $slug => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = self::normalise_key( isset( $row['package_slug'] ) ? $row['package_slug'] : $slug );
			if ( $key === '' ) {
				continue;
			}
			$row['package_slug'] = $key;
			$out[ $key ]         = $row;
		}
		return $out;
	}

	/**
	 * Plugin slugs a Maintenance subscription may download.
	 *
	 * @param object $subscription Subscription.
	 * @return array<int, string>
	 */
	public static function download_slugs( $subscription ) {
		$slugs = array();
		foreach ( self::stored_licenses( $subscription ) as $slug => $row ) {
			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( in_array( $status, array( 'removed', 'refunded' ), true ) ) {
				continue;
			}
			if ( $slug !== '' ) {
				$slugs[] = $slug;
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Decision Cloud plan included with this Maintenance subscription, if any.
	 *
	 * @param object $subscription Subscription.
	 * @return string
	 */
	public static function included_cloud_plan( $subscription ) {
		foreach ( self::stored_licenses( $subscription ) as $row ) {
			$slug = isset( $row['package_slug'] ) ? (string) $row['package_slug'] : '';
			if ( $slug !== 'reactwoo-decision-cloud' && empty( $row['cloud'] ) ) {
				continue;
			}
			$plan = strtolower( isset( $row['plan_code'] ) ? (string) $row['plan_code'] : '' );
			if ( in_array( $plan, array( 'starter', 'growth', 'scale' ), true ) ) {
				return $plan;
			}
		}
		return '';
	}

	/**
	 * When the filtered entitlement list is empty, use the saved map.
	 * Essential stays empty until the customer choice is one of the allowed plugins.
	 *
	 * @param object             $subscription Subscription.
	 * @param array<int, string> $slugs        Slugs already resolved.
	 * @return array<int, string>
	 */
	public static function slugs_for_subscription( $subscription, array $slugs ) {
		$clean = array();
		foreach ( $slugs as $slug ) {
			$key = self::normalise_key( $slug );
			if ( $key !== '' ) {
				$clean[] = $key;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		if ( $clean ) {
			return $clean;
		}
		$plan = self::plan_key_from_subscription( $subscription );
		if ( $plan === '' ) {
			return array();
		}
		$settings = self::settings();
		$choice   = '';
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_meta' ) ) {
			$choice = (string) $subscription->get_meta( self::META_CHOICE, true );
		}
		$rows = self::package_rows_for_plan( $plan, $settings, self::optimise_is_purchasable( $settings ), $choice );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['slug'] ) ) {
				$out[] = $row['slug'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param object $subscription Subscription.
	 * @return void
	 */
	public static function mark_subscription( $subscription ) {
		$plan = self::plan_key_from_subscription( $subscription );
		if ( $plan === '' || ! is_object( $subscription ) || ! method_exists( $subscription, 'update_meta_data' ) ) {
			return;
		}
		$subscription->update_meta_data( self::META_PLAN, $plan );
		if ( method_exists( $subscription, 'save' ) ) {
			$subscription->save();
		}
	}

	/**
	 * @param object      $subscription   Subscription.
	 * @param object|null $order          Parent or switch order.
	 * @param string      $slug           Package slug.
	 * @param string      $correlation_id Correlation id.
	 * @param object|null $api            Licence server client.
	 * @return array<string, mixed>|null
	 */
	public static function provision_slug( $subscription, $order, $slug, $correlation_id = '', $api = null ) {
		$slug = self::normalise_key( $slug );
		if ( $slug === '' || ! is_object( $subscription ) ) {
			return null;
		}
		$settings = self::settings();
		$package  = self::resolve_package( $slug, isset( $settings['catalog'] ) ? $settings['catalog'] : array() );
		if ( ! $package ) {
			self::log(
				'skipped_unknown_package',
				array(
					'subscription_id' => (int) $subscription->get_id(),
					'package_slug'    => $slug,
					'correlation_id'  => (string) $correlation_id,
				)
			);
			return null;
		}
		$stored = self::stored_licenses( $subscription );
		if ( isset( $stored[ $slug ]['status'] ) && 'refunded' === $stored[ $slug ]['status'] ) {
			self::log(
				'skipped_refunded',
				array(
					'subscription_id' => (int) $subscription->get_id(),
					'package_slug'    => $slug,
					'license_id'      => isset( $stored[ $slug ]['license_id'] ) ? (int) $stored[ $slug ]['license_id'] : 0,
				)
			);
			return null;
		}
		$billing = method_exists( $subscription, 'get_billing_period' ) ? (string) $subscription->get_billing_period() : '';
		$paid    = method_exists( $subscription, 'get_meta' ) ? (int) $subscription->get_meta( self::META_PAID, true ) : 0;
		if ( ! self::trigger_allows( $settings, $billing, $paid ) ) {
			self::log(
				'skipped_trigger',
				array(
					'subscription_id' => (int) $subscription->get_id(),
					'package_slug'    => $slug,
					'billing_period'  => $billing,
					'paid_renewals'   => $paid,
					'trigger'         => isset( $settings['trigger'] ) ? (string) $settings['trigger'] : '',
				)
			);
			return null;
		}
		$domain = self::domain_for( $subscription, $order );
		if ( $domain === '' ) {
			self::log(
				'skipped_domain',
				array(
					'subscription_id' => (int) $subscription->get_id(),
					'package_slug'    => $slug,
				)
			);
			return null;
		}
		$package_id = isset( $package['package_id'] ) ? (int) $package['package_id'] : 0;
		if ( $package_id && empty( $stored[ $slug ]['license_id'] ) ) {
			$owned = self::customer_standalone_package_ids( method_exists( $subscription, 'get_customer_id' ) ? (int) $subscription->get_customer_id() : 0, (int) $subscription->get_id() );
			if ( in_array( $package_id, $owned, true ) ) {
				self::log(
					'skipped_standalone',
					array(
						'subscription_id' => (int) $subscription->get_id(),
						'package_slug'    => $slug,
						'package_id'      => $package_id,
					)
				);
				return null;
			}
		}
		$woo_status = method_exists( $subscription, 'get_status' ) ? (string) $subscription->get_status() : 'active';
		if ( ! empty( $stored[ $slug ]['license_id'] ) ) {
			$local = isset( $stored[ $slug ]['status'] ) ? (string) $stored[ $slug ]['status'] : 'active';
			if ( 'active' === $local ) {
				self::log(
					'already_provisioned',
					array(
						'subscription_id' => (int) $subscription->get_id(),
						'package_slug'    => $slug,
						'license_id'      => (int) $stored[ $slug ]['license_id'],
						'source_ref'      => isset( $stored[ $slug ]['source_ref'] ) ? (string) $stored[ $slug ]['source_ref'] : '',
					)
				);
				return $stored[ $slug ];
			}
			if ( in_array( $woo_status, array( 'active', 'pending-cancel' ), true ) && in_array( $local, array( 'removed', 'cancelled', 'suspended', 'inactive', 'expired' ), true ) ) {
				return self::update_stored_status( $subscription, $slug, 'active', $api ? $api : self::api() );
			}
			if ( 'active' !== $woo_status ) {
				return $stored[ $slug ];
			}
		}
		$client = $api ? $api : self::api();
		if ( ! is_object( $client ) || ! method_exists( $client, 'provision_license_v1' ) ) {
			return null;
		}
		$args = self::provision_args_for_package( $package, (int) $subscription->get_id(), $domain, $settings );
		$args['customer_email'] = ( $order && method_exists( $order, 'get_billing_email' ) ) ? (string) $order->get_billing_email() : '';
		$args['customer_name']  = '';
		if ( $order && method_exists( $order, 'get_billing_first_name' ) ) {
			$args['customer_name'] = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
		}
		$args['wc_order_id']    = ( $order && method_exists( $order, 'get_id' ) ) ? (string) $order->get_id() : '';
		$args['correlation_id'] = (string) $correlation_id;
		$args['currency']       = method_exists( $subscription, 'get_currency' ) ? (string) $subscription->get_currency() : '';
		$args['billing_period'] = $billing;
		if ( method_exists( $subscription, 'get_billing_interval' ) ) {
			$args['billing_interval'] = (int) $subscription->get_billing_interval();
		}
		$license = $client->provision_license_v1( $args );
		if ( is_wp_error( $license ) || ! is_array( $license ) ) {
			self::log(
				'provision_failed',
				array(
					'subscription_id' => (int) $subscription->get_id(),
					'package_slug'    => $slug,
					'source_ref'      => $args['source_ref'],
					'error'           => is_wp_error( $license ) ? $license->get_error_message() : 'empty',
				)
			);
			return null;
		}
		if ( empty( $license['license_key'] ) || empty( $license['license_id'] ) ) {
			self::log(
				'provision_failed',
				array(
					'subscription_id' => (int) $subscription->get_id(),
					'package_slug'    => $slug,
					'source_ref'      => $args['source_ref'],
					'error'           => 'incomplete',
				)
			);
			return null;
		}
		if ( self::response_collides( $license, $args, $stored, $slug ) ) {
			self::log(
				'duplicate_subscription_licence',
				array(
					'subscription_id' => (int) $subscription->get_id(),
					'package_slug'    => $slug,
					'source_ref'      => $args['source_ref'],
					'license_id'      => isset( $license['license_id'] ) ? (int) $license['license_id'] : 0,
				)
			);
			return null;
		}
		$row = array(
			'package_slug'    => $slug,
			'package_id'      => isset( $license['package_id'] ) ? (int) $license['package_id'] : (int) $package['package_id'],
			'license_id'      => isset( $license['license_id'] ) ? (int) $license['license_id'] : 0,
			'license_key'     => isset( $license['license_key'] ) ? (string) $license['license_key'] : '',
			'status'          => 'active',
			'domain'          => isset( $license['domain'] ) && $license['domain'] ? (string) $license['domain'] : $domain,
			'max_activations' => 1,
			'plan_code'       => isset( $args['plan_code'] ) ? (string) $args['plan_code'] : '',
			'source_ref'      => $args['source_ref'],
			'product_id'      => isset( $package['product_id'] ) ? (int) $package['product_id'] : 0,
			'product_name'    => isset( $package['name'] ) ? (string) $package['name'] : $slug,
			'origin'          => 'maintenance_bundle',
			'renewal_policy'  => self::RENEWAL_POLICY,
			'cloud'           => ! empty( $package['cloud'] ),
			'atomic'          => ! empty( $package['atomic'] ),
		);
		$stored[ $slug ] = $row;
		self::write_licenses( $subscription, $stored );
		self::log(
			'provisioned',
			array(
				'subscription_id' => (int) $subscription->get_id(),
				'package_slug'    => $slug,
				'package_id'      => (int) $row['package_id'],
				'license_id'      => (int) $row['license_id'],
				'source_ref'      => $row['source_ref'],
				'domain'          => $row['domain'],
				'max_activations' => 1,
				'plan_code'       => $row['plan_code'],
				'correlation_id'  => (string) $correlation_id,
			)
		);
		if ( ! empty( $package['cloud'] ) && function_exists( 'do_action' ) ) {
			$payload = $row;
			$payload['maintenance_bundle'] = true;
			do_action( 'reactwoo_license_generated', $subscription, $order, $payload );
		}
		return $row;
	}

	/**
	 * Cancel bundle licences whose package is no longer in the tier.
	 * Standalone licences are not in this meta, so they are left alone.
	 *
	 * @param object             $subscription Subscription.
	 * @param array<int, string> $desired      Slugs the tier includes.
	 * @param object|null        $api          Client.
	 * @return array<int, string>
	 */
	public static function revoke_to_match( $subscription, array $desired, $api = null ) {
		$wanted = array();
		foreach ( $desired as $slug ) {
			$key = self::normalise_key( $slug );
			if ( $key !== '' ) {
				$wanted[] = $key;
			}
		}
		$stored  = self::stored_licenses( $subscription );
		$client  = $api ? $api : self::api();
		$revoked = array();
		foreach ( $stored as $slug => $row ) {
			if ( in_array( $slug, $wanted, true ) ) {
				continue;
			}
			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( in_array( $status, array( 'removed', 'refunded' ), true ) ) {
				continue;
			}
			$license_id = isset( $row['license_id'] ) ? (int) $row['license_id'] : 0;
			if ( $license_id && is_object( $client ) && method_exists( $client, 'update_license_status' ) ) {
				$client->update_license_status( $license_id, 'cancelled' );
			}
			$stored[ $slug ]['status'] = 'removed';
			$revoked[] = $slug;
			self::log(
				'revoked',
				array(
					'subscription_id' => (int) $subscription->get_id(),
					'package_slug'    => $slug,
					'license_id'      => $license_id,
				)
			);
		}
		if ( $revoked ) {
			self::write_licenses( $subscription, $stored );
		}
		return $revoked;
	}

	/**
	 * @param object      $subscription Subscription.
	 * @param object|null $renewal_order Renewal order.
	 * @return int
	 */
	public static function note_paid_renewal( $subscription, $renewal_order = null ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_meta' ) || ! method_exists( $subscription, 'update_meta_data' ) ) {
			return 0;
		}
		$order_id = ( $renewal_order && method_exists( $renewal_order, 'get_id' ) ) ? (int) $renewal_order->get_id() : 0;
		$counted  = $subscription->get_meta( self::META_COUNTED, true );
		$counted  = is_array( $counted ) ? array_map( 'intval', $counted ) : array();
		if ( $order_id && in_array( $order_id, $counted, true ) ) {
			return (int) $subscription->get_meta( self::META_PAID, true );
		}
		$count = (int) $subscription->get_meta( self::META_PAID, true ) + 1;
		if ( $order_id ) {
			$counted[] = $order_id;
		}
		$subscription->update_meta_data( self::META_PAID, $count );
		$subscription->update_meta_data( self::META_COUNTED, $counted );
		if ( method_exists( $subscription, 'save' ) ) {
			$subscription->save();
		}
		self::log(
			'renewal_counted',
			array(
				'subscription_id' => (int) $subscription->get_id(),
				'order_id'        => $order_id,
				'paid_renewals'   => $count,
			)
		);
		return $count;
	}

	/**
	 * Point each stored bundle licence at the subscription status.
	 * Refunded rows stay refunded. Downgraded rows stay cancelled.
	 *
	 * @param object      $subscription Subscription.
	 * @param string      $woo_status   New Woo status.
	 * @param string|null $period_end   Period end.
	 * @param object|null $api          Client.
	 * @return void
	 */
	public static function sync_stored_licences( $subscription, $woo_status, $period_end = '', $api = null ) {
		$stored = self::stored_licenses( $subscription );
		if ( ! $stored ) {
			return;
		}
		$settings = self::settings();
		$choice   = method_exists( $subscription, 'get_meta' ) ? (string) $subscription->get_meta( self::META_CHOICE, true ) : '';
		$plan     = self::plan_key_from_subscription( $subscription );
		if ( $plan === '' && method_exists( $subscription, 'get_meta' ) ) {
			$plan = self::normalise_key( $subscription->get_meta( self::META_PLAN, true ) );
		}
		$rows    = self::package_rows_for_plan( $plan, $settings, self::optimise_is_purchasable( $settings ), $choice );
		$desired = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['slug'] ) ) {
				$desired[] = $row['slug'];
			}
		}
		$client = $api ? $api : self::api();
		if ( is_object( $client ) && method_exists( $client, 'sync_subscription_v1' ) ) {
			$client->sync_subscription_v1(
				(int) $subscription->get_id(),
				(string) $woo_status,
				$period_end ? (string) $period_end : null,
				array(
					'sync_all'      => true,
					'package_slugs' => $desired,
				)
			);
		}
		$target = self::licence_status_for_subscription( $woo_status, (string) $period_end );
		foreach ( $stored as $slug => $row ) {
			$license_id = isset( $row['license_id'] ) ? (int) $row['license_id'] : 0;
			if ( ! $license_id ) {
				continue;
			}
			$local = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( 'refunded' === $local ) {
				if ( is_object( $client ) && method_exists( $client, 'update_license_status' ) ) {
					$client->update_license_status( $license_id, 'refunded' );
				}
				continue;
			}
			if ( ! in_array( $slug, $desired, true ) ) {
				if ( is_object( $client ) && method_exists( $client, 'update_license_status' ) ) {
					$client->update_license_status( $license_id, 'cancelled' );
				}
				$stored[ $slug ]['status'] = 'removed';
				continue;
			}
			if ( $target === '' ) {
				continue;
			}
			if ( is_object( $client ) && method_exists( $client, 'update_license_status' ) ) {
				$client->update_license_status( $license_id, $target );
			}
			$stored[ $slug ]['status'] = $target;
		}
		self::write_licenses( $subscription, $stored );
		self::log(
			'synced',
			array(
				'subscription_id' => (int) $subscription->get_id(),
				'woo_status'      => (string) $woo_status,
				'licence_status'  => $target,
				'package_slugs'   => $desired,
			)
		);
	}

	/**
	 * @param object $subscription Subscription.
	 * @param object $api          Client.
	 * @return array<int, int>
	 */
	public static function mark_payment_failed( $subscription, $api ) {
		$ids = array();
		$stored = self::stored_licenses( $subscription );
		foreach ( $stored as $slug => $row ) {
			$local = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( in_array( $local, array( 'removed', 'refunded' ), true ) ) {
				continue;
			}
			$license_id = isset( $row['license_id'] ) ? (int) $row['license_id'] : 0;
			if ( ! $license_id ) {
				continue;
			}
			if ( is_object( $api ) && method_exists( $api, 'update_license_status' ) ) {
				$api->update_license_status( $license_id, 'inactive' );
			}
			$stored[ $slug ]['status'] = 'inactive';
			$ids[] = $license_id;
		}
		if ( $ids ) {
			self::write_licenses( $subscription, $stored );
			self::log(
				'payment_failed',
				array(
					'subscription_id' => (int) $subscription->get_id(),
					'license_ids'     => $ids,
				)
			);
		}
		return $ids;
	}

	/**
	 * @param object $subscription Subscription.
	 * @param object $api          Client.
	 * @return array<int, int>
	 */
	public static function refund_bundle( $subscription, $api = null ) {
		$client = $api ? $api : self::api();
		$stored = self::stored_licenses( $subscription );
		$ids    = array();
		foreach ( $stored as $slug => $row ) {
			if ( isset( $row['status'] ) && 'refunded' === $row['status'] ) {
				continue;
			}
			$license_id = isset( $row['license_id'] ) ? (int) $row['license_id'] : 0;
			if ( ! $license_id ) {
				continue;
			}
			if ( is_object( $client ) && method_exists( $client, 'update_license_status' ) ) {
				// PUT api/licenses/{id}/status with { "status": "refunded" }.
				// The licence server must accept that value. Local rows stay
				// refunded when it refuses, so the key is not issued again.
				$result = $client->update_license_status( $license_id, 'refunded' );
				if ( is_wp_error( $result ) ) {
					self::log(
						'refund_status_rejected',
						array(
							'subscription_id' => (int) $subscription->get_id(),
							'package_slug'    => $slug,
							'license_id'      => $license_id,
							'error'           => $result->get_error_message(),
						)
					);
				}
			}
			$stored[ $slug ]['status'] = 'refunded';
			$ids[] = $license_id;
			self::log(
				'refunded',
				array(
					'subscription_id' => (int) $subscription->get_id(),
					'package_slug'    => $slug,
					'license_id'      => $license_id,
				)
			);
		}
		if ( $ids ) {
			self::write_licenses( $subscription, $stored );
		}
		return $ids;
	}

	/**
	 * Licence ids on this subscription only.
	 *
	 * @param object $subscription Subscription.
	 * @return array<int, int>
	 */
	public static function license_ids( $subscription ) {
		$ids = array();
		foreach ( self::stored_licenses( $subscription ) as $row ) {
			if ( ! empty( $row['license_id'] ) ) {
				$ids[] = (int) $row['license_id'];
			}
		}
		return $ids;
	}

	/**
	 * My Account cards, one per bundle licence. Keys stay in meta for masking.
	 * The Maintenance product itself is not a card.
	 *
	 * Record shape the theme can read:
	 * - id: entitlement:{subscription_id}:{package_slug}
	 * - subscription_id, license_id, package_slug, package_id, product_id
	 * - product_name: the licensed plugin, not the Maintenance plan
	 * - included_with_maintenance: true
	 * - maintenance_plan: essential|standard|business
	 * - maintenance_product_id, maintenance_product_name
	 * - plan_label: "Included with ReactWoo Maintenance {Plan}. One website."
	 * - masked_key, key_available, can_reveal_key
	 * - registered_domain, max_activations (always 1), site_limit (always 1)
	 * - is_decision_cloud: true only on the Decision Cloud card
	 * - origin: maintenance_bundle
	 * - files: one download for that package slug when downloads are allowed
	 *
	 * Copy the key with the existing account REST route and package_slug:
	 * GET /wp-json/reactwoo/v1/account/licenses/{subscription_id}/key?package_slug={slug}
	 *
	 * @param object $subscription Subscription.
	 * @return array<int, array<string, mixed>>
	 */
	public static function account_records( $subscription ) {
		$stored = self::stored_licenses( $subscription );
		if ( ! $stored || ! is_object( $subscription ) ) {
			return array();
		}
		$plan = self::normalise_key( $subscription->get_meta( self::META_PLAN, true ) );
		if ( $plan === '' ) {
			$plan = self::plan_key_from_subscription( $subscription );
		}
		$settings   = self::settings();
		$plan_label = self::plan_label( $plan );
		$plan_id    = isset( $settings['plans'][ $plan ]['product_id'] ) ? (int) $settings['plans'][ $plan ]['product_id'] : 0;
		$records    = array();
		foreach ( $stored as $slug => $row ) {
			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( 'removed' === $status || empty( $row['license_id'] ) ) {
				continue;
			}
			$key = isset( $row['license_key'] ) ? (string) $row['license_key'] : '';
			$ui  = self::account_status( $status );
			$record = array(
				'id'                        => 'entitlement:' . (int) $subscription->get_id() . ':' . $slug,
				'subscription_id'           => (int) $subscription->get_id(),
				'license_id'                => (int) $row['license_id'],
				'package_slug'              => $slug,
				'package_id'                => isset( $row['package_id'] ) ? (int) $row['package_id'] : 0,
				'product_id'                => isset( $row['product_id'] ) ? (int) $row['product_id'] : 0,
				'order_id'                  => 0,
				'product_name'              => isset( $row['product_name'] ) ? (string) $row['product_name'] : $slug,
				'product_description'       => '',
				'plan_label'                => sprintf(
					/* translators: %s: Maintenance plan name. */
					__( 'Included with ReactWoo Maintenance %s. One website.', 'reactwoo-api-manager' ),
					$plan_label
				),
				'included_with_maintenance' => true,
				'maintenance_plan'          => $plan,
				'maintenance_product_id'    => $plan_id,
				'maintenance_product_name'  => sprintf(
					/* translators: %s: Maintenance plan name. */
					__( 'ReactWoo Maintenance %s', 'reactwoo-api-manager' ),
					$plan_label
				),
				'status'                    => $ui,
				'masked_key'                => ( $key !== '' && class_exists( 'ReactWoo_Customer_Account_Service' ) ) ? ReactWoo_Customer_Account_Service::mask_license_key( $key ) : '',
				'key_available'             => $key !== '',
				'can_reveal_key'            => $key !== '',
				'registered_domain'         => isset( $row['domain'] ) ? (string) $row['domain'] : '',
				'max_activations'           => 1,
				'site_limit'                => 1,
				'is_decision_cloud'         => ( 'reactwoo-decision-cloud' === $slug || ! empty( $row['cloud'] ) ),
				'origin'                    => 'maintenance_bundle',
				'version'                   => '',
				'renewal_date'              => '',
				'renewal_url'               => method_exists( $subscription, 'get_change_payment_method_url' ) ? (string) $subscription->get_change_payment_method_url() : '',
				'documentation_url'         => 'https://reactwoo.com/docs/',
				'order_url'                 => '',
				'order_date'                => '',
				'browse_url'                => function_exists( 'home_url' ) ? home_url( '/' ) : '',
				'support_url'               => 'https://reactwoo.com/support/',
				'files'                     => self::download_file( $subscription, $slug, isset( $row['product_name'] ) ? (string) $row['product_name'] : $slug ),
			);
			$records[] = $record;
		}
		return $records;
	}

	/**
	 * @param object $subscription Subscription.
	 * @param string $package_slug Package slug.
	 * @return string
	 */
	public static function owned_key( $subscription, $package_slug ) {
		$slug = self::normalise_key( $package_slug );
		$stored = self::stored_licenses( $subscription );
		if ( $slug === '' || ! isset( $stored[ $slug ]['license_key'] ) ) {
			return '';
		}
		return (string) $stored[ $slug ]['license_key'];
	}

	/**
	 * @param object      $order          Switch order. Unused when the subscription is known.
	 * @param object      $subscription   Subscription.
	 * @param mixed       $add_line_item  New line.
	 * @param mixed       $remove_line_item Previous line.
	 * @return void
	 */
	public function reconcile_after_switch( $order, $subscription, $add_line_item = null, $remove_line_item = null ) {
		unset( $add_line_item, $remove_line_item );
		if ( ! self::is_maintenance_subscription( $subscription ) ) {
			return;
		}
		self::mark_subscription( $subscription );
		$slugs = self::slugs_for_subscription( $subscription, array() );
		foreach ( $slugs as $slug ) {
			self::provision_slug( $subscription, $order, $slug, 'switch' );
		}
		self::revoke_to_match( $subscription, $slugs );
	}

	/**
	 * @param object $subscription Subscription from the switch-completed hook.
	 * @return void
	 */
	public function reconcile_after_switch_completed( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order->get_id(), array( 'order_type' => 'any' ) );
			foreach ( (array) $subscriptions as $subscription ) {
				$this->reconcile_after_switch( $order, $subscription );
			}
			return;
		}
		if ( self::is_maintenance_subscription( $order ) ) {
			$this->reconcile_after_switch( null, $order );
		}
	}

	/**
	 * Full refunds revoke bundle keys. Partial refunds do not.
	 * A zero-total order counts when a refund has been recorded.
	 * A refund of a separately purchased licence is not handled here.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 * @return void
	 */
	public function handle_refund( $order_id, $refund_id ) {
		unset( $refund_id );
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}
		$order         = wc_get_order( $order_id );
		$subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'any' ) );
		foreach ( (array) $subscriptions as $subscription ) {
			$has_keys = (bool) self::stored_licenses( $subscription );
			if ( ! $has_keys && ! self::is_bundle_subscription( $subscription ) ) {
				continue;
			}
			if ( ! $order || ! self::order_is_fully_refunded( $order ) ) {
				self::log(
					'refund_not_full',
					array(
						'order_id'        => (int) $order_id,
						'subscription_id' => (int) $subscription->get_id(),
					)
				);
				continue;
			}
			if ( $has_keys ) {
				self::refund_bundle( $subscription );
			}
			self::cancel_after_full_refund( $subscription, $order_id );
		}
	}

	/**
	 * Cancel a bundle subscription after one of its orders is fully refunded.
	 * Keys were already marked refunded by refund_bundle(); the status sync that the
	 * cancellation triggers keeps refunded rows refunded, so they never become cancelled.
	 * Safe to call more than once: a subscription that has already ended is left alone.
	 *
	 * @param object $subscription Subscription.
	 * @param int    $order_id     Fully refunded order.
	 * @return bool True when this call cancelled the subscription.
	 */
	public static function cancel_after_full_refund( $subscription, $order_id = 0 ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_status' ) || ! method_exists( $subscription, 'update_status' ) ) {
			return false;
		}
		$status = strtolower( (string) $subscription->get_status() );
		if ( in_array( $status, array( 'cancelled', 'expired', 'switched', 'trash' ), true ) ) {
			return false;
		}
		if ( method_exists( $subscription, 'can_be_updated_to' ) && ! $subscription->can_be_updated_to( 'cancelled' ) ) {
			self::log(
				'refund_cancel_not_allowed',
				array(
					'order_id'        => (int) $order_id,
					'subscription_id' => (int) $subscription->get_id(),
					'status'          => $status,
				)
			);
			return false;
		}
		$subscription->update_status(
			'cancelled',
			sprintf(
				/* translators: %d: order ID */
				__( 'Cancelled automatically because order #%d was fully refunded. Included licence keys stay refunded.', '[REDACTED]-api-manager' ),
				(int) $order_id
			)
		);
		self::log(
			'refund_cancelled_subscription',
			array(
				'order_id'        => (int) $order_id,
				'subscription_id' => (int) $subscription->get_id(),
				'previous_status' => $status,
			)
		);
		return true;
	}

	/**
	 * @param object $subscription Subscription.
	 * @return void
	 */
	public function render_essential_choice( $subscription ) {
		if ( ! self::customer_can_manage( $subscription ) ) {
			return;
		}
		if ( self::plan_key_from_subscription( $subscription ) !== 'essential' ) {
			return;
		}
		$settings = self::settings();
		$packages = isset( $settings['plans']['essential']['packages'] ) ? trim( (string) $settings['plans']['essential']['packages'] ) : '';
		if ( $packages !== '' ) {
			return;
		}
		echo '<section class="reactwoo-maintenance-choice">';
		echo '<h2>' . esc_html__( 'Included plugin', 'reactwoo-api-manager' ) . '</h2>';
		$issued = null;
		foreach ( self::stored_licenses( $subscription ) as $row ) {
			if ( ! empty( $row['license_id'] ) && ( ! isset( $row['status'] ) || ! in_array( $row['status'], array( 'removed', 'refunded' ), true ) ) ) {
				$issued = $row;
				break;
			}
		}
		if ( $issued ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: plugin name. */
					__( 'This plan includes %s for the website on the subscription. Contact support if you need a different plugin.', 'reactwoo-api-manager' ),
					isset( $issued['product_name'] ) ? (string) $issued['product_name'] : ''
				)
			) . '</p>';
			echo '</section>';
			return;
		}
		$choices = self::choice_rows( $settings, self::optimise_is_purchasable( $settings ) );
		if ( ! $choices ) {
			echo '<p>' . esc_html__( 'Essential does not include a plugin licence until a plugin is available to choose.', 'reactwoo-api-manager' ) . '</p>';
			echo '</section>';
			return;
		}
		$selected = method_exists( $subscription, 'get_meta' ) ? (string) $subscription->get_meta( self::META_CHOICE, true ) : '';
		echo '<form method="post" class="reactwoo-maintenance-choice__form">';
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'reactwoo_maintenance_choice_' . (int) $subscription->get_id() );
		}
		echo '<input type="hidden" name="reactwoo_maintenance_choice_subscription" value="' . esc_attr( (string) $subscription->get_id() ) . '" />';
		echo '<p>' . esc_html__( 'Choose the one licensed plugin included with Maintenance Essential. The licence covers the website on this plan, and it stays active only while the plan stays active.', 'reactwoo-api-manager' ) . '</p>';
		echo '<p><label for="reactwoo_maintenance_choice">' . esc_html__( 'Licensed plugin', 'reactwoo-api-manager' ) . '</label> ';
		echo '<select id="reactwoo_maintenance_choice" name="reactwoo_maintenance_choice">';
		echo '<option value="">' . esc_html__( 'Select a plugin', 'reactwoo-api-manager' ) . '</option>';
		foreach ( $choices as $row ) {
			echo '<option value="' . esc_attr( $row['slug'] ) . '" ' . ( function_exists( 'selected' ) ? selected( $selected, $row['slug'], false ) : ( $selected === $row['slug'] ? 'selected' : '' ) ) . '>' . esc_html( $row['name'] ) . '</option>';
		}
		echo '</select></p>';
		echo '<p>' . esc_html( self::trigger_customer_copy( $settings ) ) . '</p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Save plugin choice', 'reactwoo-api-manager' ) . '</button></p>';
		echo '</form></section>';
	}

	/**
	 * @return void
	 */
	public function save_essential_choice() {
		if ( ! isset( $_POST['reactwoo_maintenance_choice_subscription'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$subscription_id = absint( wp_unslash( $_POST['reactwoo_maintenance_choice_subscription'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce           = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $subscription_id || ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, 'reactwoo_maintenance_choice_' . $subscription_id ) ) {
			return;
		}
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! self::customer_can_manage( $subscription ) || self::plan_key_from_subscription( $subscription ) !== 'essential' ) {
			return;
		}
		$choice   = self::normalise_key( isset( $_POST['reactwoo_maintenance_choice'] ) ? wp_unslash( $_POST['reactwoo_maintenance_choice'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$settings = self::settings();
		$allowed  = self::choice_slugs( $settings, self::optimise_is_purchasable( $settings ) );
		if ( ! in_array( $choice, $allowed, true ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'Choose a plugin from the list.', 'reactwoo-api-manager' ), 'error' );
			}
			return;
		}
		$subscription->update_meta_data( self::META_CHOICE, $choice );
		$subscription->save();
		self::log(
			'choice_saved',
			array(
				'subscription_id' => $subscription_id,
				'package_slug'    => $choice,
			)
		);
		self::mark_subscription( $subscription );
		$order = method_exists( $subscription, 'get_parent' ) ? $subscription->get_parent() : null;
		self::provision_slug( $subscription, $order, $choice, 'essential-choice' );
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Your plugin choice has been saved.', 'reactwoo-api-manager' ), 'success' );
		}
	}

	/**
	 * A paid order is fully refunded when the refunded amount covers the total.
	 * A zero-total order is fully refunded when a refund has been recorded:
	 * a refund object, order status refunded, or a refunded amount above zero.
	 * A checkout that was free and has no refund is not a refund.
	 *
	 * @param object $order Order.
	 * @return bool
	 */
	public static function order_is_fully_refunded( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) || ! method_exists( $order, 'get_total_refunded' ) ) {
			return false;
		}
		if ( method_exists( $order, 'get_status' ) && 'refunded' === (string) $order->get_status() ) {
			return true;
		}
		$total    = (float) $order->get_total();
		$refunded = (float) $order->get_total_refunded();
		if ( $total > 0 ) {
			return ( $refunded + 0.00001 ) >= $total;
		}
		return self::order_records_refund( $order );
	}

	/**
	 * Whether WooCommerce has stored a refund against this order.
	 *
	 * @param object $order Order.
	 * @return bool
	 */
	public static function order_records_refund( $order ) {
		if ( ! is_object( $order ) ) {
			return false;
		}
		if ( method_exists( $order, 'get_refunds' ) ) {
			$refunds = $order->get_refunds();
			if ( is_array( $refunds ) && count( $refunds ) > 0 ) {
				return true;
			}
		}
		if ( method_exists( $order, 'get_status' ) && 'refunded' === (string) $order->get_status() ) {
			return true;
		}
		if ( method_exists( $order, 'get_total_refunded' ) && (float) $order->get_total_refunded() > 0 ) {
			return true;
		}
		return false;
	}

	/**
	 * True when this status change is a cancellation caused by a refund.
	 * A partial refund of a paid order does not qualify.
	 *
	 * @param object $subscription Subscription.
	 * @param string $new_status   Status being applied. Empty uses the subscription status.
	 * @return bool
	 */
	public static function subscription_cancelled_for_refund( $subscription, $new_status = '' ) {
		if ( ! is_object( $subscription ) ) {
			return false;
		}
		$status = strtolower( trim( (string) $new_status ) );
		if ( '' === $status && method_exists( $subscription, 'get_status' ) ) {
			$status = strtolower( (string) $subscription->get_status() );
		}
		if ( ! in_array( $status, array( 'cancelled', 'canceled' ), true ) ) {
			return false;
		}
		$orders = array();
		if ( method_exists( $subscription, 'get_parent' ) ) {
			$parent = $subscription->get_parent();
			if ( is_object( $parent ) ) {
				$orders[] = $parent;
			}
		}
		if ( method_exists( $subscription, 'get_related_orders' ) ) {
			$related = $subscription->get_related_orders();
			if ( is_array( $related ) ) {
				foreach ( $related as $order ) {
					if ( is_object( $order ) ) {
						$orders[] = $order;
					}
				}
			}
		}
		foreach ( $orders as $order ) {
			if ( self::order_is_fully_refunded( $order ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $event   Event name.
	 * @param array  $context Context. Licence keys are removed.
	 * @return void
	 */
	public static function log( $event, array $context = array() ) {
		$safe            = self::redact( $context );
		$safe['event']   = (string) $event;
		if ( ! isset( $GLOBALS['reactwoo_maintenance_bundle_log'] ) || ! is_array( $GLOBALS['reactwoo_maintenance_bundle_log'] ) ) {
			$GLOBALS['reactwoo_maintenance_bundle_log'] = array();
		}
		$GLOBALS['reactwoo_maintenance_bundle_log'][] = $safe;
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			if ( is_object( $logger ) && method_exists( $logger, 'info' ) ) {
				$logger->info( (string) $event, array( 'source' => 'reactwoo-maintenance-bundle', 'context' => $safe ) );
			}
		}
	}

	/**
	 * @param object $subscription Subscription.
	 * @return string
	 */
	public static function plan_key_from_subscription( $subscription ) {
		if ( ! is_object( $subscription ) ) {
			return '';
		}
		$settings = self::settings();
		$ids      = array();
		if ( method_exists( $subscription, 'get_items' ) ) {
			foreach ( $subscription->get_items() as $item ) {
				$product = ( is_object( $item ) && method_exists( $item, 'get_product' ) ) ? $item->get_product() : null;
				if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
					$ids[] = (int) $product->get_id();
					if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
						$ids[] = (int) $product->get_parent_id();
					}
				}
				if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
					$ids[] = (int) $item->get_product_id();
				}
				if ( is_object( $item ) && method_exists( $item, 'get_variation_id' ) && $item->get_variation_id() ) {
					$ids[] = (int) $item->get_variation_id();
				}
			}
		}
		foreach ( $ids as $id ) {
			if ( $id && function_exists( 'get_post_meta' ) ) {
				$key = self::normalise_key( get_post_meta( $id, '_rw_maintenance_plan_key', true ) );
				if ( in_array( $key, array( 'essential', 'standard', 'business' ), true ) ) {
					return $key;
				}
			}
		}
		foreach ( array( 'essential', 'standard', 'business' ) as $plan ) {
			$product_id = isset( $settings['plans'][ $plan ]['product_id'] ) ? (int) $settings['plans'][ $plan ]['product_id'] : 0;
			if ( $product_id && in_array( $product_id, $ids, true ) ) {
				return $plan;
			}
		}
		return '';
	}

	/**
	 * @param int $customer_id Customer id.
	 * @param int $except_id   This Maintenance subscription.
	 * @return array<int, int>
	 */
	public static function customer_standalone_package_ids( $customer_id, $except_id ) {
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'reactwoo_maintenance_standalone_package_ids', null, $customer_id, $except_id );
			if ( is_array( $filtered ) ) {
				return array_values( array_unique( array_map( 'intval', $filtered ) ) );
			}
		}
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return array();
		}
		$ids = array();
		foreach ( (array) wcs_get_users_subscriptions( $customer_id ) as $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) {
				continue;
			}
			if ( (int) $subscription->get_id() === (int) $except_id ) {
				continue;
			}
			if ( self::is_maintenance_subscription( $subscription ) || self::is_bundle_subscription( $subscription ) ) {
				continue;
			}
			$status = method_exists( $subscription, 'get_status' ) ? (string) $subscription->get_status() : '';
			if ( ! in_array( $status, array( 'active', 'pending-cancel' ), true ) ) {
				continue;
			}
			$package_id = method_exists( $subscription, 'get_meta' ) ? (int) $subscription->get_meta( '_reactwoo_license_package_id', true ) : 0;
			if ( $package_id ) {
				$ids[] = $package_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param string                           $list    Comma separated ids or slugs.
	 * @param array<int|string, array>         $catalog Catalogue.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows_from_list( $list, array $catalog ) {
		$rows = array();
		$seen = array();
		foreach ( preg_split( '/[\s,]+/', (string) $list ) as $token ) {
			if ( $token === '' ) {
				continue;
			}
			$row = self::resolve_package( $token, $catalog );
			if ( ! $row || isset( $seen[ $row['slug'] ] ) ) {
				continue;
			}
			$seen[ $row['slug'] ] = true;
			$rows[]               = $row;
		}
		return $rows;
	}

	/**
	 * @param string                   $token   Id or slug.
	 * @param array<int|string, array> $catalog Catalogue.
	 * @return array<string, mixed>|null
	 */
	public static function resolve_package( $token, array $catalog ) {
		$token = trim( (string) $token );
		if ( $token === '' ) {
			return null;
		}
		if ( isset( $catalog[ $token ] ) && is_array( $catalog[ $token ] ) ) {
			return self::finish_package( (int) $token, $catalog[ $token ] );
		}
		if ( isset( $catalog[ (int) $token ] ) && ctype_digit( $token ) && is_array( $catalog[ (int) $token ] ) ) {
			return self::finish_package( (int) $token, $catalog[ (int) $token ] );
		}
		foreach ( $catalog as $id => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug = isset( $row['slug'] ) ? self::normalise_key( $row['slug'] ) : '';
			if ( $slug !== '' && $slug === self::normalise_key( $token ) ) {
				return self::finish_package( (int) $id, $row );
			}
		}
		$key = self::normalise_key( $token );
		if ( strpos( $key, 'reactwoo-' ) === 0 ) {
			return array(
				'package_id' => 0,
				'slug'       => $key,
				'name'       => $key,
				'product_id' => 0,
				'atomic'     => ( 'reactwoo-atomic-pro' === $key ),
				'cloud'      => ( 'reactwoo-decision-cloud' === $key ),
			);
		}
		return null;
	}

	/**
	 * @param int                  $id  Package id.
	 * @param array<string, mixed> $row Catalogue row.
	 * @return array<string, mixed>|null
	 */
	private static function finish_package( $id, array $row ) {
		$slug = isset( $row['slug'] ) ? self::normalise_key( $row['slug'] ) : '';
		if ( $slug === '' ) {
			return null;
		}
		$row['package_id'] = $id;
		$row['slug']       = $slug;
		$row['name']       = isset( $row['name'] ) ? (string) $row['name'] : $slug;
		$row['product_id'] = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
		$row['atomic']     = ! empty( $row['atomic'] ) || 'reactwoo-atomic-pro' === $slug;
		$row['cloud']      = ! empty( $row['cloud'] ) || 'reactwoo-decision-cloud' === $slug;
		return $row;
	}

	/**
	 * @param array<string, mixed> $settings             Settings.
	 * @param bool                 $optimise_purchasable Product can be bought.
	 * @return bool
	 */
	private static function optimise_allowed( array $settings, $optimise_purchasable ) {
		$mode = isset( $settings['optimise_mode'] ) ? (string) $settings['optimise_mode'] : 'auto';
		if ( 'yes' === $mode ) {
			return true;
		}
		if ( 'no' === $mode ) {
			return false;
		}
		return (bool) $optimise_purchasable;
	}

	/**
	 * @param array<string, mixed>|null $settings Settings.
	 * @return bool
	 */
	public static function optimise_is_purchasable( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::settings();
		$mode     = isset( $settings['optimise_mode'] ) ? (string) $settings['optimise_mode'] : 'auto';
		if ( 'yes' === $mode ) {
			return true;
		}
		if ( 'no' === $mode ) {
			return false;
		}
		$product_id = isset( $settings['optimise_product_id'] ) ? (int) $settings['optimise_product_id'] : 0;
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'is_purchasable' ) ) {
			return false;
		}
		$status = method_exists( $product, 'get_status' ) ? (string) $product->get_status() : 'publish';
		return 'publish' === $status && (bool) $product->is_purchasable();
	}

	/**
	 * @param object      $subscription Subscription.
	 * @param object|null $order        Order.
	 * @return string
	 */
	public static function domain_for( $subscription, $order = null ) {
		$raw = array();
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_meta' ) ) {
			$raw[] = $subscription->get_meta( '_rw_service_domain', true );
		}
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_items' ) ) {
			foreach ( $subscription->get_items() as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'get_meta' ) ) {
					$raw[] = $item->get_meta( '_rw_service_domain', true );
				}
			}
		}
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
			$raw[] = $order->get_meta( '_rw_service_domain', true );
			$raw[] = $order->get_meta( '_reactwoo_domain', true );
		}
		foreach ( $raw as $candidate ) {
			$domain = self::normalise_domain( $candidate );
			if ( $domain !== '' ) {
				return $domain;
			}
		}
		return '';
	}

	/**
	 * @param object             $subscription Subscription.
	 * @param string             $slug         Slug.
	 * @param string             $server       Licence status.
	 * @param object|null        $api          Client.
	 * @return array<string, mixed>|null
	 */
	private static function update_stored_status( $subscription, $slug, $server, $api ) {
		$stored = self::stored_licenses( $subscription );
		if ( empty( $stored[ $slug ]['license_id'] ) ) {
			return null;
		}
		$license_id = (int) $stored[ $slug ]['license_id'];
		if ( is_object( $api ) && method_exists( $api, 'update_license_status' ) ) {
			$api->update_license_status( $license_id, $server );
		}
		$stored[ $slug ]['status'] = $server;
		self::write_licenses( $subscription, $stored );
		self::log(
			'reactivated',
			array(
				'subscription_id' => (int) $subscription->get_id(),
				'package_slug'    => $slug,
				'license_id'      => $license_id,
				'licence_status'  => $server,
			)
		);
		if ( ! empty( $stored[ $slug ]['cloud'] ) && 'active' === $server && function_exists( 'do_action' ) ) {
			$payload = $stored[ $slug ];
			$payload['maintenance_bundle'] = true;
			do_action( 'reactwoo_license_generated', $subscription, null, $payload );
		}
		return $stored[ $slug ];
	}

	/**
	 * @param array<string, mixed> $license  Server payload.
	 * @param array<string, mixed> $args     Request.
	 * @param array<string, array> $stored   Already issued.
	 * @param string               $slug     Requested slug.
	 * @return bool
	 */
	private static function response_collides( array $license, array $args, array $stored, $slug ) {
		$expected = isset( $args['source_ref'] ) ? (string) $args['source_ref'] : '';
		if ( ! empty( $license['source_ref'] ) && (string) $license['source_ref'] !== $expected ) {
			return true;
		}
		if ( ! empty( $license['package_slug'] ) && self::normalise_key( $license['package_slug'] ) !== $slug ) {
			return true;
		}
		$license_id = isset( $license['license_id'] ) ? (int) $license['license_id'] : 0;
		$key        = isset( $license['license_key'] ) ? (string) $license['license_key'] : '';
		foreach ( $stored as $other_slug => $row ) {
			if ( $other_slug === $slug ) {
				continue;
			}
			if ( $license_id && isset( $row['license_id'] ) && (int) $row['license_id'] === $license_id ) {
				return true;
			}
			if ( $key !== '' && isset( $row['license_key'] ) && (string) $row['license_key'] === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param object                           $subscription Subscription.
	 * @param array<string, array<string,mixed>> $stored     Rows.
	 * @return void
	 */
	private static function write_licenses( $subscription, array $stored ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'update_meta_data' ) ) {
			return;
		}
		$subscription->update_meta_data( self::META_LICENSES, $stored );
		if ( method_exists( $subscription, 'save' ) ) {
			$subscription->save();
		}
	}

	/**
	 * @param string $status Local or server status.
	 * @return string
	 */
	private static function account_status( $status ) {
		switch ( $status ) {
			case 'active':
				return 'active';
			case 'suspended':
			case 'inactive':
			case 'cancelled':
				return 'inactive';
			case 'expired':
				return 'expired';
			case 'refunded':
				return 'inactive';
			default:
				return 'pending';
		}
	}

	/**
	 * @param string $plan Plan key.
	 * @return string
	 */
	private static function plan_label( $plan ) {
		$labels = array(
			'essential' => __( 'Essential', 'reactwoo-api-manager' ),
			'standard'  => __( 'Standard', 'reactwoo-api-manager' ),
			'business'  => __( 'Business', 'reactwoo-api-manager' ),
		);
		return isset( $labels[ $plan ] ) ? $labels[ $plan ] : $plan;
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 * @return string
	 */
	private static function trigger_customer_copy( array $settings ) {
		$trigger = isset( $settings['trigger'] ) ? (string) $settings['trigger'] : self::TRIGGER_DEFAULT;
		if ( 'immediately' === $trigger ) {
			return __( 'The licence is issued when the subscription is active.', 'reactwoo-api-manager' );
		}
		if ( 'annual_only' === $trigger ) {
			return __( 'The licence is issued for annual billing only.', 'reactwoo-api-manager' );
		}
		if ( 'after_renewal' === $trigger ) {
			return __( 'The licence is issued after the required paid renewals. The first checkout payment is not counted.', 'reactwoo-api-manager' );
		}
		return __( 'Annual plans receive the licence when the subscription is active. Monthly plans receive it after the first successful renewal.', 'reactwoo-api-manager' );
	}

	/**
	 * @param array<string, mixed> $settings             Settings.
	 * @param bool                 $optimise_purchasable Purchasable flag.
	 * @return array<int, array<string, mixed>>
	 */
	private static function choice_rows( array $settings, $optimise_purchasable ) {
		$catalog = isset( $settings['catalog'] ) && is_array( $settings['catalog'] ) ? $settings['catalog'] : array();
		$rows    = self::rows_from_list( isset( $settings['essential_choices'] ) ? (string) $settings['essential_choices'] : '', $catalog );
		if ( self::optimise_allowed( $settings, $optimise_purchasable ) ) {
			$extra = self::resolve_package( (string) ( isset( $settings['optimise_package_id'] ) ? (int) $settings['optimise_package_id'] : 2267 ), $catalog );
			if ( $extra ) {
				$rows[] = $extra;
			}
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['cloud'] ) && ! empty( $row['slug'] ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param object $subscription Subscription.
	 * @param string $slug         Slug.
	 * @param string $name         Label.
	 * @return array<int, array<string, string>>
	 */
	private static function download_file( $subscription, $slug, $name ) {
		if ( ! class_exists( 'ReactWoo_Plugin_Download_Service' ) ) {
			return array();
		}
		if ( ! ReactWoo_Plugin_Download_Service::subscription_can_download( $subscription ) ) {
			return array();
		}
		if ( ReactWoo_Plugin_Download_Service::get_store_download_token() === '' ) {
			return array();
		}
		$url = ReactWoo_Plugin_Download_Service::get_proxy_url( (int) $subscription->get_id(), $slug );
		if ( $url === '' ) {
			return array();
		}
		return array(
			array(
				'name'      => $name,
				'url'       => $url,
				'remaining' => '',
				'expires'   => '',
				'slug'      => $slug,
				'source'    => 'maintenance_bundle',
				'version'   => '',
			),
		);
	}

	/**
	 * @param object $subscription Subscription.
	 * @return bool
	 */
	private static function customer_can_manage( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_customer_id' ) ) {
			return false;
		}
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() || ! function_exists( 'get_current_user_id' ) ) {
			return false;
		}
		return (int) $subscription->get_customer_id() === (int) get_current_user_id();
	}

	/**
	 * @return object|null
	 */
	private static function api() {
		if ( ! class_exists( 'ReactWoo_License_Server_API' ) ) {
			return null;
		}
		return new ReactWoo_License_Server_API();
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function normalise_key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_\-]/', '', $value );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * @param mixed $value Raw domain.
	 * @return string
	 */
	private static function normalise_domain( $value ) {
		$domain = trim( (string) $value );
		$domain = preg_replace( '#^https?://#i', '', $domain );
		$domain = strtolower( trim( (string) $domain, "/ \t" ) );
		if ( $domain === '' || ! preg_match( '/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/', $domain ) ) {
			return '';
		}
		return $domain;
	}

	/**
	 * @param mixed $list Raw package list.
	 * @return string
	 */
	private static function sanitize_package_list( $list ) {
		$tokens = array();
		foreach ( preg_split( '/[\s,]+/', (string) $list ) as $token ) {
			$token = trim( (string) $token );
			if ( $token === '' ) {
				continue;
			}
			if ( ctype_digit( $token ) ) {
				$tokens[] = (string) absint( $token );
				continue;
			}
			$key = self::normalise_key( $token );
			if ( $key !== '' ) {
				$tokens[] = $key;
			}
		}
		return implode( ', ', array_values( array_unique( $tokens ) ) );
	}
}
