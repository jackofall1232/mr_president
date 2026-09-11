<?php
/**
 * Plugin Name:       Mr. President
 * Plugin URI:        https://github.com/jackofall1232/mr_president
 * Description:       A fictional presidential decision simulator: run a month-by-month administration through event cards, cabinet advice, and consequences that surface turns later. Ships as a self-contained plugin with a deterministic simulation engine.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mr. President Contributors
 * Author URI:        https://github.com/jackofall1232/mr_president
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mr-president-game
 * Domain Path:       /languages
 *
 * @package MrPresident
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Constants (engineering spec section 1).
 * ---------------------------------------------------------------------------
 */

define( 'MRP_VERSION', '0.2.0' );
define( 'MRP_PLUGIN_FILE', __FILE__ );
define( 'MRP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MRP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MRP_DATA_DIR', MRP_PLUGIN_DIR . 'data/' );
define( 'MRP_REST_NAMESPACE', 'mr-president/v1' );
define( 'MRP_OPTION_DEV_MODE', 'mrp_developer_mode' );
define( 'MRP_OPTION_GAME_PAGE_ID', 'mrp_game_page_id' );
define( 'MRP_OPTION_DB_VERSION', 'mrp_db_version' );
define( 'MRP_DB_VERSION', '1' );
define( 'MRP_MIN_PHP', '7.4' );

/**
 * Print an admin notice when the host PHP version is too old.
 *
 * The plugin declares `Requires PHP: 7.4`, but older WordPress installs and manual
 * uploads can still activate it, so the guard is repeated at runtime.
 *
 * @since 0.1.0
 *
 * @return void
 */
function mrp_php_version_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'Mr. President requires PHP %1$s or newer. This server runs PHP %2$s, so the plugin stays inactive.', 'mr-president-game' ),
				MRP_MIN_PHP,
				PHP_VERSION
			)
		)
	);
}

if ( version_compare( PHP_VERSION, MRP_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'mrp_php_version_notice' );

	return;
}

/**
 * Map a class name to a file inside the plugin (engineering spec section 1).
 *
 * - `MrPresident\Engine\Foo`            → `engine/Foo.php` (sub-namespaces are sub-folders).
 * - `MrPresident\Plugin\Foo_Bar`        → `includes/class-foo-bar.php`.
 * - `MrPresident\Plugin\Foo_Interface`  → `includes/interface-foo.php`.
 * - `MrPresident\Plugin\AI\Foo_Bar`     → `includes/ai/class-foo-bar.php`.
 *
 * @since 0.1.0
 *
 * @param string $class_name Fully qualified class name.
 *
 * @return string Absolute path, or an empty string when the class is not ours.
 */
function mrp_class_to_path( $class_name ) {
	$prefix = 'MrPresident\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return '';
	}

	$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
	$layer = array_shift( $parts );

	if ( empty( $parts ) ) {
		return '';
	}

	if ( 'Engine' === $layer ) {
		return MRP_PLUGIN_DIR . 'engine/' . implode( '/', $parts ) . '.php';
	}

	if ( 'Plugin' !== $layer ) {
		return '';
	}

	$short  = array_pop( $parts );
	$folder = empty( $parts ) ? '' : strtolower( implode( '/', $parts ) ) . '/';
	$suffix = '_Interface';
	$is_interface = ( strlen( $short ) > strlen( $suffix ) && substr( $short, - strlen( $suffix ) ) === $suffix );

	if ( $is_interface ) {
		$short = substr( $short, 0, - strlen( $suffix ) );
	}

	$file = ( $is_interface ? 'interface-' : 'class-' ) . str_replace( '_', '-', strtolower( $short ) ) . '.php';

	return MRP_PLUGIN_DIR . 'includes/' . $folder . $file;
}

/**
 * Autoload plugin and engine classes.
 *
 * @since 0.1.0
 *
 * @param string $class_name Fully qualified class name.
 *
 * @return void
 */
function mrp_autoload( $class_name ) {
	$path = mrp_class_to_path( $class_name );

	if ( '' !== $path && is_readable( $path ) ) {
		require_once $path;
	}
}

spl_autoload_register( 'mrp_autoload' );

/**
 * Create the saves table and seed options on activation.
 *
 * @since 0.1.0
 *
 * @return void
 */
function mrp_activate() {
	\MrPresident\Plugin\Activator::activate();
}

/**
 * Reversible cleanup on deactivation. User data is only removed by uninstall.php.
 *
 * @since 0.1.0
 *
 * @return void
 */
function mrp_deactivate() {
	\MrPresident\Plugin\Activator::deactivate();
}

register_activation_hook( __FILE__, 'mrp_activate' );
register_deactivation_hook( __FILE__, 'mrp_deactivate' );

/**
 * Boot the plugin once WordPress has loaded every active plugin.
 *
 * @since 0.1.0
 *
 * @return void
 */
function mrp_boot() {
	\MrPresident\Plugin\Plugin::instance()->boot();
}

add_action( 'plugins_loaded', 'mrp_boot' );
