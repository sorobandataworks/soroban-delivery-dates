/**
 * Soroban Delivery frontend renderer (audit item A).
 *
 * Computes the delivery estimate in the visitor's browser at view
 * time, so pages served from a full-page cache or CDN still show
 * dates for the moment of viewing:
 *  - "now" is converted into the SITE timezone via Intl (cut-off
 *    boundaries and DST transitions behave exactly as server-side).
 *  - Store configuration comes from a cacheable, content-hashed JSON
 *    document; per-product inputs (incl. the visitor's own shipping
 *    zone rules) come from a private, no-store endpoint.
 *  - The estimate re-computes on a timer, so passing the cut-off or
 *    midnight updates the display without a page reload.
 *  - On ANY failure (network, data, computation) the container is
 *    emptied: a wrong or stale date is never displayed.
 *
 * All dynamic text is inserted via textContent (no innerHTML), so
 * there is no HTML-injection surface from data values.
 */
(function () {
	'use strict';

	if (!window.SOROBAN_DELIVERY_FRONTEND || !window.SorobanDeliveryEngine) { return; }

	var configPromise = null;

	function fetchJson(url, opts) {
		return fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {})).then(function (r) {
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			return r.json();
		});
	}

	function getConfig() {
		if (!configPromise) {
			configPromise = fetchJson(window.SOROBAN_DELIVERY_FRONTEND.configUrl);
		}
		return configPromise;
	}

	/** Minimal sprintf for the plugin's string templates (%1$d, %2$d, %3$s, %s, %d). */
	function fmt(template, args) {
		var i = 0;
		return String(template).replace(/%(\d+)\$[ds]|%[ds]/g, function (m, idx) {
			var v = idx ? args[parseInt(idx, 10) - 1] : args[i++];
			return v === undefined || v === null ? '' : String(v);
		});
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (text !== undefined) { node.textContent = text; }
		return node;
	}

	/* ---- PHP-style date_format rendering (common tokens) ---- */

	var monthNames = { long: null, short: null };
	var dayNames = { long: null, short: null };

	function names(locale, kind, len) {
		if (!names.cache) { names.cache = {}; }
		var key = kind + len;
		if (!names.cache[key]) {
			var opts = kind === 'month' ? { month: len } : { weekday: len };
			var f = new Intl.DateTimeFormat(locale, opts);
			var arr = [];
			if (kind === 'month') {
				for (var m = 0; m < 12; m++) { arr.push(f.format(new Date(Date.UTC(2026, m, 15)))); }
			} else {
				// 2026-01-05 is a Monday.
				for (var d = 0; d < 7; d++) { arr.push(f.format(new Date(Date.UTC(2026, 0, 5 + d)))); }
			}
			names.cache[key] = arr;
		}
		return names.cache[key];
	}

	function formatDate(ymd, pattern, locale) {
		var p = ymd.split('-');
		var year = parseInt(p[0], 10), month = parseInt(p[1], 10), day = parseInt(p[2], 10);
		var iso = window.SorobanDeliveryEngine.isoWeekday(ymd); // 1=Mon..7=Sun
		var monthsL = names(locale, 'month', 'long');
		var monthsS = names(locale, 'month', 'short');
		var daysL = names(locale, 'weekday', 'long');
		var daysS = names(locale, 'weekday', 'short');
		function pad(n) { return (n < 10 ? '0' : '') + n; }
		var tokens = {
			Y: String(year), y: String(year).slice(2),
			m: pad(month), n: String(month),
			d: pad(day), j: String(day),
			F: monthsL[month - 1], M: monthsS[month - 1],
			l: daysL[iso - 1], D: daysS[iso - 1],
			N: String(iso), w: String(iso % 7)
		};
		var out = '';
		for (var i = 0; i < pattern.length; i++) {
			var c = pattern[i];
			if (c === '\\' && i + 1 < pattern.length) { out += pattern[++i]; continue; }
			out += Object.prototype.hasOwnProperty.call(tokens, c) ? tokens[c] : c;
		}
		return out;
	}

	/* ---- Breakdown (Pro) ---- */

	function breakdownStepText(step, strings) {
		switch (step.kind) {
			case 'order':
				return step.reason_type === 'cutoff_passed' ? strings.order_cutoff : strings.order_closed;
			case 'start': return strings.start;
			case 'prep': return fmt(strings.prep, [step.count]);
			case 'dispatch': return strings.dispatch;
			case 'skip_business':
				if (step.reason_type === 'holiday') { return fmt(strings.closed_holiday, [step.reason_name]); }
				if (step.reason_type === 'custom_closed') { return strings.closed_custom; }
				return strings.closed;
			case 'skip_carrier':
				if (step.reason_type === 'carrier_holiday') { return fmt(strings.carrier_holiday, [step.reason_name]); }
				return strings.carrier_closed;
			case 'transit':
				var t = fmt(strings.transit, [step.count]);
				if (step.is_min && step.is_max) { t += strings.arrival_both; }
				else if (step.is_min) { t += strings.arrival_earliest; }
				else if (step.is_max) { t += strings.arrival_latest; }
				return t;
		}
		return '';
	}

	function renderBreakdown(container, estimate, config) {
		var bd = config.extras && config.extras.breakdown;
		if (!bd || !bd.enabled || !estimate.walk || !estimate.walk.length) { return; }
		var wrap = el('div', 'soroban-delivery-breakdown');
		wrap.appendChild(el('p', 'soroban-delivery-breakdown-title', bd.title));
		var ul = el('ul', 'soroban-delivery-breakdown-list');
		estimate.walk.forEach(function (step) {
			var text = breakdownStepText(step, bd.strings || {});
			if (text) {
				var li = el('li', null, formatDate(step.date, config.date_format, locale()) + ' — ' + text);
				ul.appendChild(li);
			}
		});
		wrap.appendChild(ul);
		if (bd.note) { wrap.appendChild(el('p', 'soroban-delivery-breakdown-note', bd.note)); }
		container.appendChild(wrap);
	}

	function locale() {
		return document.documentElement.lang || 'en-GB';
	}

	/* ---- Main render ---- */

	function renderContainer(container, config, params) {
		container.textContent = '';
		if (!config.available || !config.calendar || (params && params.visible === false)) {
			container.style.display = 'none';
			return;
		}
		var s = config.settings;
		var nowParts = window.SorobanDeliveryEngine.nowInTimezone(config.timezone, new Date());
		if (!nowParts) { container.style.display = 'none'; return; }

		var calc = window.SorobanDeliveryEngine.createCalculator({
			holidays: config.calendar.holidays,
			carrierHolidays: (params && params.carrier_holidays) || config.calendar.carrier_holidays,
			customClosed: s.custom_closed,
			coverageEnd: config.calendar.coverage_end,
			openWeekdays: s.open_weekdays,
			carrierWeekdays: s.carrier_weekdays,
			cutoff: s.cutoff_time,
			prepDays: params ? params.prep_days : s.prep_days,
			transitMin: params ? params.transit_min : s.transit_min,
			transitMax: params ? params.transit_max : s.transit_max
		});
		var estimate = calc.estimate(nowParts);
		if (!estimate) { container.style.display = 'none'; return; }
		container.style.display = '';

		var loc = locale();
		var dateMin = formatDate(estimate.arrival_min, config.date_format, loc);
		var dateMax = formatDate(estimate.arrival_max, config.date_format, loc);
		var dispatch = formatDate(estimate.dispatch, config.date_format, loc);
		var range = estimate.arrival_min === estimate.arrival_max ? dateMin : dateMin + ' – ' + dateMax;

		var template = config.extras && config.extras.template;
		if (template) {
			var text = String(template)
				.split('{label}').join(s.label_text)
				.split('{range}').join(range)
				.split('{date_min}').join(dateMin)
				.split('{date_max}').join(dateMax)
				.split('{dispatch}').join(dispatch)
				.split('{cutoff}').join(s.cutoff_time);
			var p = el('p', 'soroban-delivery-estimate-text', text);
			var wrap = el('div', 'soroban-delivery-estimate-inner');
			wrap.appendChild(p);
			container.appendChild(wrap);
			return;
		}

		var wrap2 = el('div', 'soroban-delivery-estimate-inner');
		var dates = el('p', 'soroban-delivery-estimate-dates');
		dates.appendChild(el('span', 'soroban-delivery-estimate-label', s.label_text));
		dates.appendChild(document.createTextNode(' '));
		var strong = el('strong', null, range);
		dates.appendChild(strong);
		wrap2.appendChild(dates);

		var basis = '';
		if (estimate.before_cutoff && estimate.order_date === estimate.start) {
			var cp = s.cutoff_time.split(':');
			var cutoffMins = parseInt(cp[0], 10) * 60 + parseInt(cp[1], 10);
			var nowMins = nowParts.hour * 60 + nowParts.minute;
			var remain = cutoffMins - nowMins;
			if (remain >= 0) {
				basis += fmt(config.strings.order_within, [Math.floor(remain / 60), remain % 60, s.cutoff_time]);
			}
		}
		if (config.calendar.country === 'UK') {
			basis += fmt(config.strings.based_on_uk, [config.calendar.label]);
		} else {
			basis += config.strings.based_on_jp;
		}
		if (params && params.zone_applied) {
			basis += config.strings.based_on_location;
		}
		basis += config.strings.not_guarantee;
		wrap2.appendChild(el('p', 'soroban-delivery-estimate-basis', basis));
		container.appendChild(wrap2);

		renderBreakdown(container, estimate, config);
	}

	function initContainer(container) {
		var productId = parseInt(container.getAttribute('data-product-id') || '0', 10);
		var paramsPromise = productId > 0
			? fetchJson(window.SOROBAN_DELIVERY_FRONTEND.productUrl + productId)
			: Promise.resolve(null);

		function refresh() {
			Promise.all([getConfig(), paramsPromise]).then(function (res) {
				renderContainer(container, res[0], res[1]);
			}).catch(function () {
				container.textContent = '';
				container.style.display = 'none';
			});
		}
		refresh();
		// Keep the countdown and the cut-off/midnight boundaries honest
		// while the page stays open.
		setInterval(refresh, 15000);
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) { refresh(); }
		});
	}

	function boot() {
		var nodes = document.querySelectorAll('[data-soroban-delivery-estimate]');
		for (var i = 0; i < nodes.length; i++) { initContainer(nodes[i]); }
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
