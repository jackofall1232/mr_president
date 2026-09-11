<?php
/**
 * Custom-table save backend.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

use MrPresident\Engine\EngineException;
use MrPresident\Engine\GameState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores games in `{$wpdb->prefix}mrp_games` (engineering spec section 8.2).
 *
 * Every statement goes through `$wpdb->prepare` and every statement that reads or writes a
 * single game carries `user_id = %d`, so ownership is enforced by the query itself and not
 * by a caller-side check that a future refactor could drop.
 */
final class Database_Save_Store implements Save_Store_Interface {

	/**
	 * Columns selected when listing saves; the state blob is deliberately excluded.
	 */
	const LIST_COLUMNS = 'game_uuid, president_name, scenario_id, game_date, turn_number, updated_at';

	/**
	 * MySQL DATE fallback used when a state carries no parsable date.
	 */
	const DATE_FALLBACK = '2001-01-20';

	/**
	 * Row format for `$wpdb->insert()` / `$wpdb->update()`, in column order.
	 */
	const ROW_FORMAT = array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' );

	/**
	 * Last database or JSON error, for logging by the caller.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * {@inheritDoc}
	 */
	public function create( int $user_id, GameState $state ): string {
		global $wpdb;

		$uuid = (string) $state->get( 'game_uuid', '' );

		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			$state->set( 'game_uuid', $uuid );
		}

		$now  = current_time( 'mysql', true );
		$data = $this->row_from_state( $user_id, $uuid, $state, $now );

		if ( null === $data ) {
			return '';
		}

		$data['created_at'] = $now;

		$inserted = $wpdb->insert( Activator::table_name(), $data, self::ROW_FORMAT ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $inserted ) {
			$this->last_error = (string) $wpdb->last_error;

			return '';
		}

		return $uuid;
	}

	/**
	 * {@inheritDoc}
	 */
	public function load( int $user_id, string $game_uuid ): ?GameState {
		global $wpdb;

		$table = Activator::table_name();

		$json = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT state_json FROM {$table} WHERE game_uuid = %s AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$game_uuid,
				$user_id
			)
		);

		if ( null === $json || '' === $json ) {
			return null;
		}

		return $this->decode_state( (string) $json );
	}

	/**
	 * {@inheritDoc}
	 */
	public function save( int $user_id, string $game_uuid, GameState $state ): bool {
		global $wpdb;

		$data = $this->row_from_state( $user_id, $game_uuid, $state, current_time( 'mysql', true ) );

		if ( null === $data ) {
			return false;
		}

		unset( $data['user_id'], $data['game_uuid'] );

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Activator::table_name(),
			$data,
			array(
				'game_uuid' => $game_uuid,
				'user_id'   => $user_id,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ),
			array( '%s', '%d' )
		);

		if ( false === $updated ) {
			$this->last_error = (string) $wpdb->last_error;

			return false;
		}

		/*
		 * `$wpdb->update()` returns 0 both for "no such row" and for "row already identical".
		 * Autosaves after an advance always change `updated_at`, so 0 here means the row is
		 * missing or not owned by this user; confirm rather than guess.
		 */
		if ( 0 === (int) $updated ) {
			return $this->owns( $user_id, $game_uuid );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( int $user_id, string $game_uuid ): bool {
		global $wpdb;

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Activator::table_name(),
			array(
				'game_uuid' => $game_uuid,
				'user_id'   => $user_id,
			),
			array( '%s', '%d' )
		);

		return is_int( $deleted ) && $deleted > 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_for_user( int $user_id ): array {
		global $wpdb;

		$table   = Activator::table_name();
		$columns = self::LIST_COLUMNS;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT {$columns} FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$games = array();

		foreach ( $rows as $row ) {
			$games[] = array(
				'game_uuid'      => (string) $row['game_uuid'],
				'president_name' => (string) $row['president_name'],
				'scenario_id'    => (string) $row['scenario_id'],
				'game_date'      => (string) $row['game_date'],
				'turn_number'    => (int) $row['turn_number'],
				'updated_at'     => (string) $row['updated_at'],
			);
		}

		return $games;
	}

	/**
	 * The most recent database or JSON error, if any.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function last_error(): string {
		return $this->last_error;
	}

	/**
	 * Whether the user owns a game with this uuid.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id   Owner.
	 * @param string $game_uuid Game uuid.
	 *
	 * @return bool
	 */
	private function owns( int $user_id, string $game_uuid ): bool {
		global $wpdb;

		$table = Activator::table_name();

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE game_uuid = %s AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$game_uuid,
				$user_id
			)
		);

		return null !== $found;
	}

	/**
	 * Flatten a state into the table's column set.
	 *
	 * @since 0.1.0
	 *
	 * @param int       $user_id   Owner.
	 * @param string    $game_uuid Game uuid.
	 * @param GameState $state     State to store.
	 * @param string    $now       UTC MySQL timestamp.
	 *
	 * @return array|null Null when the state could not be encoded.
	 */
	private function row_from_state( int $user_id, string $game_uuid, GameState $state, string $now ) {
		$snapshot = $state->toArray();
		$json     = wp_json_encode( $snapshot );

		if ( ! is_string( $json ) || '' === $json ) {
			$this->last_error = 'state_json_encode_failed';

			return null;
		}

		return array(
			'user_id'        => $user_id,
			'game_uuid'      => $game_uuid,
			'president_name' => (string) $state->get( 'president_name', '' ),
			'scenario_id'    => (string) $state->get( 'scenario_id', '' ),
			'game_date'      => $this->mysql_date( (string) $state->get( 'date', '' ) ),
			'turn_number'    => (int) $state->turn(),
			'rng_seed'       => (int) $state->get( 'seed', 0 ),
			'schema_version' => isset( $snapshot['schema_version'] ) ? (int) $snapshot['schema_version'] : 1,
			'state_json'     => $json,
			'updated_at'     => $now,
		);
	}

	/**
	 * Decode a stored blob back into a state object.
	 *
	 * @since 0.1.0
	 *
	 * @param string $json Stored JSON document.
	 *
	 * @return GameState|null Null when the blob is unusable.
	 */
	private function decode_state( string $json ) {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			$this->last_error = 'state_json_decode_failed';

			return null;
		}

		try {
			return GameState::fromArray( $data );
		} catch ( EngineException $exception ) {
			$this->last_error = $exception->code();

			return null;
		}
	}

	/**
	 * Normalise an in-game ISO date for the DATE column.
	 *
	 * @since 0.1.0
	 *
	 * @param string $date Date from the state.
	 *
	 * @return string `YYYY-MM-DD`.
	 */
	private function mysql_date( string $date ): string {
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		return self::DATE_FALLBACK;
	}
}
