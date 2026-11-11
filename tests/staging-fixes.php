<?php
/**
 * Staging matrix fixes: status hook argument order, standalone package id
 * and service website, and refunds of a zero-total order.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string $type Date type.
	 * @return string
	 */
	function current_time( $type = 'mysql' ) {
		unset( $type );
		return '2026-11-10 12:00:00';
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int $order_id Order id.
	 * @return object|null
	 */
	function wc_get_order( $order_id ) {
		$order_id = (int) $order_id;
		return isset( $GLOBALS['rw_test_orders'][ $order_id ] ) ? $GLOBALS['rw_test_orders'][ $order_id ] : null;
	}
}

if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
	/**
	 * @param int                  $order_id Order id.
	 * @param array<string, mixed> $args     Unused.
	 * @return array<int, object>
	 */
	function wcs_get_subscriptions_for_order( $order_id, $args = array() ) {
		unset( $args );
		$order_id = (int) $order_id;
		return isset( $GLOBALS['rw_test_order_subscriptions'][ $order_id ] ) ? $GLOBALS['rw_test_order_subscriptions'][ $order_id ] : array();
	}
}

if ( ! class_exists( 'ReactWoo_License_Server_API', false ) ) {
	/**
	 * Records provision and status calls. Does not talk to the licence server.
	 */
	class ReactWoo_License_Server_API {
		/**
		 * nested, toplevel, both, or none.
		 *
		 * @var string
		 */
		public static $mode = 'nested';

		/**
		 * @var array<string, mixed>|null
		 */
		public static $last_provision = null;

		/**
		 * @var array<int, array{0:int,1:string}>
		 */
		public static $updates = array();

		/**
		 * @var array<int, array<string, mixed>>
		 */
		public static $syncs = array();

		/**
		 * @param int $package_id Package id.
		 * @return array<string, mixed>
		 */
		public function get_package_by_id( $package_id ) {
			return array(
				'id'   => (int) $package_id,
				'slug' => 'reactwoo-geo-core-pro',
				'name' => 'Geo Core Pro',
			);
		}

		/**
		 * @param array<string, mixed> $args Provision arguments.
		 * @return array<string, mixed>
		 */
		public function provision_license_v1( $args ) {
			self::$last_provision = $args;
			$row = array(
				'license_key' => 'RW-SECRET-standalone',
				'license_id'  => 9100,
				'domain'      => isset( $args['domain'] ) ? $args['domain'] : '',
			);
			if ( 'nested' === self::$mode || 'both' === self::$mode ) {
				$row['package'] = array(
					'id'   => 2269,
					'slug' => 'reactwoo-geo-core-pro',
				);
			}
			if ( 'toplevel' === self::$mode ) {
				$row['package_id'] = 2269;
			}
			if ( 'both' === self::$mode ) {
				$row['package_id'] = 1;
			}
			return $row;
		}

		/**
		 * @param int    $license_id Licence id.
		 * @param string $status     Status sent to PUT api/licenses/{id}/status.
		 * @return bool
		 */
		public function update_license_status( $license_id, $status ) {
			self::$updates[] = array( (int) $license_id, (string) $status );
			return true;
		}

		/**
		 * @param int         $subscription_id Subscription id.
		 * @param string      $status          Woo status.
		 * @param string|null $end             Period end.
		 * @param array       $extra           Extra sync fields.
		 * @return array<string, string>
		 */
		public function sync_subscription_v1( $subscription_id, $status, $end = null, $extra = array() ) {
			self::$syncs[] = array(
				'id'     => (int) $subscription_id,
				'status' => (string) $status,
				'end'    => $end,
				'extra'  => $extra,
			);
			return array( 'license_status' => (string) $status );
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-subscription-handler.php';

/**
 * @param int    $id     Subscription id.
 * @param string $domain Service website. Empty leaves the meta unset.
 * @return RW_Bundle_Sub
 */
function rw_standalone_subscription( $id, $domain = '' ) {
	$sub                 = new RW_Bundle_Sub( $id, 7, 'active' );
	$sub->billing_period = 'year';
	$sub->items          = array( new RW_Bundle_Item( new RW_Bundle_Product( 2294 ) ) );
	if ( '' !== $domain ) {
		$sub->meta['_rw_service_domain'] = $domain;
	}
	$sub->parent = new RW_Bundle_Order( 9000 + (int) $id );
	return $sub;
}

/**
 * @param object $subscription Subscription.
 * @param string $status       Local licence status.
 * @return void
 */
function rw_seed_bundle_licence( $subscription, $status = 'active' ) {
	$subscription->update_meta_data(
		ReactWoo_Maintenance_Bundle::META_LICENSES,
		array(
			'reactwoo-geo-core-pro' => array(
				'package_slug' => 'reactwoo-geo-core-pro',
				'package_id'   => 2269,
				'license_id'   => 501,
				'license_key'  => 'RW-SECRET-bundle',
				'status'       => $status,
				'domain'       => 'maint.example',
			),
		)
	);
	$subscription->update_meta_data( ReactWoo_Maintenance_Bundle::META_PLAN, 'standard' );
}

/**
 * @param object $subscription Subscription.
 * @return string
 */
function rw_bundle_core_status( $subscription ) {
	$stored = ReactWoo_Maintenance_Bundle::stored_licenses( $subscription );
	return isset( $stored['reactwoo-geo-core-pro']['status'] ) ? (string) $stored['reactwoo-geo-core-pro']['status'] : '';
}

$handler = new ReactWoo_Subscription_Handler();
$create  = new ReflectionMethod( 'ReactWoo_Subscription_Handler', 'create_license_for_subscription' );
$create->setAccessible( true );

if ( ! isset( $GLOBALS['rw_test_post_meta'] ) || ! is_array( $GLOBALS['rw_test_post_meta'] ) ) {
	$GLOBALS['rw_test_post_meta'] = array();
}
$GLOBALS['rw_test_post_meta'][2294]['_reactwoo_license_package_id'] = 2269;

ReactWoo_License_Server_API::$mode = 'nested';
$standalone                        = rw_standalone_subscription( 91, 'geo.example.com' );
$create->invoke( $handler, $standalone, $standalone->parent );
rw_assert( (int) $standalone->get_meta( '_reactwoo_license_package_id' ) === 2269, 'Standalone provisioning stores package.id as the licence package id' );
rw_assert( (int) $standalone->get_meta( '_reactwoo_license_id' ) === 9100, 'Standalone provisioning still stores the licence id' );
rw_assert(
	isset( ReactWoo_License_Server_API::$last_provision['domain'] ) && ReactWoo_License_Server_API::$last_provision['domain'] === 'geo.example.com',
	'Standalone provisioning uses _rw_service_domain when it is present'
);
rw_assert(
	! isset( ReactWoo_License_Server_API::$last_provision['source_ref'] ) && empty( ReactWoo_License_Server_API::$last_provision['maintenance_bundle'] ),
	'Standalone provisioning still omits the bundle source ref'
);

ReactWoo_License_Server_API::$mode = 'both';
$prefer_nested                     = rw_standalone_subscription( 92, 'geo.example.com' );
$create->invoke( $handler, $prefer_nested, $prefer_nested->parent );
rw_assert( (int) $prefer_nested->get_meta( '_reactwoo_license_package_id' ) === 2269, 'package.id is kept when the response also has a different top-level package id' );

ReactWoo_License_Server_API::$mode = 'toplevel';
$legacy                            = rw_standalone_subscription( 93, 'geo.example.com' );
$create->invoke( $handler, $legacy, $legacy->parent );
rw_assert( (int) $legacy->get_meta( '_reactwoo_license_package_id' ) === 2269, 'A top-level package_id is still stored when package.id is absent' );

ReactWoo_License_Server_API::$mode = 'none';
$fallback                          = rw_standalone_subscription( 94, 'geo.example.com' );
$create->invoke( $handler, $fallback, $fallback->parent );
rw_assert( (int) $fallback->get_meta( '_reactwoo_license_package_id' ) === 2269, 'Standalone provisioning falls back to the product package id' );

$licence_domain                                  = rw_standalone_subscription( 95, 'service.example.com' );
$licence_domain->parent->meta['_reactwoo_domain'] = 'licence.example.com';
ReactWoo_License_Server_API::$mode               = 'nested';
$create->invoke( $handler, $licence_domain, $licence_domain->parent );
rw_assert(
	ReactWoo_License_Server_API::$last_provision['domain'] === 'licence.example.com',
	'The checkout licence domain is used ahead of the service website'
);

$order_service                       = rw_standalone_subscription( 96, '' );
$order_service->parent->meta['_rw_service_domain'] = 'order.example.com';
$create->invoke( $handler, $order_service, $order_service->parent );
rw_assert(
	ReactWoo_License_Server_API::$last_provision['domain'] === 'order.example.com',
	'Standalone provisioning reads _rw_service_domain from the order'
);

$email_only = rw_standalone_subscription( 97, '' );
$create->invoke( $handler, $email_only, $email_only->parent );
rw_assert(
	ReactWoo_License_Server_API::$last_provision['domain'] === 'example.com',
	'The billing email domain is used only when no website was stored'
);

$saved_lists = isset( $GLOBALS['rw_test_subscriptions'] ) ? $GLOBALS['rw_test_subscriptions'] : array();
unset( $GLOBALS['rw_test_filters']['reactwoo_maintenance_standalone_package_ids'] );
$owned = new WC_Subscription(
	90,
	7,
	'active',
	array(
		'_reactwoo_license_package_id' => (int) $standalone->get_meta( '_reactwoo_license_package_id' ),
		'_reactwoo_license_id'         => (int) $standalone->get_meta( '_reactwoo_license_id' ),
		'_reactwoo_license_key'        => (string) $standalone->get_meta( '_reactwoo_license_key' ),
	)
);
$GLOBALS['rw_test_subscriptions'] = array( $owned );
$overlap_bundle                   = rw_bundle_subscription( 98, 3657, 'standard', 'year', 'overlap-stored.example' );
$overlap_api                      = new RW_Bundle_Api();
ReactWoo_Maintenance_Bundle::provision_slug( $overlap_bundle, $overlap_bundle->parent, 'reactwoo-geo-core-pro', 'stored-overlap', $overlap_api );
ReactWoo_Maintenance_Bundle::provision_slug( $overlap_bundle, $overlap_bundle->parent, 'reactwoo-geo-commerce', 'stored-overlap', $overlap_api );
rw_assert( ! isset( ReactWoo_Maintenance_Bundle::stored_licenses( $overlap_bundle )['reactwoo-geo-core-pro'] ), 'A stored standalone package id stops the bundle issuing that package again' );
rw_assert( isset( ReactWoo_Maintenance_Bundle::stored_licenses( $overlap_bundle )['reactwoo-geo-commerce'] ), 'Packages the customer has not bought are still issued' );
rw_assert( (int) $owned->get_meta( '_reactwoo_license_id' ) === 9100, 'Overlap detection does not change the standalone licence' );
$GLOBALS['rw_test_subscriptions'] = $saved_lists;

$status_sub = rw_bundle_subscription( 110, 3657, 'standard', 'year', 'status.example' );
rw_seed_bundle_licence( $status_sub );
$status_sub->end_date = '2099-01-01 00:00:00';

$expect = array(
	array( 'on-hold', 'active', 'suspended', 'On-hold suspends bundle licences' ),
	array( 'active', 'on-hold', 'active', 'Reactivating the subscription restores bundle licences' ),
	array( 'pending-cancel', 'active', 'active', 'Pending cancel keeps bundle licences active until the period ends' ),
	array( 'cancelled', 'pending-cancel', 'cancelled', 'Cancel sets bundle licences to cancelled' ),
	array( 'expired', 'cancelled', 'expired', 'Expiry sets bundle licences to expired' ),
);
foreach ( $expect as $row ) {
	ReactWoo_License_Server_API::$syncs = array();
	$handler->handle_subscription_status_change( $status_sub, $row[0], $row[1] );
	rw_assert( rw_bundle_core_status( $status_sub ) === $row[2], $row[3] );
	$last_sync = ReactWoo_License_Server_API::$syncs ? ReactWoo_License_Server_API::$syncs[ count( ReactWoo_License_Server_API::$syncs ) - 1 ] : array();
	rw_assert( isset( $last_sync['status'] ) && $last_sync['status'] === $row[0], 'Status sync sends the new Woo status ' . $row[0] );
}

$status_sub->end_date = '2000-01-01 00:00:00';
rw_seed_bundle_licence( $status_sub, 'active' );
$handler->handle_subscription_status_change( $status_sub, 'pending-cancel', 'active' );
rw_assert( rw_bundle_core_status( $status_sub ) === 'cancelled', 'Pending cancel ends bundle licences when the period end has passed' );

$hook = $GLOBALS['rw_bundle_actions'][ count( $GLOBALS['rw_bundle_actions'] ) - 1 ];
rw_assert( $hook[0] === 'reactwoo_license_status_synced', 'Status sync still notifies observers' );
rw_assert( isset( $hook[1][1], $hook[1][2] ) && $hook[1][1] === 'active' && $hook[1][2] === 'pending-cancel', 'Observers receive the previous status, then the new status' );

$alone = new RW_Bundle_Sub( 111, 7, 'on-hold' );
$alone->meta['_reactwoo_license_key'] = 'RW-SECRET-standalone';
$alone->meta['_reactwoo_license_id']  = 4242;
ReactWoo_License_Server_API::$syncs   = array();
$handler->handle_subscription_status_change( $alone, 'on-hold', 'active' );
rw_assert(
	ReactWoo_License_Server_API::$syncs && ReactWoo_License_Server_API::$syncs[0]['status'] === 'on-hold' && (int) ReactWoo_License_Server_API::$syncs[0]['id'] === 111,
	'A standalone licence is suspended from the new status, not the previous one'
);
ReactWoo_License_Server_API::$syncs = array();
$handler->handle_subscription_status_change( $alone, 'active', 'on-hold' );
rw_assert(
	ReactWoo_License_Server_API::$syncs && ReactWoo_License_Server_API::$syncs[0]['status'] === 'active',
	'A standalone licence is reactivated from the new status, not the previous one'
);

$zero_refund = rw_bundle_subscription( 112, 3657, 'standard', 'year', 'zero-refund.example' );
rw_seed_bundle_licence( $zero_refund );
$zero_refund->parent->meta['total']    = 0;
$zero_refund->parent->meta['refunded'] = 0;
$zero_refund->parent->meta['refunds']  = array( array( 'id' => 9 ) );
$zero_refund->status                   = 'cancelled';
ReactWoo_License_Server_API::$updates  = array();
$handler->handle_subscription_status_change( $zero_refund, 'cancelled', 'active' );
rw_assert( rw_bundle_core_status( $zero_refund ) === 'refunded', 'A subscription cancelled for a zero-total refund marks bundle licences refunded' );
rw_assert(
	in_array( array( 501, 'refunded' ), ReactWoo_License_Server_API::$updates, true ),
	'That cancellation sends status refunded to the licence server'
);
$handler->handle_subscription_status_change( $zero_refund, 'active', 'cancelled' );
rw_assert( rw_bundle_core_status( $zero_refund ) === 'refunded', 'Setting the subscription active again does not restore a refunded bundle licence' );

$related = rw_bundle_subscription( 113, 3657, 'standard', 'year', 'related-refund.example' );
rw_seed_bundle_licence( $related );
$related->parent->meta['total'] = 0;
$renewal                        = new RW_Bundle_Order( 8113 );
$renewal->meta['total']         = 0;
$renewal->meta['status']        = 'refunded';
$related->related_orders        = array( $renewal );
$related->status                = 'cancelled';
$handler->handle_subscription_status_change( $related, 'cancelled', 'active' );
rw_assert( rw_bundle_core_status( $related ) === 'refunded', 'A subscription cancelled because a related order was refunded revokes the bundle' );

$plain_cancel = rw_bundle_subscription( 114, 3657, 'standard', 'year', 'plain-cancel.example' );
rw_seed_bundle_licence( $plain_cancel );
$plain_cancel->parent->meta['total'] = 0;
$handler->handle_subscription_status_change( $plain_cancel, 'cancelled', 'active' );
rw_assert( rw_bundle_core_status( $plain_cancel ) === 'cancelled', 'Cancelling without a refund does not mark the bundle refunded' );

$refund_hook = rw_bundle_subscription( 115, 3657, 'standard', 'year', 'hook-refund.example' );
rw_seed_bundle_licence( $refund_hook );
$refund_hook->parent->meta['total']    = 0;
$refund_hook->parent->meta['refunded'] = 0;
$refund_hook->parent->meta['refunds']  = array( array( 'id' => 15 ) );
$GLOBALS['rw_test_orders'][ $refund_hook->parent->get_id() ]                 = $refund_hook->parent;
$GLOBALS['rw_test_order_subscriptions'][ $refund_hook->parent->get_id() ] = array( $refund_hook );
ReactWoo_License_Server_API::$updates = array();
( new ReactWoo_Maintenance_Bundle() )->handle_refund( $refund_hook->parent->get_id(), 15 );
rw_assert( rw_bundle_core_status( $refund_hook ) === 'refunded', 'The refund hook revokes a bundle on a zero-total order that has a refund' );
rw_assert(
	in_array( array( 501, 'refunded' ), ReactWoo_License_Server_API::$updates, true ),
	'The refund hook persists status refunded through the licence status update'
);

$free = rw_bundle_subscription( 116, 3657, 'standard', 'year', 'free.example' );
rw_seed_bundle_licence( $free );
$free->parent->meta['total']    = 0;
$free->parent->meta['refunded'] = 0;
$GLOBALS['rw_test_orders'][ $free->parent->get_id() ]                 = $free->parent;
$GLOBALS['rw_test_order_subscriptions'][ $free->parent->get_id() ] = array( $free );
( new ReactWoo_Maintenance_Bundle() )->handle_refund( $free->parent->get_id(), 16 );
rw_assert( rw_bundle_core_status( $free ) === 'active', 'A zero-total order with no refund leaves the bundle active' );

$api_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-license-server-api.php' );
$status_fn = substr( $api_src, (int) strpos( $api_src, 'function update_license_status' ), 1200 );
rw_assert( false !== strpos( $status_fn, "api/licenses/" ) && false !== strpos( $status_fn, "/status'" ), 'Licence status updates use PUT api/licenses/{id}/status' );
rw_assert( false !== strpos( $status_fn, "'status' => \$status" ), 'The status update body sends the status field' );
