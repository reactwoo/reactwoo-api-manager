<?php
/**
 * Product / variation mapping UI: Decision Cloud plan, billing cycle, type.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Product_Fields {

	public function register() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_simple_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_simple_field' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_field' ), 10, 2 );
	}

	public function render_simple_field() {
		global $post;
		if ( ! $post ) {
			return;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product || ! ( $product->is_type( 'subscription' ) || $product->is_type( 'variable-subscription' ) ) ) {
			return;
		}
		echo '<div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'          => RWCC_Plan_Map::META_PRODUCT_TYPE,
				'label'       => __( 'ReactWoo product type', 'reactwoo-api-manager' ),
				'description' => __( 'Mark the Decision Cloud parent product. Do not put a plan on the parent — set starter/growth/scale on each variation.', 'reactwoo-api-manager' ),
				'desc_tip'    => true,
				'options'     => $this->type_options(),
				'value'       => (string) get_post_meta( $post->ID, RWCC_Plan_Map::META_PRODUCT_TYPE, true ),
			)
		);
		if ( $product->is_type( 'subscription' ) ) {
			woocommerce_wp_select(
				array(
					'id'          => RWCC_Plan_Map::META_KEY,
					'label'       => __( 'Decision Cloud plan', 'reactwoo-api-manager' ),
					'description' => __( 'Maps this product to an internal Cloud plan. Leave blank for standalone plugin licences.', 'reactwoo-api-manager' ),
					'desc_tip'    => true,
					'options'     => $this->plan_options(),
					'value'       => (string) get_post_meta( $post->ID, RWCC_Plan_Map::META_KEY, true ),
				)
			);
			woocommerce_wp_select(
				array(
					'id'          => RWCC_Plan_Map::META_BILLING_CYCLE,
					'label'       => __( 'Decision Cloud billing cycle', 'reactwoo-api-manager' ),
					'options'     => $this->cycle_options(),
					'value'       => (string) get_post_meta( $post->ID, RWCC_Plan_Map::META_BILLING_CYCLE, true ),
				)
			);
		}
		echo '</div>';
	}

	/**
	 * @param int $post_id Product id.
	 */
	public function save_simple_field( $post_id ) {
		$this->save_meta(
			$post_id,
			RWCC_Plan_Map::META_PRODUCT_TYPE,
			isset( $_POST[ RWCC_Plan_Map::META_PRODUCT_TYPE ] ) ? RWCC_Plan_Map::normalize_product_type( wp_unslash( $_POST[ RWCC_Plan_Map::META_PRODUCT_TYPE ] ) ) : '' // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
		$this->save_meta(
			$post_id,
			RWCC_Plan_Map::META_KEY,
			isset( $_POST[ RWCC_Plan_Map::META_KEY ] ) ? RWCC_Plan_Map::normalize_plan( wp_unslash( $_POST[ RWCC_Plan_Map::META_KEY ] ) ) : '' // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
		$this->save_meta(
			$post_id,
			RWCC_Plan_Map::META_BILLING_CYCLE,
			isset( $_POST[ RWCC_Plan_Map::META_BILLING_CYCLE ] ) ? RWCC_Plan_Map::normalize_billing_cycle( wp_unslash( $_POST[ RWCC_Plan_Map::META_BILLING_CYCLE ] ) ) : '' // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
	}

	/**
	 * @param int     $loop           Loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public function render_variation_field( $loop, $variation_data, $variation ) {
		unset( $variation_data );
		$id = is_object( $variation ) ? (int) $variation->ID : 0;
		woocommerce_wp_select(
			array(
				'id'            => RWCC_Plan_Map::META_KEY . '_' . $loop,
				'name'          => 'variable_rw_cloud_plan[' . $loop . ']',
				'label'         => __( 'Decision Cloud plan', 'reactwoo-api-manager' ),
				'options'       => $this->plan_options(),
				'value'         => $id ? (string) get_post_meta( $id, RWCC_Plan_Map::META_KEY, true ) : '',
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => RWCC_Plan_Map::META_BILLING_CYCLE . '_' . $loop,
				'name'          => 'variable_rw_cloud_billing_cycle[' . $loop . ']',
				'label'         => __( 'Billing cycle', 'reactwoo-api-manager' ),
				'options'       => $this->cycle_options(),
				'value'         => $id ? (string) get_post_meta( $id, RWCC_Plan_Map::META_BILLING_CYCLE, true ) : '',
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => RWCC_Plan_Map::META_PRODUCT_TYPE . '_' . $loop,
				'name'          => 'variable_rw_cloud_product_type[' . $loop . ']',
				'label'         => __( 'ReactWoo product type', 'reactwoo-api-manager' ),
				'options'       => $this->type_options(),
				'value'         => $id ? (string) get_post_meta( $id, RWCC_Plan_Map::META_PRODUCT_TYPE, true ) : '',
				'wrapper_class' => 'form-row form-row-full',
			)
		);
	}

	/**
	 * @param int $variation_id Variation id.
	 * @param int $loop         Loop index.
	 */
	public function save_variation_field( $variation_id, $loop ) {
		$plan = '';
		if ( isset( $_POST['variable_rw_cloud_plan'][ $loop ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$plan = RWCC_Plan_Map::normalize_plan( wp_unslash( $_POST['variable_rw_cloud_plan'][ $loop ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$cycle = '';
		if ( isset( $_POST['variable_rw_cloud_billing_cycle'][ $loop ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$cycle = RWCC_Plan_Map::normalize_billing_cycle( wp_unslash( $_POST['variable_rw_cloud_billing_cycle'][ $loop ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$type = '';
		if ( isset( $_POST['variable_rw_cloud_product_type'][ $loop ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$type = RWCC_Plan_Map::normalize_product_type( wp_unslash( $_POST['variable_rw_cloud_product_type'][ $loop ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$this->save_meta( $variation_id, RWCC_Plan_Map::META_KEY, $plan );
		$this->save_meta( $variation_id, RWCC_Plan_Map::META_BILLING_CYCLE, $cycle );
		$this->save_meta( $variation_id, RWCC_Plan_Map::META_PRODUCT_TYPE, $type );
	}

	/**
	 * @param int    $post_id Product id.
	 * @param string $key     Meta key.
	 * @param string $value   Value.
	 */
	private function save_meta( $post_id, $key, $value ) {
		if ( $value ) {
			update_post_meta( $post_id, $key, $value );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}

	/**
	 * @return array
	 */
	private function plan_options() {
		return array(
			''        => __( '— Not a Cloud plan —', 'reactwoo-api-manager' ),
			'starter' => __( 'Starter', 'reactwoo-api-manager' ),
			'growth'  => __( 'Growth', 'reactwoo-api-manager' ),
			'scale'   => __( 'Scale', 'reactwoo-api-manager' ),
		);
	}

	/**
	 * @return array
	 */
	private function cycle_options() {
		return array(
			''        => __( '— Not set —', 'reactwoo-api-manager' ),
			'monthly' => __( 'Monthly', 'reactwoo-api-manager' ),
			'annual'  => __( 'Annual', 'reactwoo-api-manager' ),
		);
	}

	/**
	 * @return array
	 */
	private function type_options() {
		return array(
			''               => __( '— Standalone / other —', 'reactwoo-api-manager' ),
			'decision_cloud' => __( 'Decision Cloud', 'reactwoo-api-manager' ),
		);
	}
}
