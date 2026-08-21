<?php
/**
 * Commerce Bridge settings. Secrets stay server-side (options / constants).
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Settings {

	const OPTION_KEY = 'rwcc_settings';

	const PLANS = array( 'starter', 'growth', 'scale' );

	/**
	 * @var array
	 */
	private $values;

	/**
	 * @param array $values Settings overlay.
	 */
	public function __construct( array $values = array() ) {
		$this->values = array_merge( self::defaults(), $values );
	}

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'cloud_origin'        => 'https://decision.reactwoo.com',
			'webhook_url'         => '',
			'webhook_secret'      => '',
			'handoff_secret'      => '',
			'reconcile_token'     => '',
			'claim_ttl_sec'       => 1800,
			'replay_window_sec'   => 300,
			'return_origins'      => "https://decision.reactwoo.com\nhttps://reactwoo.com",
			'product_decision_cloud' => '',
			'product_starter'     => '',
			'product_growth'      => '',
			'product_scale'       => '',
			'product_geocore_pro' => '',
			'product_geo_commerce' => '',
			'product_geo_optimise' => '',
			'activation_path'     => '/activate',
			'allow_http_local'    => false,
		);
	}

	/**
	 * Load from WordPress options with constant overrides.
	 *
	 * @return self
	 */
	public static function from_wordpress() {
		$stored = array();
		if ( function_exists( 'get_option' ) ) {
			$raw = get_option( self::OPTION_KEY, array() );
			if ( is_array( $raw ) ) {
				$stored = $raw;
			}
		}
		$instance = new self( $stored );
		$instance->apply_constants();
		return $instance;
	}

	/**
	 * Constants win so secrets can live in wp-config.php, never in the browser.
	 */
	public function apply_constants() {
		$map = array(
			'RWCC_CLOUD_ORIGIN'     => 'cloud_origin',
			'RWCC_WEBHOOK_URL'      => 'webhook_url',
			'RWCC_WEBHOOK_SECRET'   => 'webhook_secret',
			'RWCC_HANDOFF_SECRET'   => 'handoff_secret',
			'RWCC_RECONCILE_TOKEN'  => 'reconcile_token',
		);
		foreach ( $map as $constant => $key ) {
			if ( defined( $constant ) && (string) constant( $constant ) !== '' ) {
				$this->values[ $key ] = (string) constant( $constant );
			}
		}
	}

	/**
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : '';
	}

	/**
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 */
	public function set( $key, $value ) {
		$this->values[ $key ] = $value;
	}

	/**
	 * @return array
	 */
	public function all() {
		return $this->values;
	}

	/**
	 * Keys that must never be filled from an operator catalogue script.
	 *
	 * @return string[]
	 */
	public static function secret_keys() {
		return array( 'webhook_secret', 'handoff_secret', 'reconcile_token' );
	}

	/**
	 * Product-map keys required for upgrade credit and covered-SKU detection.
	 *
	 * @return string[]
	 */
	public static function catalogue_keys() {
		return array(
			'product_decision_cloud',
			'product_starter',
			'product_growth',
			'product_scale',
			'product_geocore_pro',
			'product_geo_commerce',
			'product_geo_optimise',
		);
	}

	/**
	 * Merge fill values into empty keys only. Never copies secrets. Never overwrites a non-empty setting.
	 *
	 * @param array $current Stored settings.
	 * @param array $fill    Candidate values (product IDs, origin).
	 * @return array
	 */
	public static function merge_empty( array $current, array $fill ) {
		$out     = array_merge( self::defaults(), $current );
		$secrets = self::secret_keys();
		foreach ( $fill as $key => $value ) {
			if ( in_array( $key, $secrets, true ) ) {
				continue;
			}
			if ( $key === 'allow_http_local' ) {
				continue;
			}
			$incoming = is_bool( $value ) ? $value : trim( (string) $value );
			if ( $incoming === '' || $incoming === false ) {
				continue;
			}
			$existing = isset( $out[ $key ] ) ? trim( (string) $out[ $key ] ) : '';
			if ( $existing === '' ) {
				$out[ $key ] = is_bool( $value ) ? $value : (string) $incoming;
			}
		}
		return $out;
	}

	/**
	 * @param array $values Settings.
	 * @return string[] Empty catalogue keys.
	 */
	public static function catalogue_gaps( array $values ) {
		$gaps = array();
		foreach ( self::catalogue_keys() as $key ) {
			if ( trim( (string) ( isset( $values[ $key ] ) ? $values[ $key ] : '' ) ) === '' ) {
				$gaps[] = $key;
			}
		}
		return $gaps;
	}

	/**
	 * Persist non-constant values. Autoload false so secrets are not dumped on every request.
	 *
	 * @param array $values Values to store.
	 */
	public static function save( array $values ) {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}
		$clean = array_merge( self::defaults(), $values );
		unset( $clean['webhook_secret_confirm'], $clean['handoff_secret_confirm'] );
		update_option( self::OPTION_KEY, $clean, false );
	}

	/**
	 * @return string
	 */
	public function cloud_origin() {
		return rtrim( (string) $this->get( 'cloud_origin' ), '/' );
	}

	/**
	 * @return string
	 */
	public function webhook_url() {
		$explicit = trim( (string) $this->get( 'webhook_url' ) );
		if ( $explicit !== '' ) {
			return $explicit;
		}
		$origin = $this->cloud_origin();
		return $origin ? $origin . '/api/v1/billing/webhooks/woocommerce' : '';
	}

	/**
	 * @return int
	 */
	public function claim_ttl_sec() {
		$ttl = (int) $this->get( 'claim_ttl_sec' );
		return $ttl > 0 ? $ttl : 1800;
	}

	/**
	 * @return int
	 */
	public function replay_window_sec() {
		$window = (int) $this->get( 'replay_window_sec' );
		return $window > 0 ? $window : 300;
	}

	/**
	 * Fallback product/variation IDs keyed by internal plan.
	 * Values may be comma-separated (monthly, annual, sandbox replacements).
	 *
	 * @return array<string,string>
	 */
	public function product_map() {
		$map = array();
		foreach ( self::PLANS as $plan ) {
			$id = trim( (string) $this->get( 'product_' . $plan ) );
			if ( $id !== '' ) {
				$map[ $plan ] = $id;
			}
		}
		return $map;
	}

	/**
	 * Allowed return-URL origins (scheme + host).
	 *
	 * @return string[]
	 */
	public function return_origins() {
		$raw   = (string) $this->get( 'return_origins' );
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( $line === '' ) {
				continue;
			}
			$parsed = self::parse_url( $line );
			if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
				continue;
			}
			$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : 'https';
			$out[]  = $scheme . '://' . strtolower( $parsed['host'] );
		}
		$cloud = $this->cloud_origin();
		if ( $cloud !== '' ) {
			$parsed = self::parse_url( $cloud );
			if ( is_array( $parsed ) && ! empty( $parsed['host'] ) ) {
				$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : 'https';
				$out[]  = $scheme . '://' . strtolower( $parsed['host'] );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether HTTP (non-TLS) return URLs are allowed — local only.
	 *
	 * @return bool
	 */
	public function allow_http() {
		if ( $this->get( 'allow_http_local' ) ) {
			return true;
		}
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * @param string $url URL.
	 * @return array|false
	 */
	private static function parse_url( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url );
		}
		return parse_url( $url );
	}
}
