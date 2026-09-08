<?php
/**
 * State to client-safe payload translation.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

use MrPresident\Engine\ContentRepository;
use MrPresident\Engine\EngineException;
use MrPresident\Engine\GameState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the `game` and `briefing` payloads the browser receives (spec section 8.4).
 *
 * This class is the boundary between the authoritative state and the wire. It assembles
 * every array key by key from the allow-lists in {@see View_Model_Filters}, so hidden
 * state, the RNG, the seed, flags, counters, cooldowns, the delayed queue and a choice's
 * effects cannot leak by accident. They appear only inside `debug`, which is populated
 * only when the caller passes `$debug = true` — granted by {@see Plugin::is_dev_mode()}
 * to a `manage_options` user on a site with developer mode switched on, and nobody else.
 */
final class View_Model {

	/**
	 * Headlines kept in the `game` payload.
	 */
	const MEDIA_LIMIT = 8;

	/**
	 * Headlines kept in the briefing payload.
	 */
	const BRIEFING_MEDIA_LIMIT = 5;

	/**
	 * Memories kept in the briefing payload.
	 */
	const BRIEFING_MEMORY_LIMIT = 5;

	/**
	 * Country fields published per country; `cooperation` exists only for blocs.
	 */
	const COUNTRY_FIELDS = array( 'relationship', 'trust', 'trade_dependency', 'military_tension', 'cooperation' );

	/**
	 * State blocks copied verbatim into `debug`.
	 */
	const DEBUG_STATE_KEYS = array( 'seed', 'hidden', 'flags', 'counters', 'delayed_queue', 'cooldowns', 'seen_events' );

	/**
	 * Fallback country kind when the catalog has no entry.
	 */
	const DEFAULT_COUNTRY_KIND = 'partner';

	/**
	 * Content source for names and event copy; optional so the view layer stays testable.
	 *
	 * @var ContentRepository|null
	 */
	private $content;

	/**
	 * Optional callable returning the developer eligibility table for a state.
	 *
	 * @var callable|null
	 */
	private $eligibility_provider = null;

	/**
	 * @since 0.1.0
	 *
	 * @param ContentRepository|null $content Content repository, or null to fall back to ids.
	 */
	public function __construct( ?ContentRepository $content = null ) {
		$this->content = $content;
	}

	/**
	 * Supply the developer-mode event eligibility table.
	 *
	 * The engine owns eligibility, so the view model never recomputes it; the controller
	 * injects a reader when one is available and the key is omitted when it is not.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $provider Receives a GameState, returns an array of rows.
	 *
	 * @return void
	 */
	public function set_eligibility_provider( $provider ) {
		if ( is_callable( $provider ) ) {
			$this->eligibility_provider = $provider;
		}
	}

	/**
	 * The `game` object (engineering spec section 8.4).
	 *
	 * @since 0.1.0
	 *
	 * @param GameState $state Authoritative state.
	 * @param bool      $debug Whether to attach the developer payload.
	 * @param array     $meta  Extra `meta` values, e.g. `updated_at` from the save row.
	 *
	 * @return array Client-safe payload.
	 */
	public function game( GameState $state, $debug = false, array $meta = array() ) {
		$date = (string) $state->get( 'date', '' );

		$game = array(
			'id'             => (string) $state->get( 'game_uuid', '' ),
			'president_name' => (string) $state->get( 'president_name', '' ),
			'scenario'       => $this->scenario_stub( (string) $state->get( 'scenario_id', '' ) ),
			'date'           => $date,
			'month_label'    => View_Model_Labels::month_label( $date ),
			'turn'           => (int) $state->turn(),
			'term'           => (int) $state->get( 'term', 1 ),
			'indicators'     => $this->indicators( $state ),
			'countries'      => $this->countries( $state ),
			'active_event'   => $this->event_card( $state ),
			'last_outcome'   => View_Model_Filters::outcome( $state->get( 'last_outcome' ) ),
			'turn_report'    => View_Model_Filters::turn_report( $state->get( 'turn_report' ) ),
			'media'          => $this->media( $state, self::MEDIA_LIMIT ),
			'memories'       => View_Model_Filters::memories( $this->tail( $state->get( 'memories', array() ), 0 ) ),
			'meta'           => $this->meta( $state, $meta ),
		);

		if ( $debug ) {
			$game['debug'] = $this->debug( $state );
		}

		return $game;
	}

