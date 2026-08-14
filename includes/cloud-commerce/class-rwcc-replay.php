<?php
/**
 * Delivery UUID + timestamp replay protection.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Replay {

	/**
	 * @var callable fn(id): bool
	 */
	private $seen;

	/**
	 * @var callable fn(id, meta): void
	 */
	private $record;

	/**
	 * @param callable $seen   Whether a delivery id was already accepted.
	 * @param callable $record Persist a delivery id.
	 */
	public function __construct( $seen, $record ) {
		$this->seen   = $seen;
		$this->record = $record;
	}

	/**
	 * @param string $delivery_id UUID.
	 * @return bool
	 */
	public function already_delivered( $delivery_id ) {
		$delivery_id = (string) $delivery_id;
		if ( $delivery_id === '' ) {
			return false;
		}
		return (bool) call_user_func( $this->seen, $delivery_id );
	}

	/**
	 * @param string $delivery_id UUID.
	 * @param array  $meta        Optional metadata.
	 */
	public function remember( $delivery_id, array $meta = array() ) {
		call_user_func( $this->record, (string) $delivery_id, $meta );
	}

	/**
	 * Validate timestamp against the replay window.
	 *
	 * @param int $timestamp Unix seconds from the payload.
	 * @param int $window    Allowed age in seconds.
	 * @param int $now       Current unix seconds.
	 * @param int $skew      Future-clock skew allowance.
	 * @return string Empty string if ok, otherwise an error code.
	 */
	public static function timestamp_error( $timestamp, $window, $now = null, $skew = 60 ) {
		$timestamp = (int) $timestamp;
		$window    = (int) $window > 0 ? (int) $window : 300;
		$now       = null === $now ? time() : (int) $now;
		$skew      = (int) $skew;
		if ( $timestamp <= 0 ) {
			return 'timestamp_missing';
		}
		if ( $timestamp > $now + $skew ) {
			return 'timestamp_future';
		}
		if ( ( $now - $timestamp ) > $window ) {
			return 'replay_window_exceeded';
		}
		return '';
	}
}
