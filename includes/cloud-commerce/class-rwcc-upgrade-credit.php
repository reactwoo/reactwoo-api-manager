<?php
/**
 * Remaining-term upgrade credit (PLAN.md). Calculated on ReactWoo.com, not Decision Cloud.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Upgrade_Credit {

	/**
	 * @param array[] $lines Eligible subscription credit lines.
	 * @param array   $opts  now, cloud_checkout_value, currency.
	 * @return array
	 */
	public static function calculate( array $lines, array $opts = array() ) {
		$now      = isset( $opts['now'] ) ? (int) $opts['now'] : time();
		$cap      = isset( $opts['cloud_checkout_value'] ) ? (float) $opts['cloud_checkout_value'] : 0.0;
		$currency = isset( $opts['currency'] ) ? strtoupper( (string) $opts['currency'] ) : '';
		$audit    = array();
		$gross    = 0.0;

		foreach ( $lines as $line ) {
			$item = self::line_credit( $line, $now, $currency );
			$audit[] = $item;
			if ( ! empty( $item['eligible'] ) ) {
				$gross += (float) $item['line_credit'];
			}
		}

		$applied = $gross;
		if ( $cap > 0 && $applied > $cap ) {
			$applied = $cap;
		}

		return array(
			'gross_credit'   => self::money( $gross ),
			'applied_credit' => self::money( $applied ),
			'capped'         => $cap > 0 && $gross > $cap,
			'cap'            => self::money( $cap ),
			'currency'       => $currency,
			'lines'          => $audit,
		);
	}

	/**
	 * @param array $line Subscription line.
	 * @param int   $now  Unix time.
	 * @param string $currency Expected currency.
	 * @return array
	 */
	public static function line_credit( array $line, $now, $currency ) {
		$reason = self::ineligible_reason( $line, $currency );
		if ( $reason ) {
			return array(
				'eligible'     => false,
				'reason'       => $reason,
				'line_credit'  => '0.00',
				'subscription_id' => isset( $line['id'] ) ? (string) $line['id'] : '',
			);
		}

		$period_start = (int) $line['period_start'];
		$period_end   = (int) $line['period_end'];
		$paid         = (float) $line['amount_paid'];
		$length       = $period_end - $period_start;
		$remaining    = $period_end - $now;
		if ( $length <= 0 || $remaining <= 0 ) {
			return array(
				'eligible'        => false,
				'reason'          => 'no_unused_period',
				'line_credit'     => '0.00',
				'subscription_id' => isset( $line['id'] ) ? (string) $line['id'] : '',
			);
		}

		$fraction = $remaining / $length;
		if ( $fraction > 1 ) {
			$fraction = 1;
		}
		$credit = $paid * $fraction;

		return array(
			'eligible'          => true,
			'reason'            => '',
			'subscription_id'   => isset( $line['id'] ) ? (string) $line['id'] : '',
			'slug'              => isset( $line['slug'] ) ? (string) $line['slug'] : '',
			'unused_fraction'   => round( $fraction, 6 ),
			'amount_paid'       => self::money( $paid ),
			'line_credit'       => self::money( $credit ),
			'currency'          => strtoupper( (string) $line['currency'] ),
			'period_start'      => $period_start,
			'period_end'        => $period_end,
		);
	}

	/**
	 * @param array  $line     Line.
	 * @param string $currency Expected currency.
	 * @return string
	 */
	public static function ineligible_reason( array $line, $currency ) {
		$status = isset( $line['status'] ) ? strtolower( (string) $line['status'] ) : '';
		if ( ! empty( $line['trial'] ) ) {
			return 'trial';
		}
		if ( ! empty( $line['refunded'] ) ) {
			return 'refunded';
		}
		if ( ! empty( $line['superseded'] ) ) {
			return 'already_superseded';
		}
		if ( in_array( $status, array( 'cancelled', 'canceled', 'expired' ), true ) ) {
			return 'not_active';
		}
		if ( empty( $line['covered'] ) ) {
			return 'not_covered_by_plan';
		}
		if ( $currency !== '' && isset( $line['currency'] ) && strtoupper( (string) $line['currency'] ) !== $currency ) {
			return 'currency_mismatch';
		}
		if ( empty( $line['period_start'] ) || empty( $line['period_end'] ) ) {
			return 'missing_period';
		}
		if ( ! isset( $line['amount_paid'] ) ) {
			return 'missing_amount';
		}
		return '';
	}

	/**
	 * @param float $value Amount.
	 * @return string
	 */
	private static function money( $value ) {
		return number_format( (float) $value, 2, '.', '' );
	}
}
