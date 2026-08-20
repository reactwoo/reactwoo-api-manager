<?php
/**
 * Admin settings for the ReactWoo Commerce Bridge.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Admin {

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
	}

	public function add_menu() {
		$parent = 'reactwoo-license-manager';
		if ( ! function_exists( 'add_submenu_page' ) ) {
			return;
		}
		add_submenu_page(
			$parent,
			__( 'Decision Cloud', 'reactwoo-api-manager' ),
			__( 'Decision Cloud', 'reactwoo-api-manager' ),
			'manage_woocommerce',
			'reactwoo-cloud-commerce',
			array( $this, 'render' )
		);
		add_submenu_page(
			$parent,
			__( 'Cloud overlap', 'reactwoo-api-manager' ),
			__( 'Cloud overlap', 'reactwoo-api-manager' ),
			'manage_woocommerce',
			'reactwoo-cloud-overlap',
			array( $this, 'render_overlap' )
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = RWCC_Settings::from_wordpress();
		$notice   = '';

		if ( isset( $_POST['rwcc_save'] ) && check_admin_referer( 'rwcc_settings' ) ) {
			$current = $settings->all();
			$current['cloud_origin']      = isset( $_POST['cloud_origin'] ) ? esc_url_raw( wp_unslash( $_POST['cloud_origin'] ) ) : '';
			$current['webhook_url']       = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
			$current['activation_path']   = isset( $_POST['activation_path'] ) ? sanitize_text_field( wp_unslash( $_POST['activation_path'] ) ) : '/activate';
			$current['claim_ttl_sec']     = isset( $_POST['claim_ttl_sec'] ) ? absint( $_POST['claim_ttl_sec'] ) : 1800;
			$current['replay_window_sec'] = isset( $_POST['replay_window_sec'] ) ? absint( $_POST['replay_window_sec'] ) : 300;
			$current['return_origins']    = isset( $_POST['return_origins'] ) ? sanitize_textarea_field( wp_unslash( $_POST['return_origins'] ) ) : '';
			$current['product_decision_cloud'] = isset( $_POST['product_decision_cloud'] ) ? sanitize_text_field( wp_unslash( $_POST['product_decision_cloud'] ) ) : '';
			$current['product_starter']        = isset( $_POST['product_starter'] ) ? sanitize_text_field( wp_unslash( $_POST['product_starter'] ) ) : '';
			$current['product_growth']         = isset( $_POST['product_growth'] ) ? sanitize_text_field( wp_unslash( $_POST['product_growth'] ) ) : '';
			$current['product_scale']          = isset( $_POST['product_scale'] ) ? sanitize_text_field( wp_unslash( $_POST['product_scale'] ) ) : '';
			$current['product_geocore_pro']  = isset( $_POST['product_geocore_pro'] ) ? sanitize_text_field( wp_unslash( $_POST['product_geocore_pro'] ) ) : '';
			$current['product_geo_commerce'] = isset( $_POST['product_geo_commerce'] ) ? sanitize_text_field( wp_unslash( $_POST['product_geo_commerce'] ) ) : '';
			$current['product_geo_optimise'] = isset( $_POST['product_geo_optimise'] ) ? sanitize_text_field( wp_unslash( $_POST['product_geo_optimise'] ) ) : '';
			$current['allow_http_local']  = ! empty( $_POST['allow_http_local'] );

			foreach ( array( 'webhook_secret', 'handoff_secret', 'reconcile_token' ) as $secret_key ) {
				if ( isset( $_POST[ $secret_key ] ) && (string) wp_unslash( $_POST[ $secret_key ] ) !== '' ) {
					$current[ $secret_key ] = sanitize_text_field( wp_unslash( $_POST[ $secret_key ] ) );
				}
			}

			RWCC_Settings::save( $current );
			$settings = RWCC_Settings::from_wordpress();
			$notice   = __( 'Decision Cloud commerce settings saved.', 'reactwoo-api-manager' );
		}

		$values = $settings->all();
		require REACTWOO_API_MANAGER_PLUGIN_DIR . 'admin/views/cloud-commerce.php';
	}

	public function render_overlap() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings = RWCC_Settings::from_wordpress();
		$plans    = new RWCC_Plan_Map( $settings->product_map() );
		$notice   = '';
		$result   = null;
		$detected = null;
		$cloud    = null;
		$subs     = array();

		$subscription_id = 0;
		if ( isset( $_POST['rwcc_overlap_id'] ) ) {
			$subscription_id = absint( wp_unslash( $_POST['rwcc_overlap_id'] ) );
		} elseif ( isset( $_GET['subscription_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$subscription_id = absint( wp_unslash( $_GET['subscription_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( $subscription_id && function_exists( 'wcs_get_subscription' ) ) {
			$cloud = wcs_get_subscription( $subscription_id );
		}
		if ( $cloud && method_exists( $cloud, 'get_customer_id' ) && function_exists( 'wcs_get_users_subscriptions' ) ) {
			$subs = array_values( (array) wcs_get_users_subscriptions( (int) $cloud->get_customer_id() ) );
			$rows = array();
			foreach ( $subs as $sub ) {
				$rows[] = RWCC_Supersession::row_from_subscription( $sub );
			}
			$cloud_row          = RWCC_Supersession::row_from_subscription( $cloud );
			$cloud_row['plan']  = RWCC_Plan_Map::normalize_plan( RWCC_Order_Meta::get( $cloud, RWCC_Order_Meta::META_PLAN ) );
			$cloud_row['renewing'] = true;
			$detected           = RWCC_Overlap::detect( $cloud_row, $rows, $settings, $plans );
		}

		if ( isset( $_POST['rwcc_overlap_correct'] ) && check_admin_referer( 'rwcc_overlap' ) ) {
			$confirm = ! empty( $_POST['rwcc_overlap_confirm'] );
			$user    = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
			$actor   = $user && isset( $user->user_login ) ? (string) $user->user_login : 'admin';
			$result  = RWCC_Overlap::correct( $cloud, $subs, $settings, $plans, $confirm, $actor );
			$notice  = ! empty( $result['ok'] )
				? __( 'Overlapping individual renewals were stopped. History was kept.', 'reactwoo-api-manager' )
				: __( 'Correction requires an explicit confirm checkbox.', 'reactwoo-api-manager' );
			if ( $cloud && $subs ) {
				$rows = array();
				foreach ( $subs as $sub ) {
					$rows[] = RWCC_Supersession::row_from_subscription( $sub );
				}
				$cloud_row          = RWCC_Supersession::row_from_subscription( $cloud );
				$cloud_row['plan']  = RWCC_Plan_Map::normalize_plan( RWCC_Order_Meta::get( $cloud, RWCC_Order_Meta::META_PLAN ) );
				$cloud_row['renewing'] = true;
				$detected           = RWCC_Overlap::detect( $cloud_row, $rows, $settings, $plans );
			}
		}

		require REACTWOO_API_MANAGER_PLUGIN_DIR . 'includes/cloud-commerce/views/overlap.php';
	}
}
