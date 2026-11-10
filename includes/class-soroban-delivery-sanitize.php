<?php
/**
 * Pure-PHP sanitizers and defaults for the plugin settings.
 *
 * This class deliberately uses no WordPress functions so it can be
 * unit-tested with a plain PHP interpreter (see tests/run.php).
 *
 * @package SorobanDeliveryDates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soroban_Delivery_Sanitize {

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'             => 1,
			'country'             => 'UK', // 'UK' or 'JP'.
			'division'            => 'england-and-wales',
			'open_weekdays'       => array( 1, 2, 3, 4, 5 ), // ISO: Mon=1 .. Sun=7.
			'custom_closed_dates' => array(),
			'cutoff_time'         => '14:00',
			'prep_days'           => 1,
			'transit_min'         => 2,
			'transit_max'         => 4,
			'carrier_weekdays'    => array( 1, 2, 3, 4, 5, 6 ),
			'label_text'          => 'Estimated delivery:',
			'position'            => 'after_price',
		);
	}

	/**
	 * Allowed values for enumerated settings.
	 *
	 * @return array
	 */
	public static function choices() {
		return array(
			'country'  => array( 'UK', 'JP' ),
			'division' => array( 'england-and-wales', 'scotland', 'northern-ireland' ),
			'position' => array( 'after_title', 'after_price', 'after_excerpt' ),
		);
	}

	/**
	 * Sanitize a full settings array (used as the register_setting
	 * sanitize callback). Unknown keys are dropped; every known key
	 * is validated or replaced by its default.
	 *
	 * @param mixed $raw Raw input.
	 * @return array
	 */
	public static function sanitize_settings( $raw ) {
		$defaults = self::defaults();
		$choices  = self::choices();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$out = array();
		$out['enabled']   = empty( $raw['enabled'] ) ? 0 : 1;
		$out['country']   = self::choice( isset( $raw['country'] ) ? $raw['country'] : null, $choices['country'], $defaults['country'] );
		$out['division']  = self::choice( isset( $raw['division'] ) ? $raw['division'] : null, $choices['division'], $defaults['division'] );
		$out['open_weekdays']       = self::weekday_list( isset( $raw['open_weekdays'] ) ? $raw['open_weekdays'] : null, $defaults['open_weekdays'] );
		$out['carrier_weekdays']    = self::weekday_list( isset( $raw['carrier_weekdays'] ) ? $raw['carrier_weekdays'] : null, $defaults['carrier_weekdays'] );
		$out['custom_closed_dates'] = self::date_list( isset( $raw['custom_closed_dates'] ) ? $raw['custom_closed_dates'] : null );
		$out['cutoff_time'] = self::time_hhmm( isset( $raw['cutoff_time'] ) ? $raw['cutoff_time'] : null, $defaults['cutoff_time'] );
		$out['prep_days']   = self::int_range( isset( $raw['prep_days'] ) ? $raw['prep_days'] : null, 0, 60, $defaults['prep_days'] );
		$out['transit_min'] = self::int_range( isset( $raw['transit_min'] ) ? $raw['transit_min'] : null, 0, 60, $defaults['transit_min'] );
		$out['transit_max'] = self::int_range( isset( $raw['transit_max'] ) ? $raw['transit_max'] : null, 0, 90, $defaults['transit_max'] );
		if ( $out['transit_max'] < $out['transit_min'] ) {
			$out['transit_max'] = $out['transit_min'];
		}
		$out['label_text'] = self::label_text( isset( $raw['label_text'] ) ? $raw['label_text'] : null, $defaults['label_text'] );
		$out['position']   = self::choice( isset( $raw['position'] ) ? $raw['position'] : null, $choices['position'], $defaults['position'] );
		return $out;
	}

	/**
	 * Validate an "HH:MM" 24-hour time string.
	 *
	 * @param mixed  $value    Candidate value.
	 * @param string $fallback Returned when invalid.
	 * @return string
	 */
	public static function time_hhmm( $value, $fallback ) {
		if ( is_string( $value ) && preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])$/', trim( $value ), $m ) ) {
			return $m[1] . ':' . $m[2];
		}
		return $fallback;
	}

	/**
	 * Clamp an integer into a range; non-numeric input yields fallback.
	 *
	 * @param mixed $value    Candidate value.
	 * @param int   $min      Minimum allowed.
	 * @param int   $max      Maximum allowed.
	 * @param int   $fallback Returned when not numeric.
	 * @return int
	 */
	public static function int_range( $value, $min, $max, $fallback ) {
		if ( is_bool( $value ) || null === $value || '' === $value || ! is_numeric( $value ) ) {
			return $fallback;
		}
		$n = (int) $value;
		if ( $n < $min ) {
			return $min;
		}
		if ( $n > $max ) {
			return $max;
		}
		return $n;
	}

	/**
	 * Validate a list of ISO weekday numbers (1=Mon .. 7=Sun).
	 * Returns a sorted, de-duplicated list; an empty result yields
	 * the fallback so the store always has at least one open day.
	 *
	 * @param mixed $value    Candidate value (array expected).
	 * @param array $fallback Returned when nothing valid remains.
	 * @return int[]
	 */
	public static function weekday_list( $value, $fallback ) {
		if ( ! is_array( $value ) ) {
			return $fallback;
		}
		$days = array();
		foreach ( $value as $v ) {
			if ( is_numeric( $v ) ) {
				$n = (int) $v;
				if ( $n >= 1 && $n <= 7 ) {
					$days[ $n ] = $n;
				}
			}
		}
		$days = array_values( $days );
		sort( $days );
		return $days ? $days : $fallback;
	}

	/**
	 * Validate a list of calendar dates. Accepts an array of strings
	 * or a textarea string (one date per line, comma/space tolerant).
	 * Only real calendar dates in Y-m-d form survive; the result is
	 * sorted and de-duplicated.
	 *
	 * @param mixed $value Candidate value.
	 * @return string[] List of 'Y-m-d' dates.
	 */
	public static function date_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$dates = array();
		foreach ( $value as $v ) {
			if ( ! is_string( $v ) ) {
				continue;
			}
			$v = trim( $v );
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m )
				&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				$dates[ $v ] = $v;
			}
		}
		$dates = array_values( $dates );
		sort( $dates );
		return $dates;
	}

	/**
	 * Validate an enumerated value.
	 *
	 * @param mixed $value    Candidate value.
	 * @param array $allowed  Allowed values.
	 * @param mixed $fallback Returned when not allowed.
	 * @return mixed
	 */
	public static function choice( $value, $allowed, $fallback ) {
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Validate free label text: plain text only, length-capped.
	 *
	 * @param mixed  $value    Candidate value.
	 * @param string $fallback Returned when empty/invalid.
	 * @return string
	 */
	public static function label_text( $value, $fallback ) {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$value = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
		if ( '' === $value ) {
			return $fallback;
		}
		if ( strlen( $value ) > 120 ) {
			$value = substr( $value, 0, 120 );
		}
		return $value;
	}
}
