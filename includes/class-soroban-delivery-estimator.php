<?php
/**
 * Shared estimator service (audit item D).
 *
 * One code path resolves the effective calculation inputs (store
 * settings passed through the Pro add-on's filters) and runs the
 * calculator. It is used by:
 *  - the front end (via the REST product endpoint feeding the
 *    browser engine),
 *  - the admin simulator (so what the administrator tests is exactly
 *    what the storefront computes),
 *  - the daily health check (fail-safe state transitions).
 *
 * Extension points (all optional, provided by the Pro add-on):
 *  - 'soroban_delivery_prep_days'        filter: (int $days, WC_Product|null $product)
 *  - 'soroban_delivery_transit_range'    filter: (array [$min,$max], WC_Product|null $product)
 *  - 'soroban_delivery_carrier_holidays' filter: (array $holidays, array $settings, WC_Product|null $product)
 *  - 'soroban_delivery_estimate_visible' filter: (bool $visible, WC_Product|null $product)
 *  - 'soroban_delivery_estimate_notes'   filter: (string[] $notes, WC_Product|null $product)
 *        Human-readable notes about which rules matched (simulator).
 *
 * @package SorobanDeliveryDates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soroban_Delivery_Estimator {

	/**
	 * Resolve the effective calculation inputs for a product.
	 *
	 * @param WC_Product|null $product Product context, when any.
	 * @return array|null {
	 *     @type array $settings        Effective store settings.
	 *     @type array $calendar        Calendar used (country, label, coverage_end, holidays).
	 *     @type array $carrier_holidays Effective carrier closure map.
	 *     @type int   $prep_days       Effective preparation days.
	 *     @type int   $transit_min     Effective transit minimum.
	 *     @type int   $transit_max     Effective transit maximum.
	 *     @type bool  $visible         Whether an estimate may be shown for this product.
	 *     @type array $notes           Rule-match notes (admin use).
	 * } Null when the calendar data cannot be loaded.
	 */
	public static function resolve_inputs( $product = null ) {
		$settings = Soroban_Delivery_Plugin::settings();
		$calendar = ( new Soroban_Delivery_Calendar( SOROBAN_DELIVERY_PLUGIN_DIR . 'data' ) )
			->get_calendar( $settings['country'], $settings['division'] );
		if ( null === $calendar ) {
			return null;
		}

		$prep = (int) apply_filters( 'soroban_delivery_prep_days', (int) $settings['prep_days'], $product );
		$transit = apply_filters( 'soroban_delivery_transit_range', array( (int) $settings['transit_min'], (int) $settings['transit_max'] ), $product );
		$transit_min = isset( $transit[0] ) ? (int) $transit[0] : (int) $settings['transit_min'];
		$transit_max = isset( $transit[1] ) ? (int) $transit[1] : (int) $settings['transit_max'];
		$carrier_holidays = apply_filters( 'soroban_delivery_carrier_holidays', $calendar['holidays'], $settings, $product );
		if ( ! is_array( $carrier_holidays ) ) {
			$carrier_holidays = $calendar['holidays'];
		}
		$visible = (bool) apply_filters( 'soroban_delivery_estimate_visible', true, $product );
		$notes = apply_filters( 'soroban_delivery_estimate_notes', array(), $product );
		if ( ! is_array( $notes ) ) {
			$notes = array();
		}

		return array(
			'settings'         => $settings,
			'calendar'         => $calendar,
			'carrier_holidays' => $carrier_holidays,
			'prep_days'        => max( 0, $prep ),
			'transit_min'      => max( 0, $transit_min ),
			'transit_max'      => max( 0, $transit_max ),
			'visible'          => $visible,
			'notes'            => $notes,
		);
	}

	/**
	 * Build a full estimate.
	 *
	 * @param WC_Product|null $product Product context, when any.
	 * @param array           $args {
	 *     @type DateTimeImmutable $now             Order moment (default: current site time).
	 *     @type bool              $record_failsafe Update the fail-safe state (default false;
	 *                                              the simulator passes false so test runs
	 *                                              never change the shop's health state).
	 *     @type array             $overrides       Optional prep/transit overrides (simulator).
	 * }
	 * @return array|null {
	 *     @type array $estimate Calculator result.
	 *     @type array $inputs   Resolved inputs (see resolve_inputs()).
	 *     @type int|null $countdown_minutes Minutes until today's cut-off, when applicable.
	 * } Null when no estimate can be shown.
	 */
	public static function build( $product = null, array $args = array() ) {
		$record = ! empty( $args['record_failsafe'] );
		$inputs = self::resolve_inputs( $product );
		if ( null === $inputs ) {
			if ( $record ) {
				Soroban_Delivery_Failsafe::record_failure( 'calendar_data_missing' );
			}
			return null;
		}
		if ( ! $inputs['visible'] ) {
			return null;
		}
		$settings = $inputs['settings'];

		$prep_days   = $inputs['prep_days'];
		$transit_min = $inputs['transit_min'];
		$transit_max = $inputs['transit_max'];
		if ( isset( $args['overrides'] ) && is_array( $args['overrides'] ) ) {
			$o = $args['overrides'];
			if ( isset( $o['prep_days'] ) ) {
				$prep_days = max( 0, (int) $o['prep_days'] );
			}
			if ( isset( $o['transit_min'] ) ) {
				$transit_min = max( 0, (int) $o['transit_min'] );
			}
			if ( isset( $o['transit_max'] ) ) {
				$transit_max = max( 0, (int) $o['transit_max'] );
			}
			if ( $transit_max < $transit_min ) {
				$transit_max = $transit_min;
			}
		}

		$calculator = new Soroban_Delivery_Calculator( array(
			'holidays'         => $inputs['calendar']['holidays'],
			'carrier_holidays' => $inputs['carrier_holidays'],
			'custom_closed'    => $settings['custom_closed_dates'],
			'coverage_end'     => $inputs['calendar']['coverage_end'],
			'open_weekdays'    => $settings['open_weekdays'],
			'carrier_weekdays' => $settings['carrier_weekdays'],
			'cutoff'           => $settings['cutoff_time'],
			'prep_days'        => $prep_days,
			'transit_min'      => $transit_min,
			'transit_max'      => $transit_max,
		) );

		$now = isset( $args['now'] ) && $args['now'] instanceof DateTimeImmutable
			? $args['now']
			: current_datetime();
		$estimate = $calculator->estimate( $now );
		if ( null === $estimate ) {
			if ( $record ) {
				Soroban_Delivery_Failsafe::record_failure( (string) $calculator->get_failure_reason() );
			}
			return null;
		}
		if ( $record ) {
			Soroban_Delivery_Failsafe::record_success();
		}

		$countdown = null;
		if ( $estimate['before_cutoff'] && $estimate['order_date'] === $estimate['start'] ) {
			$cutoff_dt = DateTimeImmutable::createFromFormat(
				'Y-m-d H:i',
				$estimate['order_date'] . ' ' . $estimate['cutoff'],
				$now->getTimezone()
			);
			if ( $cutoff_dt ) {
				$countdown = (int) floor( ( $cutoff_dt->getTimestamp() - $now->getTimestamp() ) / 60 );
				if ( $countdown < 0 ) {
					$countdown = null;
				}
			}
		}

		$inputs['prep_days']   = $prep_days;
		$inputs['transit_min'] = $transit_min;
		$inputs['transit_max'] = $transit_max;

		return array(
			'estimate'          => $estimate,
			'inputs'            => $inputs,
			'countdown_minutes' => $countdown,
		);
	}

	/**
	 * Simulator run: like build() but always returns the full
	 * picture — including the failure reason and the partial walk —
	 * and never touches the fail-safe state.
	 *
	 * @param WC_Product|null $product Product context, when any.
	 * @param array           $args    Same arguments as build().
	 * @return array {
	 *     @type array|null $estimate Calculator result or null.
	 *     @type string|null $failure Failure reason when null.
	 *     @type array $walk          Day-by-day walk (partial on failure).
	 *     @type array|null $inputs   Resolved inputs (null when the calendar is missing).
	 * }
	 */
	public static function simulate( $product = null, array $args = array() ) {
		$inputs = self::resolve_inputs( $product );
		if ( null === $inputs ) {
			return array(
				'estimate' => null,
				'failure'  => 'calendar_data_missing',
				'walk'     => array(),
				'inputs'   => null,
			);
		}
		if ( ! $inputs['visible'] ) {
			return array(
				'estimate' => null,
				'failure'  => 'hidden_by_rule',
				'walk'     => array(),
				'inputs'   => $inputs,
			);
		}
		$settings    = $inputs['settings'];
		$prep_days   = $inputs['prep_days'];
		$transit_min = $inputs['transit_min'];
		$transit_max = $inputs['transit_max'];
		if ( isset( $args['overrides'] ) && is_array( $args['overrides'] ) ) {
			$o = $args['overrides'];
			if ( isset( $o['prep_days'] ) ) {
				$prep_days = max( 0, (int) $o['prep_days'] );
			}
			if ( isset( $o['transit_min'] ) ) {
				$transit_min = max( 0, (int) $o['transit_min'] );
			}
			if ( isset( $o['transit_max'] ) ) {
				$transit_max = max( 0, (int) $o['transit_max'] );
			}
			if ( $transit_max < $transit_min ) {
				$transit_max = $transit_min;
			}
		}
		$calculator = new Soroban_Delivery_Calculator( array(
			'holidays'         => $inputs['calendar']['holidays'],
			'carrier_holidays' => $inputs['carrier_holidays'],
			'custom_closed'    => $settings['custom_closed_dates'],
			'coverage_end'     => $inputs['calendar']['coverage_end'],
			'open_weekdays'    => $settings['open_weekdays'],
			'carrier_weekdays' => $settings['carrier_weekdays'],
			'cutoff'           => $settings['cutoff_time'],
			'prep_days'        => $prep_days,
			'transit_min'      => $transit_min,
			'transit_max'      => $transit_max,
		) );
		$now = isset( $args['now'] ) && $args['now'] instanceof DateTimeImmutable
			? $args['now']
			: current_datetime();
		$estimate = $calculator->estimate( $now );
		$inputs['prep_days']   = $prep_days;
		$inputs['transit_min'] = $transit_min;
		$inputs['transit_max'] = $transit_max;
		return array(
			'estimate' => $estimate,
			'failure'  => null === $estimate ? (string) $calculator->get_failure_reason() : null,
			'walk'     => $calculator->get_walk(),
			'inputs'   => $inputs,
		);
	}

	/**
	 * A short fingerprint of everything that can change the browser
	 * configuration document (settings, Pro extras, calendar data
	 * version). Any change produces a new config URL, so caches can
	 * never serve a stale configuration under the old URL.
	 *
	 * @return string 16-hex-character hash.
	 */
	public static function config_version() {
		$settings = Soroban_Delivery_Plugin::settings();
		$manifest = array();
		$manifest_path = SOROBAN_DELIVERY_PLUGIN_DIR . 'data/calendar-manifest.json';
		if ( is_readable( $manifest_path ) ) {
			$decoded = json_decode( (string) file_get_contents( $manifest_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled data file only.
			if ( is_array( $decoded ) ) {
				$manifest = $decoded;
			}
		}
		$material = array(
			'plugin'   => SOROBAN_DELIVERY_VERSION,
			'settings' => $settings,
			'manifest' => $manifest,
			'extras'   => apply_filters( 'soroban_delivery_js_config', array() ),
		);
		return substr( hash( 'sha256', (string) wp_json_encode( $material ) ), 0, 16 );
	}
}
