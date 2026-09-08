<?php
/**
 * Minimal WordPress stubs for the plugin-layer smoke test.
 *
 * This file fakes just enough of WordPress to load `mr-president-game.php`, autoload the
 * `MrPresident\Plugin` classes, build a View_Model payload and register the REST routes —
 * with no database, no HTTP and no WordPress checkout.
 *
 * It is a harness, never shipped: nothing in `mr-president-game/` may require it. Hooks,
 * routes and enqueued handles are recorded in `MRP_Test_WP` so assertions can inspect what
 * the plugin asked WordPress to do.
 *
 * @package MrPresident
 */

declare(strict_types=1);

/**
 * Recording surface for the stubbed WordPress functions.
 */
final class MRP_Test_WP {

	/** @var array<string, array<int, callable>> Actions and filters, by hook name. */
	public static $hooks = array();

	/** @var array<int, array> Every register_rest_route() call. */
	public static $routes = array();

	/** @var array<string, mixed> The options table. */
	public static $options = array();

	/** @var array<int, string> Handles passed to wp_enqueue_script()/wp_enqueue_style(). */
	public static $enqueued = array();

	/** @var array<string, array> Localized script data, by object name. */
	public static $localized = array();

	/** @var array<string, callable> Registered shortcodes. */
	public static $shortcodes = array();

	/** @var bool Whether the fake current user is signed in. */
	public static $logged_in = true;

	/** @var bool Whether the fake current user can manage options. */
	public static $can_manage = false;

	/**
	 * Forget everything recorded so far.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$hooks      = array();
		self::$routes     = array();
		self::$options    = array();
		self::$enqueued   = array();
		self::$localized  = array();
		self::$shortcodes = array();
	}

	/**
	 * Every endpoint configuration across every registered route.
	 *
	 * `register_rest_route()` accepts either one endpoint array or a list of them; this
	 * flattens both shapes to `[ route => endpoint config ]` pairs.
	 *
	 * @return array<int, array> Rows of `['route' => string, 'config' => array]`.
	 */
	public static function endpoints() {
		$out = array();

		foreach ( self::$routes as $route ) {
			$args = $route['args'];
			$list = isset( $args['methods'] ) ? array( $args ) : $args;

			foreach ( $list as $config ) {
				if ( is_array( $config ) ) {
					$out[] = array(
						'route'  => $route['namespace'] . $route['route'],
						'config' => $config,
					);
				}
			}
		}

		return $out;
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/* --- Paths ---------------------------------------------------------------- */

function plugin_dir_path( $file ) {
	return rtrim( str_replace( '\\', '/', dirname( (string) $file ) ), '/' ) . '/';
}

function plugin_dir_url( $file ) {
	unset( $file );

	return 'https://example.test/wp-content/plugins/mr-president-game/';
}

function home_url( $path = '/' ) {
	return 'https://example.test' . (string) $path;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . (string) $path;
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}

function get_permalink( $post = null ) {
	unset( $post );

	return 'https://example.test/mr-president/';
}

function wp_login_url( $redirect = '' ) {
	return 'https://example.test/wp-login.php?redirect_to=' . rawurlencode( (string) $redirect );
}

/* --- Hooks ---------------------------------------------------------------- */

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );
	MRP_Test_WP::$hooks[ (string) $hook ][] = $callback;

	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_action( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value ) {
	unset( $hook );

	return $value;
}

function do_action( $hook ) {
	unset( $hook );
}

function did_action( $hook ) {
	return isset( MRP_Test_WP::$hooks[ (string) $hook ] ) ? count( MRP_Test_WP::$hooks[ (string) $hook ] ) : 0;
}

function register_activation_hook( $file, $callback ) {
	unset( $file );
	MRP_Test_WP::$hooks['activate'][] = $callback;
}

function register_deactivation_hook( $file, $callback ) {
	unset( $file );
	MRP_Test_WP::$hooks['deactivate'][] = $callback;
}

function add_shortcode( $tag, $callback ) {
	MRP_Test_WP::$shortcodes[ (string) $tag ] = $callback;
}

function has_shortcode( $content, $tag ) {
	return false !== strpos( (string) $content, '[' . (string) $tag );
}

/* --- REST ----------------------------------------------------------------- */

function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
	unset( $override );

