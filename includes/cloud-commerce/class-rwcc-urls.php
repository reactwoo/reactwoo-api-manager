<?php
/**
 * Allowlisted return URLs. Never accept an arbitrary redirect.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Urls {

	/**
	 * @var string[]
	 */
	private $origins;

	/**
	 * @var bool
	 */
	private $allow_http;

	/**
	 * @param string[] $origins    Allowed scheme://host values.
	 * @param bool     $allow_http Permit http for local development.
	 */
	public function __construct( array $origins, $allow_http = false ) {
		$this->origins    = array_map( 'strtolower', $origins );
		$this->allow_http = (bool) $allow_http;
	}

	/**
	 * @param RWCC_Settings $settings Settings.
	 * @return self
	 */
	public static function from_settings( RWCC_Settings $settings ) {
		return new self( $settings->return_origins(), $settings->allow_http() );
	}

	/**
	 * @param string $url Candidate return URL.
	 * @return bool
	 */
	public function is_allowed( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x1f\x7f]/', $url ) ) {
			return false;
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( $scheme !== 'https' && ! ( $scheme === 'http' && $this->allow_http ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );
		if ( $host === 'localhost' && ! $this->allow_http ) {
			return false;
		}

		$origin = $scheme . '://' . $host;
		if ( in_array( $origin, $this->origins, true ) ) {
			return true;
		}

		// Host-only match against configured origins (scheme already validated).
		foreach ( $this->origins as $allowed ) {
			$allowed_parts = parse_url( $allowed );
			if ( ! is_array( $allowed_parts ) || empty( $allowed_parts['host'] ) ) {
				continue;
			}
			if ( strtolower( $allowed_parts['host'] ) === $host ) {
				$allowed_scheme = isset( $allowed_parts['scheme'] ) ? strtolower( $allowed_parts['scheme'] ) : 'https';
				if ( $scheme === $allowed_scheme || ( $scheme === 'https' && $allowed_scheme === 'https' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param string $url Candidate.
	 * @return string Allowed URL or empty string.
	 */
	public function sanitize( $url ) {
		return $this->is_allowed( $url ) ? (string) $url : '';
	}

	/**
	 * Build a short-lived Cloud activation URL. Return query is omitted unless allowlisted.
	 *
	 * @param string $cloud_origin Cloud origin.
	 * @param string $path         Activation path.
	 * @param string $token        Plaintext claim.
	 * @param string $return_url   Optional return URL.
	 * @return string
	 */
	public function activation_url( $cloud_origin, $path, $token, $return_url = '' ) {
		$origin = rtrim( (string) $cloud_origin, '/' );
		$path   = (string) $path;
		if ( $path === '' ) {
			$path = '/activate';
		}
		if ( $path[0] !== '/' ) {
			$path = '/' . $path;
		}
		$url = $origin . $path;
		$safe = $this->sanitize( $return_url );
		if ( $safe !== '' ) {
			$url .= '?return=' . rawurlencode( $safe );
		}
		$url .= '#claim=' . rawurlencode( (string) $token );
		return $url;
	}
}
