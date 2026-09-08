<?php
/**
 * Allow-list filters for client-facing payloads.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The field allow-lists that make {@see View_Model} safe by construction.
 *
 * Nothing here reads state or content: each method takes a raw array and returns a copy
 * containing only the named keys. Adding a field to the engine, to an event JSON file, or
 * to a memory record therefore cannot leak to the browser until somebody adds its name to
 * a list in this file — which is the review gate we want.
 */
final class View_Model_Filters {

	/**
	 * Event card fields that are safe to publish.
	 */
	const EVENT_FIELDS = array( 'id', 'title', 'flash_label', 'category', 'severity', 'summary', 'briefing_text', 'location', 'tags' );

	/**
	 * The only choice fields that ever reach the browser.
	 *
	 * `effects`, `hidden_effects`, `delayed`, `memory`, `headlines` and `outcome_text` are
	 * deliberately absent: the player sees consequences after choosing, never before.
	 */
	const CHOICE_FIELDS = array( 'id', 'label', 'description', 'advisor_positions' );

	/**
	 * Cabinet row fields.
	 */
	const CABINET_FIELDS = array( 'advisor_id', 'name', 'office', 'position' );

	/**
	 * Outcome fields (engineering spec section 4.3).
	 */
	const OUTCOME_FIELDS = array( 'event_id', 'choice_id', 'choice_label', 'outcome_text', 'visible_deltas', 'hidden_change_count', 'headlines', 'memory', 'delayed_count' );

	/**
	 * Turn report fields (engineering spec section 4.4).
	 */
	const TURN_REPORT_FIELDS = array( 'turn', 'date', 'month_label', 'indicator_deltas', 'system_notes', 'fired_consequences', 'headlines', 'active_event_id' );

	/**
	 * Fired-consequence fields inside a turn report.
	 */
	const CONSEQUENCE_FIELDS = array( 'id', 'label', 'headline', 'visible_deltas' );

	/**
	 * Delta row fields.
	 */
	const DELTA_FIELDS = array( 'path', 'label', 'delta', 'display' );

	/**
	 * Headline fields.
	 */
	const HEADLINE_FIELDS = array( 'outlet_id', 'outlet', 'title', 'source', 'turn', 'date' );

	/**
	 * Memory record fields (engineering spec section 6).
	 */
	const MEMORY_FIELDS = array( 'id', 'turn', 'date', 'type', 'action', 'target', 'result', 'tags' );

	/**
	 * Keys that must never appear in a non-debug payload, at any depth.
	 *
	 * The first seven are the strip list from engineering spec section 8.4; the rest are
	 * authoring keys from `data/events/*.json` that would reveal a choice's consequences.
	 */
	const FORBIDDEN_KEYS = array(
		'hidden',
		'rng',
		'seed',
		'delayed_queue',
		'flags',
		'counters',
		'cooldowns',
		'effects',
		'hidden_effects',
		'delayed',
		'start_conditions',
		'weight',
		'weight_modifiers',
		'followup_events',
		'cabinet_assessment',
		'seen_events',
		'raw_state',
	);

