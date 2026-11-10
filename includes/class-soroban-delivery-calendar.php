<?php
/**
 * Calendar data access: loads the bundled holiday datasets.
 *
 * Data files (built by the Python scripts in dev/tools):
 *  - data/uk-holidays.json : official GOV.UK bank holidays, per division.
 *  - data/jp-holidays.json : Japanese national holidays generated from
 *                            the statutory rules of the Act on National
 *                            Holidays.
 *
 * No external calls are ever made at runtime; the plugin works fully
 * offline with the bundled data.
 *
 * @package SorobanDeliveryDates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soroban_Delivery_Calendar {

	/**
	 * Directory containing the JSON data files.
	 *
	 * @var string
	 */
	private $data_dir;

	/**
	 * Decoded-file cache.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * @param string $data_dir Absolute path to the data directory.
	 */
	public function __construct( $data_dir ) {
		$this->data_dir = rtrim( $data_dir, '/' );
	}

	/**
	 * Get the calendar for a country (and UK division).
	 *
	 * @param string $country  'UK' or 'JP'.
	 * @param string $division UK division slug (ignored for JP).
	 * @return array|null {
	 *     @type string $country      Country code.
	 *     @type string $label        Human label, e.g. "England & Wales".
	 *     @type string $coverage_end Last covered date, 'Y-m-d'.
	 *     @type array  $holidays     Map of 'Y-m-d' => holiday name.
	 * } Null when the data file or division is missing.
	 */
	public function get_calendar( $country, $division = 'england-and-wales' ) {
		if ( 'JP' === $country ) {
			$data = $this->load( 'jp-holidays.json' );
			if ( null === $data || empty( $data['holidays'] ) ) {
				return null;
			}
			return array(
				'country'      => 'JP',
				'label'        => 'Japan',
				'coverage_end' => $data['coverage_end'],
				'holidays'     => $data['holidays'],
			);
		}

		// Default: United Kingdom.
		$data = $this->load( 'uk-holidays.json' );
		if ( null === $data || empty( $data['divisions'][ $division ] ) ) {
			return null;
		}
		$div = $data['divisions'][ $division ];
		return array(
			'country'      => 'UK',
			'label'        => $div['label'],
			'coverage_end' => $data['coverage_end'],
			'holidays'     => $div['holidays'],
		);
	}

	/**
	 * Load and decode a JSON data file (cached per process).
	 *
	 * @param string $file File name inside the data directory.
	 * @return array|null Decoded data, or null on any failure.
	 */
	private function load( $file ) {
		if ( isset( self::$cache[ $file ] ) ) {
			return self::$cache[ $file ];
		}
		$path = $this->data_dir . '/' . $file;
		$decoded = null;
		if ( is_readable( $path ) ) {
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled data file only.
			if ( false !== $raw ) {
				$json = json_decode( $raw, true );
				if ( is_array( $json ) ) {
					$decoded = $json;
				}
			}
		}
		self::$cache[ $file ] = $decoded;
		return $decoded;
	}
}
