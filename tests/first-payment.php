<?php
/**
 * 2.1.21: monthly Maintenance plans get keys at the first payment, like annual.
 * Keys need a paid parent order (processing or completed). Unpaid and failed
 * orders get none. Renewals add no keys. Cancel and refund still revoke them.
 * Runs after maintenance-bundle.php, which defines the stubs used here.
 */

function rw_fp_sub( $id, $product_id, $plan, $period, $parent_status ) {
	$sub = rw_bundle_subscription( $id, $product_id, $plan, $period, 'fp' . $id . '.example' );
	$sub->parent->meta['status'] = $parent_status;
	return $sub;
}

function rw_fp_issue( $sub, $api, $tag = 'fp' ) {
	foreach ( ReactWoo_Maintenance_Bundle::slugs_for_subscription( $sub, array() ) as $slug ) {
		ReactWoo_Maintenance_Bundle::provision_slug( $sub, $sub->parent, $slug, $tag, $api );
	}
	return ReactWoo_Maintenance_Bundle::stored_licenses( $sub );
}

function rw_fp_last_event( $sub_id ) {
	$event = '';
	foreach ( $GLOBALS['reactwoo_maintenance_bundle_log'] as $row ) {
		if ( isset( $row['subscription_id'] ) && (int) $row['subscription_id'] === (int) $sub_id ) {
			$event = $row['event'];
		}
	}
	return $event;
}

$fp_defaults = ReactWoo_Maintenance_Bundle::defaults();
rw_assert( $fp_defaults['trigger'] === 'first_payment', 'The default issue trigger is first_payment' );
$fp_legacy = ReactWoo_Maintenance_Bundle::sanitize_settings( array( 'trigger' => 'annual_immediate_monthly_after_renewal' ) );
rw_assert( $fp_legacy['trigger'] === 'first_payment', 'A saved legacy monthly-after-renewal trigger is read as first_payment' );
$fp_after = ReactWoo_Maintenance_Bundle::sanitize_settings( array( 'trigger' => 'after_renewal' ) );
rw_assert( $fp_after['trigger'] === 'after_renewal', 'After paid renewals can still be chosen' );

rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $fp_defaults, 'month', 0, true ) === true, 'first_payment allows monthly once the first payment is complete' );
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $fp_defaults, 'year', 0, true ) === true, 'first_payment allows annual once the first payment is complete' );
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $fp_defaults, 'month', 0, false ) === false, 'first_payment refuses monthly before payment' );
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $fp_defaults, 'year', 0, false ) === false, 'first_payment refuses annual before payment' );
$fp_immediate = $fp_defaults;
$fp_immediate['trigger'] = 'immediately';
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $fp_immediate, 'month', 0, false ) === false, 'Immediately still needs a paid first payment' );
$fp_old = $fp_defaults;
$fp_old['trigger'] = 'annual_immediate_monthly_after_renewal';
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( $fp_old, 'month', 0, true ) === false, 'The legacy rule, if forced by a filter, still defers monthly' );

// Paid status detection.
foreach ( array( 'processing' => true, 'completed' => true, 'pending' => false, 'on-hold' => false, 'failed' => false, 'cancelled' => false, 'refunded' => false, 'checkout-draft' => false ) as $fp_status => $fp_want ) {
	$fp_order = new RW_Bundle_Order( 7100 );
	$fp_order->meta['status'] = $fp_status;
	rw_assert( ReactWoo_Maintenance_Bundle::order_is_paid( $fp_order ) === $fp_want, 'Parent order ' . $fp_status . ( $fp_want ? ' counts' : ' does not count' ) . ' as paid' );
}

// Unpaid and failed monthly orders get no keys.
foreach ( array( 'pending', 'failed', 'on-hold' ) as $i => $fp_status ) {
	$fp_api = new RW_Bundle_Api();
	$fp_sub = rw_fp_sub( 700 + $i, 3657, 'standard', 'month', $fp_status );
	$fp_rows = rw_fp_issue( $fp_sub, $fp_api );
	rw_assert( $fp_rows === array() && $fp_api->calls === array(), 'Standard monthly with a ' . $fp_status . ' order gets no keys and no licence server call' );
	rw_assert( rw_fp_last_event( $fp_sub->id ) === 'skipped_unpaid', 'The ' . $fp_status . ' skip is logged as skipped_unpaid' );
}
$fp_api = new RW_Bundle_Api();
$fp_sub = rw_fp_sub( 710, 3654, 'essential', 'year', 'pending' );
$fp_sub->meta[ ReactWoo_Maintenance_Bundle::META_CHOICE ] = 'reactwoo-geo-commerce';
rw_assert( rw_fp_issue( $fp_sub, $fp_api ) === array(), 'Essential annual with an unpaid order gets no key' );

