<?php
/**
 * Derived, human-facing labels for the view model.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rounding rules and derived status words used by {@see View_Model}.
 *
 * Split out of `View_Model` so the presentation thresholds live in one readable table:
 * every band boundary in the UI is a constant here, never a literal in a conditional.
 */
final class View_Model_Labels {

	/**
	 * Decimal places per public indicator (engineering spec section 2.1).
	 *
	 * Percentages of the electorate read as whole numbers; economic rates keep one decimal.
	 */
	const PRECISION = array(
		'approval'           => 0,
		'gdp_growth'         => 1,
		'inflation'          => 1,
		'unemployment'       => 1,
		'deficit'            => 0,
		'national_debt'      => 0,
		'global_influence'   => 0,
		'allied_confidence'  => 0,
		'domestic_stability' => 0,
		'congress_support'   => 0,
		'crisis_level'       => 0,
	);

	/**
	 * Default precision for an indicator the table above does not name.
	 */
	const DEFAULT_PRECISION = 1;

	/**
	 * Lower bound of each economy band, richest first.
	 */
	const ECONOMY_EXPANDING = 3.0;

	/**
	 * Lower bound of the "Stable" economy band.
	 */
	const ECONOMY_STABLE = 1.0;

	/**
	 * Lower bound of the "Softening" economy band; below it the economy contracts.
	 */
	const ECONOMY_SOFTENING = 0.0;

	/**
	 * Upper bound of each crisis band, calmest first.
	 */
	const SECURITY_LOW = 15.0;

	/**
	 * Upper bound of the "Guarded" security band.
	 */
	const SECURITY_GUARDED = 35.0;

	/**
	 * Upper bound of the "Elevated" security band.
	 */
	const SECURITY_ELEVATED = 55.0;

	/**
	 * Upper bound of the "High" security band; above it the posture is severe.
	 */
	const SECURITY_HIGH = 75.0;

	/**
	 * Upper bound of the "Shaky" allied-confidence band.
	 */
	const ALLIES_SHAKY = 45.0;

	/**
	 * Upper bound of the "Stable" allied-confidence band.
	 */
	const ALLIES_STABLE = 70.0;

	/**
	 * Round one indicator for display.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   Indicator key, e.g. `approval`.
	 * @param mixed  $value Raw stored value.
	 *
	 * @return float|int Integer when the indicator has no decimals, float otherwise.
	 */
	public static function round_indicator( $key, $value ) {
		$precision = isset( self::PRECISION[ $key ] ) ? self::PRECISION[ $key ] : self::DEFAULT_PRECISION;
		$number    = is_numeric( $value ) ? (float) $value : 0.0;
		$rounded   = round( $number, $precision );

		return ( 0 === $precision ) ? (int) $rounded : $rounded;
	}

	/**
	 * Word describing the economy, from annualised growth.
	 *
	 * Unemployment nudges the reading down one band when it is climbing hard, so a
	 * technically positive quarter with a broken labour market never reads as "Expanding".
	 *
	 * @since 0.1.0
	 *
	 * @param float $gdp_growth   Annualised growth, percent.
	 * @param float $unemployment Unemployment rate, percent.
	 *
	 * @return string Translated status word.
	 */
	public static function economy_status( $gdp_growth, $unemployment ) {
		$growth = (float) $gdp_growth;
		$jobs   = (float) $unemployment;

		if ( $growth >= self::ECONOMY_EXPANDING && $jobs < 7.0 ) {
			return __( 'Expanding', 'mr-president-game' );
		}

		if ( $growth >= self::ECONOMY_STABLE ) {
			return __( 'Stable', 'mr-president-game' );
		}

		if ( $growth >= self::ECONOMY_SOFTENING ) {
			return __( 'Softening', 'mr-president-game' );
		}

		return __( 'Contracting', 'mr-president-game' );
	}

	/**
	 * Word describing the security posture, from the crisis level.
	 *
	 * @since 0.1.0
	 *
	 * @param float $crisis_level 0..100.
	 *
	 * @return string Translated status word.
	 */
	public static function security_status( $crisis_level ) {
		$level = (float) $crisis_level;

		if ( $level < self::SECURITY_LOW ) {
			return __( 'Low', 'mr-president-game' );
		}

		if ( $level < self::SECURITY_GUARDED ) {
			return __( 'Guarded', 'mr-president-game' );
		}

		if ( $level < self::SECURITY_ELEVATED ) {
			return __( 'Elevated', 'mr-president-game' );
		}

		if ( $level < self::SECURITY_HIGH ) {
			return __( 'High', 'mr-president-game' );
		}

		return __( 'Severe', 'mr-president-game' );
	}

	/**
	 * Word describing how allies read the administration.
	 *
	 * @since 0.1.0
	 *
	 * @param float $allied_confidence 0..100.
	 *
	 * @return string Translated status word.
	 */
	public static function allied_confidence_label( $allied_confidence ) {
		$value = (float) $allied_confidence;

		if ( $value < self::ALLIES_SHAKY ) {
			return __( 'Shaky', 'mr-president-game' );
		}

		if ( $value < self::ALLIES_STABLE ) {
			return __( 'Stable', 'mr-president-game' );
		}

		return __( 'Strong', 'mr-president-game' );
	}

	/**
	 * Turn an in-game ISO date into "April 2001".
	 *
	 * Parsed by hand rather than through `date()` so the site timezone can never shift an
	 * in-game month, and so the label is stable regardless of server locale.
	 *
	 * @since 0.1.0
	 *
	 * @param string $iso_date `YYYY-MM-DD`.
	 *
	 * @return string Month label, or the raw input when it is not a date.
	 */
	public static function month_label( $iso_date ) {
		$date = (string) $iso_date;

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-\d{2}$/', $date, $matches ) ) {
			return $date;
		}

		$month = (int) $matches[2];
		$names = self::month_names();

		if ( ! isset( $names[ $month ] ) ) {
			return $date;
		}

		/* translators: 1: month name, 2: four-digit year. */
		return sprintf( __( '%1$s %2$s', 'mr-president-game' ), $names[ $month ], $matches[1] );
	}

	/**
	 * Month names, 1-indexed and translatable.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	private static function month_names() {
		return array(
			1  => __( 'January', 'mr-president-game' ),
			2  => __( 'February', 'mr-president-game' ),
			3  => __( 'March', 'mr-president-game' ),
			4  => __( 'April', 'mr-president-game' ),
			5  => __( 'May', 'mr-president-game' ),
			6  => __( 'June', 'mr-president-game' ),
			7  => __( 'July', 'mr-president-game' ),
			8  => __( 'August', 'mr-president-game' ),
			9  => __( 'September', 'mr-president-game' ),
			10 => __( 'October', 'mr-president-game' ),
			11 => __( 'November', 'mr-president-game' ),
			12 => __( 'December', 'mr-president-game' ),
		);
	}
}
