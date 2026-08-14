<?php
/**
 * Sign and deliver subscription lifecycle events to Decision Cloud.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Webhooks {

	/**
	 * @var RWCC_Settings
	 */
	private $settings;

	/**
	 * @var RWCC_Replay
	 */
	private $replay;

	/**
	 * @var callable fn(url, raw_body, headers): array{ok:bool,status?:int,error?:string,body?:string}
	 */
	private $transport;

	/**
	 * @param RWCC_Settings $settings  Settings.
	 * @param RWCC_Replay   $replay    Delivery tracker.
	 * @param callable|null $transport HTTP transport.
	 */
	public function __construct( RWCC_Settings $settings, RWCC_Replay $replay, $transport = null ) {
		$this->settings  = $settings;
		$this->replay    = $replay;
		$this->transport = $transport ? $transport : array( $this, 'wp_transport' );
	}

	/**
	 * @param array  $payload Cloud-shaped payload (includes rwcc).
	 * @param string $topic   X-WC-Webhook-Topic.
	 * @return array
	 */
	public function deliver( array $payload, $topic ) {
		$secret = (string) $this->settings->get( 'webhook_secret' );
		$url    = $this->settings->webhook_url();
		if ( $secret === '' || $url === '' ) {
			return array( 'ok' => false, 'error' => 'webhook_not_configured' );
		}

		$rwcc = isset( $payload['rwcc'] ) && is_array( $payload['rwcc'] ) ? $payload['rwcc'] : array();
		$delivery_id = isset( $rwcc['delivery_id'] ) ? (string) $rwcc['delivery_id'] : '';
		if ( $delivery_id === '' ) {
			return array( 'ok' => false, 'error' => 'delivery_id_missing' );
		}

		$ts_error = RWCC_Replay::timestamp_error(
			isset( $rwcc['timestamp'] ) ? $rwcc['timestamp'] : 0,
			isset( $rwcc['replay_window_sec'] ) ? $rwcc['replay_window_sec'] : $this->settings->replay_window_sec()
		);
		if ( $ts_error !== '' ) {
			return array( 'ok' => false, 'error' => $ts_error, 'delivery_id' => $delivery_id );
		}

		if ( $this->replay->already_delivered( $delivery_id ) ) {
			return array( 'ok' => true, 'duplicate' => true, 'delivery_id' => $delivery_id );
		}

		$raw = RWCC_Payload::encode( $payload );
		$sig = RWCC_Crypto::sign_woocommerce_body( $raw, $secret );
		$headers = array(
			'Content-Type'              => 'application/json',
			'X-WC-Webhook-Signature'    => $sig,
			'X-WC-Webhook-Topic'        => (string) $topic,
			'X-WC-Webhook-Delivery-ID'  => $delivery_id,
		);

		$result = call_user_func( $this->transport, $url, $raw, $headers );
		if ( ! is_array( $result ) ) {
			$result = array( 'ok' => false, 'error' => 'transport_failed' );
		}

		if ( ! empty( $result['ok'] ) ) {
			$this->replay->remember(
				$delivery_id,
				array(
					'topic'     => $topic,
					'timestamp' => isset( $rwcc['timestamp'] ) ? (int) $rwcc['timestamp'] : time(),
					'event'     => isset( $rwcc['event'] ) ? (string) $rwcc['event'] : '',
				)
			);
			if ( function_exists( 'do_action' ) ) {
				do_action( 'rwcc_webhook_delivered', $delivery_id, $topic, $payload );
			}
		} elseif ( function_exists( 'do_action' ) ) {
			do_action( 'rwcc_webhook_failed', $delivery_id, $topic, $result );
		}

		$result['delivery_id'] = $delivery_id;
		$result['signature']   = $sig;
		$result['raw']         = $raw;
		return $result;
	}

	/**
	 * Default WordPress HTTP transport. Credentials never leave the server.
	 *
	 * @param string $url     Target URL.
	 * @param string $raw     Raw JSON.
	 * @param array  $headers Headers.
	 * @return array
	 */
	public function wp_transport( $url, $raw, array $headers ) {
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return array( 'ok' => false, 'error' => 'http_unavailable' );
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => 8,
				'blocking' => true,
				'headers'  => $headers,
				'body'     => $raw,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		return array(
			'ok'     => $status >= 200 && $status < 300,
			'status' => $status,
			'body'   => $body,
			'error'  => $status >= 200 && $status < 300 ? '' : 'http_' . $status,
		);
	}

	/**
	 * Sign a raw body without delivering (tests / local e2e).
	 *
	 * @param string $raw Raw JSON.
	 * @return string
	 */
	public function sign_raw( $raw ) {
		return RWCC_Crypto::sign_woocommerce_body( $raw, (string) $this->settings->get( 'webhook_secret' ) );
	}
}
