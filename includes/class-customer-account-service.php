<?php
/**
 * Customer account records (read-only presentation layer).
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReactWoo_Customer_Account_Service {

	/**
	 * @var ReactWoo_Customer_Account_Service|null
	 */
	private static $instance = null;

	/**
	 * @return ReactWoo_Customer_Account_Service
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Build presentation-neutral account records for a customer.
	 *
	 * Never returns a full licence key. Never writes.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_account_records( $user_id = 0 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return array();
		}

		$subscriptions = wcs_get_users_subscriptions( $user_id );
		if ( ! is_array( $subscriptions ) || empty( $subscriptions ) ) {
			return array();
		}

		$downloads = array();
		if ( function_exists( 'wc_get_customer_available_downloads' ) ) {
			try {
				$maybe = wc_get_customer_available_downloads( $user_id );
				if ( is_array( $maybe ) ) {
					$downloads = $maybe;
				}
			} catch ( Exception $e ) {
				$downloads = array();
			}
		}

		$records = array();
		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription instanceof WC_Subscription ) {
				continue;
			}
			if ( (int) $subscription->get_customer_id() !== $user_id ) {
				continue;
			}

			try {
				$record = $this->build_record( $subscription, $downloads );
			} catch ( Exception $e ) {
				$record = null;
			}
			if ( $record ) {
				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * Resolve a full key for an owned subscription (REST only).
	 *
	 * Uses local meta first; optional authenticated server recovery if missing.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return string|WP_Error
	 */
	public function get_owned_license_key( $subscription ) {
		if ( ! $subscription instanceof WC_Subscription ) {
			return new WP_Error( 'not_found', __( 'Licence not found.', 'reactwoo-api-manager' ), array( 'status' => 404 ) );
		}

		$key = (string) $subscription->get_meta( '_reactwoo_license_key', true );
		if ( $key !== '' ) {
			return $key;
		}

		$parent = $subscription->get_parent();
		if ( $parent instanceof WC_Order ) {
			$key = (string) $parent->get_meta( '_reactwoo_license_key', true );
			if ( $key !== '' ) {
				return $key;
			}
		}

		$recovered = $this->recover_key_from_server( $subscription );
		if ( is_wp_error( $recovered ) ) {
			return $recovered;
		}
		if ( is_string( $recovered ) && $recovered !== '' ) {
			return $recovered;
		}

		return new WP_Error( 'not_found', __( 'Licence not found.', 'reactwoo-api-manager' ), array( 'status' => 404 ) );
	}

	/**
	 * Mask a licence key for HTML (e.g. RWGC-••••-••••-7F2A).
	 *
	 * @param string $key Full key.
	 * @return string
	 */
	public static function mask_license_key( $key ) {
		$key = (string) $key;
		if ( $key === '' ) {
			return '';
		}

		$parts = explode( '-', $key );
		$count = count( $parts );
		if ( $count < 2 ) {
			$len = strlen( $key );
			if ( $len <= 4 ) {
				return str_repeat( '•', $len );
			}
			return str_repeat( '•', $len - 4 ) . substr( $key, -4 );
		}

		$masked   = array( $parts[0] );
		$last_idx = $count - 1;
		for ( $i = 1; $i < $last_idx; $i++ ) {
			$masked[] = str_repeat( '•', max( 4, min( 4, strlen( $parts[ $i ] ) ) ) );
		}
		$last = $parts[ $last_idx ];
		if ( strlen( $last ) <= 4 ) {
			$masked[] = $last;
		} else {
			$masked[] = str_repeat( '•', strlen( $last ) - 4 ) . substr( $last, -4 );
		}

		return implode( '-', $masked );
	}

	/**
	 * Map WooCommerce Subscription status to account UI status.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @param string          $license_key  Local key if any.
	 * @return string
	 */
	public static function map_status( $subscription, $license_key = '' ) {
		$status = $subscription instanceof WC_Subscription ? $subscription->get_status() : '';

		if ( $license_key === '' && in_array( $status, array( 'active', 'pending', 'on-hold' ), true ) ) {
			return 'pending';
		}

		switch ( $status ) {
			case 'active':
				return 'active';
			case 'pending-cancel':
				return 'expiring';
			case 'on-hold':
			case 'cancelled':
				return 'inactive';
			case 'expired':
				return 'expired';
			case 'pending':
				return 'pending';
			default:
				return $license_key !== '' ? 'inactive' : 'pending';
		}
	}

	/**
	 * @param WC_Subscription          $subscription Subscription.
	 * @param array<int, array<string, mixed>> $downloads Customer downloads.
	 * @return array<string, mixed>|null
	 */
	private function build_record( $subscription, $downloads ) {
		$subscription_id = (int) $subscription->get_id();
		$license_key     = (string) $subscription->get_meta( '_reactwoo_license_key', true );
		$license_id      = absint( $subscription->get_meta( '_reactwoo_license_id', true ) );
		$package_id      = absint( $subscription->get_meta( '_reactwoo_license_package_id', true ) );
		$domain          = (string) $subscription->get_meta( '_reactwoo_license_domain', true );

		$parent = $subscription->get_parent();
		$order_id = $parent instanceof WC_Order ? (int) $parent->get_id() : 0;

		if ( $domain === '' && $parent instanceof WC_Order ) {
			$domain = (string) $parent->get_meta( '_reactwoo_domain', true );
		}

		if ( $license_key === '' && $parent instanceof WC_Order ) {
			$license_key = (string) $parent->get_meta( '_reactwoo_license_key', true );
		}

		$product_id   = 0;
		$product_name = __( 'Subscription', 'reactwoo-api-manager' );
		$product_desc = '';

		foreach ( $subscription->get_items() as $item ) {
			$product = $item->get_product();
			$product_name = $item->get_name();
			if ( $product ) {
				$product_id = (int) $product->get_id();
				if ( ! $package_id ) {
					$package_id = absint( get_post_meta( $product_id, '_reactwoo_license_package_id', true ) );
					if ( ! $package_id && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
						$package_id = absint( get_post_meta( $product->get_parent_id(), '_reactwoo_license_package_id', true ) );
						if ( $package_id ) {
							$product_id = (int) $product->get_parent_id();
						}
					}
				}
				if ( method_exists( $product, 'get_short_description' ) ) {
					$product_desc = wp_strip_all_tags( (string) $product->get_short_description() );
				}
			}
			break;
		}

		$status       = self::map_status( $subscription, $license_key );
		$key_available = $license_key !== '';
		$renewal_ts   = $subscription->get_time( 'next_payment' );
		if ( ! $renewal_ts ) {
			$renewal_ts = $subscription->get_time( 'end' );
		}

		$renewal_date = $renewal_ts ? date_i18n( 'j F Y', $renewal_ts ) : '';
		$order_date   = '';
		$order_url    = '';
		if ( $parent instanceof WC_Order ) {
			$order_date = $parent->get_date_created()
				? $parent->get_date_created()->date_i18n( 'j F Y' )
				: '';
			$order_url = $parent->get_view_order_url();
		}

		$files = $this->match_downloads( $downloads, $product_id, $order_id );

		if ( class_exists( 'ReactWoo_Plugin_Download_Service' ) ) {
			$synthetics = ReactWoo_Plugin_Download_Service::build_synthetic_files( $subscription );
			if ( $synthetics ) {
				$files = array_merge( $synthetics, $files );
			}
		}

		$version = '';
		if ( ! empty( $files[0]['version'] ) ) {
			$version = (string) $files[0]['version'];
		} elseif ( ! empty( $files[0]['name'] ) ) {
			if ( preg_match( '/v?(\d+\.\d+(?:\.\d+)?)/i', (string) $files[0]['name'], $m ) ) {
				$version = $m[1];
			}
		}

		$docs_url = apply_filters(
			'reactwoo_api_manager_documentation_url',
			'https://reactwoo.com/docs/',
			$product_id,
			$subscription_id
		);

		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );

		return array(
			'id'                => 'subscription:' . $subscription_id,
			'subscription_id'   => $subscription_id,
			'license_id'        => $license_id,
			'product_id'        => $product_id,
			'order_id'          => $order_id,
			'product_name'      => $product_name,
			'product_description' => $product_desc,
			'plan_label'        => __( 'Single-domain subscription licence', 'reactwoo-api-manager' ),
			'status'            => $status,
			'masked_key'        => $key_available ? self::mask_license_key( $license_key ) : '',
			'key_available'     => $key_available,
			'can_reveal_key'    => $key_available,
			'registered_domain' => $domain,
			'version'           => $version,
			'renewal_date'      => $renewal_date,
			'renewal_url'       => $subscription->get_change_payment_method_url(),
			'documentation_url' => esc_url_raw( $docs_url ),
			'order_url'         => $order_url,
			'order_date'        => $order_date,
			'browse_url'        => $shop_url,
			'support_url'       => apply_filters( 'reactwoo_api_manager_support_url', 'https://reactwoo.com/support/', $subscription_id ),
			'files'             => $files,
		);
	}

	/**
	 * Match WooCommerce protected downloads to a subscription record.
	 *
	 * @param array<int, array<string, mixed>> $downloads Downloads.
	 * @param int                              $product_id Product ID.
	 * @param int                              $order_id Order ID.
	 * @return array<int, array<string, string>>
	 */
	private function match_downloads( $downloads, $product_id, $order_id ) {
		$matched = array();
		if ( empty( $downloads ) || ! is_array( $downloads ) ) {
			return $matched;
		}

		foreach ( $downloads as $download ) {
			$dl_product = isset( $download['product_id'] ) ? (int) $download['product_id'] : 0;
			$dl_order   = isset( $download['order_id'] ) ? (int) $download['order_id'] : 0;
			$url        = isset( $download['download_url'] ) ? (string) $download['download_url'] : '';

			if ( $url === '' ) {
				continue;
			}

			$matches = false;
			if ( $product_id && $order_id ) {
				$matches = ( $dl_product === $product_id && ( ! $dl_order || $dl_order === $order_id ) );
			} elseif ( $product_id ) {
				$matches = ( $dl_product === $product_id );
			} elseif ( $order_id ) {
				$matches = ( $dl_order === $order_id );
			}

			if ( ! $matches ) {
				continue;
			}

			$matched[] = array(
				'name'      => isset( $download['download_name'] ) ? (string) $download['download_name'] : __( 'Plugin ZIP', 'reactwoo-api-manager' ),
				'url'       => $url,
				'remaining' => isset( $download['downloads_remaining'] ) ? (string) $download['downloads_remaining'] : '',
				'expires'   => isset( $download['access_expires'] ) ? (string) $download['access_expires'] : '',
			);
		}

		return $matched;
	}

	/**
	 * Authenticated recovery only — never uses public domain lookup.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return string|WP_Error|null
	 */
	private function recover_key_from_server( $subscription ) {
		if ( ! ReactWoo_License_Server_API::has_master_key() && ! ReactWoo_API_Manager::get_api_key() ) {
			return null;
		}

		$domain = (string) $subscription->get_meta( '_reactwoo_license_domain', true );
		if ( $domain === '' ) {
			$parent = $subscription->get_parent();
			if ( $parent instanceof WC_Order ) {
				$domain = (string) $parent->get_meta( '_reactwoo_domain', true );
			}
		}

		$package_id = absint( $subscription->get_meta( '_reactwoo_license_package_id', true ) );
		if ( ! $package_id ) {
			foreach ( $subscription->get_items() as $item ) {
				$product = $item->get_product();
				if ( $product ) {
					$package_id = absint( get_post_meta( $product->get_id(), '_reactwoo_license_package_id', true ) );
					if ( ! $package_id && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
						$package_id = absint( get_post_meta( $product->get_parent_id(), '_reactwoo_license_package_id', true ) );
					}
					if ( $package_id ) {
						break;
					}
				}
			}
		}

		if ( $domain === '' || ! $package_id ) {
			return null;
		}

		$api      = new ReactWoo_License_Server_API();
		$licenses = $api->get_all_licenses(
			array(
				'domain'     => $domain,
				'package_id' => $package_id,
			)
		);

		if ( is_wp_error( $licenses ) ) {
			return new WP_Error(
				'license_server_unavailable',
				__( 'Licence server temporarily unavailable.', 'reactwoo-api-manager' ),
				array( 'status' => 503 )
			);
		}

		$subscription_id = (string) $subscription->get_id();
		foreach ( (array) $licenses as $license ) {
			$source_ref = isset( $license['source_ref'] ) ? (string) $license['source_ref'] : '';
			$key        = isset( $license['license_key'] ) ? (string) $license['license_key'] : '';
			if ( $key === '' ) {
				continue;
			}
			if ( $source_ref !== '' && $source_ref === $subscription_id ) {
				return $key;
			}
			$pkg = isset( $license['package_id'] ) ? (int) $license['package_id'] : 0;
			if ( $pkg === $package_id && isset( $license['domain'] ) && strtolower( (string) $license['domain'] ) === strtolower( $domain ) ) {
				return $key;
			}
		}

		return null;
	}
}

/**
 * Public wrapper for account records.
 *
 * @param int $user_id User ID.
 * @return array<int, array<string, mixed>>
 */
function reactwoo_api_manager_get_customer_account_records( $user_id = 0 ) {
	return ReactWoo_Customer_Account_Service::get_instance()
		->get_account_records( $user_id ?: get_current_user_id() );
}
