<?php
/**
 * 2.1.20: a full refund of a bundle order cancels the linked subscription.
 * Keys stay refunded, partial refunds change nothing, and repeat calls are no-ops.
 * Runs after staging-fixes.php, which defines the order/subscription lookups and $handler.
 */

class RW_Refund_Cancel_Sub extends RW_Bundle_Sub {
	public $status_changes = array();
	public $notes = array();
	public function update_status( $new_status, $note = '' ) {
		$old                    = $this->status;
		$this->status           = $new_status;
		$this->status_changes[] = $new_status;
		$this->notes[]          = $note;
		// WooCommerce Subscriptions fires the status-change hook the store listens to.
		$GLOBALS['handler']->handle_subscription_status_change( $this, $new_status, $old );
	}
	public function can_be_updated_to( $status ) {
		unset( $status );
		return ! in_array( $this->status, array( 'cancelled', 'expired' ), true );
	}
}

function rw_refund_cancel_sub( $id, $product_id, $plan, $period, $domain ) {
	$base = rw_bundle_subscription( $id, $product_id, $plan, $period, $domain );
	$sub  = new RW_Refund_Cancel_Sub( $base->id, $base->customer_id, $base->status );
	foreach ( get_object_vars( $base ) as $key => $value ) {
		$sub->$key = $value;
	}
	$sub->update_meta_data( ReactWoo_Maintenance_Bundle::META_PLAN, $plan );
	rw_seed_bundle_licence( $sub );
	$GLOBALS['rw_test_orders'][ $sub->parent->get_id() ]              = $sub->parent;
	$GLOBALS['rw_test_order_subscriptions'][ $sub->parent->get_id() ] = array( $sub );
	return $sub;
}

$bundle = new ReactWoo_Maintenance_Bundle();

// Full refund: subscription cancelled once, keys stay refunded (never overwritten with cancelled).
$full_sub = rw_refund_cancel_sub( 130, 3654, 'essential', 'year', 'full-refund-cancel.example' );
$full_sub->parent->meta['total']    = 429;
$full_sub->parent->meta['refunded'] = 429;
ReactWoo_License_Server_API::$updates = array();
$bundle->handle_refund( $full_sub->parent->get_id(), 30 );
rw_assert( 'cancelled' === $full_sub->get_status(), 'A full refund of a bundle order cancels the linked subscription' );
rw_assert( 'refunded' === rw_bundle_core_status( $full_sub ), 'Keys stay refunded after the refund cancels the subscription' );
rw_assert( ! in_array( array( 501, 'cancelled' ), ReactWoo_License_Server_API::$updates, true ), 'The cancellation never sends status cancelled for a refunded key' );
rw_assert( false !== strpos( (string) end( $full_sub->notes ), 'fully refunded' ), 'The subscription note says it was cancelled because of the refund' );

// Idempotent: a second refund event changes nothing.
$bundle->handle_refund( $full_sub->parent->get_id(), 31 );
rw_assert( 1 === count( $full_sub->status_changes ), 'A repeat refund event does not cancel the subscription twice' );
rw_assert( false === ReactWoo_Maintenance_Bundle::cancel_after_full_refund( $full_sub, 130 ), 'cancel_after_full_refund is a no-op on a cancelled subscription' );
rw_assert( 'refunded' === rw_bundle_core_status( $full_sub ), 'Keys are still refunded after repeat calls' );

// Partial refund: nothing changes.
$partial_sub = rw_refund_cancel_sub( 131, 3657, 'standard', 'month', 'partial-refund.example' );
$partial_sub->parent->meta['total']    = 99;
$partial_sub->parent->meta['refunded'] = 10;
$bundle->handle_refund( $partial_sub->parent->get_id(), 32 );
rw_assert( 'active' === $partial_sub->get_status(), 'A partial refund leaves the subscription active' );
rw_assert( 'active' === rw_bundle_core_status( $partial_sub ), 'A partial refund leaves the keys active' );
rw_assert( array() === $partial_sub->status_changes, 'A partial refund makes no status change' );

// A bundle subscription with no keys yet (monthly before first renewal) is still cancelled on a full refund.
$nokeys = rw_refund_cancel_sub( 132, 3657, 'standard', 'month', 'nokeys-refund.example' );
$nokeys->update_meta_data( ReactWoo_Maintenance_Bundle::META_LICENSES, array() );
$nokeys->parent->meta['total']    = 99;
$nokeys->parent->meta['refunded'] = 99;
$bundle->handle_refund( $nokeys->parent->get_id(), 33 );
rw_assert( 'cancelled' === $nokeys->get_status(), 'A full refund cancels a bundle subscription that has no keys yet' );

// Normal cancellation is unchanged: keys become cancelled, not refunded.
$normal = rw_refund_cancel_sub( 133, 3657, 'standard', 'year', 'normal-cancel.example' );
$normal->parent->meta['total']    = 1089;
$normal->parent->meta['refunded'] = 0;
$normal->update_status( 'cancelled', 'customer cancelled' );
rw_assert( 'cancelled' === rw_bundle_core_status( $normal ), 'A normal cancellation still cancels the keys' );

// Ended subscriptions are left alone.
$expired = rw_refund_cancel_sub( 134, 3654, 'essential', 'year', 'expired-refund.example' );
$expired->status = 'expired';
rw_assert( false === ReactWoo_Maintenance_Bundle::cancel_after_full_refund( $expired, 134 ), 'An expired subscription is not moved to cancelled' );

// Non-bundle subscriptions are ignored by the refund hook.
$plain = new RW_Refund_Cancel_Sub( 135, 9, 'active' );
$plain->parent = new RW_Bundle_Order( 9135 );
$plain->parent->meta['total']    = 50;
$plain->parent->meta['refunded'] = 50;
$GLOBALS['rw_test_orders'][9135]              = $plain->parent;
$GLOBALS['rw_test_order_subscriptions'][9135] = array( $plain );
$bundle->handle_refund( 9135, 34 );
rw_assert( 'active' === $plain->get_status(), 'A refund of a non-bundle order does not cancel its subscription' );

$runbook = dirname( __DIR__ ) . '/docs/SUPPORT-RUNBOOK.md';
rw_assert( file_exists( $runbook ) && false !== stripos( (string) file_get_contents( $runbook ), 'chargeback' ), 'The support runbook documents chargebacks and disputes' );
