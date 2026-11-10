<?php
/**
 * Pure delivery-date calculation engine.
 *
 * Semantics (shared with the UK Delivery Date Checker web tool):
 *
 *  - A store business day is a configured open weekday that is not a
 *    bank/national holiday of the selected calendar and not a custom
 *    closed date.
 *  - The preparation start day is the order day when it is a business
 *    day and the local time (site timezone, injected by the caller as
 *    $now) is before the cut-off. At or after the cut-off, or on a
 *    non-business day, preparation starts on the next business day.
 *  - Preparation takes prep_days business days counted forward from
 *    the start day (the start day is day 0); the order is dispatched
 *    on the business day on which preparation completes. With
 *    prep_days = 0 the start day is the dispatch day.
 *  - Transit counts carrier days (configured carrier weekdays,
 *    excluding carrier holidays) strictly after the dispatch day,
 *    giving an estimated range [transit_min, transit_max]. A transit
 *    value of 0 means the dispatch day itself.
 *  - Fail-safe: if the computation needs to inspect any date beyond
 *    the calendar coverage end, no estimate is produced at all
 *    (estimate() returns null and get_failure_reason() reports
 *    'beyond_coverage'); the front end must display nothing.
 *
 * All date arithmetic is done on Y/M/D components using UTC-midnight
 * DateTimeImmutable values, so daylight-saving transitions can never
 * shift a result. The only timezone-sensitive inputs are the order
 * date and time-of-day, taken from the injected $now.
 *
 * This class uses no WordPress functions and returns structured walk
 * data (reason codes + holiday names) so callers can localise the
 * presentation. It is unit-testable with a plain PHP interpreter.
 *
 * @package SorobanDeliveryDates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soroban_Delivery_Calculator {

	const MAX_SCAN_DAYS = 500;

	/** @var array Map 'Y-m-d' => holiday name (store closures). */
	private $holidays;
	/** @var array Map 'Y-m-d' => holiday name (carrier closures). */
	private $carrier_holidays;
	/** @var array Map 'Y-m-d' => true (custom closed dates). */
	private $custom_closed;
	/** @var string 'Y-m-d' last date covered by the calendar data. */
	private $coverage_end;
	/** @var int[] ISO weekdays the store is open (1=Mon .. 7=Sun). */
	private $open_weekdays;
	/** @var int[] ISO weekdays the carrier operates. */
	private $carrier_weekdays;
	/** @var string Cut-off time 'HH:MM' (site local time). */
	private $cutoff;
	/** @var int Preparation time in business days (>= 0). */
	private $prep_days;
	/** @var int Minimum transit time in carrier days (>= 0). */
	private $transit_min;
	/** @var int Maximum transit time in carrier days (>= transit_min). */
	private $transit_max;
	/** @var string|null Failure reason after a null estimate. */
	private $failure_reason = null;
	/** @var array Day-by-day walk of the last computation. */
	private $walk = array();

	/**
	 * @param array $config {
	 *     @type array  $holidays         Map 'Y-m-d' => name.
	 *     @type array  $carrier_holidays Map 'Y-m-d' => name (default: $holidays).
	 *     @type array  $custom_closed    List of 'Y-m-d' dates.
	 *     @type string $coverage_end     'Y-m-d'.
	 *     @type int[]  $open_weekdays    ISO weekdays.
	 *     @type int[]  $carrier_weekdays ISO weekdays.
	 *     @type string $cutoff           'HH:MM'.
	 *     @type int    $prep_days        >= 0.
	 *     @type int    $transit_min      >= 0.
	 *     @type int    $transit_max      >= 0.
	 * }
	 */
	public function __construct( array $config ) {
		$this->holidays         = isset( $config['holidays'] ) && is_array( $config['holidays'] ) ? $config['holidays'] : array();
		$this->carrier_holidays = isset( $config['carrier_holidays'] ) && is_array( $config['carrier_holidays'] ) ? $config['carrier_holidays'] : $this->holidays;
		$this->custom_closed    = array();
		if ( ! empty( $config['custom_closed'] ) && is_array( $config['custom_closed'] ) ) {
			foreach ( $config['custom_closed'] as $d ) {
				$this->custom_closed[ $d ] = true;
			}
		}
		$this->coverage_end     = isset( $config['coverage_end'] ) ? $config['coverage_end'] : '9999-12-31';
		$this->open_weekdays    = self::clean_weekdays( isset( $config['open_weekdays'] ) ? $config['open_weekdays'] : array( 1, 2, 3, 4, 5 ) );
		$this->carrier_weekdays = self::clean_weekdays( isset( $config['carrier_weekdays'] ) ? $config['carrier_weekdays'] : array( 1, 2, 3, 4, 5, 6 ) );
		$this->cutoff           = isset( $config['cutoff'] ) ? $config['cutoff'] : '14:00';
		$this->prep_days        = max( 0, (int) ( isset( $config['prep_days'] ) ? $config['prep_days'] : 0 ) );
		$this->transit_min      = max( 0, (int) ( isset( $config['transit_min'] ) ? $config['transit_min'] : 0 ) );
		$this->transit_max      = max( $this->transit_min, (int) ( isset( $config['transit_max'] ) ? $config['transit_max'] : 0 ) );
	}

	/**
	 * Reason the last estimate() call failed, or null.
	 * One of: 'beyond_coverage', 'no_business_day', 'no_carrier_day'.
	 *
	 * @return string|null
	 */
	public function get_failure_reason() {
		return $this->failure_reason;
	}

	/**
	 * Day-by-day walk recorded during the last estimate() call.
	 * Each entry: {date, kind, reason_type, reason_name, count,
	 * is_min, is_max}. Kinds: order, start, prep, dispatch,
	 * skip_business, skip_carrier, transit.
	 *
	 * @return array
	 */
	public function get_walk() {
		return $this->walk;
	}

	/**
	 * Whether a date is a store business day.
	 *
	 * @param string $date 'Y-m-d'.
	 * @return bool
	 */
	public function is_business_day( $date ) {
		return null === $this->business_day_closure_reason( $date );
	}

	/**
	 * Why a date is not a store business day.
	 *
	 * @param string $date 'Y-m-d'.
	 * @return array|null {type: weekday_closed|holiday|custom_closed, name: string|null}
	 */
	public function business_day_closure_reason( $date ) {
		if ( ! in_array( self::iso_weekday( $date ), $this->open_weekdays, true ) ) {
			return array( 'type' => 'weekday_closed', 'name' => null );
		}
		if ( isset( $this->holidays[ $date ] ) ) {
			return array( 'type' => 'holiday', 'name' => $this->holidays[ $date ] );
		}
		if ( isset( $this->custom_closed[ $date ] ) ) {
			return array( 'type' => 'custom_closed', 'name' => null );
		}
		return null;
	}

	/**
	 * Whether the carrier operates on a date.
	 *
	 * @param string $date 'Y-m-d'.
	 * @return bool
	 */
	public function is_carrier_day( $date ) {
		return null === $this->carrier_day_closure_reason( $date );
	}

	/**
	 * Why the carrier does not operate on a date.
	 *
	 * @param string $date 'Y-m-d'.
	 * @return array|null {type: carrier_weekday_closed|carrier_holiday, name: string|null}
	 */
	public function carrier_day_closure_reason( $date ) {
		if ( ! in_array( self::iso_weekday( $date ), $this->carrier_weekdays, true ) ) {
			return array( 'type' => 'carrier_weekday_closed', 'name' => null );
		}
		if ( isset( $this->carrier_holidays[ $date ] ) ) {
			return array( 'type' => 'carrier_holiday', 'name' => $this->carrier_holidays[ $date ] );
		}
		return null;
	}

	/**
	 * Compute the delivery estimate for an order placed at $now.
	 *
	 * @param DateTimeImmutable $now Order moment, in the site timezone.
	 * @return array|null {
	 *     @type string $order_date   'Y-m-d'.
	 *     @type bool   $before_cutoff Whether the order beat the cut-off.
	 *     @type string $cutoff       'HH:MM'.
	 *     @type string $start        Preparation start day, 'Y-m-d'.
	 *     @type string $dispatch     Dispatch day, 'Y-m-d'.
	 *     @type string $arrival_min  Earliest estimated arrival, 'Y-m-d'.
	 *     @type string $arrival_max  Latest estimated arrival, 'Y-m-d'.
	 *     @type array  $walk         Day-by-day walk (see get_walk()).
	 * } Null on fail-safe failure; inspect get_failure_reason().
	 */
	public function estimate( DateTimeImmutable $now ) {
		$this->walk           = array();
		$this->failure_reason = null;

		$order_date    = $now->format( 'Y-m-d' );
		$before_cutoff = $now->format( 'H:i' ) < $this->cutoff;

		if ( $order_date > $this->coverage_end ) {
			return $this->fail( 'beyond_coverage' );
		}

		// --- Preparation start day. ---
		$order_reason = $this->business_day_closure_reason( $order_date );
		if ( null === $order_reason && $before_cutoff ) {
			$start = $order_date;
			$this->record( $start, 'start', 'open', null );
		} else {
			if ( null === $order_reason ) {
				$order_reason = array( 'type' => 'cutoff_passed', 'name' => null );
			}
			$this->record( $order_date, 'order', $order_reason['type'], $order_reason['name'] );
			$start = $this->next_business_day( $order_date );
			if ( null === $start ) {
				return null;
			}
			$this->record( $start, 'start', 'open', null );
		}

		// --- Dispatch day: advance prep_days business days. ---
		$dispatch = $start;
		for ( $i = 1; $i <= $this->prep_days; $i++ ) {
			$next = $this->next_business_day( $dispatch );
			if ( null === $next ) {
				return null;
			}
			$dispatch = $next;
			$this->record( $dispatch, ( $i === $this->prep_days ) ? 'dispatch' : 'prep', 'open', null, $i );
		}
		if ( 0 === $this->prep_days ) {
			$this->record( $dispatch, 'dispatch', 'open', null, 0 );
		}

		// --- Arrival range: advance carrier days from dispatch. ---
		$arrival_min = $dispatch;
		$arrival_max = $dispatch;
		if ( 0 === $this->transit_min ) {
			$this->mark_last( 'dispatch', 'is_min' );
		}
		$cursor = $dispatch;
		for ( $k = 1; $k <= $this->transit_max; $k++ ) {
			$next = $this->next_carrier_day( $cursor );
			if ( null === $next ) {
				return null;
			}
			$cursor = $next;
			$flags = array();
			if ( $k === $this->transit_min ) {
				$arrival_min    = $cursor;
				$flags['is_min'] = true;
			}
			if ( $k === $this->transit_max ) {
				$arrival_max    = $cursor;
				$flags['is_max'] = true;
			}
			$this->record( $cursor, 'transit', 'open', null, $k, $flags );
		}
		if ( 0 === $this->transit_max ) {
			$this->mark_last( 'dispatch', 'is_max' );
			$arrival_min = $dispatch;
			$arrival_max = $dispatch;
		}

		return array(
			'order_date'    => $order_date,
			'before_cutoff' => $before_cutoff,
			'cutoff'        => $this->cutoff,
			'start'         => $start,
			'dispatch'      => $dispatch,
			'arrival_min'   => $arrival_min,
			'arrival_max'   => $arrival_max,
			'walk'          => $this->walk,
		);
	}

	/**
	 * Find the next business day strictly after $date, recording every
	 * skipped day in the walk. Returns null (with failure reason set)
	 * when the scan passes the coverage end or finds nothing.
	 *
	 * @param string $date 'Y-m-d'.
	 * @return string|null 'Y-m-d'.
	 */
	private function next_business_day( $date ) {
		$cursor = $date;
		for ( $i = 0; $i < self::MAX_SCAN_DAYS; $i++ ) {
			$cursor = self::add_day( $cursor );
			if ( $cursor > $this->coverage_end ) {
				$this->fail( 'beyond_coverage' );
				return null;
			}
			$reason = $this->business_day_closure_reason( $cursor );
			if ( null === $reason ) {
				return $cursor;
			}
			$this->record( $cursor, 'skip_business', $reason['type'], $reason['name'] );
		}
		$this->fail( 'no_business_day' );
		return null;
	}

	/**
	 * Find the next carrier day strictly after $date, recording skips.
	 *
	 * @param string $date 'Y-m-d'.
	 * @return string|null 'Y-m-d'.
	 */
	private function next_carrier_day( $date ) {
		$cursor = $date;
		for ( $i = 0; $i < self::MAX_SCAN_DAYS; $i++ ) {
			$cursor = self::add_day( $cursor );
			if ( $cursor > $this->coverage_end ) {
				$this->fail( 'beyond_coverage' );
				return null;
			}
			$reason = $this->carrier_day_closure_reason( $cursor );
			if ( null === $reason ) {
				return $cursor;
			}
			$this->record( $cursor, 'skip_carrier', $reason['type'], $reason['name'] );
		}
		$this->fail( 'no_carrier_day' );
		return null;
	}

	/**
	 * Record a walk entry.
	 *
	 * @param string      $date        'Y-m-d'.
	 * @param string      $kind        Entry kind.
	 * @param string      $reason_type Reason code.
	 * @param string|null $reason_name Holiday name, when relevant.
	 * @param int         $count       Running count for prep/transit.
	 * @param array       $flags       Optional flags (is_min, is_max).
	 */
	private function record( $date, $kind, $reason_type, $reason_name, $count = 0, $flags = array() ) {
		$this->walk[] = array(
			'date'        => $date,
			'kind'        => $kind,
			'reason_type' => $reason_type,
			'reason_name' => $reason_name,
			'count'       => $count,
			'is_min'      => ! empty( $flags['is_min'] ),
			'is_max'      => ! empty( $flags['is_max'] ),
		);
	}

	/**
	 * Set a flag on the most recent walk entry of a given kind.
	 *
	 * @param string $kind Entry kind to find.
	 * @param string $flag Flag name ('is_min' or 'is_max').
	 */
	private function mark_last( $kind, $flag ) {
		for ( $i = count( $this->walk ) - 1; $i >= 0; $i-- ) {
			if ( $this->walk[ $i ]['kind'] === $kind ) {
				$this->walk[ $i ][ $flag ] = true;
				return;
			}
		}
	}

	/**
	 * Set the failure reason and return null.
	 *
	 * @param string $reason Failure reason code.
	 * @return null
	 */
	private function fail( $reason ) {
		$this->failure_reason = $reason;
		return null;
	}

	/**
	 * Normalise a weekday list to unique ISO values 1..7.
	 *
	 * @param mixed $days Candidate list.
	 * @return int[]
	 */
	private static function clean_weekdays( $days ) {
		$out = array();
		if ( is_array( $days ) ) {
			foreach ( $days as $d ) {
				$d = (int) $d;
				if ( $d >= 1 && $d <= 7 ) {
					$out[ $d ] = $d;
				}
			}
		}
		return $out ? array_values( $out ) : array( 1, 2, 3, 4, 5 );
	}

	/**
	 * ISO weekday (1=Mon .. 7=Sun) of a 'Y-m-d' date. Computed on a
	 * UTC-midnight value so the result is timezone- and DST-proof.
	 *
	 * @param string $date 'Y-m-d'.
	 * @return int
	 */
	private static function iso_weekday( $date ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
		return (int) $dt->format( 'N' );
	}

	/**
	 * The calendar day after a 'Y-m-d' date (component arithmetic on
	 * a UTC-midnight value; immune to DST transitions).
	 *
	 * @param string $date 'Y-m-d'.
	 * @return string 'Y-m-d'.
	 */
	private static function add_day( $date ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
		return $dt->modify( '+1 day' )->format( 'Y-m-d' );
	}
}
