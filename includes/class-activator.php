<?php
/**
 * Activation, database schema, and version-gated upgrades.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `{$wpdb->prefix}mrp_games` table (engineering spec section 8.1).
 *
 * dbDelta parses the CREATE TABLE text rather than executing it verbatim, so the SQL below
 * follows its formatting rules exactly: one field per line, two spaces after PRIMARY KEY,
 * KEY (never INDEX), no backticks, and a trailing semicolon.
 */
final class Activator {

	/**
	 * Table base name, appended to `$wpdb->prefix`.
	 *
	 * `current_date` is a reserved word in MySQL, hence `game_date`.
	 */
	const TABLE = 'mrp_games';

	/**
	 * Longest president name the column accepts.
	 */
	const PRESIDENT_NAME_LENGTH = 100;

	/**
	 * Fully qualified saves table name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the table and seed options. Runs on `register_activation_hook`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_table();

		update_option( MRP_OPTION_DB_VERSION, MRP_DB_VERSION );

		if ( false === get_option( MRP_OPTION_DEV_MODE, false ) ) {
			add_option( MRP_OPTION_DEV_MODE, 0 );
		}
	}

	/**
	 * Reversible cleanup only — never deletes player data.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Re-run dbDelta when the stored schema version differs from the shipped one.
	 *
	 * Hooked on `plugins_loaded` by {@see Plugin::boot()}. The version gate keeps dbDelta
	 * off the hot path: it only runs after a plugin update that bumps MRP_DB_VERSION.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Whether an upgrade ran.
	 */
	public static function maybe_upgrade() {
		$stored = (string) get_option( MRP_OPTION_DB_VERSION, '' );

		if ( MRP_DB_VERSION === $stored ) {
			return false;
		}

		self::create_table();
		update_option( MRP_OPTION_DB_VERSION, MRP_DB_VERSION );

		return true;
	}

	/**
	 * Whether the saves table exists.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $table === $found;
	}

	/**
	 * Number of saved games across all users. Used by the admin status panel.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function row_count() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Create or migrate the saves table through dbDelta.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$name_length     = self::PRESIDENT_NAME_LENGTH;

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			game_uuid CHAR(36) NOT NULL,
			president_name VARCHAR({$name_length}) NOT NULL,
			scenario_id VARCHAR(64) NOT NULL,
			game_date DATE NOT NULL,
			turn_number INT UNSIGNED NOT NULL DEFAULT 1,
			rng_seed BIGINT NOT NULL,
			schema_version SMALLINT UNSIGNED NOT NULL,
			state_json LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY game_uuid (game_uuid),
			KEY user_id (user_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
