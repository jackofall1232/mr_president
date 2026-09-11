<?php
/**
 * The AI seam.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns authoritative outcomes into prose (engineering spec section 8.7).
 *
 * Implementations receive arrays produced by {@see \MrPresident\Plugin\View_Model} and
 * return strings. Nothing behind this interface may hold a reference to a mutable
 * `GameState`, and no return value is ever fed back into the simulation: the engine owns
 * reality, this layer only describes it.
 */
interface AI_Provider_Interface {

	/**
	 * An advisor's spoken position on an event.
	 *
	 * @since 0.1.0
	 *
	 * @param array $advisor      Advisor record: `advisor_id`, `name`, `office`, `position`.
	 * @param array $event        Event card as published to the client.
	 * @param array $public_state Rounded public indicators.
	 * @param array $memories     Recent memory records.
	 *
	 * @return string
	 */
	public function generate_cabinet_response( array $advisor, array $event, array $public_state, array $memories );

	/**
	 * A short news story built around a headline.
	 *
	 * @since 0.1.0
	 *
	 * @param array $headline     Headline record: `outlet_id`, `outlet`, `title`.
	 * @param array $outcome      Outcome payload (engineering spec section 4.3).
	 * @param array $public_state Rounded public indicators.
	 *
	 * @return string
	 */
	public function generate_news_story( array $headline, array $outcome, array $public_state );

	/**
	 * A diplomatic note from one country after a decision.
	 *
	 * @since 0.1.0
	 *
	 * @param array $country  Country record: `name`, `kind`, relation values.
	 * @param array $outcome  Outcome payload.
	 * @param array $memories Recent memory records.
	 *
	 * @return string
	 */
	public function generate_diplomatic_message( array $country, array $outcome, array $memories );

	/**
	 * Read a player-written address. Returns description only, never effects.
	 *
	 * @since 0.1.0
	 *
	 * @param string $speech       Player text.
	 * @param array  $public_state Rounded public indicators.
	 *
	 * @return array `['tone' => string, 'themes' => string[]]`.
	 */
	public function analyze_player_speech( $speech, array $public_state );

	/**
	 * Atmospheric copy for a crisis card.
	 *
	 * @since 0.1.0
	 *
	 * @param array $event        Event card.
	 * @param array $public_state Rounded public indicators.
	 *
	 * @return string
	 */
	public function generate_crisis_flavor( array $event, array $public_state );

	/**
	 * A closing retrospective of the administration.
	 *
	 * @since 0.1.0
	 *
	 * @param array $memories     Memory records, oldest first.
	 * @param array $public_state Rounded public indicators.
	 *
	 * @return string
	 */
	public function summarize_presidency( array $memories, array $public_state );

	/**
	 * The `summary` line of the daily brief.
	 *
	 * Added to the interface because engineering spec section 8.7 requires the briefing
	 * summary to be produced in this layer from the structured briefing the engine returns,
	 * and no other method on the seam takes a briefing.
	 *
	 * @since 0.1.0
	 *
	 * @param array $briefing Client-safe briefing payload (engineering spec section 4.5).
	 *
	 * @return string
	 */
	public function generate_briefing_summary( array $briefing );

	/**
	 * Provider identifier, e.g. `template`.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id();
}
