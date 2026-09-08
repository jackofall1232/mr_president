<?php
/**
 * REST action orchestration.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

use MrPresident\Engine\EngineException;
use MrPresident\Engine\GameEngine;
use MrPresident\Engine\GameState;
use MrPresident\Plugin\AI\AI_Provider_Interface;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One method per REST action: load, call the engine, persist, publish.
 *
 * The controller is the only place where the three layers meet. It never inspects engine
 * internals and never lets a client value reach the state: a request carries a choice id
 * or nothing at all, everything else is dropped (and logged in developer mode).
 */
final class Game_Controller {

	/**
	 * Scenario used when the client does not name one.
	 */
	const DEFAULT_SCENARIO = 'new-administration';

	/**
	 * Inclusive bounds for a freshly minted RNG seed.
	 *
	 * Kept inside the 32-bit range because the engine's generator is xorshift32.
	 */
	const SEED_MIN = 1;

	/**
	 * Upper bound for a freshly minted RNG seed.
	 */
	const SEED_MAX = 2147483646;

	/**
	 * HTTP status per engine error code (engineering spec section 8.3).
	 */
	const ERROR_STATUS = array(
		'decision_required'      => 409,
		'event_already_resolved' => 409,
		'no_active_event'        => 409,
		'unknown_choice'         => 400,
		'unknown_scenario'       => 400,
		'unknown_event'          => 404,
		'invalid_content'        => 500,
	);

	/**
	 * Status used for an engine code the table above does not name.
	 */
	const FALLBACK_STATUS = 400;

	/**
	 * Simulation facade.
	 *
	 * @var GameEngine
	 */
	private $engine;

	/**
	 * Persistence.
	 *
	 * @var Save_Manager
	 */
	private $saves;

	/**
	 * State to payload translation.
	 *
	 * @var View_Model
	 */
	private $view;

	/**
	 * Prose provider.
	 *
	 * @var AI_Provider_Interface
	 */
	private $ai;

	/**
	 * Whether this request may see hidden state.
	 *
	 * @var bool
	 */
	private $debug;

	/**
	 * @since 0.1.0
	 *
	 * @param GameEngine            $engine Simulation facade.
	 * @param Save_Manager          $saves  Persistence.
	 * @param View_Model            $view   Payload builder.
	 * @param AI_Provider_Interface $ai     Prose provider.
	 * @param bool                  $debug  Whether developer mode applies to this request.
	 */
	public function __construct( GameEngine $engine, Save_Manager $saves, View_Model $view, AI_Provider_Interface $ai, $debug = false ) {
		$this->engine = $engine;
		$this->saves  = $saves;
		$this->view   = $view;
		$this->ai     = $ai;
		$this->debug  = (bool) $debug;
	}

	/**
	 * `GET /games`.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id Acting user.
	 *
	 * @return array
	 */
	public function list_games( $user_id ) {
		return array( 'games' => $this->saves->list_for_user( (int) $user_id ) );
	}

	/**
	 * `POST /game/new`.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $user_id     Acting user.
	 * @param string      $name        President name, already sanitized by the route.
	 * @param string|null $scenario_id Scenario id, or null for the default.
	 * @param array       $client      Raw request body, for developer-mode logging.
	 *
	 * @return array|WP_Error
	 */
	public function create_game( $user_id, $name, $scenario_id = null, array $client = array() ) {
		$this->note_ignored_fields( $client, array( 'president_name', 'scenario_id' ) );

		$scenario = ( is_string( $scenario_id ) && '' !== $scenario_id ) ? $scenario_id : self::DEFAULT_SCENARIO;

		try {
			$state = $this->engine->newGame( $scenario, (string) $name, $this->new_seed() );
		} catch ( EngineException $exception ) {
			return $this->engine_error( $exception );
		}

		$uuid = $this->saves->create( (int) $user_id, $state );

		if ( '' === $uuid ) {
			return $this->store_error( __( 'The new administration could not be saved.', 'mr-president-game' ) );
		}

		return array( 'game' => $this->publish( $state ) );
	}

	/**
	 * `GET /game/{uuid}`.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id Acting user.
	 * @param string $uuid    Game uuid.
	 *
	 * @return array|WP_Error
	 */
	public function get_game( $user_id, $uuid ) {
		$state = $this->saves->load( (int) $user_id, (string) $uuid );

		if ( null === $state ) {
			return $this->not_found();
		}

		return array( 'game' => $this->publish( $state ) );
	}

	/**
	 * `GET /game/{uuid}/briefing`.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id Acting user.
	 * @param string $uuid    Game uuid.
	 *
	 * @return array|WP_Error
	 */
	public function get_briefing( $user_id, $uuid ) {
		$state = $this->saves->load( (int) $user_id, (string) $uuid );

		if ( null === $state ) {
			return $this->not_found();
		}

		try {
			$structured = $this->engine->buildBriefing( $state );
		} catch ( EngineException $exception ) {
			return $this->engine_error( $exception );
		}

		$briefing = $this->view->briefing( $structured, $state );

		/*
		 * The prose is produced from the already-published payload, so the AI seam only
		 * ever sees client-safe arrays — never a GameState (engineering spec section 8.7).
		 */
		$briefing['summary'] = $this->ai->generate_briefing_summary( $briefing );

		return array( 'briefing' => $briefing );
	}

