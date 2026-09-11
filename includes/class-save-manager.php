<?php
/**
 * Save orchestration.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

use MrPresident\Engine\GameState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single entry point the controller uses to persist and retrieve games.
 *
 * `Save_Manager` owns identity (uuid minting) and leaves storage to the injected
 * {@see Save_Store_Interface}; 0.1.0 ships only {@see Database_Save_Store}, but the seam
 * keeps a future transient or object-cache store a constructor argument away.
 */
final class Save_Manager {

	/**
	 * Active backend.
	 *
	 * @var Save_Store_Interface
	 */
	private $store;

	/**
	 * @since 0.1.0
	 *
	 * @param Save_Store_Interface $store Backend to persist through.
	 */
	public function __construct( Save_Store_Interface $store ) {
		$this->store = $store;
	}

	/**
	 * The active backend, exposed for diagnostics.
	 *
	 * @since 0.1.0
	 *
	 * @return Save_Store_Interface
	 */
	public function store(): Save_Store_Interface {
		return $this->store;
	}

	/**
	 * Mint a uuid, stamp it onto the state, and persist the game.
	 *
	 * @since 0.1.0
	 *
	 * @param int       $user_id Owner.
	 * @param GameState $state   Freshly built state.
	 *
	 * @return string The stored uuid, or an empty string when the write failed.
	 */
	public function create( int $user_id, GameState $state ): string {
		$uuid = (string) $state->get( 'game_uuid', '' );

		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			$state->set( 'game_uuid', $uuid );
		}

		return $this->store->create( $user_id, $state );
	}

	/**
	 * Load a game the user owns.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id   Owner.
	 * @param string $game_uuid Game uuid.
	 *
	 * @return GameState|null Null when missing or not owned.
	 */
	public function load( int $user_id, string $game_uuid ): ?GameState {
		if ( ! self::is_uuid( $game_uuid ) ) {
			return null;
		}

		return $this->store->load( $user_id, $game_uuid );
	}

	/**
	 * Persist an updated state.
	 *
	 * @since 0.1.0
	 *
	 * @param int       $user_id   Owner.
	 * @param string    $game_uuid Game uuid.
	 * @param GameState $state     State to store.
	 *
	 * @return bool
	 */
	public function save( int $user_id, string $game_uuid, GameState $state ): bool {
		if ( ! self::is_uuid( $game_uuid ) ) {
			return false;
		}

		return $this->store->save( $user_id, $game_uuid, $state );
	}

	/**
	 * Delete a game.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id   Owner.
	 * @param string $game_uuid Game uuid.
	 *
	 * @return bool
	 */
	public function delete( int $user_id, string $game_uuid ): bool {
		if ( ! self::is_uuid( $game_uuid ) ) {
			return false;
		}

		return $this->store->delete( $user_id, $game_uuid );
	}

	/**
	 * A user's saves, newest first.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id Owner.
	 *
	 * @return array<int, array>
	 */
	public function list_for_user( int $user_id ): array {
		return $this->store->list_for_user( $user_id );
	}

	/**
	 * Whether a string is a canonical v4-shaped uuid.
	 *
	 * Mirrors the REST route regex so a malformed id never reaches the database layer.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Candidate.
	 *
	 * @return bool
	 */
	public static function is_uuid( string $value ): bool {
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $value );
	}
}