// Standard monthly: keys at the first payment, none added on renewal, revoked on cancel.
$fp_api = new RW_Bundle_Api();
$fp_std = rw_fp_sub( 720, 3657, 'standard', 'month', 'pending' );
rw_fp_issue( $fp_std, $fp_api );
rw_assert( ReactWoo_Maintenance_Bundle::stored_licenses( $fp_std ) === array(), 'Standard monthly has no keys while the order is pending' );
$fp_std->parent->meta['status'] = 'processing';
$fp_rows = rw_fp_issue( $fp_std, $fp_api, 'paid' );
$fp_slugs = array_keys( $fp_rows );
sort( $fp_slugs );
rw_assert( $fp_slugs === array( 'reactwoo-atomic-pro', 'reactwoo-geo-commerce', 'reactwoo-geo-core-pro' ), 'Standard monthly gets its three keys when the first payment completes' );
$fp_all_active = true;
foreach ( $fp_rows as $row ) {
	$fp_all_active = $fp_all_active && 'active' === $row['status'] && ! empty( $row['license_key'] ) && (int) $row['max_activations'] === 1;
}
rw_assert( $fp_all_active, 'Each Standard monthly key is active and limited to one site' );
$fp_calls = count( $fp_api->calls );
$fp_ids   = ReactWoo_Maintenance_Bundle::license_ids( $fp_std );
ReactWoo_Maintenance_Bundle::note_paid_renewal( $fp_std, new RW_Bundle_Order( 7201 ) );
rw_fp_issue( $fp_std, $fp_api, 'renewal' );
rw_assert( count( $fp_api->calls ) === $fp_calls, 'The first monthly renewal does not call the licence server again' );
rw_assert( ReactWoo_Maintenance_Bundle::license_ids( $fp_std ) === $fp_ids && count( $fp_api->provisions ) === 3, 'The first monthly renewal adds no duplicate keys' );
ReactWoo_Maintenance_Bundle::note_paid_renewal( $fp_std, new RW_Bundle_Order( 7202 ) );
rw_fp_issue( $fp_std, $fp_api, 'renewal-2' );
rw_assert( count( $fp_api->provisions ) === 3, 'A second renewal adds no keys either' );
$fp_std->status = 'cancelled';
ReactWoo_Maintenance_Bundle::sync_stored_licences( $fp_std, 'cancelled', '', $fp_api );
$fp_cancelled = true;
foreach ( ReactWoo_Maintenance_Bundle::stored_licenses( $fp_std ) as $row ) {
	$fp_cancelled = $fp_cancelled && 'cancelled' === $row['status'];
}
rw_assert( $fp_cancelled, 'Cancelling Standard monthly cancels every key' );
$fp_cancel_updates = 0;
foreach ( $fp_api->updates as $update ) {
	if ( 'cancelled' === $update[1] && in_array( $update[0], $fp_ids, true ) ) {
		++$fp_cancel_updates;
	}
}
rw_assert( 3 === $fp_cancel_updates, 'The licence server is told to cancel each Standard monthly key' );
rw_fp_issue( $fp_std, $fp_api, 'after-cancel' );
rw_assert( count( $fp_api->provisions ) === 3, 'A cancelled subscription is not issued new keys' );

