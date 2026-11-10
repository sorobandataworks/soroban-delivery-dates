=== Soroban Delivery Dates for WooCommerce ===
Contributors: sorobandataworks
Tags: woocommerce, delivery date, estimated delivery, business days, bank holidays
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Estimated delivery dates calculated from your business days, official holiday calendars and order cut-off — shown as a range, never a promise.

== Description ==

Show customers an estimated delivery date range that is actually calculated from how your store works — not a flat "+5 days".

**Who this is for**

If you sell into the UK or Japan, a flat "+5 days" estimate quietly goes wrong: it lands on bank holidays, substitute days and your own closed days, and shows shoppers a date you cannot meet. This plugin works the range out from your actual pattern — open weekdays, preparation time, carrier transit days and the official holiday calendar — and displays it on the product page before checkout, stated as an estimate, never a promise.

**How it works**

* You choose a holiday calendar: **UK bank holidays** (official GOV.UK data, per division — England & Wales, Scotland, or Northern Ireland) or **Japanese national holidays** (generated from the statutory rules).
* You set your store's open weekdays, any extra custom closed dates, an order cut-off time, a preparation time in business days, and a transit range in carrier days.
* On each product page the plugin counts forward from the moment of viewing: orders before the cut-off start preparation the same business day; later orders start on the next business day. Preparation and transit skip closed days, weekends and holidays on the correct calendar.
* The calculation runs **in the visitor's browser at view time** (in your site's timezone), so the dates stay correct even behind full-page caches and CDNs — including right after the cut-off and across daylight-saving changes. Without JavaScript no dates are shown, rather than risk showing a wrong one.
* The result is displayed as a **range with its basis** ("Based on UK bank holidays (England & Wales). Estimate — not a guarantee."), plus a live "order within X hrs Y mins" countdown when the cut-off is still ahead.

**Built-in safety**

* If a calculation would run past the end of the bundled calendar data, the estimate is **hidden entirely** and the failure is flagged in wp-admin, instead of showing a date the data cannot support.
* A **simulator** (Tools → Delivery Date Simulator) runs the exact storefront calculation — for any order moment, product and (with Pro) destination — and shows the dispatch date, the range, the effective values used, and the day-by-day walk with reasons, including holiday names.
* A **status panel** on the settings page shows the calendar coverage with advance warnings before the data runs out, and settings can be **exported/imported** as JSON.
* The plugin makes **no external calls**, stores **no personal data**, and does not read or write orders. All computation happens locally with the bundled calendar data.

**Calendars included**

