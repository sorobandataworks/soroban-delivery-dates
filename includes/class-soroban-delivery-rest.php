<?php
/**
 * REST endpoints feeding the browser-side estimate (audit item A).
 *
 * Two documents:
 *
 *  GET /wp-json/soroban-delivery/v1/config
 *      Store-level configuration: settings, the selected holiday
 *      calendar, the carrier closure map, the site timezone, display
 *      strings and Pro extras. IDENTICAL for every visitor, so it is
 *      explicitly cacheable; its URL carries a content hash
 *      (see Soroban_Delivery_Estimator::config_version()) and any change to the
 *      settings or data mints a new URL.
 *
 *  GET /wp-json/soroban-delivery/v1/product/{id}
 *      Per-product effective inputs (preparation / transit after the
 *      Pro product, category, stock and shipping-zone rules,
 *      visibility). The transit range depends on the VISITOR'S OWN
 *      WooCommerce session (their shipping destination), so this
 *      response is marked private/no-store and is never shared
 *      between visitors. It contains no personal data — only the
 *      rule outcomes for that session. Only publicly viewable
 *      products are answered for: anything else returns the same
 *      404 as a product that does not exist.
 *
 * @package SorobanDeliveryDates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soroban_Delivery_REST {

	const NAMESPACE = 'soroban-delivery/v1';

	/**
	 * Register routes (rest_api_init).
	 */
	public static function register_routes() {
		register_rest_route( self::NAMESPACE, '/config', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'config' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/product/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'product' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );
	}

	/**
	 * Store-level configuration document.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function config( WP_REST_Request $request ) {
		$settings = Soroban_Delivery_Plugin::settings();
		$inputs   = Soroban_Delivery_Estimator::resolve_inputs( null );

		$data = array(
			'version'     => Soroban_Delivery_Estimator::config_version(),
			'timezone'    => wp_timezone_string(),
			'date_format' => (string) get_option( 'date_format', 'F j, Y' ),
			'settings'    => array(
				'enabled'          => (int) $settings['enabled'],
				'label_text'       => $settings['label_text'],
				'cutoff_time'      => $settings['cutoff_time'],
				'prep_days'        => (int) $settings['prep_days'],
				'transit_min'      => (int) $settings['transit_min'],
				'transit_max'      => (int) $settings['transit_max'],
				'open_weekdays'    => array_values( $settings['open_weekdays'] ),
				'carrier_weekdays' => array_values( $settings['carrier_weekdays'] ),
				'custom_closed'    => array_values( $settings['custom_closed_dates'] ),
				'country'          => $settings['country'],
				'division'         => $settings['division'],
			),
			'available'   => null !== $inputs,
			'calendar'    => null,
			'extras'      => apply_filters( 'soroban_delivery_js_config', array() ),
			'strings'     => array(
				/* translators: 1: hours, 2: minutes, 3: cut-off time */
				'order_within'     => __( 'Order within %1$d hrs %2$d mins (cut-off %3$s). ', 'soroban-delivery-dates' ),
				/* translators: %s: UK division label, e.g. "England & Wales" */
				'based_on_uk'      => __( 'Based on UK bank holidays (%s). ', 'soroban-delivery-dates' ),
				'based_on_jp'      => __( 'Based on Japanese national holidays. ', 'soroban-delivery-dates' ),
				'based_on_location' => __( 'Based on your delivery location. ', 'soroban-delivery-dates' ),
				'not_guarantee'    => __( 'Estimate — not a guarantee.', 'soroban-delivery-dates' ),
			),
		);
		if ( null !== $inputs ) {
			$data['calendar'] = array(
				'country'          => $inputs['calendar']['country'],
				'label'            => $inputs['calendar']['label'],
				'coverage_end'     => $inputs['calendar']['coverage_end'],
				'holidays'         => $inputs['calendar']['holidays'],
				'carrier_holidays' => $inputs['carrier_holidays'],
			);
		}

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		$response->header( 'ETag', '"' . $data['version'] . '"' );
		return $response;
	}

	/**
	 * Per-product effective inputs for the current visitor session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function product( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		// This endpoint is public, so it must only ever answer for
		// products a visitor could already view on the storefront.
		// Anything else — a missing ID, a draft, a private or
		// password-protected product, a catalog-hidden product —
		// returns the SAME 404, so the endpoint cannot be used to
		// probe the existence or stock state of non-public products.
		if ( ! $product instanceof WC_Product || ! self::is_publicly_viewable( $product ) ) {
			return new WP_Error( 'soroban_delivery_product_not_found', 'Product not found.', array( 'status' => 404 ) );
		}
		self::bootstrap_wc_session();
		$inputs = Soroban_Delivery_Estimator::resolve_inputs( $product );
		if ( null === $inputs ) {
			return new WP_Error( 'soroban_delivery_calendar_missing', 'Calendar data is unavailable.', array( 'status' => 503 ) );
		}
		$settings = $inputs['settings'];
		$zone_applied = ( $inputs['transit_min'] !== (int) $settings['transit_min']
			|| $inputs['transit_max'] !== (int) $settings['transit_max'] )
			&& (bool) apply_filters( 'soroban_delivery_zone_adjustment_applied', false, $product );

		$data = array(
			'product_id'    => $id,
			'visible'       => (bool) $inputs['visible'],
			'prep_days'     => $inputs['prep_days'],
			'transit_min'   => $inputs['transit_min'],
			'transit_max'   => $inputs['transit_max'],
			'zone_applied'  => $zone_applied,
			'stock_status'  => $product->get_stock_status(),
		);
		// Include the product-specific carrier map only when it
		// differs from the store-level one in the config document.
		$store_inputs = Soroban_Delivery_Estimator::resolve_inputs( null );
		if ( null !== $store_inputs && $inputs['carrier_holidays'] !== $store_inputs['carrier_holidays'] ) {
			$data['carrier_holidays'] = $inputs['carrier_holidays'];
		}

		$response = new WP_REST_Response( $data, 200 );
		// Session-dependent: never store, never share between visitors.
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}

	/**
	 * Whether a product may be shown to any storefront visitor:
	 * published, not password-protected, and not hidden from the
	 * catalog. WC_Product::is_visible() (when present) additionally
	 * applies WooCommerce's own rules, e.g. parent-product checks
	 * for variations.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private static function is_publicly_viewable( WC_Product $product ) {
		if ( 'publish' !== $product->get_status() ) {
			return false;
		}
		$post = get_post( $product->get_id() );
		if ( ! $post || '' !== (string) $post->post_password ) {
			return false;
		}
		if ( 'hidden' === $product->get_catalog_visibility() ) {
			return false;
		}
		if ( method_exists( $product, 'is_visible' ) && ! $product->is_visible() ) {
			return false;
		}
		return true;
	}

	/**
	 * Load the visitor's WooCommerce session and customer.
	 *
	 * WooCommerce initialises its frontend session/customer only for
	 * its own requests; inside a third-party REST namespace both are
	 * null even when the visitor's session cookie is present, which
	 * would silently disable every session-based rule (shipping-zone
	 * transit). This performs the same bootstrap WooCommerce itself
	 * performs for frontend requests — session handler from the
	 * visitor's cookie first, then the customer object (which reads
	 * a guest's location from that session). Read-only for our
	 * purposes: nothing is written back.
	 */
	private static function bootstrap_wc_session() {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return;
		}
		$wc = WC();
		try {
			$created = false;
			if ( null === $wc->session && class_exists( 'WC_Session_Handler' ) ) {
				$handler = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
				if ( is_string( $handler ) && class_exists( $handler ) ) {
					$wc->session = new $handler();
					$created = true;
				}
			}
			// has_session() only reports that a session COOKIE exists;
			// a handler we just created has not loaded its data yet,
			// so init() must run unconditionally for it (and whenever
			// an existing handler has no customer id resolved).
			if ( $wc->session instanceof WC_Session_Handler
				&& ( $created || '' === (string) $wc->session->get_customer_id() ) ) {
				$wc->session->init();
			}
			if ( null === $wc->customer && class_exists( 'WC_Customer' ) ) {
				$wc->customer = new WC_Customer( get_current_user_id(), true );
			}
		} catch ( Exception $e ) {
			// A failed bootstrap must never break the request: the
			// estimator simply resolves without session-based rules.
			return;
		}
	}
}
