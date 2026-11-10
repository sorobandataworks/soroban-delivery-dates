<?php
/**
 * Fail-safe state machine (audit item C).
 *
 * Previously a failure notice was written once and never cleared, so
 * the admin error could outlive the problem forever and "happening
 * now" could not be distinguished from "recovered". This class keeps
 * a single state record instead:
 *
 *   state        'ok' | 'failing' | 'recovered'
 *   reason       last failure reason code (e.g. 'beyond_coverage')
 *   first_seen   timestamp the current failure streak began
 *   last_seen    timestamp of the most recent failure
 *   recovered_at timestamp of the most recent recovery
 *   occurrences  failure count within the current streak
 *
 * Writes are throttled: state TRANSITIONS are always recorded, but
 * repeated failures in the same state refresh the record at most
 * once per hour, so a busy shop cannot flood the options table.
 * The legacy 'soroban_delivery_failsafe_notice' option from 1.0.0 is honoured as
 * a read fallback and removed on the first state write.
 *
 * @package SorobanDeliveryDates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soroban_Delivery_Failsafe {

	const OPTION_STATE  = 'soroban_delivery_failsafe_state';
	const OPTION_LEGACY = 'soroban_delivery_failsafe_notice';

	/**
	 * Current state record (with defaults / legacy migration).
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::OPTION_STATE );
		if ( is_array( $state ) && isset( $state['state'] ) ) {
			return array_merge( self::defaults(), $state );
		}
		$legacy = get_option( self::OPTION_LEGACY );
		if ( is_array( $legacy ) && ! empty( $legacy['reason'] ) ) {
			$out = self::defaults();
			$out['state']      = 'failing';
			$out['reason']     = (string) $legacy['reason'];
			$out['first_seen'] = isset( $legacy['time'] ) ? (int) $legacy['time'] : 0;
			$out['last_seen']  = $out['first_seen'];
			$out['occurrences'] = 1;
			return $out;
		}
		return self::defaults();
	}

	/**
	 * Record a failed estimate computation.
	 *
	 * @param string $reason Failure reason code.
	 */
	public static function record_failure( $reason ) {
		$reason = (string) $reason;
		$now    = time();
		$state  = self::state();
		if ( 'failing' === $state['state'] && $state['reason'] === $reason ) {
			// Same ongoing failure: refresh at most hourly.
			if ( ( $now - (int) $state['last_seen'] ) < HOUR_IN_SECONDS ) {
				return;
			}
			$state['last_seen'] = $now;
			$state['occurrences'] = (int) $state['occurrences'] + 1;
			self::write( $state );
			return;
		}
		// New failure (or a different reason): a transition — always write.
		$state['state']      = 'failing';
		$state['reason']     = $reason;
		$state['first_seen'] = $now;
		$state['last_seen']  = $now;
		$state['occurrences'] = 1;
		self::write( $state );
	}

	/**
	 * Record a successful estimate computation. Transitions the state
	 * to 'recovered' when a failure was in progress; a no-op (no
	 * write) when everything was already fine.
	 */
	public static function record_success() {
		$state = self::state();
		if ( 'failing' !== $state['state'] ) {
			return;
		}
		$state['state']        = 'recovered';
		$state['recovered_at'] = time();
		self::write( $state );
	}

	/**
	 * Persist a state record and drop the legacy option.
	 *
	 * @param array $state State record.
	 */
	private static function write( array $state ) {
		update_option( self::OPTION_STATE, $state, false );
		if ( false !== get_option( self::OPTION_LEGACY, false ) ) {
			delete_option( self::OPTION_LEGACY );
		}
	}

	/**
	 * @return array Default (healthy) state record.
	 */
	private static function defaults() {
		return array(
			'state'        => 'ok',
			'reason'       => '',
			'first_seen'   => 0,
			'last_seen'    => 0,
			'recovered_at' => 0,
			'occurrences'  => 0,
		);
	}
}
