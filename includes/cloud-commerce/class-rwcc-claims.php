<?php
/**
 * Hashed, single-use, short-lived activation claims.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Claims {

	/**
	 * @var callable fn(hash): ?array
	 */
	private $reader;

	/**
	 * @var callable fn(hash, row): void
	 */
	private $writer;

	/**
	 * @var callable fn(subscription_id): ?array  Active unused claim for a subscription.
	 */
	private $by_subscription;

	/**
	 * @var string
	 */
	private $secret;

	/**
	 * @var int
	 */
	private $ttl;

	/**
	 * @param callable $reader          Load claim row by hash.
	 * @param callable $writer          Persist claim row.
	 * @param callable $by_subscription Find latest unused claim for a subscription.
	 * @param string   $secret          HMAC secret.
	 * @param int      $ttl             Seconds until expiry.
	 */
	public function __construct( $reader, $writer, $by_subscription, $secret, $ttl = 1800 ) {
		$this->reader          = $reader;
		$this->writer          = $writer;
		$this->by_subscription = $by_subscription;
		$this->secret          = (string) $secret;
		$this->ttl             = (int) $ttl > 0 ? (int) $ttl : 1800;
	}

	/**
	 * Issue a claim. Retries reuse the same provisioning id and never mint a second org.
	 *
	 * @param array $context customer_id, order_id, subscription_id, plan, org_id, identity_user, identity_email, provisioning_id, now.
	 * @return array{ok:bool,error?:string,token?:string,hash?:string,expires_at?:int,provisioning_id?:string,already_provisioned?:bool,org_id?:string}
	 */
	public function issue( array $context ) {
		$now = isset( $context['now'] ) ? (int) $context['now'] : time();
		$subscription_id = (int) ( $context['subscription_id'] ?? 0 );
		$customer_id     = (int) ( $context['customer_id'] ?? 0 );
		$order_id        = (int) ( $context['order_id'] ?? 0 );
		$plan            = RWCC_Plan_Map::normalize_plan( $context['plan'] ?? '' );
		$org_id          = isset( $context['org_id'] ) ? (string) $context['org_id'] : '';
		$provisioning_id = isset( $context['provisioning_id'] ) ? (string) $context['provisioning_id'] : '';

		if ( $subscription_id <= 0 || $customer_id <= 0 || $order_id <= 0 || ! $plan ) {
			return array( 'ok' => false, 'error' => 'invalid_claim_context' );
		}
		if ( $this->secret === '' ) {
			return array( 'ok' => false, 'error' => 'claim_secret_missing' );
		}

		if ( ! empty( $context['already_provisioned'] ) ) {
			return array(
				'ok'                   => true,
				'already_provisioned'  => true,
				'org_id'               => $org_id,
				'provisioning_id'      => $provisioning_id,
			);
		}

		$existing = call_user_func( $this->by_subscription, $subscription_id );
		if ( is_array( $existing ) && ! empty( $existing['provisioning_id'] ) ) {
			$provisioning_id = (string) $existing['provisioning_id'];
			if ( empty( $org_id ) && ! empty( $existing['org_id'] ) ) {
				$org_id = (string) $existing['org_id'];
			}
			if ( ! empty( $existing['used_at'] ) && $org_id !== '' ) {
				return array(
					'ok'                  => true,
					'already_provisioned' => true,
					'org_id'              => $org_id,
					'provisioning_id'     => $provisioning_id,
					'hash'                => isset( $existing['hash'] ) ? (string) $existing['hash'] : '',
				);
			}
			if ( ! empty( $existing['hash'] ) && empty( $existing['used_at'] ) ) {
				$existing['revoked_at'] = $now;
				call_user_func( $this->writer, $existing['hash'], $existing );
			}
		}

		if ( $provisioning_id === '' ) {
			$blog_id         = isset( $context['blog_id'] ) ? (int) $context['blog_id'] : 1;
			$provisioning_id = RWCC_Crypto::provisioning_id( $blog_id, $customer_id, $subscription_id );
		}

		$token = RWCC_Crypto::claim_token();
		$hash  = RWCC_Crypto::hash_claim( $token, $this->secret );
		$row   = array(
			'hash'             => $hash,
			'customer_id'      => $customer_id,
			'order_id'         => $order_id,
			'subscription_id'  => $subscription_id,
			'plan'             => $plan,
			'org_id'           => $org_id,
			'identity_user'    => (int) ( $context['identity_user'] ?? 0 ),
			'identity_email'   => (string) ( $context['identity_email'] ?? '' ),
			'provisioning_id'  => $provisioning_id,
			'created_at'       => $now,
			'expires_at'       => $now + $this->ttl,
			'used_at'          => 0,
			'revoked_at'       => 0,
			'blog_id'          => isset( $context['blog_id'] ) ? (int) $context['blog_id'] : 1,
		);
		call_user_func( $this->writer, $hash, $row );

		return array(
			'ok'              => true,
			'token'           => $token,
			'hash'            => $hash,
			'expires_at'      => $row['expires_at'],
			'provisioning_id' => $provisioning_id,
			'org_id'          => $org_id,
			'already_provisioned' => false,
		);
	}

	/**
	 * Inspect a plaintext token without consuming it.
	 *
	 * @param string $token Plaintext claim.
	 * @param int    $now   Unix time.
	 * @return array{ok:bool,error?:string,row?:array}
	 */
	public function inspect( $token, $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$hash = RWCC_Crypto::hash_claim( (string) $token, $this->secret );
		$row  = call_user_func( $this->reader, $hash );
		if ( ! is_array( $row ) ) {
			return array( 'ok' => false, 'error' => 'claim_not_found' );
		}
		if ( ! empty( $row['revoked_at'] ) ) {
			return array( 'ok' => false, 'error' => 'claim_revoked' );
		}
		if ( ! empty( $row['used_at'] ) ) {
			return array( 'ok' => false, 'error' => 'claim_used', 'row' => $row );
		}
		if ( (int) $row['expires_at'] < $now ) {
			return array( 'ok' => false, 'error' => 'claim_expired', 'row' => $row );
		}
		return array( 'ok' => true, 'row' => $row );
	}

	/**
	 * Consume a valid claim (single use).
	 *
	 * @param string $token Plaintext claim.
	 * @param int    $now   Unix time.
	 * @return array{ok:bool,error?:string,row?:array}
	 */
	public function consume( $token, $now = null ) {
		$now      = null === $now ? time() : (int) $now;
		$inspected = $this->inspect( $token, $now );
		if ( empty( $inspected['ok'] ) ) {
			return $inspected;
		}
		$row            = $inspected['row'];
		$row['used_at'] = $now;
		call_user_func( $this->writer, $row['hash'], $row );
		return array( 'ok' => true, 'row' => $row );
	}
}
