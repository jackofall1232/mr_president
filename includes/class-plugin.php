<?php
/**
 * Plugin bootstrap.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

use MrPresident\Engine\ContentRepository;
use MrPresident\Engine\GameEngine;
use MrPresident\Plugin\AI\AI_Provider_Interface;
use MrPresident\Plugin\AI\Template_AI_Provider;
use MrPresident\Plugin\AI\WordPress_AI_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the WordPress layer together and owns the shared objects.
 *
 * Everything expensive is built lazily: a page view that never touches the game parses no
 * content JSON and constructs no engine. The singleton exists so the shortcode, the REST
 * layer and the admin screen agree on one content repository and one AI provider.
 */
final class Plugin {

	/**
	 * Filter that swaps the AI provider.
	 */
	const AI_PROVIDER_FILTER = 'mrp_ai_provider';

	/**
	 * Standalone full-page template, relative to the plugin root.
	 */
	const PAGE_TEMPLATE = 'templates/game-page.php';

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether {@see self::boot()} has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Content repository.
	 *
	 * @var ContentRepository|null
	 */
	private $content = null;

	/**
	 * Simulation facade.
	 *
	 * @var GameEngine|null
	 */
	private $engine = null;

	/**
	 * Prose provider.
	 *
	 * @var AI_Provider_Interface|null
	 */
	private $ai = null;

	/**
	 * Persistence.
	 *
	 * @var Save_Manager|null
	 */
	private $saves = null;

	/**
	 * Asset loader.
	 *
	 * @var Assets|null
	 */
	private $assets = null;

	/**
	 * Singletons are constructed through {@see self::instance()}.
	 */
	private function __construct() {
	}

	/**
	 * The shared instance.
	 *
	 * @since 0.1.0
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register every hook. Runs on `plugins_loaded`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		Activator::maybe_upgrade();

		$assets    = $this->assets();
		$shortcode = new Shortcode( $assets );
		$rest      = new Rest_Api( array( $this, 'controller' ) );

		$assets->register();
		$shortcode->register();
		$rest->register();

		add_filter( 'template_include', array( $this, 'filter_template' ) );

		if ( is_admin() ) {
			$admin = new Admin();
			$admin->register();
		}
	}

	/**
	 * Serve the standalone template on the designated game page.
	 *
	 * The path is returned rather than included so WordPress keeps ownership of template
	 * loading, per the template hierarchy contract.
	 *
	 * @since 0.1.0
	 *
	 * @param string $template Template chosen so far.
	 *
	 * @return string
	 */
	public function filter_template( $template ) {
		$page_id = (int) get_option( MRP_OPTION_GAME_PAGE_ID, 0 );

		if ( $page_id < 1 || ! is_page( $page_id ) ) {
			return $template;
		}

		$candidate = MRP_PLUGIN_DIR . self::PAGE_TEMPLATE;

		return is_readable( $candidate ) ? $candidate : $template;
	}

	/**
	 * The content repository, reading `data/`.
	 *
	 * @since 0.1.0
	 *
	 * @return ContentRepository
	 */
	public function content() {
		if ( null === $this->content ) {
			$this->content = new ContentRepository( MRP_DATA_DIR );
		}

		return $this->content;
	}

	/**
	 * The simulation facade.
	 *
	 * @since 0.1.0
	 *
	 * @return GameEngine
	 */
	public function engine() {
		if ( null === $this->engine ) {
			$this->engine = new GameEngine( $this->content() );
		}

		return $this->engine;
	}

	/**
	 * The AI provider, filterable through `mrp_ai_provider`.
	 *
	 * A filtered value that does not implement the interface is discarded, so a broken
	 * add-on degrades to the built-in templates instead of breaking the briefing.
	 *
	 * @since 0.1.0
	 *
	 * @return AI_Provider_Interface
	 */
	public function ai() {
		if ( null === $this->ai ) {
			$default  = 'wordpress' === get_option( AI_Settings::MODE_OPTION, 'wordpress' ) && AI_Settings::available()
				? new WordPress_AI_Provider() : new Template_AI_Provider();
			$filtered = apply_filters( self::AI_PROVIDER_FILTER, $default );

			$this->ai = ( $filtered instanceof AI_Provider_Interface ) ? $filtered : $default;
		}

		return $this->ai;
	}

	/**
	 * The save manager.
	 *
	 * @since 0.1.0
	 *
	 * @return Save_Manager
	 */
	public function saves() {
		if ( null === $this->saves ) {
			$this->saves = new Save_Manager( new Database_Save_Store() );
		}

		return $this->saves;
	}

	/**
	 * The asset loader.
	 *
	 * @since 0.1.0
	 *
	 * @return Assets
	 */
	public function assets() {
		if ( null === $this->assets ) {
			$this->assets = new Assets();
		}

		return $this->assets;
	}

	/**
	 * A view model bound to this request's developer-mode state.
	 *
	 * @since 0.1.0
	 *
	 * @return View_Model
	 */
	public function view_model() {
		return new View_Model( $this->content() );
	}

	/**
	 * A controller for the current request.
	 *
	 * Built per request rather than memoised because it captures the acting user's
	 * developer-mode state, which must not survive into another request.
	 *
	 * @since 0.1.0
	 *
	 * @return Game_Controller
	 */
	public function controller() {
		return new Game_Controller(
			$this->engine(),
			$this->saves(),
			$this->view_model(),
			$this->ai(),
			$this->is_dev_mode()
		);
	}

	/**
	 * Whether hidden state may be published to the current user.
	 *
	 * Both halves are required (engineering spec section 0.4): the site option must be on
	 * *and* the acting user must be able to manage options.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_dev_mode() {
		if ( empty( get_option( MRP_OPTION_DEV_MODE, 0 ) ) ) {
			return false;
		}

		return current_user_can( 'manage_options' );
	}
}
