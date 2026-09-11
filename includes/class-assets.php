<?php
/**
 * Front-end asset registration.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and conditionally enqueues the game's CSS and JS (spec section 8.5).
 *
 * Assets never load site-wide. They load on the designated game page, on any singular post
 * whose content carries the shortcode, and whenever the shortcode itself asks for them —
 * the third case covers widgets, blocks and template parts, where scanning `post_content`
 * would miss the shortcode entirely.
 */
final class Assets {

	/**
	 * Stylesheet handle.
	 */
	const STYLE_HANDLE = 'mrp-game';

	/**
	 * Handle of the boot script; `MRP_CONFIG` is attached to it and it loads last.
	 */
	const APP_HANDLE = 'mrp-app';

	/**
	 * The `window.MRP_CONFIG` object name.
	 */
	const CONFIG_OBJECT = 'MRP_CONFIG';

	/**
	 * Base scripts, in load order. Handle => path relative to the plugin root.
	 */
	const BASE_SCRIPTS = array(
		'mrp-api'   => 'assets/js/api.js',
		'mrp-store' => 'assets/js/store.js',
		'mrp-ui'    => 'assets/js/ui.js',
	);

	/**
	 * View scripts. Handle => path relative to the plugin root.
	 *
	 * `views/side-panel.js` is the right-hand rail that `views/dashboard.js` renders into;
	 * dashboard fails without it, so it is registered before dashboard and named in its
	 * dependency list.
	 */
	const VIEW_SCRIPTS = array(
		'mrp-view-campaign'   => 'assets/js/views/campaign.js',
		'mrp-view-title'      => 'assets/js/views/title.js',
		'mrp-view-new-game'   => 'assets/js/views/new-game.js',
		'mrp-view-event-card' => 'assets/js/views/event-card.js',
		'mrp-view-outcome'    => 'assets/js/views/outcome.js',
		'mrp-view-side-panel' => 'assets/js/views/side-panel.js',
		'mrp-view-dashboard'  => 'assets/js/views/dashboard.js',
		'mrp-view-dev-panel'  => 'assets/js/views/dev-panel.js',
	);

	/**
	 * Panel scripts. Handle => path relative to the plugin root.
	 */
	const PANEL_SCRIPTS = array(
		'mrp-panel-economy'   => 'assets/js/views/panels/economy.js',
		'mrp-panel-congress'  => 'assets/js/views/panels/congress.js',
		'mrp-panel-diplomacy' => 'assets/js/views/panels/diplomacy.js',
		'mrp-panel-security'  => 'assets/js/views/panels/security.js',
		'mrp-panel-history'   => 'assets/js/views/panels/history.js',
	);

	/**
	 * Whether something on this request asked for the assets.
	 *
	 * @var bool
	 */
	private $requested = false;

	/**
	 * Whether `wp_register_*` has already run this request.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Whether the assets are already enqueued this request.
	 *
	 * @var bool
	 */
	private $enqueued = false;

	/**
	 * Hook asset registration.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Register every handle, then enqueue when this request shows the game.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function maybe_enqueue() {
		$this->register_assets();

		if ( $this->is_game_context() ) {
			$this->enqueue();
		}
	}

	/**
	 * Ask for the assets from inside a shortcode or template.
	 *
	 * Safe to call after `wp_enqueue_scripts` has fired: every script is registered for the
	 * footer, so a late enqueue still prints before `wp_footer()`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function request() {
		$this->requested = true;

		if ( did_action( 'wp_enqueue_scripts' ) ) {
			$this->register_assets();
			$this->enqueue();
		}
	}

	/**
	 * Handles in enqueue order: base, views, panels, then the boot script.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public static function script_handles() {
		return array_merge(
			array_keys( self::BASE_SCRIPTS ),
			array_keys( self::VIEW_SCRIPTS ),
			array_keys( self::PANEL_SCRIPTS ),
			array( self::APP_HANDLE )
		);
	}

	/**
	 * The `MRP_CONFIG` payload handed to the browser.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $dev_mode Whether the current user sees developer output.
	 *
	 * @return array
	 */
	public static function config( $dev_mode = false ) {
		$user     = wp_get_current_user();
		$redirect = is_singular() ? get_permalink() : home_url( '/' );

		return array(
			'restUrl'    => esc_url_raw( rest_url( MRP_REST_NAMESPACE . '/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'devMode'    => (bool) $dev_mode,
			'isLoggedIn' => is_user_logged_in(),
			'userName'   => ( $user && ! empty( $user->display_name ) ) ? $user->display_name : '',
			'loginUrl'   => wp_login_url( $redirect ? $redirect : home_url( '/' ) ),
			'version'    => MRP_VERSION,
			'assetsUrl'  => MRP_PLUGIN_URL . 'assets/',
			'profileOptions' => array(
				'states' => \MrPresident\Engine\PresidentProfile::STATES,
				'alignments' => \MrPresident\Engine\PresidentProfile::ALIGNMENTS,
				'priorities' => \MrPresident\Engine\PresidentProfile::PRIORITIES,
			),
		);
	}

	/**
	 * Register the stylesheet and every script handle.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function register_assets() {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		wp_register_style( self::STYLE_HANDLE, MRP_PLUGIN_URL . 'assets/css/game.css', array(), MRP_VERSION );

		foreach ( self::BASE_SCRIPTS as $handle => $path ) {
			$this->register_script( $handle, $path, array() );
		}

		$base = array_keys( self::BASE_SCRIPTS );

		foreach ( self::PANEL_SCRIPTS as $handle => $path ) {
			$this->register_script( $handle, $path, $base );
		}

		$dashboard_deps = array_merge(
			$base,
			array( 'mrp-view-side-panel', 'mrp-view-event-card', 'mrp-view-outcome', 'mrp-view-campaign' ),
			array_keys( self::PANEL_SCRIPTS )
		);

		foreach ( self::VIEW_SCRIPTS as $handle => $path ) {
			$deps = ( 'mrp-view-dashboard' === $handle ) ? $dashboard_deps : $base;
			$this->register_script( $handle, $path, $deps );
		}

		$this->register_script(
			self::APP_HANDLE,
			'assets/js/app.js',
			array_merge( $base, array_keys( self::VIEW_SCRIPTS ), array_keys( self::PANEL_SCRIPTS ) )
		);
	}

	/**
	 * Register one footer script.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handle Script handle.
	 * @param string $path   Path relative to the plugin root.
	 * @param array  $deps   Dependency handles.
	 *
	 * @return void
	 */
	private function register_script( $handle, $path, array $deps ) {
		wp_register_script( $handle, MRP_PLUGIN_URL . $path, $deps, MRP_VERSION, true );
	}

	/**
	 * Enqueue everything and attach `MRP_CONFIG`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function enqueue() {
		if ( $this->enqueued ) {
			return;
		}

		$this->enqueued = true;

		wp_enqueue_style( self::STYLE_HANDLE );

		foreach ( self::script_handles() as $handle ) {
			wp_enqueue_script( $handle );
		}

		wp_localize_script( self::APP_HANDLE, self::CONFIG_OBJECT, self::config( Plugin::instance()->is_dev_mode() ) );
	}

	/**
	 * Whether this request renders the game.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	private function is_game_context() {
		if ( $this->requested ) {
			return true;
		}

		$page_id = (int) get_option( MRP_OPTION_GAME_PAGE_ID, 0 );

		if ( $page_id > 0 && is_page( $page_id ) ) {
			return true;
		}

		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();

		return ( $post instanceof \WP_Post ) && has_shortcode( (string) $post->post_content, Shortcode::TAG );
	}
}
