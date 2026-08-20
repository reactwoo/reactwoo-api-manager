<?php
/**
 * Decision Cloud product-page copy (PLAN.md §3.0 / §19 step 10).
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Product_Copy {

	public function register() {
		add_action( 'woocommerce_single_product_summary', array( $this, 'render' ), 25 );
	}

	public function render() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( get_the_ID() );
		if ( ! $product ) {
			return;
		}
		$type = RWCC_Plan_Map::normalize_product_type( (string) $product->get_meta( RWCC_Plan_Map::META_PRODUCT_TYPE, true ) );
		if ( $type !== 'decision_cloud' ) {
			$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
			if ( $parent_id ) {
				$parent = wc_get_product( $parent_id );
				$type   = $parent ? RWCC_Plan_Map::normalize_product_type( (string) $parent->get_meta( RWCC_Plan_Map::META_PRODUCT_TYPE, true ) ) : '';
			}
		}
		if ( $type !== 'decision_cloud' ) {
			return;
		}
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes.
	}

	/**
	 * @return string
	 */
	public static function html() {
		$blocks = self::blocks();
		$out    = '<div class="rwcc-cloud-product-copy" style="margin:16px 0;padding:16px;border:1px solid #dcdcde;border-radius:8px;">';
		$out   .= '<h2>' . self::esc( self::text( 'ReactWoo Decision Cloud' ) ) . '</h2>';
		foreach ( $blocks as $block ) {
			$out .= '<h3>' . self::esc( $block['title'] ) . '</h3>';
			$out .= '<p>' . self::esc( $block['body'] ) . '</p>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * @return array<int,array{title:string,body:string}>
	 */
	public static function blocks() {
		return array(
			array(
				'title' => self::text( 'Who it is for' ),
				'body'  => self::text( 'Teams that want one Decision Cloud subscription instead of separate Geo Core Pro, Geo Commerce, and Geo Optimise bills. Starter is for a single site. Growth is the most popular plan. Scale is for larger site and team limits.' ),
			),
			array(
				'title' => self::text( 'What is included' ),
				'body'  => self::text( 'Starter includes Geo Core Pro plus personalisation, components, insights, and recommendations. Growth and Scale also include Geo Commerce and Geo Optimise. Geo Core (free) stays installed either way. Atomic Pro, Reviews, and LinkedIn Core are not included.' ),
			),
			array(
				'title' => self::text( 'Starter does not include Geo Commerce or Geo Optimise' ),
				'body'  => self::text( 'Those products stay separately billed on Starter. Upgrading to Growth or Scale replaces their individual renewals after Cloud activates.' ),
			),
			array(
				'title' => self::text( 'Annual billing' ),
				'body'  => self::text( 'Annual plans are priced with about two months free versus paying monthly.' ),
			),
			array(
				'title' => self::text( 'Upgrade and cancel' ),
				'body'  => self::text( 'If you already pay for covered plugins, remaining-term credit is calculated at checkout. Cancelling Cloud does not automatically restart old plugin bills — you choose which individual plugins to keep, or none.' ),
			),
			array(
				'title' => self::text( 'Cloud does not place content for you' ),
				'body'  => self::text( 'Buying Decision Cloud does not automatically change the live site. Connect a site and choose an Experience Slot. Local configuration is kept.' ),
			),
		);
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private static function text( $text ) {
		return function_exists( '__' ) ? __( $text, 'reactwoo-api-manager' ) : $text;
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc( $text ) {
		return function_exists( 'esc_html' ) ? esc_html( $text ) : htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