	/**
	 * The briefing payload (engineering spec sections 4.5 and 8.4).
	 *
	 * Facts are re-derived from the state rather than trusted from the engine payload, so
	 * the stripping rules above apply to the briefing exactly as they do to `game`. Only
	 * the prose (`summary`) and, when present, the engine's composed cabinet are carried
	 * across.
	 *
	 * @since 0.1.0
	 *
	 * @param array     $briefing Structured briefing from the engine.
	 * @param GameState $state    Authoritative state.
	 *
	 * @return array Client-safe payload.
	 */
	public function briefing( array $briefing, GameState $state ) {
		$date  = (string) $state->get( 'date', '' );
		$event = $this->event_card( $state );

		$cabinet = isset( $briefing['cabinet'] ) && is_array( $briefing['cabinet'] ) && ! empty( $briefing['cabinet'] )
			? View_Model_Filters::cabinet( $briefing['cabinet'] )
			: ( null === $event ? array() : $event['cabinet'] );

		return array(
			'date'            => $date,
			'month_label'     => View_Model_Labels::month_label( $date ),
			'turn'            => (int) $state->turn(),
			'term'            => (int) $state->get( 'term', 1 ),
			// The engine supplies structured facts; the AI seam renders them into prose.
			'summary'         => isset( $briefing['summary'] ) && is_string( $briefing['summary'] ) ? $briefing['summary'] : '',
			'summary_facts'   => isset( $briefing['summary'] ) && is_array( $briefing['summary'] ) ? $briefing['summary'] : array(),
			'indicators'      => $this->indicators( $state ),
			'turn_report'     => View_Model_Filters::turn_report( $state->get( 'turn_report' ) ),
			'event'           => $event,
			'cabinet'         => $cabinet,
			'media'           => $this->media( $state, self::BRIEFING_MEDIA_LIMIT ),
			'memories_recent' => View_Model_Filters::memories( $this->tail( $state->get( 'memories', array() ), self::BRIEFING_MEMORY_LIMIT ) ),
		);
	}

	/**
	 * Rounded public indicators plus the three derived status words.
	 *
	 * @since 0.1.0
	 *
	 * @param GameState $state Authoritative state.
	 *
	 * @return array
	 */
	public function indicators( GameState $state ) {
		$raw        = $state->get( 'public', array() );
		$raw        = is_array( $raw ) ? $raw : array();
		$indicators = array();

		foreach ( array_keys( View_Model_Labels::PRECISION ) as $key ) {
			$value              = isset( $raw[ $key ] ) ? $raw[ $key ] : 0;
			$indicators[ $key ] = View_Model_Labels::round_indicator( $key, $value );
		}

		foreach ( $raw as $key => $value ) {
			if ( ! array_key_exists( $key, $indicators ) && is_numeric( $value ) ) {
				$indicators[ $key ] = View_Model_Labels::round_indicator( $key, $value );
			}
		}

		$growth = isset( $raw['gdp_growth'] ) ? $raw['gdp_growth'] : 0;
		$jobs   = isset( $raw['unemployment'] ) ? $raw['unemployment'] : 0;

		$indicators['economy_status']          = View_Model_Labels::economy_status( $growth, $jobs );
		$indicators['security_status']         = View_Model_Labels::security_status( isset( $raw['crisis_level'] ) ? $raw['crisis_level'] : 0 );
		$indicators['allied_confidence_label'] = View_Model_Labels::allied_confidence_label( isset( $raw['allied_confidence'] ) ? $raw['allied_confidence'] : 0 );

		return $indicators;
	}

	/**
	 * Country relations merged with the display names from content.
	 *
	 * @since 0.1.0
	 *
	 * @param GameState $state Authoritative state.
	 *
	 * @return array<string, array>
	 */
	public function countries( GameState $state ) {
		$relations = $state->get( 'countries', array() );
		$relations = is_array( $relations ) ? $relations : array();
		$catalog   = $this->content_countries();
		$out       = array();

		foreach ( $relations as $id => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			$meta = isset( $catalog[ $id ] ) ? $catalog[ $id ] : array();
			$row  = array(
				'name' => isset( $meta['name'] ) ? (string) $meta['name'] : self::humanize( (string) $id ),
				'kind' => isset( $meta['kind'] ) ? (string) $meta['kind'] : self::DEFAULT_COUNTRY_KIND,
			);

			if ( isset( $meta['map_id'] ) ) {
				$row['map_id'] = (string) $meta['map_id'];
			}

			foreach ( self::COUNTRY_FIELDS as $field ) {
				if ( array_key_exists( $field, $values ) && is_numeric( $values[ $field ] ) ) {
					$row[ $field ] = View_Model_Labels::round_indicator( $field, $values[ $field ] );
				}
			}

			$out[ (string) $id ] = $row;
		}

		return $out;
	}

