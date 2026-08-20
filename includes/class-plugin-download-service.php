<?php
/**
 * Entitled My Account plugin ZIP downloads via ReactWoo API store-download.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReactWoo_Plugin_Download_Service {

	const QUERY_VAR = 'reactwoo_plugin_download';

	/**
	 * Subscription statuses that may download the plugin ZIP.
	 *
	 * Standalone individuals stay on this list. Cloud plans also allow
	 * on-hold (payment grace) via subscription_can_download(); on-hold is
	 * not added here so failed-payment individuals remain blocked.
	 *
	 * @return array<int, string>
	 */
	public static function entitled_statuses() {
		return array( 'active', 'pending-cancel' );
	}

	/**
	 * Cloud-live statuses including payment grace (PLAN.md handover).
	 *
	 * @return array<int, string>
	 */
	public static function cloud_live_statuses() {
		return array( 'active', 'pending-cancel', 'on-hold' );
	}

	/**
	 * Covered individuals that Cloud superseded must not list ZIPs.
	 *
	 * @param object $subscription Subscription-like object.
	 * @return bool
	 */
	public static function should_hide_downloads( $subscription ) {
		return class_exists( 'RWCC_Supersession' ) && RWCC_Supersession::is_superseded( $subscription );
	}

	/**
	 * Internal plan on a Cloud subscription, if the companion is loaded.
	 *
	 * @param object $subscription Subscription-like object.
	 * @return string
	 */
	public static function cloud_plan( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_meta' ) ) {
			return '';
		}
		if ( ! class_exists( 'RWCC_Order_Meta' ) || ! class_exists( 'RWCC_Plan_Map' ) ) {
			return '';
		}
		return RWCC_Plan_Map::normalize_plan( (string) $subscription->get_meta( RWCC_Order_Meta::META_PLAN, true ) );
	}

	/**
	 * @param object $subscription Subscription-like object.
	 * @return bool
	 */
	public static function subscription_can_download( $subscription ) {
		if ( self::should_hide_downloads( $subscription ) ) {
			return false;
		}
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_status' ) ) {
			return false;
		}
		if ( class_exists( 'WC_Subscription' ) && ! ( $subscription instanceof WC_Subscription ) ) {
			return false;
		}
		$status = strtolower( (string) $subscription->get_status() );
		if ( self::cloud_plan( $subscription ) ) {
			return in_array( $status, self::cloud_live_statuses(), true );
		}
		return in_array( $status, self::entitled_statuses(), true );
	}

	/**
	 * Resolve catalog slug for a product (and parent fallback).
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public static function get_plugin_slug_for_product( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return '';
		}

		$slug = strtolower( trim( (string) get_post_meta( $product_id, '_reactwoo_plugin_slug', true ) ) );
		if ( $slug !== '' && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			return $slug;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( $product && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
			$parent_slug = strtolower( trim( (string) get_post_meta( $product->get_parent_id(), '_reactwoo_plugin_slug', true ) ) );
			if ( $parent_slug !== '' && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $parent_slug ) ) {
				return $parent_slug;
			}
		}

		$package_id = absint( get_post_meta( $product_id, '_reactwoo_license_package_id', true ) );
		if ( ! $package_id && $product && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
			$package_id = absint( get_post_meta( $product->get_parent_id(), '_reactwoo_license_package_id', true ) );
		}
		if ( ! $package_id ) {
			return '';
		}

		$api      = new ReactWoo_License_Server_API();
		$packages = $api->get_packages();
		if ( is_wp_error( $packages ) || ! is_array( $packages ) ) {
			return '';
		}
		foreach ( $packages as $package ) {
			if ( ! isset( $package['id'] ) || (int) $package['id'] !== $package_id ) {
				continue;
			}
			$candidate = '';
			if ( ! empty( $package['slug'] ) ) {
				$candidate = strtolower( trim( (string) $package['slug'] ) );
			} elseif ( ! empty( $package['package_type'] ) ) {
				$candidate = strtolower( trim( (string) $package['package_type'] ) );
			}
			if ( $candidate !== '' && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * @param WC_Subscription $subscription Subscription.
	 * @return string
	 */
	public static function get_plugin_slug_for_subscription( $subscription ) {
		if ( ! $subscription instanceof WC_Subscription ) {
			return '';
		}
		foreach ( $subscription->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$slug = self::get_plugin_slug_for_product( (int) $product->get_id() );
			if ( $slug !== '' ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Entitled plugin slugs for a subscription.
	 * Cloud plans expand to covered SKUs; standalone stays a single product slug.
	 *
	 * @param object $subscription Subscription-like object.
	 * @return string[]
	 */
	public static function entitled_plugin_slugs( $subscription ) {
		if ( self::should_hide_downloads( $subscription ) ) {
			$plan = self::cloud_plan( $subscription );
			if ( $plan && class_exists( 'RWCC_Entitlement_Handover' ) ) {
				$handover = RWCC_Entitlement_Handover::downloads( $plan, false, true );
				return isset( $handover['slugs'] ) && is_array( $handover['slugs'] ) ? $handover['slugs'] : array();
			}
			return array();
		}
		$plan = self::cloud_plan( $subscription );
		if ( $plan && class_exists( 'RWCC_Entitlement_Handover' ) ) {
			$live     = is_object( $subscription ) && method_exists( $subscription, 'get_status' )
				&& in_array( strtolower( (string) $subscription->get_status() ), self::cloud_live_statuses(), true );
			$handover = RWCC_Entitlement_Handover::downloads( $plan, $live, false );
			return isset( $handover['slugs'] ) && is_array( $handover['slugs'] ) ? $handover['slugs'] : array();
		}
		if ( $plan && class_exists( 'RWCC_Coverage' ) ) {
			return RWCC_Coverage::covered_skus( $plan );
		}
		$slug = self::get_plugin_slug_for_subscription( $subscription );
		return $slug !== '' ? array( $slug ) : array();
	}

	/**
	 * Build nonce-protected proxy URL for My Account.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $slug            Optional plugin slug for Cloud bundles.
	 * @return string
	 */
	public static function get_proxy_url( $subscription_id, $slug = '' ) {
		$subscription_id = absint( $subscription_id );
		if ( ! $subscription_id ) {
			return '';
		}
		$slug = strtolower( trim( (string) $slug ) );
		$args = array(
			self::QUERY_VAR => $subscription_id,
			'_wpnonce'      => wp_create_nonce( self::download_nonce_action( $subscription_id, $slug ) ),
		);
		if ( $slug !== '' ) {
			$args['plugin'] = $slug;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * @param int    $subscription_id Subscription ID.
	 * @param string $slug            Optional plugin slug.
	 * @return string
	 */
	public static function download_nonce_action( $subscription_id, $slug = '' ) {
		$subscription_id = absint( $subscription_id );
		$slug            = strtolower( trim( (string) $slug ) );
		return $slug !== ''
			? self::QUERY_VAR . '_' . $subscription_id . '_' . $slug
			: self::QUERY_VAR . '_' . $subscription_id;
	}

	/**
	 * @return string
	 */
	public static function get_updates_api_base() {
		$base = (string) get_option( 'reactwoo_updates_api_url', 'https://api.reactwoo.com' );
		$base = untrailingslashit( esc_url_raw( $base ) );
		return $base !== '' ? $base : 'https://api.reactwoo.com';
	}

	/**
	 * @return string
	 */
	public static function get_store_download_token() {
		return trim( (string) get_option( 'reactwoo_updates_store_download_token', '' ) );
	}

	/**
	 * Fetch signed download metadata from the ReactWoo API (server-side only).
	 *
	 * @param string $slug Plugin catalog slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function request_store_download( $slug ) {
		$slug  = strtolower( trim( (string) $slug ) );
		$token = self::get_store_download_token();
		if ( $slug === '' || $token === '' ) {
			return new WP_Error( 'not_configured', __( 'Plugin downloads are not configured.', 'reactwoo-api-manager' ), array( 'status' => 503 ) );
		}

		$url = self::get_updates_api_base() . '/api/v5/updates/store-download';
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'slug' => $slug,
						'key'  => 'latest',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 || ! is_array( $body ) || empty( $body['download_url'] ) ) {
			$message = is_array( $body ) && ! empty( $body['error'] )
				? (string) $body['error']
				: __( 'Download unavailable.', 'reactwoo-api-manager' );
			return new WP_Error( 'download_failed', $message, array( 'status' => $code ? $code : 502 ) );
		}

		if ( ! empty( $body['version'] ) ) {
			self::remember_version( $slug, (string) $body['version'] );
		}

		return $body;
	}

	/**
	 * @param string $slug Plugin slug.
	 * @param string $version Version.
	 */
	public static function remember_version( $slug, $version ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( $slug === '' ) {
			return;
		}
		set_transient( 'rw_plugin_ver_' . md5( $slug ), (string) $version, HOUR_IN_SECONDS );
	}

	/**
	 * Cached latest version label for UI (populated after a successful download mint).
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	public static function get_cached_version( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( $slug === '' ) {
			return '';
		}
		$cached = get_transient( 'rw_plugin_ver_' . md5( $slug ) );
		return is_string( $cached ) ? $cached : '';
	}

	/**
	 * Synthetic file rows for an entitled subscription.
	 * Cloud plans emit one row per included plugin; standalone remains a single ZIP.
	 *
	 * @param object $subscription Subscription.
	 * @return array<int,array<string,string>>
	 */
	public static function build_synthetic_files( $subscription ) {
		if ( self::should_hide_downloads( $subscription ) ) {
			return array();
		}
		if ( ! self::subscription_can_download( $subscription ) ) {
			return array();
		}
		if ( self::get_store_download_token() === '' ) {
			return array();
		}

		$plan = self::cloud_plan( $subscription );

		$rows = array();
		if ( $plan && class_exists( 'RWCC_Coverage' ) ) {
			$rows = RWCC_Coverage::download_rows( $plan );
		} else {
			$slug = self::get_plugin_slug_for_subscription( $subscription );
			if ( $slug !== '' ) {
				$version = self::get_cached_version( $slug );
				$rows[]  = array(
					'slug'   => $slug,
					'label'  => $slug,
					'name'   => $version
						? sprintf( /* translators: %s: version */ __( 'Plugin ZIP v%s', 'reactwoo-api-manager' ), $version )
						: __( 'Plugin ZIP', 'reactwoo-api-manager' ),
					'source' => 'standalone',
				);
			}
		}

		$files = array();
		foreach ( $rows as $row ) {
			$slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';
			$url  = self::get_proxy_url( (int) $subscription->get_id(), $plan ? $slug : '' );
			if ( $slug === '' || $url === '' ) {
				continue;
			}
			$version = self::get_cached_version( $slug );
			$name    = isset( $row['name'] ) ? (string) $row['name'] : $slug;
			$files[] = array(
				'name'      => $name,
				'url'       => $url,
				'remaining' => '',
				'expires'   => '',
				'slug'      => $slug,
				'source'    => isset( $row['source'] ) ? (string) $row['source'] : 'reactwoo_store_download',
				'version'   => $version,
			);
		}

		return $files;
	}

	/**
	 * Synthetic file row for an entitled subscription (first entitled plugin).
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return array<string, string>|null
	 */
	public static function build_synthetic_file( $subscription ) {
		$files = self::build_synthetic_files( $subscription );
		return $files ? $files[0] : null;
	}
}
