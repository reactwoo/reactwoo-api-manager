<?php
/**
 * Minimal stubs for offline PHP tests.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) {
		return $text;
	}
}

if ( ! class_exists( 'WC_Subscription', false ) ) {
	class WC_Subscription {
		private $id;
		private $customer_id;
		private $status;
		private $meta = array();

		public function __construct( $id, $customer_id, $status = 'active', $meta = array() ) {
			$this->id          = $id;
			$this->customer_id = $customer_id;
			$this->status      = $status;
			$this->meta        = $meta;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_customer_id() {
			return $this->customer_id;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_meta( $key, $single = true ) {
			return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
		}

		public function get_parent() {
			return null;
		}

		public function get_items() {
			return array();
		}

		public function get_time( $type ) {
			return 0;
		}

		public function get_change_payment_method_url() {
			return '';
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-customer-account-service.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin-download-service.php';