	/**
	 * The active event as a card, or null on a quiet month.
	 *
	 * @since 0.1.0
	 *
	 * @param GameState $state Authoritative state.
	 *
	 * @return array|null
	 */
	public function event_card( GameState $state ) {
		$active = $state->get( 'active_event' );

		if ( ! is_array( $active ) || empty( $active['event_id'] ) ) {
			return null;
		}

		$event = $this->content_event( (string) $active['event_id'] );

		if ( empty( $event ) ) {
			return null;
		}

		$card                       = View_Model_Filters::pick( $event, View_Model_Filters::EVENT_FIELDS );
		$card['id']                 = (string) $active['event_id'];
		$card['cabinet']            = $this->cabinet_for_event( $event, $state );
		$card['choices']            = $this->choices( $event );
		$card['resolved_choice_id'] = isset( $active['resolved_choice_id'] ) ? $active['resolved_choice_id'] : null;
		$card['turn_presented']     = isset( $active['turn_presented'] ) ? (int) $active['turn_presented'] : (int) $state->turn();

		return $card;
	}

	/**
	 * Advisor positions for one event.
	 *
	 * Uses the event's `cabinet_assessment` line for an advisor when there is one, then the
	 * advisor's `fallback_positions` entry for the event category, then their default line
	 * (engineering spec section 7.3). Advisors with nothing to say are omitted.
	 *
	 * @since 0.1.0
	 *
	 * @param array     $event Event document.
	 * @param GameState $state Authoritative state, for the scenario's advisor set.
	 *
	 * @return array<int, array>
	 */
	public function cabinet_for_event( array $event, GameState $state ) {
		$assessment = isset( $event['cabinet_assessment'] ) && is_array( $event['cabinet_assessment'] ) ? $event['cabinet_assessment'] : array();
		$category   = isset( $event['category'] ) ? (string) $event['category'] : '';
		$cabinet    = array();

		foreach ( $this->advisor_roster( $state ) as $advisor ) {
			if ( ! is_array( $advisor ) || empty( $advisor['id'] ) ) {
				continue;
			}

			$id       = (string) $advisor['id'];
			$position = $this->advisor_position( $advisor, $assessment, $category );

			if ( '' === $position ) {
				continue;
			}

			$cabinet[] = array(
				'advisor_id' => $id,
				'name'       => isset( $advisor['name'] ) ? (string) $advisor['name'] : self::humanize( $id ),
				'office'     => isset( $advisor['office'] ) ? (string) $advisor['office'] : '',
				'position'   => $position,
			);
		}

		return $cabinet;
	}

	/**
	 * Resolve one advisor's line for an event.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $advisor    Advisor document.
	 * @param array  $assessment The event's `cabinet_assessment` block.
	 * @param string $category   Event category.
	 *
	 * @return string Empty when the advisor has no line at all.
	 */
	private function advisor_position( array $advisor, array $assessment, $category ) {
		$id        = (string) $advisor['id'];
		$fallbacks = isset( $advisor['fallback_positions'] ) && is_array( $advisor['fallback_positions'] ) ? $advisor['fallback_positions'] : array();

		if ( isset( $assessment[ $id ] ) && is_string( $assessment[ $id ] ) && '' !== $assessment[ $id ] ) {
			return $assessment[ $id ];
		}

		if ( '' !== $category && isset( $fallbacks[ $category ] ) ) {
			return (string) $fallbacks[ $category ];
		}

		if ( isset( $fallbacks['default'] ) ) {
			return (string) $fallbacks['default'];
		}

		return '';
	}