	MRP_Test_WP::$routes[] = array(
		'namespace' => (string) $namespace,
		'route'     => (string) $route,
		'args'      => $args,
	);

	return true;
}

function rest_ensure_response( $response ) {
	return $response;
}

function rest_authorization_required_code() {
	return 401;
}

/* --- Errors --------------------------------------------------------------- */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stand-in for the core error object.
	 */
	class WP_Error {

		/** @var string */
		public $code;

		/** @var string */
		public $message;

		/** @var array */
		public $data;

		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = is_array( $data ) ? $data : array();
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Stand-in for the core post object.
	 */
	class WP_Post {

		/** @var int */
		public $ID = 0;

		/** @var string */
		public $post_content = '';

		/** @var string */
		public $post_status = 'publish';
	}
}

/* --- Options -------------------------------------------------------------- */

function get_option( $name, $default = false ) {
	return array_key_exists( (string) $name, MRP_Test_WP::$options ) ? MRP_Test_WP::$options[ (string) $name ] : $default;
}

function update_option( $name, $value ) {
	MRP_Test_WP::$options[ (string) $name ] = $value;

	return true;
}

function add_option( $name, $value ) {
	if ( ! array_key_exists( (string) $name, MRP_Test_WP::$options ) ) {
		MRP_Test_WP::$options[ (string) $name ] = $value;
	}

	return true;
}

function delete_option( $name ) {
	unset( MRP_Test_WP::$options[ (string) $name ] );

	return true;
}

/* --- Users and context ---------------------------------------------------- */

function is_user_logged_in() {
	return MRP_Test_WP::$logged_in;
}

function current_user_can( $capability ) {
	return ( 'manage_options' === $capability ) ? MRP_Test_WP::$can_manage : true;
}

function get_current_user_id() {
	return MRP_Test_WP::$logged_in ? 1 : 0;
}

function wp_get_current_user() {
	return (object) array( 'display_name' => 'Test Player' );
}

function is_admin() {
	return false;
}

function is_singular( $type = '' ) {
	unset( $type );

	return true;
}

function is_page( $page = '' ) {
	unset( $page );

	return false;
}

function get_post( $post = null ) {
	unset( $post );

	return null;
}

/* --- Escaping, i18n, sanitising ------------------------------------------- */

function __( $text, $domain = '' ) {
	unset( $domain );

	return (string) $text;
}

function _e( $text, $domain = '' ) {
	echo __( $text, $domain );
}

function _n( $single, $plural, $number, $domain = '' ) {
	unset( $domain );

	return ( 1 === (int) $number ) ? (string) $single : (string) $plural;
}

function _x( $text, $context, $domain = '' ) {
	unset( $context, $domain );

	return (string) $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return esc_html( $text );
}

function esc_url( $url ) {
	return (string) $url;
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function esc_html__( $text, $domain = '' ) {
	return esc_html( __( $text, $domain ) );
}

function esc_html_e( $text, $domain = '' ) {
	echo esc_html__( $text, $domain );
}

function esc_attr__( $text, $domain = '' ) {
	return esc_attr( __( $text, $domain ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, (int) $options, (int) $depth );
}

function wp_generate_uuid4() {
	return sprintf(
		'%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0x0fff ),
		wp_rand( 0, 0x3fff ) | 0x8000,
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff )
	);
}

function wp_rand( $min = 0, $max = 0 ) {
	return random_int( (int) $min, (int) $max );
}

function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . md5( (string) $action );
}

function current_time( $type = 'mysql', $gmt = 0 ) {
	unset( $type, $gmt );

	return '2001-01-20 12:00:00';
}

function flush_rewrite_rules( $hard = true ) {
	unset( $hard );
}

/* --- Assets --------------------------------------------------------------- */

function wp_register_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	unset( $handle, $src, $deps, $ver, $media );

	return true;
}

function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	unset( $handle, $src, $deps, $ver, $in_footer );

	return true;
}

function wp_enqueue_style( $handle ) {
	MRP_Test_WP::$enqueued[] = (string) $handle;
}

function wp_enqueue_script( $handle ) {
	MRP_Test_WP::$enqueued[] = (string) $handle;
}

function wp_localize_script( $handle, $object_name, $data ) {
	unset( $handle );
	MRP_Test_WP::$localized[ (string) $object_name ] = $data;

	return true;
}
