<?php
/**
 * Front-end display: product-page estimate and [soroban_delivery_estimate]
 * shortcode.
 *
 * CACHE-SAFE RENDERING (audit item A): the HTML served for a product
 * page contains NO dates. It contains an empty container; a browser
 * script computes the estimate at view time from the store
 * configuration (cacheable JSON) plus per-product inputs (private,
 * never cached), converting "now" into the site timezone with the
 * Intl API. Cached or CDN-served pages therefore always show dates
 * for the moment of VIEWING — correct after the cut-off, after
 * midnight and across DST changes. Without JavaScript no dates are
 * shown at all (a neutral notice only), so a wrong date can never
 * appear. Per-visitor zone rules are resolved for the visitor's own
 * session only and are never written into shared cache.
 *
 * The server-side render_static() remains for wp-admin previews
 * (uncached context) and Soroban_Delivery_Frontend::build() remains as the
 * server-side estimate used by the daily health check.
 *
 * @package SorobanDeliveryDates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soroban_Delivery_Frontend {

	const OPTION_FAILSAFE = 'soroban_delivery_failsafe_state';

	/**
	 * Register front-end hooks. Called only when WooCommerce is active.
	 */
	public static function init() {
		$settings = Soroban_Delivery_Plugin::settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		$priorities = array(
			'after_title'   => 6,
			'after_price'   => 11,
			'after_excerpt' => 21,
		);
		$priority = isset( $priorities[ $settings['position'] ] ) ? $priorities[ $settings['position'] ] : 11;
		/**
		 * Filter the priority on woocommerce_single_product_summary
		 * at which the estimate is printed.
		 *
		 * @param int $priority Hook priority.
		 */
		$priority = (int) apply_filters( 'soroban_delivery_display_priority', $priority );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'product_estimate' ), $priority );
		add_shortcode( 'soroban_delivery_estimate', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the browser engine on pages that can show an estimate
	 * (product pages, and singular content using the shortcode).
	 */
	public static function enqueue() {
		$needed = false;
		if ( function_exists( 'is_product' ) && is_product() ) {
			$needed = true;
		} elseif ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'soroban_delivery_estimate' ) ) {
				$needed = true;
			}
		}
		if ( ! $needed ) {
			return;
		}
		$base = plugin_dir_url( SOROBAN_DELIVERY_PLUGIN_FILE );
		wp_enqueue_style( 'soroban-delivery-frontend', $base . 'assets/css/soroban-delivery-frontend.css', array(), SOROBAN_DELIVERY_VERSION );
		wp_enqueue_script( 'soroban-delivery-engine', $base . 'assets/js/soroban-delivery-engine.js', array(), SOROBAN_DELIVERY_VERSION, true );
		wp_enqueue_script( 'soroban-delivery-frontend', $base . 'assets/js/soroban-delivery-frontend.js', array( 'soroban-delivery-engine' ), SOROBAN_DELIVERY_VERSION, true );
		wp_localize_script( 'soroban-delivery-frontend', 'SOROBAN_DELIVERY_FRONTEND', array(
			'configUrl'  => add_query_arg( 'v', Soroban_Delivery_Estimator::config_version(), rest_url( 'soroban-delivery/v1/config' ) ),
			'productUrl' => rest_url( 'soroban-delivery/v1/product/' ),
		) );
	}

	/**
	 * Hook callback: print the estimate container on the product page.
	 */
	public static function product_estimate() {
		global $product;
		$p = ( isset( $product ) && $product instanceof WC_Product ) ? $product : null;
		echo self::render( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every dynamic value.
	}

	/**
	 * Shortcode callback: [soroban_delivery_estimate]
	 *
	 * @param array $atts Shortcode attributes (unused).
	 * @return string Container HTML (dates are filled in by the browser script).
	 */
	public static function shortcode( $atts ) {
		global $product;
		$p = ( isset( $product ) && $product instanceof WC_Product ) ? $product : null;
		return self::render( $p );
	}

	/**
	 * The cache-safe container. Contains no dates — only the product
	 * reference the browser script needs.
	 *
	 * @param WC_Product|null $product Current product, when applicable.
	 * @return string HTML.
	 */
	public static function render( $product = null ) {
		$id = $product instanceof WC_Product ? $product->get_id() : 0;
		return '<div class="soroban-delivery-estimate" data-soroban-delivery-estimate data-product-id="' . esc_attr( $id ) . '">'
			. '<noscript><p class="soroban-delivery-estimate-noscript">'
			. esc_html__( 'Delivery estimates are calculated when JavaScript is enabled.', 'soroban-delivery-dates' )
			. '</p></noscript></div>';
	}

	/**
	 * Server-side estimate build (health check / BC). Records the
	 * fail-safe state transitions.
	 *
	 * @param WC_Product|null $product Current product, when applicable.
	 * @return array|null Estimator result (see Soroban_Delivery_Estimator::build()).
	 */
	public static function build( $product = null ) {
		return Soroban_Delivery_Estimator::build( $product, array( 'record_failsafe' => true ) );
	}

	/**
	 * Record a fail-safe event (BC shim; the state machine lives in
	 * Soroban_Delivery_Failsafe).
	 *
	 * @param string $reason Failure reason code.
	 */
	public static function record_failsafe( $reason ) {
		Soroban_Delivery_Failsafe::record_failure( $reason );
	}

	/**
	 * Server-side static render of the estimate block, fully escaped.
	 * Used by the wp-admin settings preview, where the page is never
	 * cached and "now" is the moment of viewing.
	 *
	 * @param WC_Product|null $product Product context, when applicable.
	 * @return string HTML, or '' when the fail-safe hides the estimate.
	 */
	public static function render_static( $product = null ) {
		$built = Soroban_Delivery_Estimator::build( $product );
		if ( null === $built ) {
			return '';
		}
		$estimate = $built['estimate'];
		$settings = $built['inputs']['settings'];
		$calendar = $built['inputs']['calendar'];

		$format   = get_option( 'date_format' );
		$date_min = date_i18n( $format, strtotime( $estimate['arrival_min'] ) );
		$date_max = date_i18n( $format, strtotime( $estimate['arrival_max'] ) );
		$dispatch = date_i18n( $format, strtotime( $estimate['dispatch'] ) );
		$range    = ( $estimate['arrival_min'] === $estimate['arrival_max'] )
			? $date_min
			: $date_min . ' – ' . $date_max;

		/**
		 * Filter a custom display template. Placeholders: {label},
		 * {range}, {date_min}, {date_max}, {dispatch}, {cutoff}.
		 * Return null to use the default layout.
		 *
		 * @param string|null $template Template or null.
		 * @param array       $estimate Calculator result.
		 * @param array       $settings Effective settings.
		 */
		$template = apply_filters( 'soroban_delivery_estimate_template', null, $estimate, $settings );
		if ( is_string( $template ) && '' !== $template ) {
			$text = strtr( $template, array(
				'{label}'    => $settings['label_text'],
				'{range}'    => $range,
				'{date_min}' => $date_min,
				'{date_max}' => $date_max,
				'{dispatch}' => $dispatch,
				'{cutoff}'   => $settings['cutoff_time'],
			) );
			return '<div class="soroban-delivery-estimate"><p class="soroban-delivery-estimate-text">' . esc_html( $text ) . '</p></div>';
		}

		$basis = '';
		if ( null !== $built['countdown_minutes'] ) {
			$hours = (int) floor( $built['countdown_minutes'] / 60 );
			$mins  = (int) ( $built['countdown_minutes'] % 60 );
			$basis .= sprintf(
				/* translators: 1: hours, 2: minutes, 3: cut-off time */
				__( 'Order within %1$d hrs %2$d mins (cut-off %3$s). ', 'soroban-delivery-dates' ),
				$hours,
				$mins,
				esc_html( $settings['cutoff_time'] )
			);
		}
		if ( 'UK' === $calendar['country'] ) {
			$basis .= sprintf(
				/* translators: %s: UK division label, e.g. "England & Wales" */
				__( 'Based on UK bank holidays (%s). ', 'soroban-delivery-dates' ),
				esc_html( $calendar['label'] )
			);
		} else {
			$basis .= __( 'Based on Japanese national holidays. ', 'soroban-delivery-dates' );
		}
		$basis .= __( 'Estimate — not a guarantee.', 'soroban-delivery-dates' );

		/**
		 * Filter extra HTML rendered under the estimate (the Pro
		 * add-on uses this for the itemised calculation breakdown).
		 * The returned HTML is passed through wp_kses_post() before
		 * it is printed, so filter callbacks must stay within
		 * post-level HTML.
		 *
		 * @param string $html     Default empty.
		 * @param array  $estimate Calculator result.
		 * @param array  $settings Effective settings.
		 */
		$breakdown = (string) apply_filters( 'soroban_delivery_breakdown_html', '', $estimate, $settings );

		$html  = '<div class="soroban-delivery-estimate">';
		$html .= '<p class="soroban-delivery-estimate-dates"><span class="soroban-delivery-estimate-label">'
			. esc_html( $settings['label_text'] ) . '</span> <strong>'
			. esc_html( $range ) . '</strong></p>';
		$html .= '<p class="soroban-delivery-estimate-basis">' . esc_html( $basis ) . '</p>';
		// Breakdown HTML comes from a filter (third-party code): it is
		// sanitised with wp_kses_post() at this final assembly point,
		// never printed raw.
		$html .= wp_kses_post( $breakdown );
		$html .= '</div>';
		return $html;
	}
}
