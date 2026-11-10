/**
 * SorobanDeliveryEngine — browser port of Soroban_Delivery_Calculator (PHP).
 *
 * Semantics MUST mirror the PHP engine exactly (see
 * includes/class-soroban-delivery-calculator.php): business days are configured
 * open weekdays that are not holidays and not custom closed dates;
 * preparation starts on the order day when it is a business day and
 * the site-local time is before the cut-off (at/after the cut-off
 * starts the next business day); preparation advances business days
 * from the start day (day 0); transit advances carrier days strictly
 * after dispatch; any step past the coverage end fails the whole
 * estimate (returns null with a failure reason).
 *
 * All date arithmetic is done on 'Y-m-d' strings via UTC-midnight
 * component math, so DST can never shift a result. The only
 * timezone-sensitive input is the order moment, which the caller
 * supplies already converted into the site timezone (see
 * soroban-delivery-frontend.js: siteNow()).
 *
 * Pure functions only: no DOM, no WordPress. Usable from Node for
 * the PHP<->JS parity tests (module.exports when available).
 */
(function (global) {
	'use strict';

	var MAX_SCAN_DAYS = 500;

	function pad2(n) { return (n < 10 ? '0' : '') + n; }

	/** 'Y-m-d' of a Date read in UTC. */
	function ymd(date) {
		return date.getUTCFullYear() + '-' + pad2(date.getUTCMonth() + 1) + '-' + pad2(date.getUTCDate());
	}

	/** Parse 'Y-m-d' into a UTC-midnight Date. */
	function parseYmd(s) {
		var p = s.split('-');
		return new Date(Date.UTC(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10)));
	}

	/** The calendar day after 'Y-m-d'. */
	function addDay(s) {
		var d = parseYmd(s);
		d.setUTCDate(d.getUTCDate() + 1);
		return ymd(d);
	}

	/** ISO weekday 1=Mon..7=Sun of 'Y-m-d'. */
	function isoWeekday(s) {
		var day = parseYmd(s).getUTCDay(); // 0=Sun..6=Sat
		return day === 0 ? 7 : day;
	}

	function cleanWeekdays(days, fallback) {
		var seen = {}, out = [];
		if (Array.isArray(days)) {
			for (var i = 0; i < days.length; i++) {
				var d = parseInt(days[i], 10);
				if (d >= 1 && d <= 7 && !seen[d]) { seen[d] = true; out.push(d); }
			}
		}
		return out.length ? out : fallback.slice();
	}

	/**
	 * Create a calculator.
	 * config: { holidays:{ymd:name}, carrierHolidays:{ymd:name}|null,
	 *   customClosed:[ymd], coverageEnd:'Y-m-d', openWeekdays:[1..7],
	 *   carrierWeekdays:[1..7], cutoff:'HH:MM', prepDays:int,
	 *   transitMin:int, transitMax:int }
	 */
	function createCalculator(config) {
		var holidays = config.holidays || {};
		var carrierHolidays = config.carrierHolidays || holidays;
		var customClosed = {};
		(config.customClosed || []).forEach(function (d) { customClosed[d] = true; });
		var coverageEnd = config.coverageEnd || '9999-12-31';
		var openWeekdays = cleanWeekdays(config.openWeekdays, [1, 2, 3, 4, 5]);
		var carrierWeekdays = cleanWeekdays(config.carrierWeekdays, [1, 2, 3, 4, 5, 6]);
		var cutoff = config.cutoff || '14:00';
		var prepDays = Math.max(0, parseInt(config.prepDays || 0, 10));
		var transitMin = Math.max(0, parseInt(config.transitMin || 0, 10));
		var transitMax = Math.max(transitMin, parseInt(config.transitMax || 0, 10));

		var failureReason = null;
		var walk = [];

		function businessClosure(date) {
			if (openWeekdays.indexOf(isoWeekday(date)) === -1) {
				return { type: 'weekday_closed', name: null };
			}
			if (Object.prototype.hasOwnProperty.call(holidays, date)) {
				return { type: 'holiday', name: holidays[date] };
			}
			if (customClosed[date]) {
				return { type: 'custom_closed', name: null };
			}
			return null;
		}

		function carrierClosure(date) {
			if (carrierWeekdays.indexOf(isoWeekday(date)) === -1) {
				return { type: 'carrier_weekday_closed', name: null };
			}
			if (Object.prototype.hasOwnProperty.call(carrierHolidays, date)) {
				return { type: 'carrier_holiday', name: carrierHolidays[date] };
			}
			return null;
		}

		function record(date, kind, reasonType, reasonName, count, flags) {
			walk.push({
				date: date,
				kind: kind,
				reason_type: reasonType,
				reason_name: reasonName,
				count: count || 0,
				is_min: !!(flags && flags.is_min),
				is_max: !!(flags && flags.is_max)
			});
		}

		function markLast(kind, flag) {
			for (var i = walk.length - 1; i >= 0; i--) {
				if (walk[i].kind === kind) { walk[i][flag] = true; return; }
			}
		}

		function fail(reason) { failureReason = reason; return null; }

		function nextBusinessDay(date) {
			var cursor = date;
			for (var i = 0; i < MAX_SCAN_DAYS; i++) {
				cursor = addDay(cursor);
				if (cursor > coverageEnd) { return fail('beyond_coverage'), null; }
				var reason = businessClosure(cursor);
				if (reason === null) { return cursor; }
				record(cursor, 'skip_business', reason.type, reason.name);
			}
			return fail('no_business_day'), null;
		}

		function nextCarrierDay(date) {
			var cursor = date;
			for (var i = 0; i < MAX_SCAN_DAYS; i++) {
				cursor = addDay(cursor);
				if (cursor > coverageEnd) { return fail('beyond_coverage'), null; }
				var reason = carrierClosure(cursor);
				if (reason === null) { return cursor; }
				record(cursor, 'skip_carrier', reason.type, reason.name);
			}
			return fail('no_carrier_day'), null;
		}

		/**
		 * Estimate for an order moment given as site-local parts:
		 * nowParts = { date:'Y-m-d', time:'HH:MM' } in the site timezone.
		 * Returns the estimate object or null (see getFailureReason()).
		 */
		function estimate(nowParts) {
			walk = [];
			failureReason = null;

			var orderDate = nowParts.date;
			var beforeCutoff = nowParts.time < cutoff;

			if (orderDate > coverageEnd) { fail('beyond_coverage'); return null; }

			var start;
			var orderReason = businessClosure(orderDate);
			if (orderReason === null && beforeCutoff) {
				start = orderDate;
				record(start, 'start', 'open', null);
			} else {
				if (orderReason === null) {
					orderReason = { type: 'cutoff_passed', name: null };
				}
				record(orderDate, 'order', orderReason.type, orderReason.name);
				start = nextBusinessDay(orderDate);
				if (start === null) { return null; }
				record(start, 'start', 'open', null);
			}

			var dispatch = start;
			for (var i = 1; i <= prepDays; i++) {
				var next = nextBusinessDay(dispatch);
				if (next === null) { return null; }
				dispatch = next;
				record(dispatch, i === prepDays ? 'dispatch' : 'prep', 'open', null, i);
			}
			if (prepDays === 0) {
				record(dispatch, 'dispatch', 'open', null, 0);
			}

			var arrivalMin = dispatch;
			var arrivalMax = dispatch;
			if (transitMin === 0) { markLast('dispatch', 'is_min'); }
			var cursor = dispatch;
			for (var k = 1; k <= transitMax; k++) {
				var step = nextCarrierDay(cursor);
				if (step === null) { return null; }
				cursor = step;
				var flags = {};
				if (k === transitMin) { arrivalMin = cursor; flags.is_min = true; }
				if (k === transitMax) { arrivalMax = cursor; flags.is_max = true; }
				record(cursor, 'transit', 'open', null, k, flags);
			}
			if (transitMax === 0) {
				markLast('dispatch', 'is_max');
				arrivalMin = dispatch;
				arrivalMax = dispatch;
			}

			return {
				order_date: orderDate,
				before_cutoff: beforeCutoff,
				cutoff: cutoff,
				start: start,
				dispatch: dispatch,
				arrival_min: arrivalMin,
				arrival_max: arrivalMax,
				walk: walk
			};
		}

		return {
			estimate: estimate,
			getFailureReason: function () { return failureReason; },
			getWalk: function () { return walk; }
		};
	}

	/**
	 * Current date/time parts in an IANA timezone, using Intl.
	 * Returns { date:'Y-m-d', time:'HH:MM', hour, minute } or null when
	 * the timezone is unknown to the runtime.
	 */
	function nowInTimezone(timeZone, instant) {
		try {
			var fmt = new Intl.DateTimeFormat('en-GB', {
				timeZone: timeZone,
				year: 'numeric', month: '2-digit', day: '2-digit',
				hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
			});
			var parts = fmt.formatToParts(instant || new Date());
			var v = {};
			parts.forEach(function (p) { v[p.type] = p.value; });
			return {
				date: v.year + '-' + v.month + '-' + v.day,
				time: v.hour + ':' + v.minute,
				hour: parseInt(v.hour, 10),
				minute: parseInt(v.minute, 10)
			};
		} catch (e) {
			return null;
		}
	}

	var api = {
		createCalculator: createCalculator,
		nowInTimezone: nowInTimezone,
		isoWeekday: isoWeekday,
		addDay: addDay
	};

	global.SorobanDeliveryEngine = api;
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
})(typeof window !== 'undefined' ? window : globalThis);