	/**
	 * Copy the named keys, preserving list order and skipping absent ones.
	 *
	 * @since 0.1.0
	 *
	 * @param array $source Source array.
	 * @param array $keys   Allowed keys.
	 *
	 * @return array
	 */
	public static function pick( array $source, array $keys ) {
		$out = array();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $source ) ) {
				$out[ $key ] = $source[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Filter a stored outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $outcome Stored `last_outcome`.
	 *
	 * @return array|null Null when there is no outcome yet.
	 */
	public static function outcome( $outcome ) {
		if ( ! is_array( $outcome ) || empty( $outcome ) ) {
			return null;
		}

		$row = self::pick( $outcome, self::OUTCOME_FIELDS );

		$row['visible_deltas']      = self::deltas( isset( $row['visible_deltas'] ) ? $row['visible_deltas'] : array() );
		$row['headlines']           = self::headlines( isset( $row['headlines'] ) ? $row['headlines'] : array() );
		$row['hidden_change_count'] = isset( $row['hidden_change_count'] ) ? (int) $row['hidden_change_count'] : 0;
		$row['delayed_count']       = isset( $row['delayed_count'] ) ? (int) $row['delayed_count'] : 0;
		$row['memory']              = ( isset( $row['memory'] ) && is_array( $row['memory'] ) )
			? self::pick( $row['memory'], self::MEMORY_FIELDS )
			: null;

		return $row;
	}

	/**
	 * Filter a stored turn report.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $report Stored `turn_report`.
	 *
	 * @return array|null Null before the first advance.
	 */
	public static function turn_report( $report ) {
		if ( ! is_array( $report ) || empty( $report ) ) {
			return null;
		}

		$row = self::pick( $report, self::TURN_REPORT_FIELDS );

		if ( ! isset( $row['month_label'] ) && isset( $row['date'] ) ) {
			$row['month_label'] = View_Model_Labels::month_label( (string) $row['date'] );
		}

		$row['indicator_deltas']   = self::deltas( isset( $row['indicator_deltas'] ) ? $row['indicator_deltas'] : array() );
		$row['headlines']          = self::headlines( isset( $row['headlines'] ) ? $row['headlines'] : array() );
		$row['system_notes']       = self::strings( isset( $row['system_notes'] ) ? $row['system_notes'] : array() );
		$row['fired_consequences'] = self::consequences( isset( $row['fired_consequences'] ) ? $row['fired_consequences'] : array() );

		return $row;
	}

	/**
	 * Filter fired-consequence rows.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $fired Raw rows.
	 *
	 * @return array<int, array>
	 */
	public static function consequences( $fired ) {
		$out = array();

		if ( ! is_array( $fired ) ) {
			return $out;
		}

		foreach ( $fired as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$row                   = self::pick( $item, self::CONSEQUENCE_FIELDS );
			$row['visible_deltas'] = self::deltas( isset( $row['visible_deltas'] ) ? $row['visible_deltas'] : array() );
			$row['headline']       = ( isset( $row['headline'] ) && is_array( $row['headline'] ) )
				? self::pick( $row['headline'], self::HEADLINE_FIELDS )
				: null;

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Filter delta rows.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $deltas Raw rows.
	 *
	 * @return array<int, array>
	 */
	public static function deltas( $deltas ) {
		$out = array();

		if ( ! is_array( $deltas ) ) {
			return $out;
		}

		foreach ( $deltas as $delta ) {
			if ( is_array( $delta ) && isset( $delta['path'] ) ) {
				$out[] = self::pick( $delta, self::DELTA_FIELDS );
			}
		}

		return $out;
	}

	/**
	 * Filter headline rows.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $headlines Raw rows.
	 *
	 * @return array<int, array>
	 */
	public static function headlines( $headlines ) {
		$out = array();

		if ( ! is_array( $headlines ) ) {
			return $out;
		}

		foreach ( $headlines as $headline ) {
			if ( is_array( $headline ) && isset( $headline['title'] ) ) {
				$out[] = self::pick( $headline, self::HEADLINE_FIELDS );
			}
		}

		return $out;
	}

	/**
	 * Filter memory records.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $memories Raw records.
	 *
	 * @return array<int, array>
	 */
	public static function memories( $memories ) {
		$out = array();

		if ( ! is_array( $memories ) ) {
			return $out;
		}

		foreach ( $memories as $memory ) {
			if ( is_array( $memory ) ) {
				$out[] = self::pick( $memory, self::MEMORY_FIELDS );
			}
		}

		return $out;
	}

	/**
	 * Filter cabinet rows.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $rows Raw rows.
	 *
	 * @return array<int, array>
	 */
	public static function cabinet( $rows ) {
		$out = array();

		if ( ! is_array( $rows ) ) {
			return $out;
		}

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['advisor_id'] ) ) {
				$out[] = self::pick( $row, self::CABINET_FIELDS );
			}
		}

		return $out;
	}

	/**
	 * Coerce a list to plain strings.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $values Raw list.
	 *
	 * @return array<int, string>
	 */
	public static function strings( $values ) {
		$out = array();

		if ( ! is_array( $values ) ) {
			return $out;
		}

		foreach ( $values as $value ) {
			if ( is_scalar( $value ) ) {
				$out[] = (string) $value;
			}
		}

		return $out;
	}

	/**
	 * Find forbidden keys anywhere in a payload.
	 *
	 * Exposed so tests assert the same rule the class enforces instead of a copy of it.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $payload Array tree to inspect.
	 *
	 * @return array<int, string> Offending key paths; empty when the payload is clean.
	 */
	public static function leaks( $payload ) {
		$found = array();

		self::walk( $payload, '', $found );

		return $found;
	}

	/**
	 * Recursive worker for {@see self::leaks()}.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $node  Current node.
	 * @param string $path  Path to the node.
	 * @param array  $found Accumulator, by reference.
	 *
	 * @return void
	 */
	private static function walk( $node, $path, array &$found ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		foreach ( $node as $key => $value ) {
			$here = ( '' === $path ) ? (string) $key : $path . '.' . $key;

			if ( is_string( $key ) && in_array( $key, self::FORBIDDEN_KEYS, true ) ) {
				$found[] = $here;
			}

			self::walk( $value, $here, $found );
		}
	}
}