* United Kingdom: official bank holidays from GOV.UK (https://www.gov.uk/bank-holidays.json), 2025–2028, for England & Wales, Scotland and Northern Ireland — including substitute days.
* Japan: national holidays 2025–2028 generated from the rules of the Act on National Holidays, including substitute holidays (振替休日) and citizens' holidays (国民の休日).

A Pro add-on (sold separately, outside wordpress.org) adds per-product and per-category preparation times, per-shipping-zone transit adjustments, a Royal Mail carrier-holiday layer for the UK, a customer-facing calculation breakdown, and display customisation.

== Installation ==

1. Upload the `soroban-delivery-dates` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to WooCommerce → Delivery Dates and choose your calendar, open days, cut-off time, preparation and transit times.
4. Check a scenario under Tools → Delivery Date Simulator.
5. The estimate appears on single product pages automatically. You can also place it anywhere with the `[soroban_delivery_estimate]` shortcode.

== Frequently Asked Questions ==

= Are the dates a guarantee? =

No. The plugin always presents dates as an estimated range with its basis stated, and never as a guaranteed delivery date. Weather, carrier delays and other factors outside the calculation can still change the actual arrival day.

= Which calendars are supported? =

UK bank holidays by division (England & Wales, Scotland, Northern Ireland), from official GOV.UK data, and Japanese national holidays generated from the statutory rules. Each store uses one calendar.

= What happens when the calendar data runs out? =

The bundled data covers 2025–2028. If a calculation would need dates beyond the coverage, the estimate is hidden and an admin notice records the fail-safe event. Update the plugin to receive newer calendar data.

= Does the plugin phone home or track customers? =

No. There are no requests to third-party services and no analytics. The only data stored is your settings and the fail-safe state record (reason and timestamps only). The per-product calculation inputs endpoint answers using the visitor's own WooCommerce session (for shipping-zone rules) and stores nothing.

= Does it work with page caching or a CDN? =

Yes. Product pages contain no pre-calculated dates; the estimate is computed in the visitor's browser when the page is viewed, using a cacheable configuration document whose URL changes whenever settings change. Per-visitor inputs are fetched with a private, no-store request.

= Does it need JavaScript? =

On the storefront, yes: dates are calculated in the browser so they are correct at the moment of viewing. Without JavaScript, no dates are displayed (a short neutral note appears instead). wp-admin (settings preview and simulator) works without JavaScript.

= Who can change the settings? =

Anyone with the WooCommerce shop-management capability (Administrators and Shop Managers). Viewing and saving use the same capability.

= Can I set different preparation times per product? =

Not in the free version — one store-wide preparation time. Per-product and per-category preparation times are part of the Pro add-on.

= Does it work with HPOS (custom order tables)? =

Yes. The plugin declares HPOS compatibility, and it does not read or write order data at all.

== Screenshots ==

1. The estimate on a product page (UK calendar), with its basis line and cut-off countdown.
2. Settings under WooCommerce → Delivery Dates.
3. The simulator's day-by-day walk with holiday names.
4. The same product page with the Japanese national-holiday calendar.
5. The estimate placed on a regular page with the [soroban_delivery_estimate] shortcode.

== Changelog ==

= 1.1.2 =
* Renamed: the plugin is now "Soroban Delivery Dates for WooCommerce" (slug: soroban-delivery-dates), with all internal prefixes renamed to match. The plugin was never publicly released under the old name, so no settings migration is involved.
* Hardened: the calculation-breakdown HTML hook output is now passed through wp_kses_post() before display, and the admin preview is sanitised at the output point.
* Hardened: the per-product REST endpoint now answers only for products that are publicly viewable; draft, private, password-protected and catalog-hidden products return the same 404 as a missing product.

= 1.1.1 =
* Release preparation: re-verified against the current stable WordPress and WooCommerce releases (see the header's "Tested up to"). Minimum PHP raised to 8.0 — PHP 7.4 no longer receives security fixes from the PHP project (8.2 or later is recommended).
* A translation template (.pot) now ships in the /languages folder.

= 1.1.0 =
* Cache-safe storefront rendering: estimates are computed in the visitor's browser at view time (site timezone, DST-aware), so full-page caches and CDNs can no longer serve stale dates. Per-visitor shipping-zone inputs are resolved privately per session.
* Fixed: Shop Managers could open the settings page but not save (capability mismatch between the page and the Settings API registration). Viewing and saving now both use manage_woocommerce.
* Fixed: the fail-safe admin notice never cleared after recovery. Failures, ongoing problems and recoveries are now tracked as distinct states, with a daily health check.
* The simulator now uses the same calculation path as the storefront (including all Pro rules) and can test a specific product and destination zone; it shows the effective values and which rules matched.
* Settings page: 5-minute setup guide, status panel with calendar-coverage expiry warnings, live preview, and settings export/import.
* Verified PHP/JS calculation parity (18 scenarios, including DST weekends, cut-off boundaries in BST, Scotland carrier exceptions).

= 1.0.0 =
* Initial release.
* UK bank holidays (GOV.UK, three divisions) and Japanese national holidays (statutory rules), 2025–2028.
* Business-day preparation counting with order cut-off, carrier-day transit range, fail-safe hiding beyond calendar coverage.
* Admin simulator with day-by-day walk.

== Upgrade Notice ==

= 1.1.2 =
Renamed to Soroban Delivery Dates (new slug: soroban-delivery-dates), plus output-sanitisation and REST endpoint hardening.

= 1.1.1 =
Maintenance release: compatibility re-verification; the minimum PHP version is now 8.0.

= 1.1.0 =
Recommended update: fixes stale estimates behind page caches, a Shop Manager save failure, and a fail-safe notice that never cleared.
