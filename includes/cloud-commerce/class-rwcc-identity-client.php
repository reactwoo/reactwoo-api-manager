<?php
/**
 * Server-to-server identity claim registration with Decision Cloud.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Identity_Client {

	/**
	 * @var RWCC_Settings
	 */
	private $settings;

	/**
	 * @var callable fn(url, raw, headers): array{ok:bool,status?:int,error?:string,body?:string}
	 */
	private $transport;

	/**
	 * @param RWCC_Settings $settings  Settings.
	 * @param callable|null $transport HTTP transport.
	 */
	public function __construct( RWCC_Settings $settings, $transport = null ) {
		$this->settings  = $settings;
		$this->transport = $transport ? $transport : array( $this, 'wp_transport' );
	}

	/**
	 * Register a hashed claim. Never sends the raw token.
	 *
	 * @param array $body Signed registration body.
	 * @return array
	 */
	public function register_claim( array $body ) {
		$origin = rtrim( (string) $this->settings->cloud_origin(), '/' );
		if ( $origin === '' ) {
			return array( 'ok' => false, 'error' => 'cloud_origin_missing' );
		}
		$url  = $origin . '/api/v1/identity/claims';
		$raw  = RWCC_Payload::encode( $body );
		$headers = array( 'Content-Type' => 'application/json' );
		$result  = call_user_func( $this->transport, $url, $raw, $headers );
		if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
			return array(
				'ok'     => false,
				'error'  => isset( $result['error'] ) ? $result['error'] : 'identity_register_failed',
				'status' => isset( $result['status'] ) ? (int) $result['status'] : 0,
			);
		}
		return array( 'ok' => true, 'status' => isset( $result['status'] ) ? (int) $result['status'] : 201 );
	}

	/**
	 * Issue a login claim for the current WordPress user and register it.
	 *
	 * @param int    $user_id WordPress user id.
	 * @param string $email   Account email.
	 * @param string $org_id  Optional organisation id.
	 * @return array{ok:bool,token?:string,url?:string,error?:string}
	 */
	public function issue_login( $user_id, $email = '', $org_id = '' ) {
		$secret = (string) $this->settings->get( 'handoff_secret' );
		if ( $secret === '' ) {
			return array( 'ok' => false, 'error' => 'claim_secret_missing' );
		}
		$subject = RWCC_Identity::subject_for_user( (int) $user_id );
		if ( $subject === '' ) {
			return array( 'ok' => false, 'error' => 'identity_subject_missing' );
		}
		$token = RWCC_Crypto::claim_token();
		$hash  = RWCC_Crypto::hash_claim( $token, $secret );
		$body  = RWCC_Identity::registration_body(
			array(
				'purpose'          => 'login',
				'subject'          => $subject,
				'hash'             => $hash,
				'email'            => $email,
				'organisation_id'  => $org_id,
				'intended_role'    => 'member',
				'customer_id'      => (string) $user_id,
				'secret'           => $secret,
				'ttl'              => (int) $this->settings->get( 'claim_ttl_sec' ),
			)
		);
		$registered = $this->register_claim( $body );
		if ( empty( $registered['ok'] ) ) {
			return $registered;
		}
		$urls = RWCC_Urls::from_settings( $this->settings );
		return array(
			'ok'    => true,
			'token' => $token,
			'url'   => $urls->activation_url( $this->settings->cloud_origin(), '/activate', $token, '' ),
		);
	}

	/**
	 * @param string $url     URL.
	 * @param string $raw     Body.
	 * @param array  $headers Headers.
	 * @return array
	 */
	private function wp_transport( $url, $raw, $headers ) {
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return array( 'ok' => false, 'error' => 'http_unavailable' );
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $raw,
			)
		);
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => $response->get_error_message() );
		}
		$status = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$body   = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		return array(
			'ok'     => $status >= 200 && $status < 300,
			'status' => $status,
			'body'   => $body,
			'error'  => $status >= 300 ? 'http_' . $status : '',
		);
	}
}