	/**
	 * Publishable choice list.
	 *
	 * @since 0.1.0
	 *
	 * @param array $event Event document.
	 *
	 * @return array<int, array>
	 */
	private function choices( array $event ) {
		$choices = isset( $event['choices'] ) && is_array( $event['choices'] ) ? $event['choices'] : array();
		$out     = array();

		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) || empty( $choice['id'] ) ) {
				continue;
			}

			$row = View_Model_Filters::pick( $choice, View_Model_Filters::CHOICE_FIELDS );

			if ( ! isset( $row['advisor_positions'] ) || ! is_array( $row['advisor_positions'] ) ) {
				$row['advisor_positions'] = array();
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Advisor roster for the state's scenario.
	 *
	 * @since 0.1.0
	 *
	 * @param GameState $state Authoritative state.
	 *
	 * @return array<int, array>
	 */
	private function advisor_roster( GameState $state ) {
		if ( null === $this->content ) {
			return array();
		}

		$scenario = $this->content_scenario( (string) $state->get( 'scenario_id', '' ) );
		$set      = isset( $scenario['advisor_set'] ) ? (string) $scenario['advisor_set'] : ContentRepository::DEFAULT_ADVISOR_SET;

		try {
			return $this->content->advisorSet( $set );
		} catch ( EngineException $exception ) {
			return array();
		}
	}

	/**
	 * Filtered media log, oldest first.
	 *
	 * @since 0.1.0
	 *
	 * @param GameState $state Authoritative state.
	 * @param int       $limit Keep at most this many, newest kept.
	 *
	 * @return array<int, array>
	 */
	private function media( GameState $state, $limit ) {
		return View_Model_Filters::headlines( $this->tail( $state->get( 'media_log', array() ), $limit ) );
	}

	/**
	 * Last `$limit` entries of a list, in order.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $list  Raw list.
	 * @param int   $limit Maximum entries; 0 keeps everything.
	 *
	 * @return array
	 */
	private function tail( $list, $limit ) {
		$values = is_array( $list ) ? array_values( $list ) : array();

		if ( $limit > 0 && count( $values ) > $limit ) {
			$values = array_slice( $values, - $limit );
		}

		return $values;
	}

	/**
	 * Payload metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param GameState $state Authoritative state.
	 * @param array     $extra Caller-supplied values, e.g. the save row's `updated_at`.
	 *
	 * @return array
	 */
	private function meta( GameState $state, array $extra ) {
		$snapshot = $state->toArray();

		$meta = array(
			'schema_version' => isset( $snapshot['schema_version'] ) ? (int) $snapshot['schema_version'] : 1,
			'version'        => defined( 'MRP_VERSION' ) ? MRP_VERSION : '0.1.0',
			'updated_at'     => '',
		);

		foreach ( $extra as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$meta[ (string) $key ] = $value;
			}
		}

		return $meta;
	}

	/**
	 * Developer payload; only reached when the caller passed `$debug = true`.
	 *
	 * @since 0.1.0
	 *
	 * @param GameState $state Authoritative state.
	 *
	 * @return array
	 */
	private function debug( GameState $state ) {
		$snapshot = $state->toArray();
		$debug    = array( 'rng' => isset( $snapshot['rng'] ) ? $snapshot['rng'] : null );

		foreach ( self::DEBUG_STATE_KEYS as $key ) {
			$debug[ $key ] = isset( $snapshot[ $key ] ) ? $snapshot[ $key ] : null;
		}

		if ( null !== $this->eligibility_provider ) {
			$debug['eligibility'] = call_user_func( $this->eligibility_provider, $state );
		}

		$debug['raw_state'] = $snapshot;

		return $debug;
	}

	/**
	 * `{ id, title }` for the scenario, falling back to the id when content is unavailable.
	 *
	 * @since 0.1.0
	 *
	 * @param string $scenario_id Scenario id.
	 *
	 * @return array
	 */
	private function scenario_stub( $scenario_id ) {
		$scenario = $this->content_scenario( $scenario_id );

		return array(
			'id'    => (string) $scenario_id,
			'title' => isset( $scenario['title'] ) ? (string) $scenario['title'] : self::humanize( (string) $scenario_id ),
		);
	}

	/**
	 * Scenario document, or an empty array when unavailable.
	 *
	 * @since 0.1.0
	 *
	 * @param string $scenario_id Scenario id.
	 *
	 * @return array
	 */
	private function content_scenario( $scenario_id ) {
		if ( null === $this->content || '' === $scenario_id ) {
			return array();
		}

		try {
			return $this->content->scenario( $scenario_id );
		} catch ( EngineException $exception ) {
			return array();
		}
	}

	/**
	 * Event document, or an empty array when unavailable.
	 *
	 * @since 0.1.0
	 *
	 * @param string $event_id Event id.
	 *
	 * @return array
	 */
	private function content_event( $event_id ) {
		if ( null === $this->content || '' === $event_id ) {
			return array();
		}

		try {
			return $this->content->event( $event_id );
		} catch ( EngineException $exception ) {
			return array();
		}
	}

	/**
	 * Country catalog keyed by id, or an empty array when unavailable.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array>
	 */
	private function content_countries() {
		if ( null === $this->content ) {
			return array();
		}

		try {
			return $this->content->countries();
		} catch ( EngineException $exception ) {
			return array();
		}
	}

	/**
	 * `rival_state_a` → `Rival State A`, for ids missing from content.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Kebab or snake id.
	 *
	 * @return string
	 */
	private static function humanize( $id ) {
		return ucwords( str_replace( array( '-', '_' ), ' ', (string) $id ) );
	}
}
