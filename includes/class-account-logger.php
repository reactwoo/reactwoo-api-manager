<?php
/**
 * Account diagnostics logger.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReactWoo_Account_Logger {

	const OPTION_ENABLED = 'reactwoo_api_manager_account_debug';

	/**
	 * Whether account debug logging is enabled.
	 *
	 * Enabled when WP_DEBUG is on, or when the option is truthy.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( defined( 'REACTWOO_API_MANAGER_ACCOUNT_DEBUG' ) ) {
			return (bool) REACTWOO_API_MANAGER_ACCOUNT_DEBUG;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return true;
		}
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	/**
	 * Write a structured log line.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public static function log( $message, $context = array() ) {
		if ( ! self::enabled() ) {
			return;
		}

		$line = '[ReactWoo API Manager] ' . $message;
		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( $context );
			if ( is_string( $encoded ) ) {
				$line .= ' | ' . $encoded;
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info(
				$message . ( empty( $context ) ? '' : ' ' . wp_json_encode( $context ) ),
				array( 'source' => 'reactwoo-api-manager-account' )
			);
		}
	}

	/**
	 * Snapshot of the current account request for diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	public static function request_context() {
		global $wp;

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$query_vars  = ( isset( $wp->query_vars ) && is_array( $wp->query_vars ) ) ? $wp->query_vars : array();

		return array(
			'uri'            => $request_uri,
			'user_id'        => get_current_user_id(),
			'is_account'     => function_exists( 'is_account_page' ) ? (bool) is_account_page() : null,
			'is_endpoint'    => function_exists( 'is_wc_endpoint_url' ) ? (bool) is_wc_endpoint_url() : null,
			'query_vars'     => $query_vars,
			'rewrites_ready' => function_exists( 'reactwoo_api_manager_rewrites_ready' ) ? reactwoo_api_manager_rewrites_ready() : null,
			'rewrite_ver'    => get_option( 'reactwoo_api_manager_rewrite_version', '' ),
			'plugin_ver'     => defined( 'REACTWOO_API_MANAGER_VERSION' ) ? REACTWOO_API_MANAGER_VERSION : '',
		);
	}
}
