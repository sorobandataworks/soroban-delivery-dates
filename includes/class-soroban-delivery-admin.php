<?php
/**
 * Admin: settings page (under the WooCommerce menu), the delivery
 * date simulator (under Tools), admin notices, settings
 * export/import and the settings health panel.
 *
 * Capability rule (audit item B): everything in this plugin that
 * shows or saves shop settings requires ONE capability —
 * manage_woocommerce — end to end: menu, page render, the
 * option_page_capability_* filter that governs options.php (core
 * has no register_setting() capability argument — passing one is
 * silently ignored, staging-verified on WP 7.1) and the
 * export/import handlers. Administrators hold it via the role
 * hierarchy; Shop Managers hold it natively; everyone else is
 * rejected everywhere. (In 1.0.0 nothing aligned the saving
 * capability, so options.php demanded manage_options and Shop
 * Managers could open the page but never save.)
 *
 * The simulator runs the SAME estimator service as the storefront
 * (audit item D), so Pro rules — per-product/category preparation,
 * zone adjustments, the carrier layer, stock rules — are reflected
 * exactly, and the effective values are shown next to the defaults.
 *
 * @package SorobanDeliveryDates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soroban_Delivery_Admin {

	const SETTINGS_PAGE = 'soroban-delivery-settings';
	const SIMULATOR_PAGE = 'soroban-delivery-simulator';
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menus' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'option_page_capability_soroban_delivery_settings_group', array( __CLASS__, 'settings_page_capability' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_soroban_delivery_export_settings', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_soroban_delivery_import_settings', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * The capability options.php requires to save this settings
	 * group (audit item B).
	 *
	 * WordPress core has NO 'capability' argument for
	 * register_setting() — passing one is silently ignored and
	 * options.php keeps demanding manage_options, which locks Shop
	 * Managers out of saving (found by the staging matrix on
	 * WP 7.1, 2026-11-04). The core-sanctioned mechanism is this
	 * per-group filter, applied by options.php before its check.
	 *
	 * @return string
	 */
	public static function settings_page_capability() {
		return self::CAPABILITY;
	}

	/**
	 * Add the settings submenu (WooCommerce) and simulator (Tools).
	 */
	public static function menus() {
		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				__( 'Delivery Dates', 'soroban-delivery-dates' ),
				__( 'Delivery Dates', 'soroban-delivery-dates' ),
				self::CAPABILITY,
				self::SETTINGS_PAGE,
				array( __CLASS__, 'render_settings_page' )
			);
		}
		add_management_page(
			__( 'Delivery Date Simulator', 'soroban-delivery-dates' ),
			__( 'Delivery Date Simulator', 'soroban-delivery-dates' ),
			self::CAPABILITY,
			self::SIMULATOR_PAGE,
			array( __CLASS__, 'render_simulator_page' )
		);
	}

	/**
	 * Register the settings option with the pure-PHP sanitizer as
	 * the sanitize callback. The capability that governs SAVING is
	 * aligned with the page through the option_page_capability_*
	 * filter above (audit item B) — register_setting() itself has
	 * no capability argument in WordPress core.
	 */
	public static function register_settings() {
		register_setting(
			'soroban_delivery_settings_group',
			Soroban_Delivery_Plugin::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Soroban_Delivery_Sanitize', 'sanitize_settings' ),
				'default'           => Soroban_Delivery_Sanitize::defaults(),
			)
		);
	}

	/**
	 * Admin notices: missing WooCommerce, and the fail-safe state
	 * machine (failing vs recovered — audit item C).
	 */
	public static function notices() {
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Soroban Delivery Dates: WooCommerce is not active, so delivery estimates are hidden.', 'soroban-delivery-dates' )
				. '</p></div>';
		}
		if ( ! class_exists( 'Soroban_Delivery_Failsafe' ) ) {
			return;
		}
		$state = Soroban_Delivery_Failsafe::state();
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		if ( 'failing' === $state['state'] ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html( sprintf(
					/* translators: 1: failure reason code, 2: date/time the failure began, 3: number of recorded failures */
					__( 'Soroban Delivery Dates: delivery estimates are currently HIDDEN (fail-safe: %1$s, since %2$s, %3$d occurrence(s)). Check the calendar coverage on the Delivery Dates settings page.', 'soroban-delivery-dates' ),
					$state['reason'],
					$state['first_seen'] ? date_i18n( $fmt, (int) $state['first_seen'] ) : '',
					(int) $state['occurrences']
				) )
				. '</p></div>';
		} elseif ( 'recovered' === $state['state'] && (int) $state['recovered_at'] > time() - 7 * DAY_IN_SECONDS ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( sprintf(
					/* translators: %s: date/time of recovery */
					__( 'Soroban Delivery Dates: estimates recovered at %s — the fail-safe condition has cleared and dates are being shown again.', 'soroban-delivery-dates' ),
					date_i18n( $fmt, (int) $state['recovered_at'] )
				) )
				. '</p></div>';
		}
	}

	/* ---------------------------------------------------------------
	 * Export / import
	 * ------------------------------------------------------------- */

	/**
	 * Export handler (admin-post): download the free (+ Pro, when
	 * present) settings as JSON. Nonce + capability protected.
	 */
	public static function handle_export() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export these settings.', 'soroban-delivery-dates' ) );
		}
		check_admin_referer( 'soroban_delivery_export_settings' );
		$data = array(
			'plugin'       => 'soroban-delivery-dates',
			'version'      => SOROBAN_DELIVERY_VERSION,
			'exported_at'  => gmdate( 'c' ),
			'free'         => Soroban_Delivery_Plugin::settings(),
			'pro'          => null,
		);
		if ( class_exists( 'Soroban_Delivery_Pro' ) ) {
			$data['pro'] = Soroban_Delivery_Pro::settings();
		}
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT );
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="soroban-delivery-settings-' . gmdate( 'Ymd-His' ) . '.json"' );
		header( 'Content-Length: ' . strlen( (string) $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, values were sanitised on save.
		exit;
	}

	/**
	 * Import handler (admin-post): read an exported JSON file,
	 * re-sanitise every value through the normal sanitizers, and
	 * store. Nonce + capability protected; a malformed file changes
	 * nothing.
	 */
	public static function handle_import() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to import these settings.', 'soroban-delivery-dates' ) );
		}
		check_admin_referer( 'soroban_delivery_import_settings' );
		$back = admin_url( 'admin.php?page=' . self::SETTINGS_PAGE );
		if ( ! isset( $_FILES['soroban_delivery_import_file'] ) || ! isset( $_FILES['soroban_delivery_import_file']['tmp_name'] )
			|| ! is_uploaded_file( sanitize_text_field( wp_unslash( $_FILES['soroban_delivery_import_file']['tmp_name'] ) ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is only passed to is_uploaded_file/file reads.
			wp_safe_redirect( add_query_arg( 'soroban_delivery_msg', 'import_no_file', $back ) );
			exit;
		}
		$raw = file_get_contents( sanitize_text_field( wp_unslash( $_FILES['soroban_delivery_import_file']['tmp_name'] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded temp file, admin-only.
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['free'] ) || ! is_array( $data['free'] ) ) {
			wp_safe_redirect( add_query_arg( 'soroban_delivery_msg', 'import_invalid', $back ) );
			exit;
		}
		update_option( Soroban_Delivery_Plugin::OPTION_SETTINGS, Soroban_Delivery_Sanitize::sanitize_settings( $data['free'] ) );
		if ( isset( $data['pro'] ) && is_array( $data['pro'] ) && class_exists( 'Soroban_Delivery_Pro' ) ) {
			update_option( Soroban_Delivery_Pro::OPTION_SETTINGS, Soroban_Delivery_Pro::sanitize_settings( $data['pro'] ) );
		}
		wp_safe_redirect( add_query_arg( 'soroban_delivery_msg', 'imported', $back ) );
		exit;
	}

	/* ---------------------------------------------------------------
	 * Settings page
	 * ------------------------------------------------------------- */

	/**
	 * Render the settings page: setup guide, health panel, the
	 * settings form, a live preview, and export/import.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'soroban-delivery-dates' ) );
		}
		wp_enqueue_style( 'soroban-delivery-frontend', plugin_dir_url( SOROBAN_DELIVERY_PLUGIN_FILE ) . 'assets/css/soroban-delivery-frontend.css', array(), SOROBAN_DELIVERY_VERSION );
		$s = Soroban_Delivery_Plugin::settings();
		$weekdays = array(
			1 => __( 'Mon', 'soroban-delivery-dates' ),
			2 => __( 'Tue', 'soroban-delivery-dates' ),
			3 => __( 'Wed', 'soroban-delivery-dates' ),
			4 => __( 'Thu', 'soroban-delivery-dates' ),
			5 => __( 'Fri', 'soroban-delivery-dates' ),
			6 => __( 'Sat', 'soroban-delivery-dates' ),
			7 => __( 'Sun', 'soroban-delivery-dates' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Soroban Delivery Dates — Settings', 'soroban-delivery-dates' ); ?></h1>
			<?php self::render_import_message(); ?>
			<div class="notice notice-info inline" style="margin:12px 0;">
				<p><strong><?php echo esc_html__( 'Set up in about 5 minutes:', 'soroban-delivery-dates' ); ?></strong></p>
				<ol style="margin-left:20px;">
					<li><?php echo esc_html__( 'Pick the holiday calendar for where your orders are prepared (UK region, or Japan).', 'soroban-delivery-dates' ); ?></li>
					<li><?php echo esc_html__( 'Tick the days your store is open, and add any extra closed dates (stocktaking, local holidays).', 'soroban-delivery-dates' ); ?></li>
					<li><?php echo esc_html__( 'Set the order cut-off time and how many business days you need to prepare an order.', 'soroban-delivery-dates' ); ?></li>
					<li><?php echo esc_html__( 'Set the carrier transit range (fastest–slowest, in carrier operating days).', 'soroban-delivery-dates' ); ?></li>
					<li><?php echo esc_html__( 'Save, then open a product page: the estimated delivery range appears under the price. Test edge cases any time under Tools → Delivery Date Simulator.', 'soroban-delivery-dates' ); ?></li>
				</ol>
				<p><?php echo esc_html__( 'Dates are always labelled as estimates — never a guarantee. If anything about the calendar data goes wrong, the estimate hides itself instead of showing a wrong date.', 'soroban-delivery-dates' ); ?></p>
			</div>

			<?php self::render_health_panel( $s ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'soroban_delivery_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Show estimates', 'soroban-delivery-dates' ); ?></th>
						<td><label><input type="checkbox" name="soroban_delivery_settings[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?> />
							<?php echo esc_html__( 'Display estimated delivery dates on product pages', 'soroban-delivery-dates' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Holiday calendar', 'soroban-delivery-dates' ); ?></th>
						<td>
							<select name="soroban_delivery_settings[country]">
								<option value="UK" <?php selected( $s['country'], 'UK' ); ?>><?php echo esc_html__( 'United Kingdom (GOV.UK bank holidays)', 'soroban-delivery-dates' ); ?></option>
								<option value="JP" <?php selected( $s['country'], 'JP' ); ?>><?php echo esc_html__( 'Japan (national holidays)', 'soroban-delivery-dates' ); ?></option>
							</select>
							<select name="soroban_delivery_settings[division]">
								<option value="england-and-wales" <?php selected( $s['division'], 'england-and-wales' ); ?>>England &amp; Wales</option>
								<option value="scotland" <?php selected( $s['division'], 'scotland' ); ?>>Scotland</option>
								<option value="northern-ireland" <?php selected( $s['division'], 'northern-ireland' ); ?>>Northern Ireland</option>
							</select>
							<p class="description"><?php echo esc_html__( 'The division applies to the UK calendar only.', 'soroban-delivery-dates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Store open days', 'soroban-delivery-dates' ); ?></th>
						<td>
							<?php foreach ( $weekdays as $n => $label ) : ?>
								<label style="margin-right:12px;"><input type="checkbox" name="soroban_delivery_settings[open_weekdays][]" value="<?php echo esc_attr( $n ); ?>" <?php checked( in_array( $n, $s['open_weekdays'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Custom closed dates', 'soroban-delivery-dates' ); ?></th>
						<td>
							<textarea name="soroban_delivery_settings[custom_closed_dates]" rows="4" cols="30" class="regular-text code"><?php echo esc_textarea( implode( "\n", $s['custom_closed_dates'] ) ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'One date per line, format YYYY-MM-DD (e.g. stocktaking days, local closures). Invalid lines are ignored.', 'soroban-delivery-dates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Order cut-off time', 'soroban-delivery-dates' ); ?></th>
						<td>
							<input type="time" name="soroban_delivery_settings[cutoff_time]" value="<?php echo esc_attr( $s['cutoff_time'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Site timezone. Orders at or after this time start preparation on the next business day.', 'soroban-delivery-dates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Preparation time', 'soroban-delivery-dates' ); ?></th>
						<td>
							<input type="number" name="soroban_delivery_settings[prep_days]" value="<?php echo esc_attr( $s['prep_days'] ); ?>" min="0" max="60" step="1" class="small-text" />
							<?php echo esc_html__( 'business days', 'soroban-delivery-dates' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Transit time', 'soroban-delivery-dates' ); ?></th>
						<td>
							<input type="number" name="soroban_delivery_settings[transit_min]" value="<?php echo esc_attr( $s['transit_min'] ); ?>" min="0" max="60" step="1" class="small-text" /> –
							<input type="number" name="soroban_delivery_settings[transit_max]" value="<?php echo esc_attr( $s['transit_max'] ); ?>" min="0" max="90" step="1" class="small-text" />
							<?php echo esc_html__( 'carrier days (minimum – maximum)', 'soroban-delivery-dates' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Carrier operating days', 'soroban-delivery-dates' ); ?></th>
						<td>
							<?php foreach ( $weekdays as $n => $label ) : ?>
								<label style="margin-right:12px;"><input type="checkbox" name="soroban_delivery_settings[carrier_weekdays][]" value="<?php echo esc_attr( $n ); ?>" <?php checked( in_array( $n, $s['carrier_weekdays'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Display', 'soroban-delivery-dates' ); ?></th>
						<td>
							<input type="text" name="soroban_delivery_settings[label_text]" value="<?php echo esc_attr( $s['label_text'] ); ?>" class="regular-text" />
							<select name="soroban_delivery_settings[position]">
								<option value="after_title" <?php selected( $s['position'], 'after_title' ); ?>><?php echo esc_html__( 'After the product title', 'soroban-delivery-dates' ); ?></option>
								<option value="after_price" <?php selected( $s['position'], 'after_price' ); ?>><?php echo esc_html__( 'After the price', 'soroban-delivery-dates' ); ?></option>
								<option value="after_excerpt" <?php selected( $s['position'], 'after_excerpt' ); ?>><?php echo esc_html__( 'After the short description', 'soroban-delivery-dates' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Preview (right now, store defaults)', 'soroban-delivery-dates' ); ?></h2>
			<div style="border:1px solid #ccd0d4;background:#fff;padding:12px 16px;max-width:640px;">
				<?php
				$preview = Soroban_Delivery_Frontend::render_static( null );
				if ( '' === $preview ) {
					$preview = '<p>' . esc_html__( 'No estimate would be shown right now (see the status panel above).', 'soroban-delivery-dates' ) . '</p>';
				}
				// render_static() escapes its own dynamic values, but the
				// assembled markup is still passed through wp_kses_post()
				// here, at the final output point, so nothing a filter
				// contributed can reach the page unsanitised.
				echo wp_kses_post( $preview );
				?>
			</div>
			<p class="description" style="max-width:640px;"><?php echo esc_html__( 'This preview is calculated for the current moment. On the storefront the same calculation runs in the visitor’s browser at view time, so cached pages stay correct.', 'soroban-delivery-dates' ); ?></p>

			<h2><?php echo esc_html__( 'Export / import settings', 'soroban-delivery-dates' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:16px;">
				<?php wp_nonce_field( 'soroban_delivery_export_settings' ); ?>
				<input type="hidden" name="action" value="soroban_delivery_export_settings" />
				<?php submit_button( __( 'Export settings (JSON)', 'soroban-delivery-dates' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="display:inline-block;">
				<?php wp_nonce_field( 'soroban_delivery_import_settings' ); ?>
				<input type="hidden" name="action" value="soroban_delivery_import_settings" />
				<input type="file" name="soroban_delivery_import_file" accept=".json,application/json" required />
				<?php submit_button( __( 'Import settings', 'soroban-delivery-dates' ), 'secondary', 'submit', false ); ?>
			</form>
			<p class="description"><?php echo esc_html__( 'Import re-validates every value with the same rules as saving by hand. Pro settings are included when the Pro add-on is active.', 'soroban-delivery-dates' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Import result message, when returning from the import handler.
	 */
	private static function render_import_message() {
		if ( ! isset( $_GET['soroban_delivery_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
			return;
		}
		$msg = sanitize_text_field( wp_unslash( $_GET['soroban_delivery_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		if ( 'imported' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings imported.', 'soroban-delivery-dates' ) . '</p></div>';
		} elseif ( 'import_invalid' === $msg ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Import failed: the file is not a valid settings export. Nothing was changed.', 'soroban-delivery-dates' ) . '</p></div>';
		} elseif ( 'import_no_file' === $msg ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Import failed: no file was uploaded. Nothing was changed.', 'soroban-delivery-dates' ) . '</p></div>';
		}
	}

	/**
	 * Settings health panel: calendar coverage with expiry warnings,
	 * WooCommerce presence, and the fail-safe state.
	 *
	 * @param array $s Effective settings.
	 */
	private static function render_health_panel( array $s ) {
		$calendar = ( new Soroban_Delivery_Calendar( SOROBAN_DELIVERY_PLUGIN_DIR . 'data' ) )
			->get_calendar( $s['country'], $s['division'] );
		?>
		<div style="border:1px solid #ccd0d4;background:#fff;padding:12px 16px;max-width:860px;margin:12px 0;">
			<h2 style="margin-top:0;"><?php echo esc_html__( 'Status', 'soroban-delivery-dates' ); ?></h2>
			<table class="widefat striped" style="border:none;">
				<tbody>
					<tr>
						<td style="width:220px;"><strong><?php echo esc_html__( 'WooCommerce', 'soroban-delivery-dates' ); ?></strong></td>
						<td><?php echo class_exists( 'WooCommerce' ) ? esc_html__( 'Active', 'soroban-delivery-dates' ) : esc_html__( 'NOT active — estimates are hidden.', 'soroban-delivery-dates' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php echo esc_html__( 'Holiday calendar', 'soroban-delivery-dates' ); ?></strong></td>
						<td>
						<?php
						if ( null === $calendar ) {
							echo esc_html__( 'Could not be loaded — estimates are hidden (fail-safe).', 'soroban-delivery-dates' );
						} else {
							$today = current_datetime()->format( 'Y-m-d' );
							$days_left = (int) floor( ( strtotime( $calendar['coverage_end'] ) - strtotime( $today ) ) / DAY_IN_SECONDS );
							$line = sprintf(
								/* translators: 1: calendar label, 2: coverage end date, 3: days remaining */
								__( '%1$s — data covers until %2$s (%3$d days from today). Data is bundled with the plugin and refreshed by plugin updates; nothing is fetched at runtime.', 'soroban-delivery-dates' ),
								$calendar['label'],
								date_i18n( get_option( 'date_format' ), strtotime( $calendar['coverage_end'] ) ),
								max( 0, $days_left )
							);
							echo esc_html( $line );
							if ( $days_left < 0 ) {
								echo '<br /><strong style="color:#b32d2e;">' . esc_html__( 'Coverage has ENDED: estimates are hidden until the plugin is updated.', 'soroban-delivery-dates' ) . '</strong>';
							} elseif ( $days_left <= 30 ) {
								echo '<br /><strong style="color:#b32d2e;">' . esc_html__( 'Coverage ends within 30 days — please update the plugin soon.', 'soroban-delivery-dates' ) . '</strong>';
							} elseif ( $days_left <= 90 ) {
								echo '<br /><span style="color:#996800;">' . esc_html__( 'Coverage ends within 90 days — plan a plugin update.', 'soroban-delivery-dates' ) . '</span>';
							}
						}
						?>
						</td>
					</tr>
					<tr>
						<td><strong><?php echo esc_html__( 'Estimate display', 'soroban-delivery-dates' ); ?></strong></td>
						<td>
						<?php
						$state = Soroban_Delivery_Failsafe::state();
						if ( 'failing' === $state['state'] ) {
							echo esc_html( sprintf(
								/* translators: %s: failure reason code */
								__( 'Hidden by the fail-safe (%s). See the notice at the top of wp-admin.', 'soroban-delivery-dates' ),
								$state['reason']
							) );
						} elseif ( 'recovered' === $state['state'] ) {
							echo esc_html( sprintf(
								/* translators: %s: date/time of recovery */
								__( 'Working (recovered at %s).', 'soroban-delivery-dates' ),
								date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['recovered_at'] )
							) );
						} else {
							echo esc_html__( 'Working. The daily health check last confirmed a computable estimate.', 'soroban-delivery-dates' );
						}
						?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Simulator
	 * ------------------------------------------------------------- */

	/**
	 * Render the simulator page (Tools menu). Runs the shared
	 * estimator with an arbitrary order moment, an optional product
	 * and (with Pro) an optional destination zone, and shows the
	 * effective values and the day-by-day walk.
	 */
	public static function render_simulator_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'soroban-delivery-dates' ) );
		}
		$s = Soroban_Delivery_Plugin::settings();
		$pro_active = class_exists( 'Soroban_Delivery_Pro' );

		$input_date = isset( $_POST['soroban_delivery_sim_date'] ) ? sanitize_text_field( wp_unslash( $_POST['soroban_delivery_sim_date'] ) ) : current_datetime()->format( 'Y-m-d' );
		$input_time = isset( $_POST['soroban_delivery_sim_time'] ) ? sanitize_text_field( wp_unslash( $_POST['soroban_delivery_sim_time'] ) ) : current_datetime()->format( 'H:i' );
		$input_product = isset( $_POST['soroban_delivery_sim_product'] ) ? (int) $_POST['soroban_delivery_sim_product'] : 0;
		$input_zone = isset( $_POST['soroban_delivery_sim_zone'] ) ? sanitize_text_field( wp_unslash( $_POST['soroban_delivery_sim_zone'] ) ) : '';
		$input_prep = isset( $_POST['soroban_delivery_sim_prep'] ) ? sanitize_text_field( wp_unslash( $_POST['soroban_delivery_sim_prep'] ) ) : '';
		$input_tmin = isset( $_POST['soroban_delivery_sim_tmin'] ) ? sanitize_text_field( wp_unslash( $_POST['soroban_delivery_sim_tmin'] ) ) : '';
		$input_tmax = isset( $_POST['soroban_delivery_sim_tmax'] ) ? sanitize_text_field( wp_unslash( $_POST['soroban_delivery_sim_tmax'] ) ) : '';

		$sim = null;
		$product = null;
		$error = '';
		if ( isset( $_POST['soroban_delivery_sim_run'] ) ) {
			check_admin_referer( 'soroban_delivery_simulator' );
			if ( $input_product > 0 && function_exists( 'wc_get_product' ) ) {
				$found = wc_get_product( $input_product );
				$product = $found instanceof WC_Product ? $found : null;
			}
			$now = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $input_date . ' ' . $input_time, wp_timezone() );
			if ( false === $now ) {
				$error = __( 'Invalid date or time.', 'soroban-delivery-dates' );
			} else {
				if ( $pro_active && '' !== $input_zone && method_exists( 'Soroban_Delivery_Pro', 'set_simulation_zone' ) ) {
					Soroban_Delivery_Pro::set_simulation_zone( (int) $input_zone );
				}
				$overrides = array();
				if ( '' !== $input_prep ) {
					$overrides['prep_days'] = (int) $input_prep;
				}
				if ( '' !== $input_tmin ) {
					$overrides['transit_min'] = (int) $input_tmin;
				}
				if ( '' !== $input_tmax ) {
					$overrides['transit_max'] = (int) $input_tmax;
				}
				$sim = Soroban_Delivery_Estimator::simulate( $product, array(
					'now'       => $now,
					'overrides' => $overrides,
				) );
				if ( $pro_active && method_exists( 'Soroban_Delivery_Pro', 'set_simulation_zone' ) ) {
					Soroban_Delivery_Pro::set_simulation_zone( null );
				}
			}
		}

		$products = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$found = wc_get_products( array( 'status' => 'publish', 'limit' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
			if ( is_array( $found ) ) {
				$products = $found;
			}
		}
		$zones = array();
		if ( $pro_active && class_exists( 'WC_Shipping_Zones' ) ) {
			$zones = WC_Shipping_Zones::get_zones();
			$rest = new WC_Shipping_Zone( 0 );
			$zones[0] = array( 'zone_id' => 0, 'zone_name' => $rest->get_zone_name() );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Delivery Date Simulator', 'soroban-delivery-dates' ); ?></h1>
			<p style="max-width:860px;"><?php echo esc_html__( 'Runs the exact calculation the storefront uses — including Pro rules for the selected product and destination — for any order moment. Nothing here changes the live settings or the shop’s health state.', 'soroban-delivery-dates' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'soroban_delivery_simulator' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Order date / time', 'soroban-delivery-dates' ); ?></th>
						<td>
							<input type="date" name="soroban_delivery_sim_date" value="<?php echo esc_attr( $input_date ); ?>" />
							<input type="time" name="soroban_delivery_sim_time" value="<?php echo esc_attr( $input_time ); ?>" />
							<span class="description"><?php echo esc_html__( 'Site timezone.', 'soroban-delivery-dates' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Product (optional)', 'soroban-delivery-dates' ); ?></th>
						<td>
							<select name="soroban_delivery_sim_product">
								<option value="0"><?php echo esc_html__( 'No product — store defaults', 'soroban-delivery-dates' ); ?></option>
								<?php foreach ( $products as $p ) : ?>
									<option value="<?php echo esc_attr( $p->get_id() ); ?>" <?php selected( $input_product, $p->get_id() ); ?>>
										<?php echo esc_html( $p->get_name() ); ?> (#<?php echo esc_html( $p->get_id() ); ?>, <?php echo esc_html( $p->get_stock_status() ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'With a product selected, per-product, category and stock rules apply, exactly as on the product page.', 'soroban-delivery-dates' ); ?></p>
						</td>
					</tr>
					<?php if ( $pro_active ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Destination zone (Pro, optional)', 'soroban-delivery-dates' ); ?></th>
						<td>
							<select name="soroban_delivery_sim_zone">
								<option value=""><?php echo esc_html__( 'Visitor default (no destination)', 'soroban-delivery-dates' ); ?></option>
								<option value="-1" <?php selected( $input_zone, '-1' ); ?>><?php echo esc_html__( 'No destination known', 'soroban-delivery-dates' ); ?></option>
								<?php foreach ( $zones as $zone ) : ?>
									<option value="<?php echo esc_attr( $zone['zone_id'] ); ?>" <?php selected( $input_zone, (string) $zone['zone_id'] ); ?>><?php echo esc_html( $zone['zone_name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Overrides (optional)', 'soroban-delivery-dates' ); ?></th>
						<td>
							<?php echo esc_html__( 'Preparation', 'soroban-delivery-dates' ); ?>
							<input type="number" name="soroban_delivery_sim_prep" value="<?php echo esc_attr( $input_prep ); ?>" min="0" max="60" class="small-text" placeholder="<?php echo esc_attr( $s['prep_days'] ); ?>" />
							<?php echo esc_html__( 'Transit', 'soroban-delivery-dates' ); ?>
							<input type="number" name="soroban_delivery_sim_tmin" value="<?php echo esc_attr( $input_tmin ); ?>" min="0" max="60" class="small-text" placeholder="<?php echo esc_attr( $s['transit_min'] ); ?>" /> –
							<input type="number" name="soroban_delivery_sim_tmax" value="<?php echo esc_attr( $input_tmax ); ?>" min="0" max="90" class="small-text" placeholder="<?php echo esc_attr( $s['transit_max'] ); ?>" />
							<span class="description"><?php echo esc_html__( 'Leave blank to use the resolved values.', 'soroban-delivery-dates' ); ?></span>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Calculate', 'soroban-delivery-dates' ), 'primary', 'soroban_delivery_sim_run' ); ?>
			</form>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $sim ) ) : ?>
				<?php if ( null !== $sim['inputs'] ) : ?>
					<h2><?php echo esc_html__( 'Effective values used', 'soroban-delivery-dates' ); ?></h2>
					<table class="widefat striped" style="max-width:720px;">
						<tbody>
							<tr>
								<td style="width:220px;"><strong><?php echo esc_html__( 'Preparation (business days)', 'soroban-delivery-dates' ); ?></strong></td>
								<td><?php echo esc_html( sprintf( '%d (store default: %d)', $sim['inputs']['prep_days'], (int) $s['prep_days'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php echo esc_html__( 'Transit (carrier days)', 'soroban-delivery-dates' ); ?></strong></td>
								<td><?php echo esc_html( sprintf( '%d – %d (store default: %d – %d)', $sim['inputs']['transit_min'], $sim['inputs']['transit_max'], (int) $s['transit_min'], (int) $s['transit_max'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php echo esc_html__( 'Carrier calendar', 'soroban-delivery-dates' ); ?></strong></td>
								<td><?php echo esc_html( $sim['inputs']['carrier_holidays'] === $sim['inputs']['calendar']['holidays'] ? __( 'Same as the store calendar', 'soroban-delivery-dates' ) : __( 'Carrier layer (Pro) — differs from the store calendar', 'soroban-delivery-dates' ) ); ?></td>
							</tr>
						</tbody>
					</table>
					<?php if ( ! empty( $sim['inputs']['notes'] ) ) : ?>
						<ul style="list-style:disc;margin-left:20px;">
							<?php foreach ( $sim['inputs']['notes'] as $note ) : ?>
								<li><?php echo esc_html( $note ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( null !== $sim['estimate'] ) : ?>
					<h2><?php echo esc_html__( 'Result', 'soroban-delivery-dates' ); ?></h2>
					<p>
						<?php
						echo esc_html( sprintf(
							/* translators: 1: dispatch date, 2: earliest arrival, 3: latest arrival */
							__( 'Dispatched: %1$s — Estimated delivery: %2$s – %3$s', 'soroban-delivery-dates' ),
							date_i18n( get_option( 'date_format' ), strtotime( $sim['estimate']['dispatch'] ) ),
							date_i18n( get_option( 'date_format' ), strtotime( $sim['estimate']['arrival_min'] ) ),
							date_i18n( get_option( 'date_format' ), strtotime( $sim['estimate']['arrival_max'] ) )
						) );
						?>
					</p>
				<?php else : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( self::failure_message( $sim['failure'] ) ); ?></p></div>
				<?php endif; ?>

				<?php if ( ! empty( $sim['walk'] ) ) : ?>
					<h2><?php echo esc_html__( 'Day-by-day walk', 'soroban-delivery-dates' ); ?></h2>
					<table class="widefat striped" style="max-width:720px;">
						<thead><tr>
							<th><?php echo esc_html__( 'Date', 'soroban-delivery-dates' ); ?></th>
							<th><?php echo esc_html__( 'Step', 'soroban-delivery-dates' ); ?></th>
							<th><?php echo esc_html__( 'Reason', 'soroban-delivery-dates' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $sim['walk'] as $step ) : ?>
							<tr>
								<td><?php echo esc_html( date_i18n( 'D, Y-m-d', strtotime( $step['date'] ) ) ); ?></td>
								<td><?php echo esc_html( self::walk_kind_label( $step ) ); ?></td>
								<td><?php echo esc_html( self::walk_reason_label( $step ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Human sentence for a simulator failure reason.
	 *
	 * @param string|null $reason Failure reason code.
	 * @return string
	 */
	private static function failure_message( $reason ) {
		switch ( $reason ) {
			case 'beyond_coverage':
				return __( 'No estimate would be shown: the calculation runs past the end of the holiday calendar data (fail-safe). On the storefront the estimate is hidden in this case.', 'soroban-delivery-dates' );
			case 'calendar_data_missing':
				return __( 'No estimate would be shown: the calendar data could not be loaded.', 'soroban-delivery-dates' );
			case 'hidden_by_rule':
				return __( 'No estimate would be shown for this product: a rule hides it (for example, the Pro out-of-stock setting).', 'soroban-delivery-dates' );
			case 'no_business_day':
			case 'no_carrier_day':
				return __( 'No estimate would be shown: no further business/carrier day could be found within the scan limit. Check the open-weekday settings.', 'soroban-delivery-dates' );
		}
		return __( 'No estimate would be shown.', 'soroban-delivery-dates' );
	}

	/**
	 * Human label for a walk entry's kind (admin/simulator use).
	 *
	 * @param array $step Walk entry.
	 * @return string
	 */
	public static function walk_kind_label( array $step ) {
		switch ( $step['kind'] ) {
			case 'order':
				return __( 'Order placed (does not start today)', 'soroban-delivery-dates' );
			case 'start':
				return __( 'Preparation starts', 'soroban-delivery-dates' );
			case 'prep':
				/* translators: %d: running preparation day count */
				return sprintf( __( 'Preparation day %d', 'soroban-delivery-dates' ), (int) $step['count'] );
			case 'dispatch':
				return __( 'Dispatched', 'soroban-delivery-dates' );
			case 'skip_business':
				return __( 'Skipped (store closed)', 'soroban-delivery-dates' );
			case 'skip_carrier':
				return __( 'Skipped (carrier closed)', 'soroban-delivery-dates' );
			case 'transit':
				$label = sprintf(
					/* translators: %d: running transit day count */
					__( 'Transit day %d', 'soroban-delivery-dates' ),
					(int) $step['count']
				);
				if ( ! empty( $step['is_min'] ) ) {
					$label .= ' — ' . __( 'earliest estimated arrival', 'soroban-delivery-dates' );
				}
				if ( ! empty( $step['is_max'] ) ) {
					$label .= ' — ' . __( 'latest estimated arrival', 'soroban-delivery-dates' );
				}
				return $label;
		}
		return $step['kind'];
	}

	/**
	 * Human label for a walk entry's reason (admin/simulator use).
	 *
	 * @param array $step Walk entry.
	 * @return string
	 */
	public static function walk_reason_label( array $step ) {
		switch ( $step['reason_type'] ) {
			case 'open':
				return __( 'Open', 'soroban-delivery-dates' );
			case 'weekday_closed':
				return __( 'Store closed on this weekday', 'soroban-delivery-dates' );
			case 'holiday':
				/* translators: %s: holiday name */
				return sprintf( __( 'Holiday: %s', 'soroban-delivery-dates' ), (string) $step['reason_name'] );
			case 'custom_closed':
				return __( 'Custom closed date', 'soroban-delivery-dates' );
			case 'cutoff_passed':
				return __( 'Order at/after the cut-off time', 'soroban-delivery-dates' );
			case 'carrier_weekday_closed':
				return __( 'Carrier does not operate on this weekday', 'soroban-delivery-dates' );
			case 'carrier_holiday':
				/* translators: %s: holiday name */
				return sprintf( __( 'Carrier holiday: %s', 'soroban-delivery-dates' ), (string) $step['reason_name'] );
		}
		return $step['reason_type'];
	}
}
