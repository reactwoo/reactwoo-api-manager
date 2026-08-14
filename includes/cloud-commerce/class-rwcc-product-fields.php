<?php
/**
 * Product / variation mapping UI: Decision Cloud plan.
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
		$current = (string) get_post_meta( $post->ID, RWCC_Plan_Map::META_KEY, true );
		echo '<div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'          => RWCC_Plan_Map::META_KEY,
				'label'       => __( 'Decision Cloud plan', 'reactwoo-api-manager' ),
				'description' => __( 'Maps this product to an internal Cloud plan (starter, growth, scale). Leave blank for standalone plugin licences.', 'reactwoo-api-manager' ),
				'desc_tip'    => true,
				'options'     => $this->options(),
				'value'       => $current,
			)
		);
		echo '</div>';
	}

	/**
	 * @param int $post_id Product id.
	 */
	public function save_simple_field( $post_id ) {
		$plan = isset( $_POST[ RWCC_Plan_Map::META_KEY ] ) ? RWCC_Plan_Map::normalize_plan( wp_unslash( $_POST[ RWCC_Plan_Map::META_KEY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $plan ) {
			update_post_meta( $post_id, RWCC_Plan_Map::META_KEY, $plan );
		} else {
			delete_post_meta( $post_id, RWCC_Plan_Map::META_KEY );
		}
	}

	/**
	 * @param int                  $loop           Loop index.
	 * @param array                $variation_data Variation data.
	 * @param WP_Post              $variation      Variation post.
	 */
	public function render_variation_field( $loop, $variation_data, $variation ) {
		unset( $variation_data );
		$id      = is_object( $variation ) ? (int) $variation->ID : 0;
		$current = $id ? (string) get_post_meta( $id, RWCC_Plan_Map::META_KEY, true ) : '';
		woocommerce_wp_select(
			array(
				'id'            => RWCC_Plan_Map::META_KEY . '_' . $loop,
				'name'          => 'variable_rw_cloud_plan[' . $loop . ']',
				'label'         => __( 'Decision Cloud plan', 'reactwoo-api-manager' ),
				'options'       => $this->options(),
				'value'         => $current,
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
		if ( $plan ) {
			update_post_meta( $variation_id, RWCC_Plan_Map::META_KEY, $plan );
		} else {
			delete_post_meta( $variation_id, RWCC_Plan_Map::META_KEY );
		}
	}

	/**
	 * @return array
	 */
	private function options() {
		return array(
			''        => __( '— Not a Cloud plan —', 'reactwoo-api-manager' ),
			'starter' => __( 'Starter', 'reactwoo-api-manager' ),
			'growth'  => __( 'Growth', 'reactwoo-api-manager' ),
			'scale'   => __( 'Scale', 'reactwoo-api-manager' ),
		);
	}
}
