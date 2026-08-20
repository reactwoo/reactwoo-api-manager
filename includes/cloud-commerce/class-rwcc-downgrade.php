<?php
/**
 * Decision Cloud → individual plugin downgrade (PLAN.md §8–§11).
 *
 * End-of-term scheduled downgrade is the default. No individual subscription
 * is created or charged without explicit confirmation. Immediate refunds remain a §20 decision.
 *
 * @package ReactWoo_API_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWCC_Downgrade {

	const META_KEY = '_rwcc_downgrade';

	const STATE_SELECTION_PENDING = 'selection_pending';
	const STATE_SCHEDULED         = 'scheduled';
	const STATE_NONE_SELECTED     = 'none_selected';
	const STATE_COMPLETED         = 'completed';
	const STATE_CANCELLED         = 'cancelled';

	/**
	 * @param string        $plan     Internal plan.
	 * @param RWCC_Settings $settings Settings.
	 * @param callable|null $prices   fn(product_id): string price.
	 * @return array[]
	 */
	public static function options( $plan, RWCC_Settings $settings, $prices = null ) {
		$plan = RWCC_Plan_Map::normalize_plan( $plan );
		$out  = array();
		foreach ( RWCC_Coverage::INDIVIDUAL as $slug => $meta ) {
			$product_id = (string) $settings->get( $meta['setting'] );
			$price      = '0.00';
			if ( is_callable( $prices ) && $product_id !== '' ) {
				$price = self::money( call_user_func( $prices, $product_id ) );
			} elseif ( $product_id !== '' && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( (int) $product_id );
				if ( $product && method_exists( $product, 'get_price' ) ) {
					$price = self::money( $product->get_price() );
				}
			}
			$out[] = array(
				'slug'                => $slug,
				'label'               => $meta['label'],
				'product_id'          => $product_id,
				'price'               => $price,
				'currently_included'  => RWCC_Coverage::sku_covered( $plan, $slug ),
			);
		}
		return $out;
	}

	/**
	 * @param string        $plan          Internal plan.
	 * @param string[]      $selected      Selected slugs (empty + none_selected = free/core).
	 * @param string        $cloud_end_at  ISO-8601 paid-through date.
	 * @param RWCC_Settings $settings      Settings.
	 * @param callable|null $prices        Price reader.
	 * @param bool          $none_selected Explicit "no paid plugins".
	 * @return array
	 */
	public static function quote( $plan, array $selected, $cloud_end_at, RWCC_Settings $settings, $prices = null, $none_selected = false ) {
		$plan     = RWCC_Plan_Map::normalize_plan( $plan );
		$coverage = RWCC_Coverage::plan_coverage( $plan );
		$options  = self::options( $plan, $settings, $prices );
		$wanted   = array();
		foreach ( $selected as $slug ) {
			$canonical = RWCC_Coverage::canonical_slug( $slug );
			if ( $canonical && isset( RWCC_Coverage::INDIVIDUAL[ $canonical ] ) ) {
				$wanted[ $canonical ] = true;
			}
		}
		if ( $none_selected ) {
			$wanted = array();
		}

		$items = array();
		$combined = 0.0;
		foreach ( $options as $option ) {
			if ( empty( $wanted[ $option['slug'] ] ) ) {
				continue;
			}
			$items[]     = $option;
			$combined   += (float) $option['price'];
		}

		if ( $none_selected ) {
			$state = self::STATE_NONE_SELECTED;
		} elseif ( ! $wanted ) {
			$state = self::STATE_SELECTION_PENDING;
		} else {
			$state = self::STATE_SCHEDULED;
		}

		$ending = array();
		foreach ( RWCC_Coverage::covered_skus( $plan ) as $slug ) {
			if ( empty( $wanted[ $slug ] ) ) {
				$label    = RWCC_Coverage::INDIVIDUAL[ $slug ]['label'];
				$ending[] = $label;
			}
		}

		return array(
			'plan'                 => $plan,
			'options'              => $options,
			'selected'             => $items,
			'none_selected'        => $state === self::STATE_NONE_SELECTED,
			'selection_pending'    => $state === self::STATE_SELECTION_PENDING,
			'combined_price'       => self::money( $combined ),
			'effective_at'         => (string) $cloud_end_at,
			'sites_max'            => $coverage ? (int) $coverage['sites_max'] : 0,
			'features_ending'      => $ending,
			'cloud_only_readonly'  => array(
				'Decision Cloud console authoring',
				'Cloud analytics and team seats',
				'Cloud-authored audiences and experiences (read-only / inactive after the paid-through date)',
			),
			'confirm_required'     => true,
			'charges_now'          => false,
			'state'                => $state,
		);
	}

	/**
	 * Persist a confirmed schedule. Does not charge.
	 *
	 * @param array  $quote             Quote from quote().
	 * @param bool   $explicit_confirm  Customer confirmed.
	 * @param string $cloud_subscription_id Cloud subscription id.
	 * @return array
	 */
	public static function confirm( array $quote, $explicit_confirm, $cloud_subscription_id ) {
		if ( empty( $explicit_confirm ) ) {
			return array(
				'ok'    => false,
				'error' => 'confirmation_required',
			);
		}
		if ( ! empty( $quote['selection_pending'] ) ) {
			return array(
				'ok'    => false,
				'error' => 'selection_required',
				'state' => self::STATE_SELECTION_PENDING,
			);
		}

		$cloud_id = (string) $cloud_subscription_id;
		$at       = isset( $quote['effective_at'] ) ? (string) $quote['effective_at'] : '';
		$records  = array();
		$planned  = array();

		if ( ! empty( $quote['none_selected'] ) ) {
			$records[] = RWCC_Transition::schedule_downgrade(
				array(
					'original_subscription_id' => $cloud_id,
					'transition_effective_at'  => $at,
					'covered_product_ids'      => array(),
					'idempotency_key'          => 'downgrade:' . $cloud_id . ':none',
				)
			);
		} else {
			foreach ( $quote['selected'] as $item ) {
				$slug = isset( $item['slug'] ) ? (string) $item['slug'] : '';
				$pid  = isset( $item['product_id'] ) ? (string) $item['product_id'] : '';
				$records[] = RWCC_Transition::schedule_downgrade(
					array(
						'original_subscription_id' => $cloud_id,
						'transition_effective_at'  => $at,
						'covered_product_ids'      => $pid !== '' ? array( $pid ) : array(),
						'idempotency_key'          => 'downgrade:' . $cloud_id . ':' . $slug,
					)
				);
				$planned[] = array(
					'slug'       => $slug,
					'product_id' => $pid,
					'start_date' => $at,
					'status'     => 'pending',
					'charge_now' => false,
					'price'      => isset( $item['price'] ) ? (string) $item['price'] : '0.00',
				);
			}
		}

		$payload = array(
			'state'                => isset( $quote['state'] ) ? (string) $quote['state'] : self::STATE_SCHEDULED,
			'plan'                 => isset( $quote['plan'] ) ? (string) $quote['plan'] : '',
			'effective_at'         => $at,
			'none_selected'        => ! empty( $quote['none_selected'] ),
			'combined_price'       => isset( $quote['combined_price'] ) ? (string) $quote['combined_price'] : '0.00',
			'selected'             => isset( $quote['selected'] ) ? $quote['selected'] : array(),
			'planned_subscriptions'=> $planned,
			'records'              => $records,
			'confirmed_at'         => gmdate( 'c' ),
			'charges_now'          => false,
		);

		return array(
			'ok'      => true,
			'error'   => '',
			'payload' => $payload,
			'planned' => $planned,
		);
	}

	/**
	 * @param object $subscription Cloud subscription.
	 * @param array  $payload      Confirmed payload.
	 * @return array
	 */
	public static function persist( $subscription, array $payload ) {
		if ( is_object( $subscription ) && method_exists( $subscription, 'update_meta_data' ) ) {
			$subscription->update_meta_data( self::META_KEY, $payload );
			if ( method_exists( $subscription, 'save' ) ) {
				$subscription->save();
			}
		}
		return $payload;
	}

	/**
	 * Cloud reactivation must not leave scheduled individuals to charge (state 13).
	 *
	 * @param array|null $existing Existing payload.
	 * @return array
	 */
	public static function cancel_schedule( $existing ) {
		$payload = is_array( $existing ) ? $existing : array();
		if ( empty( $payload['cancelled_planned'] ) && ! empty( $payload['planned_subscriptions'] ) ) {
			$payload['cancelled_planned'] = $payload['planned_subscriptions'];
		}
		$payload['state']       = self::STATE_CANCELLED;
		$payload['cancelled_at'] = gmdate( 'c' );
		$payload['planned_subscriptions'] = array();
		$payload['created_subscription_ids'] = array();
		return $payload;
	}

	/**
	 * @param string $status Woo subscription status.
	 * @return array{headline:string,repair_billing:bool}
	 */
	public static function context_for_status( $status ) {
		$status = strtolower( (string) $status );
		if ( in_array( $status, array( 'on-hold', 'pending' ), true ) ) {
			return array(
				'headline'       => 'Repair Decision Cloud billing, or choose individual plugins as a fallback. Nothing is charged until you confirm.',
				'repair_billing' => true,
			);
		}
		return array(
			'headline'       => 'Choose which individual plugins should start when Decision Cloud ends. Cloud stays active until the paid-through date.',
			'repair_billing' => false,
		);
	}

	/**
	 * My Account form HTML (escaped).
	 *
	 * @param array  $quote           Quote.
	 * @param array  $context         headline, repair_billing.
	 * @param string $action_url      Form action.
	 * @param int    $subscription_id Subscription id.
	 * @return string
	 */
	public static function form_html( array $quote, array $context, $action_url, $subscription_id ) {
		$html  = '<div class="rwcc-downgrade" style="margin:16px 0;padding:16px;border:1px solid #dcdcde;border-radius:8px;">';
		$html .= '<h3>' . self::esc( self::text( 'Cancel or downgrade Decision Cloud' ) ) . '</h3>';
		if ( ! empty( $context['headline'] ) ) {
			$html .= '<p>' . self::esc( (string) $context['headline'] ) . '</p>';
		}
		if ( ! empty( $context['repair_billing'] ) ) {
			$html .= '<p>' . self::esc( self::text( 'Repair billing first if you want to keep Cloud. Fallback plugins are not charged without confirmation.' ) ) . '</p>';
		}
		if ( ! empty( $quote['effective_at'] ) ) {
			$html .= '<p>' . self::esc( sprintf( self::text( 'Cloud remains active until %s. Selected plugins are scheduled to start then.' ), (string) $quote['effective_at'] ) ) . '</p>';
		}
		$html .= '<form method="post" action="' . self::esc_attr( (string) $action_url ) . '">';
		$html .= '<input type="hidden" name="rwcc_downgrade_save" value="1" />';
		$html .= '<input type="hidden" name="subscription_id" value="' . self::esc_attr( (string) $subscription_id ) . '" />';
		if ( function_exists( 'wp_nonce_field' ) ) {
			ob_start();
			wp_nonce_field( 'rwcc_downgrade' );
			$html .= ob_get_clean();
		}
		$html .= '<p><strong>' . self::esc( self::text( 'Individual plugins to keep' ) ) . '</strong></p>';
		foreach ( $quote['options'] as $option ) {
			$included = ! empty( $option['currently_included'] ) ? ' ' . self::text( '(currently included in Cloud)' ) : '';
			$html    .= '<p><label><input type="checkbox" name="rwcc_keep[]" value="' . self::esc_attr( $option['slug'] ) . '" /> ';
			$html    .= self::esc( $option['label'] . ' — ' . $option['price'] . $included );
			$html    .= '</label></p>';
		}
		$html .= '<p><label><input type="checkbox" name="rwcc_keep_none" value="1" /> ' . self::esc( self::text( 'Continue with no paid plugins (Geo Core free only after Cloud ends)' ) ) . '</label></p>';
		$html .= '<p>' . self::esc( self::text( 'Combined selected price (recurring after Cloud ends):' ) ) . ' ' . self::esc( (string) $quote['combined_price'] ) . '</p>';
		if ( ! empty( $quote['cloud_only_readonly'] ) ) {
			$html .= '<p><strong>' . self::esc( self::text( 'Cloud-only resources after the paid-through date' ) ) . '</strong></p><ul>';
			foreach ( $quote['cloud_only_readonly'] as $line ) {
				$html .= '<li>' . self::esc( (string) $line ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '<p><label><input type="checkbox" name="rwcc_downgrade_confirm" value="1" /> ' . self::esc( self::text( 'I confirm these individual subscriptions should start at the Cloud end date, and that nothing is charged until then.' ) ) . '</label></p>';
		$html .= '<p><button type="submit" class="button">' . self::esc( self::text( 'Save downgrade selection' ) ) . '</button></p>';
		$html .= '</form></div>';
		return $html;
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private static function text( $text ) {
		return function_exists( '__' ) ? __( $text, 'reactwoo-api-manager' ) : $text;
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc( $text ) {
		return function_exists( 'esc_html' ) ? esc_html( $text ) : htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc_attr( $text ) {
		return function_exists( 'esc_attr' ) ? esc_attr( $text ) : htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * @param mixed $value Amount.
	 * @return string
	 */
	private static function money( $value ) {
		return number_format( (float) $value, 2, '.', '' );
	}
}
