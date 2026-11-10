<?php
/**
 * Uninstall cleanup for Soroban Delivery Dates.
 *
 * Removes only this plugin's own options. Uninstalling deletes the
 * saved settings and any recorded fail-safe notice; nothing else in
 * the database belongs to this plugin (it stores no personal data,
 * no order data and creates no tables).
 *
 * @package SorobanDeliveryDates
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'soroban_delivery_settings' );
delete_option( 'soroban_delivery_failsafe_notice' );
delete_option( 'soroban_delivery_failsafe_state' );
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'soroban_delivery_daily_health_check' );
}
