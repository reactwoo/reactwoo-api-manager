<?php
/**
 * License Server API Client
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReactWoo_License_Server_API {

    /**
     * License server base URL
     *
     * @var string
     */
    private $base_url;

    /**
     * Master key for v1 provisioning endpoints (X-RW-Master-Key)
     *
     * @var string|null
     */
    private $master_key;

    /**
     * API key for authentication
     *
     * @var string
     */
    private $api_key;

    /**
     * Cached packages for lookups
     *
     * @var array|null
     */
    private $package_cache = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->base_url = ReactWoo_API_Manager::get_license_server_url();
        $this->api_key = ReactWoo_API_Manager::get_api_key();
        // NOTE: this is the shared secret you configured on the license server (.env RW_MASTER_KEY)
        $this->master_key = 'V3tJYMQovxmDHI3IGnqZdVeBRyzCg91I4YgVyN1X4ZN';
    }

    /**
     * Get all available packages/license types
     *
     * @return array|WP_Error
     */
    public function get_packages() {
        $url = trailingslashit( $this->base_url ) . 'api/packages';
        
        $response = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['success'] ) && $data['success'] && isset( $data['packages'] ) ) {
            $this->package_cache = $data['packages'];
            return $data['packages'];
        }

        return new WP_Error( 'api_error', 'Failed to fetch packages from license server' );
    }

    /**
     * Provision a license using the v1 provisioning endpoint (packages-only model).
     *
     * @param array $args {
     *   @type string $customer_email
     *   @type string $customer_name
     *   @type string $package_slug
     *   @type int    $wc_subscription_id
     *   @type int    $wc_order_id
     *   @type string $status
     *   @type string $domain
     *   @type string $correlation_id
     *   @type float  $price
     *   @type string $currency
     *   @type string $start_date
     *   @type string $billing_period
     *   @type int    $billing_interval
     *   @type string $renewal_frequency
     * }
     * @return array|WP_Error
     */
    public function provision_license_v1( $args ) {
        $url = trailingslashit( $this->base_url ) . 'v1/licenses/provision';

        $body = array(
            'customer_email'     => isset( $args['customer_email'] ) ? $args['customer_email'] : '',
            'customer_name'      => isset( $args['customer_name'] ) ? $args['customer_name'] : '',
            'package_slug'       => isset( $args['package_slug'] ) ? $args['package_slug'] : '',
            'domain'             => isset( $args['domain'] ) ? $args['domain'] : '',
            'wc_subscription_id' => isset( $args['wc_subscription_id'] ) ? (string) $args['wc_subscription_id'] : '',
            'wc_order_id'        => isset( $args['wc_order_id'] ) ? (string) $args['wc_order_id'] : '',
            'status'             => isset( $args['status'] ) ? $args['status'] : 'active',
        );

        // Optional pricing / billing metadata
        if ( isset( $args['price'] ) ) {
            $body['price'] = (float) $args['price'];
        }
        if ( isset( $args['currency'] ) && $args['currency'] ) {
            $body['currency'] = $args['currency'];
        }
        if ( isset( $args['start_date'] ) && $args['start_date'] ) {
            $body['start_date'] = $args['start_date'];
        }
        if ( isset( $args['billing_period'] ) && $args['billing_period'] ) {
            $body['billing_period'] = $args['billing_period'];
        }
        if ( isset( $args['billing_interval'] ) ) {
            $body['billing_interval'] = (int) $args['billing_interval'];
        }
        if ( isset( $args['renewal_frequency'] ) && $args['renewal_frequency'] ) {
            $body['renewal_frequency'] = $args['renewal_frequency'];
        }

        if ( isset( $args['correlation_id'] ) && $args['correlation_id'] ) {
            $body['correlation_id'] = $args['correlation_id'];
        }

        $headers = array(
            'Content-Type'      => 'application/json',
        );
        if ( $this->master_key ) {
            $headers['X-RW-Master-Key'] = $this->master_key;
        }

        $this->log_debug(
            'provision_license_v1: sending request to license server',
            array(
                'url'            => $url,
                'body'           => $body,
                'correlation_id' => isset( $body['correlation_id'] ) ? $body['correlation_id'] : null,
            )
        );

        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 20,
                'headers' => $headers,
                'body'    => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->log_debug(
                'provision_license_v1: HTTP error sending request',
                array(
                    'correlation_id' => isset( $body['correlation_id'] ) ? $body['correlation_id'] : null,
                    'error'          => $response->get_error_message(),
                    'error_data'     => $response->get_error_data(),
                )
            );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        $this->log_debug(
            'provision_license_v1: received response from license server',
            array(
                'correlation_id' => isset( $body['correlation_id'] ) ? $body['correlation_id'] : null,
                'status_code'    => $code,
                'response_body'  => $data,
            )
        );

        if ( in_array( $code, array( 200, 201 ), true ) && isset( $data['license_key'] ) ) {
            return $data;
        }

        $error_message = isset( $data['error'] ) ? $data['error'] : 'Failed to provision license';
        return new WP_Error( 'api_error', $error_message, array( 'status' => $code, 'data' => $data ) );
    }

    /**
     * Sync subscription status to license server v1 endpoint.
     *
     * @param int    $subscription_id
     * @param string $status          Woo status (active|on-hold|cancelled|expired|pending-cancel|pending-cancellation)
     * @param string $current_period_end Optional end date (Y-m-d H:i:s)
     * @return array|WP_Error
     */
    public function sync_subscription_v1( $subscription_id, $status, $current_period_end = null ) {
        $url = trailingslashit( $this->base_url ) . 'v1/licenses/sync-subscription';

        $body = array(
            'wc_subscription_id' => (string) $subscription_id,
            'status'             => $status,
        );
        if ( $current_period_end ) {
            $body['current_period_end'] = $current_period_end;
        }

        $headers = array(
            'Content-Type'      => 'application/json',
        );
        if ( $this->master_key ) {
            $headers['X-RW-Master-Key'] = $this->master_key;
        }

        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 15,
                'headers' => $headers,
                'body'    => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code === 200 && isset( $data['license_status'] ) ) {
            return $data;
        }

        $error_message = isset( $data['error'] ) ? $data['error'] : 'Failed to sync subscription';
        return new WP_Error( 'api_error', $error_message, array( 'status' => $code, 'data' => $data ) );
    }

    /**
     * Get package entry by ID (cached)
     *
     * @param int $package_id Package ID
     * @return array|null|WP_Error
     */
    public function get_package_by_id( $package_id ) {
        if ( ! $package_id ) {
            return null;
        }

        if ( ! is_array( $this->package_cache ) ) {
            $packages = $this->get_packages();
            if ( is_wp_error( $packages ) ) {
                return $packages;
            }
            $this->package_cache = $packages;
        }

        foreach ( $this->package_cache as $package ) {
            if ( isset( $package['id'] ) && intval( $package['id'] ) === intval( $package_id ) ) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Get package type for a given package ID
     *
     * @param int $package_id Package ID
     * @return string|null|WP_Error
     */
    public function get_package_type_by_id( $package_id ) {
        $package = $this->get_package_by_id( $package_id );
        if ( is_wp_error( $package ) ) {
            return $package;
        }
        if ( ! $package ) {
            return null;
        }
        return isset( $package['package_type'] ) ? $package['package_type'] : null;
    }

    /**
     * Find a single license matching domain and package type
     *
     * @param string $domain Domain name
     * @param string $package_type Package type identifier
     * @return array|null|WP_Error
     */
    public function find_license_by_domain_and_package_type( $domain, $package_type ) {
        if ( ! $domain || ! $package_type ) {
            return null;
        }

        $licenses = $this->get_all_licenses( array(
            'domain' => $domain,
            'package_type' => $package_type,
            'status' => 'active',
        ) );

        if ( is_wp_error( $licenses ) ) {
            return $licenses;
        }

        return ! empty( $licenses ) ? $licenses[0] : null;
    }

    /**
     * Create a license key
     *
     * @param string $domain Domain name
     * @param int    $package_id Package ID
     * @param string $status License status (default: 'active')
     * @param string $expires_at Expiration date (optional)
     * @param array  $pricing_data Optional pricing data (price, currency, start_date, billing_period, billing_interval, renewal_frequency)
     * @return array|WP_Error
     */
    public function create_license( $domain, $package_id, $status = 'active', $expires_at = null, $pricing_data = array() ) {
        $url = trailingslashit( $this->base_url ) . 'api/licenses';

        $body = array(
            'domain' => $domain,
            'package_id' => $package_id,
            'status' => $status,
        );

        if ( $expires_at ) {
            $body['expires_at'] = $expires_at;
        }

        // Add pricing information if provided
        if ( ! empty( $pricing_data ) ) {
            if ( isset( $pricing_data['price'] ) ) {
                $body['price'] = floatval( $pricing_data['price'] );
            }
            if ( isset( $pricing_data['currency'] ) ) {
                $body['currency'] = sanitize_text_field( $pricing_data['currency'] );
            }
            if ( isset( $pricing_data['start_date'] ) ) {
                $body['start_date'] = sanitize_text_field( $pricing_data['start_date'] );
            }
            if ( isset( $pricing_data['billing_period'] ) ) {
                $body['billing_period'] = sanitize_text_field( $pricing_data['billing_period'] );
            }
            if ( isset( $pricing_data['billing_interval'] ) ) {
                $body['billing_interval'] = intval( $pricing_data['billing_interval'] );
            }
            if ( isset( $pricing_data['renewal_frequency'] ) ) {
                $body['renewal_frequency'] = sanitize_text_field( $pricing_data['renewal_frequency'] );
            }
        }

        if ( $this->api_key ) {
            $body['api_key'] = $this->api_key;
        }

        $response = wp_remote_post( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        // License server returns:
        // - 201 Created for new licenses
        // - 200 OK for "upgrade" (upsert) of an existing license
        if ( in_array( $response_code, array( 200, 201 ), true ) && isset( $data['success'] ) && $data['success'] && isset( $data['license'] ) ) {
            return $data['license'];
        }

        $error_message = isset( $data['error'] ) ? $data['error'] : 'Failed to create license';
        return new WP_Error( 'api_error', $error_message, array( 'status' => $response_code, 'data' => $data ) );
    }

    /**
     * Update license details via API
     *
     * @param int   $license_id License ID
     * @param array $updates   Fields to update
     * @return array|WP_Error
     */
    public function update_license( $license_id, $updates = array() ) {
        if ( ! $license_id ) {
            return new WP_Error( 'missing_license_id', 'License ID is required for updates' );
        }

        if ( empty( $updates ) ) {
            return new WP_Error( 'missing_update_fields', 'No update data provided' );
        }

        $url = trailingslashit( $this->base_url ) . 'api/licenses/' . intval( $license_id );
        $body = $updates;

        if ( $this->api_key ) {
            $body['api_key'] = $this->api_key;
        }

        $response = wp_remote_request( $url, array(
            'method' => 'PUT',
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code === 200 && isset( $data['success'] ) && $data['success'] && isset( $data['license'] ) ) {
            return $data['license'];
        }

        $error_message = isset( $data['error'] ) ? $data['error'] : 'Failed to update license';
        return new WP_Error( 'api_error', $error_message, array( 'status' => $response_code, 'data' => $data ) );
    }

    /**
     * Update license status
     *
     * @param int    $license_id License ID
     * @param string $status New status
     * @return bool|WP_Error
     */
    public function update_license_status( $license_id, $status ) {
        // Use public API endpoint with API key authentication
        $url = trailingslashit( $this->base_url ) . 'api/licenses/' . intval( $license_id ) . '/status';

        $body = array(
            'status' => $status,
        );

        // Add API key if configured
        if ( $this->api_key ) {
            $body['api_key'] = $this->api_key;
        }

        $response = wp_remote_request( $url, array(
            'method' => 'PUT',
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code === 200 && isset( $data['success'] ) && $data['success'] ) {
            return true;
        }

        $error_message = isset( $data['error'] ) ? $data['error'] : 'Failed to update license status';
        return new WP_Error( 'api_error', $error_message, array( 'status' => $response_code ) );
    }

    /**
     * Get licenses by domain
     *
     * @param string $domain Domain name
     * @return array|WP_Error
     */
    public function get_licenses_by_domain( $domain ) {
        $url = trailingslashit( $this->base_url ) . 'api/licenses/' . urlencode( $domain );

        $response = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code === 200 && isset( $data['success'] ) && $data['success'] && isset( $data['licenses'] ) ) {
            return $data['licenses'];
        }

        if ( $response_code === 404 ) {
            return array(); // No licenses found
        }

        $error_message = isset( $data['error'] ) ? $data['error'] : 'Failed to fetch licenses';
        return new WP_Error( 'api_error', $error_message, array( 'status' => $response_code ) );
    }

    /**
     * Get all licenses (for admin portal)
     *
     * @param array $args Query arguments (status, search, etc.)
     * @return array|WP_Error
     */
    public function get_all_licenses( $args = array() ) {
        $url = trailingslashit( $this->base_url ) . 'api/licenses';
        
        // Add API key and other query parameters
        $query_args = array();
        if ( $this->api_key ) {
            $query_args['api_key'] = $this->api_key;
        }
        if ( isset( $args['status'] ) ) {
            $query_args['status'] = $args['status'];
        }
        if ( isset( $args['search'] ) ) {
            $query_args['search'] = $args['search'];
        }
        if ( isset( $args['domain'] ) ) {
            $query_args['domain'] = $args['domain'];
        }
        if ( isset( $args['package_id'] ) ) {
            $query_args['package_id'] = intval( $args['package_id'] );
        }
        if ( isset( $args['package_type'] ) ) {
            $query_args['package_type'] = $args['package_type'];
        }
        
        if ( ! empty( $query_args ) ) {
            $url = add_query_arg( $query_args, $url );
        }

        $response = wp_remote_get( $url, array(
            'timeout' => 30, // Longer timeout for potentially large datasets
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'ReactWoo API Manager: Error fetching all licenses - ' . $response->get_error_message() . ' | URL: ' . $url );
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        // Log the response for debugging
        if ( $response_code !== 200 ) {
            error_log( 'ReactWoo API Manager: License fetch failed - Status: ' . $response_code . ' | URL: ' . $url . ' | Response: ' . $response_body );
        }

        if ( $response_code === 404 ) {
            // Check if it's a route not found error
            $error_message = isset( $data['error'] ) ? $data['error'] : 'Route not found';
            if ( strpos( strtolower( $error_message ), 'route' ) !== false || strpos( strtolower( $response_body ), 'route' ) !== false ) {
                error_log( 'ReactWoo API Manager: The /api/licenses endpoint may not be deployed on the server. Please ensure the server code has been updated and restarted.' );
            }
            return new WP_Error( 'api_error', 'Route not found. The /api/licenses endpoint may not be available on the server. Please check server configuration.', array( 'status' => $response_code ) );
        }

        if ( $response_code === 200 && isset( $data['success'] ) && $data['success'] && isset( $data['licenses'] ) ) {
            return $data['licenses'];
        }

        if ( $response_code === 401 ) {
            $error_message = isset( $data['error'] ) ? $data['error'] : 'API key is required or invalid';
            return new WP_Error( 'api_auth_error', $error_message, array( 'status' => $response_code ) );
        }

        $error_message = isset( $data['error'] ) ? $data['error'] : 'Failed to fetch licenses';
        return new WP_Error( 'api_error', $error_message, array( 'status' => $response_code ) );
    }

    /**
     * Internal helper for structured debug logging.
     *
     * @param string $message
     * @param array  $context
     */
    private function log_debug( $message, $context = array() ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $line = 'ReactWoo API Manager API: ' . $message;
            if ( ! empty( $context ) ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                $line .= ' | ' . wp_json_encode( $context );
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( $line );
        }
    }
}

