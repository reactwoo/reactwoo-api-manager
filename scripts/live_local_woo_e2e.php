<?php
/**
 * Live Local WooCommerce Cloud commerce E2E (PLAN.md §12 / §17).
 *
 * Loads WordPress on reactwoo.local. Creates throwaway pending subscriptions.
 * Never processes payment. Refuses non-local hosts. Does not enable production
 * REACTWOO_CLOUD_BRIDGE_ENABLED.
 *
 * Usage: php scripts/live_local_woo_e2e.php
 *
 * @package ReactWoo_API_Manager
 */

$public = dirname( __DIR__, 4 );
$wp_load = $public . '/wp-load.php';
if ( ! is_file( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found at {$wp_load}\n" );
	exit( 1 );
}

require $wp_load;

$failed = array();
$cleanup = array(
	'subscriptions' => array(),
	'orders'        => array(),
	'user_id'       => 0,
);

/**
 * @param bool   $ok      Condition.
 * @param string $message Label.
 */
function rw_live_assert( $ok, $message ) {
	global $failed;
	if ( $ok ) {
		echo "PASS: {$message}\n";
		return;
	}
	$failed[] = $message;
	echo "FAIL: {$message}\n";
}

$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$local = ( 'localhost' === strtolower( $host ) || str_ends_with( strtolower( $host ), '.local' ) );
rw_live_assert( $local, 'home_url host is Local' );
if ( ! $local ) {
	fwrite( STDERR, "Refusing to run outside Local\n" );
	exit( 2 );
}

rw_live_assert( class_exists( 'WooCommerce' ), 'WooCommerce is active' );
rw_live_assert( function_exists( 'wcs_create_subscription' ), 'WooCommerce Subscriptions is active' );
rw_live_assert( class_exists( 'RWCC_Bootstrap' ), 'Cloud commerce bridge classes loaded (Local flag only)' );
rw_live_assert( defined( 'REACTWOO_CLOUD_BRIDGE_ENABLED' ) && REACTWOO_CLOUD_BRIDGE_ENABLED, 'Local Cloud bridge flag is on' );

$settings = RWCC_Settings::from_wordpress();
$plans    = new RWCC_Plan_Map( $settings->product_map() );

$parent = (int) $settings->get( 'product_decision_cloud' );
$growth = array_map( 'intval', preg_split( '/\s*,\s*/', (string) $settings->get( 'product_growth' ) ) );
$pro_id = (int) $settings->get( 'product_geocore_pro' );
$growth_monthly = isset( $growth[0] ) ? (int) $growth[0] : 0;

rw_live_assert( $parent === 3166, 'settings product_decision_cloud=3166' );
rw_live_assert( $growth_monthly === 3174, 'settings product_growth monthly=3174' );
rw_live_assert( $pro_id > 0, 'settings product_geocore_pro is bound' );

$parent_post = get_post( $parent );
$growth_post = get_post( $growth_monthly );
$pro_post    = get_post( $pro_id );
rw_live_assert( $parent_post && $parent_post->post_type === 'product', 'parent 3166 exists' );
rw_live_assert( $growth_post && in_array( $growth_post->post_type, array( 'product', 'product_variation' ), true ), 'growth monthly variation exists' );
rw_live_assert( $pro_post && $pro_post->post_type === 'product', 'Geo Core Pro product exists' );

$plan = $plans->resolve( $parent, $growth_monthly, array( 'RWCC_Plan_Map', 'wp_meta_reader' ) );
rw_live_assert( $plan === 'growth', 'variation 3174 maps to growth via _rw_cloud_plan' );

$pro_product    = wc_get_product( $pro_id );
$growth_product = wc_get_product( $growth_monthly );
rw_live_assert( $pro_product instanceof WC_Product, 'Geo Core Pro WC_Product loads' );
rw_live_assert( $growth_product instanceof WC_Product, 'Growth monthly WC_Product loads' );

$email = 'rwcc-live-e2e-' . wp_generate_password( 8, false, false ) . '@example.invalid';
$user_id = wp_insert_user(
	array(
		'user_login' => 'rwcc_live_e2e_' . wp_generate_password( 6, false, false ),
		'user_pass'  => wp_generate_password( 20 ),
		'user_email' => $email,
		'role'       => 'customer',
	)
);
rw_live_assert( ! is_wp_error( $user_id ) && (int) $user_id > 0, 'throwaway customer created' );
$cleanup['user_id'] = is_wp_error( $user_id ) ? 0 : (int) $user_id;

if ( $cleanup['user_id'] && $pro_product && function_exists( 'wcs_create_subscription' ) ) {
	$now   = time();
	$start = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
	$next  = gmdate( 'Y-m-d H:i:s', $now + ( 20 * DAY_IN_SECONDS ) );

	$individual = wcs_create_subscription(
		array(
			'status'           => 'pending',
			'customer_id'      => $cleanup['user_id'],
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'start_date'       => $start,
		)
	);
	if ( ! is_wp_error( $individual ) && is_object( $individual ) ) {
		$cleanup['subscriptions'][] = (int) $individual->get_id();
		$individual->add_product( $pro_product, 1 );
		$individual->update_dates(
			array(
				'start'        => $start,
				'next_payment' => $next,
			)
		);
		$individual->calculate_totals();
		$individual->save();
		rw_live_assert( (int) $individual->get_id() > 0, 'pending Geo Core Pro subscription created (no payment)' );

		$row = RWCC_Checkout_Credit::subscription_row( $individual, $settings );
		$row['covered'] = true;
		$quote = RWCC_Checkout_Credit::quote(
			'growth',
			array( $row ),
			99,
			$individual->get_currency(),
			$settings,
			$plans,
			$now
		);
		rw_live_assert( ! empty( $quote['ok'] ) || ! empty( $quote['credit']['applied_credit'] ) || isset( $quote['credit'] ), 'credit quote returned for live Geo Core Pro row' );
		$applied = isset( $quote['credit']['applied_credit'] ) ? (float) $quote['credit']['applied_credit'] : 0.0;
		$blocked = ! empty( $quote['block_unexplained'] );
		rw_live_assert( $applied > 0 || $blocked, 'live quote either applies remaining-term credit or blocks unexplained full price' );

		$cloud = wcs_create_subscription(
			array(
				'status'           => 'pending',
				'customer_id'      => $cleanup['user_id'],
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'start_date'       => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);
		if ( ! is_wp_error( $cloud ) && is_object( $cloud ) ) {
			$cleanup['subscriptions'][] = (int) $cloud->get_id();
			$cloud->add_product( $growth_product, 1 );
			$cloud->update_meta_data( RWCC_Order_Meta::META_PLAN, 'growth' );
			$cloud->calculate_totals();
			$cloud->save();
			rw_live_assert( (int) $cloud->get_id() > 0, 'pending Growth Cloud subscription created (no payment)' );

			$activation = array(
				'ok'                    => true,
				'webhook_ok'            => true,
				'plan'                  => 'growth',
				'cloud_subscription_id' => (string) $cloud->get_id(),
				'at'                    => gmdate( 'c' ),
				'credit_amount'         => (string) $applied,
				'credit_currency'       => (string) $individual->get_currency(),
			);
			$commit = RWCC_Supersession::commit_covered( $cloud, array( $individual ), $settings, $plans, $activation );
			$individual = wcs_get_subscription( $individual->get_id() );
			rw_live_assert( empty( $commit['failed'] ), 'supersession commit did not fail' );
			rw_live_assert( RWCC_Supersession::is_superseded( $individual ), 'Geo Core Pro is superseded after simulated Cloud activation' );
			rw_live_assert( (int) $individual->get_time( 'next_payment' ) === 0, 'superseded individual has no next automatic payment' );

			$fail = RWCC_Supersession::commit_covered(
				$cloud,
				array( $individual ),
				$settings,
				$plans,
				array(
					'ok'         => false,
					'webhook_ok' => false,
					'plan'       => 'growth',
				)
			);
			rw_live_assert( ! empty( $fail['failed'] ), 'failed activation does not commit a second supersession batch' );

			$effective = gmdate( 'c', $now + ( 30 * DAY_IN_SECONDS ) );
			$payload   = array(
				'confirmed'             => true,
				'none_selected'         => false,
				'effective_at'          => $effective,
				'charges_now'           => false,
				'planned_subscriptions' => array(
					array(
						'slug'       => 'reactwoo-geocore-pro',
						'product_id' => (string) $pro_id,
						'start_date' => $effective,
						'charge_now' => false,
						'price'      => '0.00',
					),
				),
			);
			$materialized = RWCC_Scheduled_Subscription::materialize(
				$payload,
				array(
					'customer_id'           => $cleanup['user_id'],
					'cloud_subscription_id' => (string) $cloud->get_id(),
				)
			);
			rw_live_assert( ! empty( $materialized['materialized'] ), 'downgrade materialize ran' );
			rw_live_assert( empty( $materialized['charges_now'] ), 'downgrade did not charge now' );
			$created_id = '';
			if ( ! empty( $materialized['created_subscription_ids'][0] ) ) {
				$created_id = (string) $materialized['created_subscription_ids'][0];
				$cleanup['subscriptions'][] = (int) $created_id;
			}
			if ( $created_id === '' && ! empty( $materialized['planned_subscriptions'][0]['error'] ) ) {
				echo 'INFO: materialize error=' . $materialized['planned_subscriptions'][0]['error'] . "\n";
			}
			rw_live_assert( $created_id !== '', 'pending individual materialized at Cloud end' );
			$scheduled = $created_id !== '' ? wcs_get_subscription( $created_id ) : null;
			if ( $scheduled ) {
				rw_live_assert( $scheduled->get_status() === 'pending', 'materialized individual stays pending' );
				rw_live_assert( (string) $scheduled->get_meta( RWCC_Scheduled_Subscription::META_CHARGE_NOW, true ) === '0', 'materialized individual charge_now=0' );
			}

			$cancelled = RWCC_Scheduled_Subscription::cancel_created( $materialized );
			rw_live_assert( ! empty( $cancelled ) && is_array( $cancelled ), 'Cloud reactivation path can cancel materialized individuals' );
		} else {
			rw_live_assert( false, 'could not create Cloud subscription: ' . ( is_wp_error( $cloud ) ? $cloud->get_error_message() : 'unknown' ) );
		}
	} else {
		rw_live_assert( false, 'could not create individual subscription: ' . ( is_wp_error( $individual ) ? $individual->get_error_message() : 'unknown' ) );
	}
}

foreach ( $cleanup['subscriptions'] as $sid ) {
	$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sid ) : null;
	if ( $sub && method_exists( $sub, 'delete' ) ) {
		$sub->delete( true );
	}
}
if ( $cleanup['user_id'] > 0 ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $cleanup['user_id'] );
}

echo "\n";
if ( $failed ) {
	fwrite( STDERR, 'Failed ' . count( $failed ) . " assertion(s). Production Cloud commerce remains off.\n" );
	exit( 1 );
}

echo "Live Local WooCommerce Cloud commerce E2E passed. Production Cloud commerce remains off.\n";
exit( 0 );
