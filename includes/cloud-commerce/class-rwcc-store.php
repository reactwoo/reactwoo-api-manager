<?php
/**
 * In-memory / WordPress option persistence for claims and deliveries.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Store {

	const CLAIMS_OPTION     = 'rwcc_claims';
	const DELIVERIES_OPTION = 'rwcc_deliveries';

	/**
	 * @var array<string,array>
	 */
	private $claims = array();

	/**
	 * @var array<string,array>
	 */
	private $deliveries = array();

	/**
	 * @var bool
	 */
	private $persist;

	/**
	 * @param bool $persist Write through to WP options (blog-scoped, multisite-safe).
	 */
	public function __construct( $persist = false ) {
		$this->persist = (bool) $persist;
		if ( $this->persist && function_exists( 'get_option' ) ) {
			$claims = get_option( self::CLAIMS_OPTION, array() );
			$dels   = get_option( self::DELIVERIES_OPTION, array() );
			$this->claims     = is_array( $claims ) ? $claims : array();
			$this->deliveries = is_array( $dels ) ? $dels : array();
		}
	}

	/**
	 * @param string $hash Claim hash.
	 * @return array|null
	 */
	public function get_claim( $hash ) {
		$hash = (string) $hash;
		return isset( $this->claims[ $hash ] ) ? $this->claims[ $hash ] : null;
	}

	/**
	 * @param string $hash Hash.
	 * @param array  $row  Row.
	 */
	public function put_claim( $hash, array $row ) {
		$this->claims[ (string) $hash ] = $row;
		$this->flush_claims();
	}

	/**
	 * Latest non-revoked claim for a subscription.
	 *
	 * @param int $subscription_id Subscription id.
	 * @return array|null
	 */
	public function claim_for_subscription( $subscription_id ) {
		$subscription_id = (int) $subscription_id;
		$best            = null;
		foreach ( $this->claims as $row ) {
			if ( (int) ( $row['subscription_id'] ?? 0 ) !== $subscription_id ) {
				continue;
			}
			if ( ! empty( $row['revoked_at'] ) ) {
				continue;
			}
			if ( null === $best || (int) $row['created_at'] >= (int) $best['created_at'] ) {
				$best = $row;
			}
		}
		return $best;
	}

	/**
	 * @param string $delivery_id UUID.
	 * @return bool
	 */
	public function has_delivery( $delivery_id ) {
		return isset( $this->deliveries[ (string) $delivery_id ] );
	}

	/**
	 * @param string $delivery_id UUID.
	 * @param array  $meta        Meta.
	 */
	public function put_delivery( $delivery_id, array $meta = array() ) {
		$this->deliveries[ (string) $delivery_id ] = $meta;
		$this->flush_deliveries();
	}

	/**
	 * @return RWCC_Claims
	 */
	public function claims_service( RWCC_Settings $settings ) {
		$store = $this;
		return new RWCC_Claims(
			function ( $hash ) use ( $store ) {
				return $store->get_claim( $hash );
			},
			function ( $hash, $row ) use ( $store ) {
				$store->put_claim( $hash, $row );
			},
			function ( $subscription_id ) use ( $store ) {
				return $store->claim_for_subscription( $subscription_id );
			},
			(string) $settings->get( 'handoff_secret' ) !== '' ? (string) $settings->get( 'handoff_secret' ) : (string) $settings->get( 'webhook_secret' ),
			$settings->claim_ttl_sec()
		);
	}

	/**
	 * @return RWCC_Replay
	 */
	public function replay_service() {
		$store = $this;
		return new RWCC_Replay(
			function ( $id ) use ( $store ) {
				return $store->has_delivery( $id );
			},
			function ( $id, $meta ) use ( $store ) {
				$store->put_delivery( $id, $meta );
			}
		);
	}

	private function flush_claims() {
		if ( $this->persist && function_exists( 'update_option' ) ) {
			update_option( self::CLAIMS_OPTION, $this->claims, false );
		}
	}

	private function flush_deliveries() {
		if ( $this->persist && function_exists( 'update_option' ) ) {
			update_option( self::DELIVERIES_OPTION, $this->deliveries, false );
		}
	}
}