// Business monthly: four keys, including Decision Cloud, at the first payment. Refund revokes them.
$fp_api = new RW_Bundle_Api();
$fp_biz = rw_fp_sub( 730, 3660, 'business', 'month', 'completed' );
$fp_rows = rw_fp_issue( $fp_biz, $fp_api );
rw_assert( count( $fp_rows ) === 4 && isset( $fp_rows['reactwoo-decision-cloud'] ), 'Business monthly gets four keys, with Decision Cloud, at the first payment' );
ReactWoo_Maintenance_Bundle::note_paid_renewal( $fp_biz, new RW_Bundle_Order( 7301 ) );
rw_fp_issue( $fp_biz, $fp_api, 'renewal' );
rw_assert( count( $fp_api->provisions ) === 4, 'Business monthly renewal adds no duplicate keys' );
$fp_refunded = ReactWoo_Maintenance_Bundle::refund_bundle( $fp_biz, $fp_api );
$fp_all_refunded = count( $fp_refunded ) === 4;
foreach ( ReactWoo_Maintenance_Bundle::stored_licenses( $fp_biz ) as $row ) {
	$fp_all_refunded = $fp_all_refunded && 'refunded' === $row['status'];
}
rw_assert( $fp_all_refunded, 'A full refund still revokes every Business monthly key' );
$fp_biz->parent->meta['status'] = 'refunded';
rw_fp_issue( $fp_biz, $fp_api, 'after-refund' );
rw_assert( count( $fp_api->provisions ) === 4, 'A refunded subscription is not issued new keys' );

// Essential annual still issues on its first payment; Essential without a choice still waits.
$fp_api = new RW_Bundle_Api();
$fp_ess = rw_fp_sub( 740, 3654, 'essential', 'year', 'completed' );
$fp_ess->meta[ ReactWoo_Maintenance_Bundle::META_CHOICE ] = 'reactwoo-geo-commerce';
$fp_rows = rw_fp_issue( $fp_ess, $fp_api );
rw_assert( array_keys( $fp_rows ) === array( 'reactwoo-geo-commerce' ), 'Essential annual issues its chosen key on the first payment' );
$fp_api = new RW_Bundle_Api();
foreach ( array( 'year', 'month' ) as $i => $fp_period ) {
	$fp_nochoice = rw_fp_sub( 750 + $i, 3654, 'essential', $fp_period, 'completed' );
	rw_assert( ReactWoo_Maintenance_Bundle::slugs_for_subscription( $fp_nochoice, array() ) === array() && rw_fp_issue( $fp_nochoice, $fp_api ) === array(), 'Essential ' . $fp_period . ' without a plugin choice still waits for the choice' );
}
$fp_monthly_ess = rw_fp_sub( 752, 3654, 'essential', 'month', 'processing' );
$fp_monthly_ess->meta[ ReactWoo_Maintenance_Bundle::META_CHOICE ] = 'reactwoo-geo-core-pro';
rw_assert( array_keys( rw_fp_issue( $fp_monthly_ess, $fp_api ) ) === array( 'reactwoo-geo-core-pro' ), 'Essential monthly issues its chosen key once paid' );

// A subscription made by hand with no parent order counts as paid only when active.
$fp_api = new RW_Bundle_Api();
$fp_manual = rw_fp_sub( 760, 3657, 'standard', 'month', 'completed' );
$fp_manual->parent = null;
$fp_manual->status = 'pending';
rw_assert( rw_fp_issue( $fp_manual, $fp_api, 'manual' ) === array(), 'A pending subscription with no parent order gets no keys' );
$fp_manual->status = 'active';
rw_assert( count( rw_fp_issue( $fp_manual, $fp_api, 'manual' ) ) === 3, 'An active subscription with no parent order gets its keys' );

// A counted paid renewal is a payment even if the parent order is not paid.
$fp_api = new RW_Bundle_Api();
$fp_renewed = rw_fp_sub( 770, 3657, 'standard', 'month', 'failed' );
ReactWoo_Maintenance_Bundle::note_paid_renewal( $fp_renewed, new RW_Bundle_Order( 7701 ) );
rw_assert( count( rw_fp_issue( $fp_renewed, $fp_api ) ) === 3, 'A paid renewal issues keys when the first order never paid' );

// After paid renewals still waits even when the first payment is complete.
rw_assert( ReactWoo_Maintenance_Bundle::trigger_allows( array( 'trigger' => 'after_renewal', 'paid_periods' => 1 ), 'month', 0, true ) === false, 'After paid renewals still ignores the checkout payment' );

$fp_handler_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-subscription-handler.php' );
rw_assert( strpos( $fp_handler_src, "'woocommerce_subscription_status_active'" ) !== false, 'Keys are still issued from subscription activation, which follows the paid first order' );
rw_assert( strpos( $fp_handler_src, 'ReactWoo_Maintenance_Bundle::note_paid_renewal( $subscription, $renewal_order )' ) !== false, 'Renewals are still counted once per renewal order' );
