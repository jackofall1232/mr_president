<?php
/**
 * Uninstall routine for Mr. President.
 *
 * Runs only when the user deletes the plugin from the Plugins screen. This is the single
 * place that destroys player data: deactivation never does (engineering spec section 8.1).
 *
 * @package MrPresident
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Options created by the plugin, removed on every site we clean.
 *
 * Kept as a literal list rather than the MRP_* constants because the plugin bootstrap
 * does not run during uninstall.
 */
$mrp_options = array(
	'mrp_developer_mode',
	'mrp_game_page_id',
	'mrp_db_version',
);

/**
 * Drop the saves table and delete the plugin options for the current site.
 *
 * @since 0.1.0
 *
 * @param array $options Option names to delete.
 *
 * @return void
 */
function mrp_uninstall_site( array $options ) {
	global $wpdb;

	$table = $wpdb->prefix . 'mrp_games';

	// The table name is built from the trusted prefix and a literal, never user input.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching

	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

if ( is_multisite() ) {
	$mrp_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $mrp_sites as $mrp_site_id ) {
		switch_to_blog( (int) $mrp_site_id );
		mrp_uninstall_site( $mrp_options );
		restore_current_blog();
	}

	foreach ( $mrp_options as $mrp_option ) {
		delete_site_option( $mrp_option );
	}
} else {
	mrp_uninstall_site( $mrp_options );
}
