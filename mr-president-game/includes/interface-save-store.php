<?php
/**
 * Persistence seam for saved games.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

use MrPresident\Engine\GameState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract every save backend implements (engineering spec section 8.2).
 *
 * Ownership is part of the contract, not a caller responsibility: every method takes the
 * acting `$user_id` and must scope its query by it, so a valid uuid belonging to another
 * user is indistinguishable from a uuid that does not exist.
 */
interface Save_Store_Interface {

	/**
	 * Persist a brand new game.
	 *
	 * @since 0.1.0
	 *
	 * @param int       $user_id Owner.
	 * @param GameState $state   Authoritative state; must already carry `game_uuid`.
	 *
	 * @return string The stored `game_uuid`, or an empty string on failure.
	 */
	public function create( int $user_id, GameState $state ): string;

	/**
	 * Load a game owned by the given user.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id   Owner.
	 * @param string $game_uuid Game uuid.
	 *
	 * @return GameState|null Null when missing, unreadable, or owned by somebody else.
	 */
	public function load( int $user_id, string $game_uuid ): ?GameState;

	/**
	 * Overwrite an existing game.
	 *
	 * @since 0.1.0
	 *
	 * @param int       $user_id   Owner.
	 * @param string    $game_uuid Game uuid.
	 * @param GameState $state     New state.
	 *
	 * @return bool Whether a row owned by the user was written.
	 */
	public function save( int $user_id, string $game_uuid, GameState $state ): bool;

	/**
	 * Delete a game.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id   Owner.
	 * @param string $game_uuid Game uuid.
	 *
	 * @return bool Whether a row owned by the user was removed.
	 */
	public function delete( int $user_id, string $game_uuid ): bool;

	/**
	 * List a user's saves, newest first.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id Owner.
	 *
	 * @return array<int, array> Rows of `game_uuid`, `president_name`, `scenario_id`,
	 *                           `game_date`, `turn_number`, `updated_at`.
	 */
	public function list_for_user( int $user_id ): array;
}
