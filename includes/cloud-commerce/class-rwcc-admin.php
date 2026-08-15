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
			$current['product_starter']   = isset( $_POST['product_starter'] ) ? sanitize_text_field( wp_unslash( $_POST['product_starter'] ) ) : '';
			$current['product_growth']    = isset( $_POST['product_growth'] ) ? sanitize_text_field( wp_unslash( $_POST['product_growth'] ) ) : '';
			$current['product_scale']     = isset( $_POST['product_scale'] ) ? sanitize_text_field( wp_unslash( $_POST['product_scale'] ) ) : '';
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
}
