<?php
/**
 * Plugin Name:       Soroban Delivery Dates for WooCommerce
 * Description:       Shows an estimated delivery date range on product pages, calculated from your business days, an official holiday calendar (UK bank holidays by division, or Japanese national holidays), your order cut-off time and preparation time. Dates are estimates, never guarantees.
 * Version:           1.1.3
 * Author:            Soroban Data Works
 * Text Domain:       soroban-delivery-dates
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package SorobanDeliveryDates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SOROBAN_DELIVERY_VERSION', '1.1.3' );
define( 'SOROBAN_DELIVERY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOROBAN_DELIVERY_PLUGIN_FILE', __FILE__ );

require_once SOROBAN_DELIVERY_PLUGIN_DIR . 'includes/class-soroban-delivery-sanitize.php';
require_once SOROBAN_DELIVERY_PLUGIN_DIR . 'includes/class-soroban-delivery-calendar.php';
require_once SOROBAN_DELIVERY_PLUGIN_DIR . 'includes/class-soroban-delivery-calculator.php';
require_once SOROBAN_DELIVERY_PLUGIN_DIR . 'includes/class-soroban-delivery-failsafe.php';
require_once SOROBAN_DELIVERY_PLUGIN_DIR . 'includes/class-soroban-delivery-estimator.php';
require_once SOROBAN_DELIVERY_PLUGIN_DIR . 'includes/class-soroban-delivery-frontend.php';
require_once SOROBAN_DELIVERY_PLUGIN_DIR . 'includes/class-soroban-delivery-rest.php';
require_once SOROBAN_DELIVERY_PLUGIN_DIR . 'includes/class-soroban-delivery-admin.php';

/**
 * Main plugin bootstrap: lifecycle hooks and settings access.
 */
class Soroban_Delivery_Plugin {

	const OPTION_SETTINGS = 'soroban_delivery_settings';

	/**
	 * Wire up hooks. Front-end hooks are registered only when
	 * WooCommerce is active; if it is not, estimates stay hidden and
	 * an admin notice explains why.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( 'Soroban_Delivery_REST', 'register_routes' ) );
		add_action( 'soroban_delivery_daily_health_check', array( __CLASS__, 'health_check' ) );
		if ( is_admin() ) {
			Soroban_Delivery_Admin::init();
		}
		if ( class_exists( 'WooCommerce' ) ) {
			Soroban_Delivery_Frontend::init();
		}
	}

	/**
	 * Load translations.
	 *
	 * Since WordPress 4.6, translations for plugins hosted on
	 * WordPress.org are loaded automatically (just-in-time) from the
	 * plugin's Domain Path, so no manual load_plugin_textdomain()
	 * call is needed here.
	 */
	public static function load_textdomain() {
		// Intentionally empty: see the note above.
	}

	/**
	 * Effective settings: administrator values merged over defaults
	 * and passed through the same sanitizer used on save, so a
	 * hand-edited option can never break the calculator.
	 *
	 * @return array
	 */
	public static function settings() {
		static $settings = null;
		if ( null === $settings ) {
			$raw = get_option( self::OPTION_SETTINGS, array() );
			$settings = Soroban_Delivery_Sanitize::sanitize_settings( is_array( $raw ) ? $raw : array() );
		}
		return $settings;
	}

	/**
	 * Activation: store default settings on first activation.
	 * Deletes nothing; safe to re-run.
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION_SETTINGS, false ) ) {
			add_option( self::OPTION_SETTINGS, Soroban_Delivery_Sanitize::defaults() );
		}
		if ( ! wp_next_scheduled( 'soroban_delivery_daily_health_check' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'soroban_delivery_daily_health_check' );
		}
	}

	/**
	 * Daily health check (WP-Cron): runs the server-side estimator
	 * once so the fail-safe state machine observes failures and
	 * recoveries even though storefront rendering is browser-side.
	 * Records state transitions only; sends no notifications.
	 */
	public static function health_check() {
		Soroban_Delivery_Estimator::build( null, array( 'record_failsafe' => true ) );
	}

	/**
	 * Deactivation: intentionally a no-op. Settings are kept so a
	 * re-activation restores the previous configuration; removal is
	 * handled by uninstall.php.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'soroban_delivery_daily_health_check' );
	}
}

register_activation_hook( __FILE__, array( 'Soroban_Delivery_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Soroban_Delivery_Plugin', 'deactivate' ) );

// HPOS (custom order tables) compatibility declaration. This plugin
// does not read or write orders, but the declaration keeps WooCommerce
// from flagging it as untested when HPOS is enabled.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', array( 'Soroban_Delivery_Plugin', 'init' ) );
