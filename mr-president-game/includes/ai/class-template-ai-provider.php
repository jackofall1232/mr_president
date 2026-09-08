<?php
/**
 * Offline, deterministic AI provider.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The default provider: string templates, no network, no API key.
 *
 * Every method is a pure function of its arguments, so the same outcome always produces
 * the same prose — which keeps the game reproducible and makes the seam safe to swap for a
 * real model later without changing a single caller.
 */
final class Template_AI_Provider implements AI_Provider_Interface {

	/**
	 * Provider id reported to callers and to the admin panel.
	 */
	const ID = 'template';

	/**
	 * Longest excerpt of player text echoed back by {@see self::analyze_player_speech()}.
	 */
	const SPEECH_EXCERPT_WORDS = 40;

	/**
	 * Themes recognised in player-written text, as keyword => theme.
	 */
	const SPEECH_THEMES = array(
		'econom'     => 'economy',
		'job'        => 'economy',
		'inflation'  => 'economy',
		'security'   => 'security',
		'defen'      => 'security',
		'threat'     => 'security',
		'ally'       => 'alliances',
		'allies'     => 'alliances',
		'partner'    => 'alliances',
		'congress'   => 'congress',
		'bill'       => 'congress',
		'budget'     => 'congress',
		'unity'      => 'unity',
		'together'   => 'unity',
		'reform'     => 'reform',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate_cabinet_response( array $advisor, array $event, array $public_state, array $memories ) {
		$name     = $this->text( $advisor, 'name', __( 'Your adviser', 'mr-president-game' ) );
		$office   = $this->text( $advisor, 'office', __( 'the Cabinet', 'mr-president-game' ) );
		$position = $this->text( $advisor, 'position', '' );
		$title    = $this->text( $event, 'title', __( 'the situation on your desk', 'mr-president-game' ) );

		$lines = array(
			sprintf(
				/* translators: 1: advisor name, 2: office, 3: event title. */
				__( '%1$s, %2$s, opens on %3$s.', 'mr-president-game' ),
				$name,
				$office,
				$title
			),
		);

		if ( '' !== $position ) {
			$lines[] = $position;
		}

		$lines[] = $this->pressure_sentence( $public_state, count( $memories ) );

		return $this->join( $lines );
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate_news_story( array $headline, array $outcome, array $public_state ) {
		$outlet = $this->text( $headline, 'outlet', __( 'The wire desk', 'mr-president-game' ) );
		$title  = $this->text( $headline, 'title', __( 'the decision', 'mr-president-game' ) );
		$choice = $this->text( $outcome, 'choice_label', __( 'the announced course', 'mr-president-game' ) );

		$lines = array(
			sprintf(
				/* translators: 1: outlet name, 2: headline. */
				__( '%1$s leads with "%2$s".', 'mr-president-game' ),
				$outlet,
				$title
			),
			sprintf(
				/* translators: %s: chosen option label. */
				__( 'The report describes the administration settling on %s and notes that reaction is still forming.', 'mr-president-game' ),
				$this->lower_first( $choice )
			),
			$this->approval_sentence( $public_state ),
		);

		return $this->join( $lines );
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate_diplomatic_message( array $country, array $outcome, array $memories ) {
		$name         = $this->text( $country, 'name', __( 'The government', 'mr-president-game' ) );
		$relationship = $this->number( $country, 'relationship' );
		$choice       = $this->text( $outcome, 'choice_label', __( 'the announced course', 'mr-president-game' ) );

		if ( $relationship >= 40 ) {
			$tone = __( 'welcomes continued coordination and asks to be briefed early next time.', 'mr-president-game' );
		} elseif ( $relationship >= 0 ) {
			$tone = __( 'acknowledges the decision and reserves its position pending consultations.', 'mr-president-game' );
		} else {
			$tone = __( 'registers a formal objection and warns of a proportionate response.', 'mr-president-game' );
		}

		$lines = array(
			sprintf(
				/* translators: 1: country name, 2: chosen option label. */
				__( '%1$s has responded to %2$s through its mission.', 'mr-president-game' ),
				$name,
				$this->lower_first( $choice )
			),
			sprintf(
				/* translators: %s: diplomatic tone sentence. */
				__( 'The note %s', 'mr-president-game' ),
				$tone
			),
			sprintf(
				/* translators: %d: number of prior memory records. */
				_n(
					'The file attached to it cites %d earlier exchange.',
					'The file attached to it cites %d earlier exchanges.',
					count( $memories ),
					'mr-president-game'
				),
				count( $memories )
			),
		);

		return $this->join( $lines );
	}

	/**
	 * {@inheritDoc}
	 */
	public function analyze_player_speech( $speech, array $public_state ) {
		$text   = trim( (string) $speech );
		$lower  = strtolower( $text );
		$themes = array();

		foreach ( self::SPEECH_THEMES as $needle => $theme ) {
			if ( '' !== $text && false !== strpos( $lower, $needle ) && ! in_array( $theme, $themes, true ) ) {
				$themes[] = $theme;
			}
		}

		return array(
			'tone'    => $this->speech_tone( $lower ),
			'themes'  => $themes,
			'excerpt' => $this->excerpt( $text, self::SPEECH_EXCERPT_WORDS ),
			'reading' => $this->approval_sentence( $public_state ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate_crisis_flavor( array $event, array $public_state ) {
		$title    = $this->text( $event, 'title', __( 'the incident', 'mr-president-game' ) );
		$severity = (int) $this->number( $event, 'severity' );

		if ( $severity >= 4 ) {
			$room = __( 'The situation room has been held open overnight.', 'mr-president-game' );
		} elseif ( $severity >= 2 ) {
			$room = __( 'Staff have moved the morning schedule to keep the room clear.', 'mr-president-game' );
		} else {
			$room = __( 'The item sits on the desk without disrupting the day.', 'mr-president-game' );
		}

		$lines = array(
			sprintf(
				/* translators: %s: event title. */
				__( 'Reporting on %s continues to arrive in fragments.', 'mr-president-game' ),
				$this->lower_first( $title )
			),
			$room,
			$this->pressure_sentence( $public_state, 0 ),
		);

		return $this->join( $lines );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summarize_presidency( array $memories, array $public_state ) {
		$count  = count( $memories );
		$recent = array();

		foreach ( array_slice( $memories, -3 ) as $memory ) {
			$action = is_array( $memory ) ? $this->text( $memory, 'action', '' ) : '';

			if ( '' !== $action ) {
				$recent[] = $action;
			}
		}

		$lines = array(
			sprintf(
				/* translators: %d: number of recorded decisions. */
				_n(
					'The record of this administration holds %d decision.',
					'The record of this administration holds %d decisions.',
					$count,
					'mr-president-game'
				),
				$count
			),
		);

		if ( ! empty( $recent ) ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated list of recent actions. */
				__( 'Most recently: %s.', 'mr-president-game' ),
				implode( '; ', $recent )
			);
		}

		$lines[] = $this->approval_sentence( $public_state );

		return $this->join( $lines );
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate_briefing_summary( array $briefing ) {
		$month      = isset( $briefing['month_label'] ) ? (string) $briefing['month_label'] : '';
		$indicators = isset( $briefing['indicators'] ) && is_array( $briefing['indicators'] ) ? $briefing['indicators'] : array();
		$event      = isset( $briefing['event'] ) && is_array( $briefing['event'] ) ? $briefing['event'] : array();
		$report     = isset( $briefing['turn_report'] ) && is_array( $briefing['turn_report'] ) ? $briefing['turn_report'] : array();

		$lines = array(
			'' === $month
				? __( 'Presidential Daily Brief.', 'mr-president-game' )
				: sprintf(
					/* translators: %s: month label, e.g. "April 2001". */
					__( 'Presidential Daily Brief for %s.', 'mr-president-game' ),
					$month
				),
			sprintf(
				/* translators: 1: approval percentage, 2: economy status word, 3: security status word. */
				__( 'Approval stands at %1$s percent, the economy reads %2$s and the security posture is %3$s.', 'mr-president-game' ),
				(string) $this->number( $indicators, 'approval' ),
				$this->text( $indicators, 'economy_status', __( 'unchanged', 'mr-president-game' ) ),
				$this->text( $indicators, 'security_status', __( 'steady', 'mr-president-game' ) )
			),
			$this->briefing_closing( $event, $report ),
		);

		return $this->join( $lines );
	}

	/**
	 * Closing sentence of the daily brief: the decision waiting, or the month just passed.
	 *
	 * @since 0.1.0
	 *
	 * @param array $event  Event card, possibly empty.
	 * @param array $report Turn report, possibly empty.
	 *
	 * @return string
	 */
	private function briefing_closing( array $event, array $report ) {
		if ( ! empty( $event['title'] ) ) {
			return sprintf(
				/* translators: %s: event title. */
				__( 'One item needs a decision today: %s.', 'mr-president-game' ),
				(string) $event['title']
			);
		}

		$notes = isset( $report['system_notes'] ) && is_array( $report['system_notes'] ) ? $report['system_notes'] : array();

		if ( ! empty( $notes ) ) {
			return sprintf(
				/* translators: %s: first system note from the turn report. */
				__( 'Nothing needs a signature this morning. Staff note: %s', 'mr-president-game' ),
				(string) $notes[0]
			);
		}

		return __( 'Nothing needs a signature this morning; the desk is clear.', 'mr-president-game' );
	}

	/**
	 * Sentence describing where approval sits.
	 *
	 * @since 0.1.0
	 *
	 * @param array $public_state Rounded public indicators.
	 *
	 * @return string
	 */
	private function approval_sentence( array $public_state ) {
		$approval = $this->number( $public_state, 'approval' );

		if ( $approval >= 60 ) {
			return sprintf(
				/* translators: %s: approval percentage. */
				__( 'Approval remains strong at %s percent, which buys the room a little patience.', 'mr-president-game' ),
				(string) $approval
			);
		}

		if ( $approval >= 45 ) {
			return sprintf(
				/* translators: %s: approval percentage. */
				__( 'Approval sits at %s percent, close enough to the middle that either reading survives the week.', 'mr-president-game' ),
				(string) $approval
			);
		}

		return sprintf(
			/* translators: %s: approval percentage. */
			__( 'Approval is thin at %s percent, and the political room for a second mistake is thinner.', 'mr-president-game' ),
			(string) $approval
		);
	}

	/**
	 * Sentence describing the pressure the administration is under.
	 *
	 * @since 0.1.0
	 *
	 * @param array $public_state  Rounded public indicators.
	 * @param int   $memory_count  Number of memory records supplied.
	 *
	 * @return string
	 */
	private function pressure_sentence( array $public_state, $memory_count ) {
		$crisis = $this->number( $public_state, 'crisis_level' );

		if ( $crisis >= 55 ) {
			$base = __( 'The crisis board is crowded, so the recommendation is to decide early rather than well-late.', 'mr-president-game' );
		} elseif ( $crisis >= 25 ) {
			$base = __( 'Pressure is manageable, which is exactly when a decision can still be shaped.', 'mr-president-game' );
		} else {
			$base = __( 'The board is quiet enough to think a step ahead.', 'mr-president-game' );
		}

		if ( $memory_count > 0 ) {
			return $base . ' ' . sprintf(
				/* translators: %d: number of prior related decisions. */
				_n(
					'%d earlier decision is on file.',
					'%d earlier decisions are on file.',
					$memory_count,
					'mr-president-game'
				),
				$memory_count
			);
		}

		return $base;
	}

	/**
	 * Classify the tone of player-written text.
	 *
	 * @since 0.1.0
	 *
	 * @param string $lower Lowercased text.
	 *
	 * @return string
	 */
	private function speech_tone( $lower ) {
		if ( '' === $lower ) {
			return 'neutral';
		}

		if ( false !== strpos( $lower, '!' ) || false !== strpos( $lower, 'demand' ) || false !== strpos( $lower, 'must' ) ) {
			return 'forceful';
		}

		if ( false !== strpos( $lower, 'together' ) || false !== strpos( $lower, 'thank' ) ) {
			return 'conciliatory';
		}

		return 'measured';
	}

	/**
	 * First N words of a string, ellipsised.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text  Source text.
	 * @param int    $words Word budget.
	 *
	 * @return string
	 */
	private function excerpt( $text, $words ) {
		$parts = preg_split( '/\s+/', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return '';
		}

		if ( count( $parts ) <= $words ) {
			return implode( ' ', $parts );
		}

		return implode( ' ', array_slice( $parts, 0, $words ) ) . '…';
	}

	/**
	 * Read a string field with a fallback.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $source   Source array.
	 * @param string $key      Field name.
	 * @param string $fallback Value when absent or empty.
	 *
	 * @return string
	 */
	private function text( array $source, $key, $fallback ) {
		if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) && '' !== (string) $source[ $key ] ) {
			return (string) $source[ $key ];
		}

		return $fallback;
	}

	/**
	 * Read a numeric field, defaulting to zero.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $source Source array.
	 * @param string $key    Field name.
	 *
	 * @return float
	 */
	private function number( array $source, $key ) {
		return ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) ) ? (float) $source[ $key ] : 0.0;
	}

	/**
	 * Lowercase the first character of a label so it reads inside a sentence.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Source text.
	 *
	 * @return string
	 */
	private function lower_first( $text ) {
		$value = (string) $text;

		if ( '' === $value ) {
			return $value;
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( mb_substr( $value, 0, 1 ) ) . mb_substr( $value, 1 );
		}

		return strtolower( substr( $value, 0, 1 ) ) . substr( $value, 1 );
	}

	/**
	 * Join sentences into a short paragraph, dropping empties.
	 *
	 * @since 0.1.0
	 *
	 * @param array $lines Sentences.
	 *
	 * @return string
	 */
	private function join( array $lines ) {
		$kept = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' !== $line ) {
				$kept[] = $line;
			}
		}

		return implode( ' ', $kept );
	}
}
