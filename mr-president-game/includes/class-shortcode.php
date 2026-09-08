<?php
/**
 * The [mr_president_game] shortcode.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the game shell wherever the shortcode appears (spec section 8.5).
 *
 * The shortcode is the only thing that has to work in every theme, so it asks
 * {@see Assets} for its scripts rather than relying on a `post_content` scan: that makes
 * it correct inside widgets, blocks and template parts too.
 */
final class Shortcode {

	/**
	 * Shortcode tag.
	 */
	const TAG = 'mr_president_game';

	/**
	 * Template rendered by the shortcode, relative to the plugin root.
	 */
	const TEMPLATE = 'templates/game-shell.php';

	/**
	 * Asset loader.
	 *
	 * @var Assets
	 */
	private $assets;

	/**
	 * @since 0.1.0
	 *
	 * @param Assets $assets Asset loader to notify when the shell renders.
	 */
	public function __construct( Assets $assets ) {
		$this->assets = $assets;
	}

	/**
	 * Register the shortcode.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render the shell.
	 *
	 * @since 0.1.0
	 *
	 * @param array|string $atts    Shortcode attributes; none are supported in 0.1.0.
	 * @param string|null  $content Enclosed content, unused.
	 * @param string       $tag     Shortcode tag, unused.
	 *
	 * @return string Escaped markup.
	 */
	public function render( $atts = array(), $content = null, $tag = '' ) {
		unset( $atts, $content, $tag );

		$this->assets->request();

		$template = MRP_PLUGIN_DIR . self::TEMPLATE;

		if ( ! is_readable( $template ) ) {
			return '';
		}

		// Consumed by the template; see templates/game-shell.php.
		$config = self::shell_config();

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * The `$config` array the shell template expects.
	 *
	 * @since 0.1.0
	 *
	 * @return array {
	 *     @type bool   $is_logged_in Whether the visitor is signed in.
	 *     @type string $login_url    Where to send a signed-out visitor.
	 * }
	 */
	public static function shell_config() {
		$permalink = is_singular() ? get_permalink() : '';
		$redirect  = ( is_string( $permalink ) && '' !== $permalink ) ? $permalink : home_url( '/' );

		return array(
			'is_logged_in' => is_user_logged_in(),
			'login_url'    => wp_login_url( $redirect ),
		);
	}
}
