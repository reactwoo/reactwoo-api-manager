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
     * API key for authentication
     *
     * @var string
     */
    private $api_key;

    /**
     * Constructor
     */
    public function __construct() {
        $this->base_url = ReactWoo_API_Manager::get_license_server_url();
        $this->api_key = ReactWoo_API_Manager::get_api_key();
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
            return $data['packages'];
        }

        return new WP_Error( 'api_error', 'Failed to fetch packages from license server' );
    }

    /**
     * Create a license key
     *
     * @param string $domain Domain name
     * @param int    $package_id Package ID
     * @param string $status License status (default: 'active')
     * @param string $expires_at Expiration date (optional)
     * @return array|WP_Error
     */
    public function create_license( $domain, $package_id, $status = 'active', $expires_at = null ) {
        $url = trailingslashit( $this->base_url ) . 'api/licenses';

        $body = array(
            'domain' => $domain,
            'package_id' => $package_id,
            'status' => $status,
        );

        if ( $expires_at ) {
            $body['expires_at'] = $expires_at;
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

        if ( $response_code === 201 && isset( $data['success'] ) && $data['success'] ) {
            return $data['license'];
        }

        $error_message = isset( $data['error'] ) ? $data['error'] : 'Failed to create license';
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
        // Note: This would require authentication on the license server
        // For now, we'll use the admin endpoint which requires proper auth setup
        // This is a placeholder - actual implementation depends on your auth setup
        return new WP_Error( 'not_implemented', 'Admin endpoint requires authentication setup' );
    }
}