	/**
	 * `POST /game/{uuid}/decision`.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id   Acting user.
	 * @param string $uuid      Game uuid.
	 * @param string $choice_id Chosen option.
	 * @param array  $client    Raw request body, for developer-mode logging.
	 *
	 * @return array|WP_Error
	 */
	public function decide( $user_id, $uuid, $choice_id, array $client = array() ) {
		$this->note_ignored_fields( $client, array( 'choice_id' ) );

		$state = $this->saves->load( (int) $user_id, (string) $uuid );

		if ( null === $state ) {
			return $this->not_found();
		}

		try {
			$outcome = $this->engine->applyDecision( $state, (string) $choice_id );
		} catch ( EngineException $exception ) {
			return $this->engine_error( $exception );
		}

		if ( ! $this->saves->save( (int) $user_id, (string) $uuid, $state ) ) {
			return $this->store_error( __( 'The decision was made but could not be saved.', 'mr-president-game' ) );
		}

		return array(
			'outcome' => View_Model_Filters::outcome( $outcome ),
			'game'    => $this->publish( $state ),
		);
	}

	/**
	 * `POST /game/{uuid}/advance`.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id Acting user.
	 * @param string $uuid    Game uuid.
	 * @param array  $client  Raw request body, for developer-mode logging.
	 *
	 * @return array|WP_Error
	 */
	public function advance( $user_id, $uuid, array $client = array() ) {
		$this->note_ignored_fields( $client, array() );

		$state = $this->saves->load( (int) $user_id, (string) $uuid );

		if ( null === $state ) {
			return $this->not_found();
		}

		try {
			$report = $this->engine->advanceTurn( $state );
		} catch ( EngineException $exception ) {
			return $this->engine_error( $exception );
		}

		if ( ! $this->saves->save( (int) $user_id, (string) $uuid, $state ) ) {
			return $this->store_error( __( 'The month advanced but could not be saved.', 'mr-president-game' ) );
		}

		return array(
			'turn_report' => View_Model_Filters::turn_report( $report ),
			'game'        => $this->publish( $state ),
		);
	}

	/**
	 * `POST /game/{uuid}/save`.
	 *
	 * Every mutation already autosaves; this route exists so the UI can offer an explicit
	 * "save" and so a future non-database store has a flush point.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id Acting user.
	 * @param string $uuid    Game uuid.
	 *
	 * @return array|WP_Error
	 */
	public function save_game( $user_id, $uuid ) {
		$state = $this->saves->load( (int) $user_id, (string) $uuid );

		if ( null === $state ) {
			return $this->not_found();
		}

		if ( ! $this->saves->save( (int) $user_id, (string) $uuid, $state ) ) {
			return $this->store_error( __( 'The administration could not be saved.', 'mr-president-game' ) );
		}

		return array( 'saved_at' => $this->now() );
	}

	/**
	 * `DELETE /game/{uuid}`.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id Acting user.
	 * @param string $uuid    Game uuid.
	 *
	 * @return array|WP_Error
	 */
	public function delete_game( $user_id, $uuid ) {
		if ( ! $this->saves->delete( (int) $user_id, (string) $uuid ) ) {
			return $this->not_found();
		}

		return array( 'deleted' => true );
	}

	/**
	 * Build the published `game` payload for a state.
	 *
	 * @since 0.1.0
	 *
	 * @param GameState $state Authoritative state.
	 *
	 * @return array
	 */
	private function publish( GameState $state ) {
		return $this->view->game( $state, $this->debug, array( 'updated_at' => $this->now() ) );
	}

	/**
	 * A fresh RNG seed inside the engine's 32-bit range.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	private function new_seed() {
		return (int) wp_rand( self::SEED_MIN, self::SEED_MAX );
	}

	/**
	 * Current UTC timestamp in MySQL format.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Translate an engine exception into a REST error.
	 *
	 * @since 0.1.0
	 *
	 * @param EngineException $exception Thrown exception.
	 *
	 * @return WP_Error
	 */
	private function engine_error( EngineException $exception ) {
		$code   = $exception->code();
		$status = isset( self::ERROR_STATUS[ $code ] ) ? self::ERROR_STATUS[ $code ] : self::FALLBACK_STATUS;

		if ( 500 === $status && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[Mr. President] engine %s: %s', $code, $exception->getMessage() ) );
		}

		return new WP_Error(
			'mrp_' . $code,
			$exception->getMessage(),
			array( 'status' => $status )
		);
	}

	/**
	 * The 404 used for "missing or not yours".
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error
	 */
	private function not_found() {
		return new WP_Error(
			'mrp_not_found',
			__( 'That administration is not on file.', 'mr-president-game' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * The 500 used when persistence fails.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Human-readable message.
	 *
	 * @return WP_Error
	 */
	private function store_error( $message ) {
		return new WP_Error( 'mrp_save_failed', $message, array( 'status' => 500 ) );
	}

	/**
	 * Log client-sent fields the server ignored.
	 *
	 * The browser sends intent only (engineering spec section 0.3). Anything else is
	 * dropped silently in production and surfaced in the log for developers.
	 *
	 * @since 0.1.0
	 *
	 * @param array $client  Raw request body.
	 * @param array $allowed Field names the route accepts.
	 *
	 * @return array<int, string> The ignored field names.
	 */
	private function note_ignored_fields( array $client, array $allowed ) {
		$ignored = array_values( array_diff( array_keys( $client ), $allowed ) );

		if ( empty( $ignored ) || ! $this->debug ) {
			return $ignored;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[Mr. President] ignored client fields: %s', implode( ', ', $ignored ) ) );
		}

		return $ignored;
	}
}
