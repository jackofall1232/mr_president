<?php
/**
 * Settings screen.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

use MrPresident\Engine\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → Mr. President (engineering spec section 8.6).
 *
 * Three things live here: the developer-mode switch, a one-click "create the game page"
 * action, and a read-only status panel. Every write goes through the Settings API or
 * `admin-post.php` with a nonce and a `manage_options` check.
 */
final class Admin {

	/**
	 * Menu slug and settings page id.
	 */
	const PAGE_SLUG = 'mr-president-game';

	/**
	 * Settings group registered with `register_setting()`.
	 */
	const OPTION_GROUP = 'mrp_settings';

	/**
	 * Settings section id.
	 */
	const SECTION_ID = 'mrp_settings_general';

	/**
	 * `admin-post.php` action for the page-creation button.
	 */
	const CREATE_PAGE_ACTION = 'mrp_create_game_page';

	/**
	 * Nonce field name for that action.
	 */
	const CREATE_PAGE_NONCE = 'mrp_create_game_page_nonce';

	/**
	 * Capability required for everything on this screen.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Title of the page created by the button.
	 */
	const PAGE_TITLE = 'Mr. President';

	/**
	 * Hook the admin screens.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::CREATE_PAGE_ACTION, array( $this, 'handle_create_page' ) );
	}

	/**
	 * Add the options page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Mr. President', 'mr-president-game' ),
			__( 'Mr. President', 'mr-president-game' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the developer-mode setting and its field.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_settings() {
		AI_Settings::register();
		register_setting(
			self::OPTION_GROUP,
			MRP_OPTION_DEV_MODE,
			array(
				'type'              => 'boolean',
				'default'           => 0,
				'sanitize_callback' => array( $this, 'sanitize_dev_mode' ),
			)
		);

		add_settings_section(
			self::SECTION_ID,
			__( 'Gameplay', 'mr-president-game' ),
			array( $this, 'render_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			MRP_OPTION_DEV_MODE,
			__( 'Developer mode', 'mr-president-game' ),
			array( $this, 'render_dev_mode_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);
	}

	/**
	 * Coerce the checkbox to 0/1.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return int
	 */
	public function sanitize_dev_mode( $value ) {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Section blurb.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_section() {
		echo '<p>' . esc_html__( 'Developer mode exposes hidden variables, the RNG seed, the delayed-consequence queue and raw state to administrators only. Players never receive that data.', 'mr-president-game' ) . '</p>';
	}

	/**
	 * The developer-mode checkbox.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_dev_mode_field() {
		$enabled = (bool) get_option( MRP_OPTION_DEV_MODE, 0 );
		?>
		<label for="<?php echo esc_attr( MRP_OPTION_DEV_MODE ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( MRP_OPTION_DEV_MODE ); ?>"
				name="<?php echo esc_attr( MRP_OPTION_DEV_MODE ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Show hidden state to administrators', 'mr-president-game' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the settings screen.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this plugin.', 'mr-president-game' ) );
		}

		$page_id = (int) get_option( MRP_OPTION_GAME_PAGE_ID, 0 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mr. President', 'mr-president-game' ); ?></h1>

			<?php
			$this->render_notice();
			settings_errors();
			?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'Game page', 'mr-president-game' ); ?></h2>
			<?php $this->render_page_status( $page_id ); ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE_PAGE_ACTION ); ?>" />
				<?php wp_nonce_field( self::CREATE_PAGE_ACTION, self::CREATE_PAGE_NONCE ); ?>
				<?php submit_button( __( 'Create game page', 'mr-president-game' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Status', 'mr-president-game' ); ?></h2>
			<?php $this->render_status_table(); ?>
		</div>
		<?php
	}

	/**
	 * Show the outcome of the last "create game page" action.
	 *
	 * The value is a fixed keyword echoed back through the redirect, matched against a
	 * known list before anything is printed, so the query string cannot inject copy.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only keyword, validated below.
		$notice = isset( $_GET['mrp_notice'] ) ? sanitize_key( wp_unslash( $_GET['mrp_notice'] ) ) : '';

		$messages = array(
			'created' => array( 'success', __( 'Game page created and published.', 'mr-president-game' ) ),
			'exists'  => array( 'info', __( 'The game page already exists.', 'mr-president-game' ) ),
			'failed'  => array( 'error', __( 'The game page could not be created.', 'mr-president-game' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * Describe the currently designated game page.
	 *
	 * @since 0.1.0
	 *
	 * @param int $page_id Stored page id.
	 *
	 * @return void
	 */
	private function render_page_status( $page_id ) {
		$post = ( $page_id > 0 ) ? get_post( $page_id ) : null;

		if ( ! ( $post instanceof \WP_Post ) ) {
			echo '<p>' . esc_html__( 'No game page yet. Creating one publishes a page containing the shortcode and serves it full-screen.', 'mr-president-game' ) . '</p>';

			return;
		}

		printf(
			'<p>%1$s <a href="%2$s">%3$s</a> &middot; <a href="%4$s">%5$s</a></p>',
			esc_html__( 'Current game page:', 'mr-president-game' ),
			esc_url( (string) get_permalink( $post ) ),
			esc_html( get_the_title( $post ) ),
			esc_url( (string) get_edit_post_link( $post->ID ) ),
			esc_html__( 'edit', 'mr-president-game' )
		);
	}

	/**
	 * Read-only diagnostics.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function render_status_table() {
		$rows = array(
			__( 'Plugin version', 'mr-president-game' )    => MRP_VERSION,
			__( 'State schema version', 'mr-president-game' ) => (string) ( class_exists( Schema::class ) ? Schema::VERSION : '—' ),
			__( 'Database version', 'mr-president-game' )  => (string) get_option( MRP_OPTION_DB_VERSION, '—' ),
			__( 'Saved games', 'mr-president-game' )       => (string) Activator::row_count(),
			__( 'AI provider', 'mr-president-game' )       => Plugin::instance()->ai()->id(),
		);

		echo '<table class="widefat striped" style="max-width:32rem"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Create (or re-point to) the designated game page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_create_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mr-president-game' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::CREATE_PAGE_ACTION, self::CREATE_PAGE_NONCE );

		$existing = (int) get_option( MRP_OPTION_GAME_PAGE_ID, 0 );
		$post     = ( $existing > 0 ) ? get_post( $existing ) : null;
		$notice   = 'exists';

		if ( ! ( $post instanceof \WP_Post ) || 'trash' === $post->post_status ) {
			$page_id = wp_insert_post(
				array(
					'post_title'   => self::PAGE_TITLE,
					'post_name'    => self::PAGE_SLUG,
					'post_content' => '[' . Shortcode::TAG . ']',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				),
				true
			);

			if ( is_wp_error( $page_id ) ) {
				$notice = 'failed';
			} else {
				update_option( MRP_OPTION_GAME_PAGE_ID, (int) $page_id );
				$notice = 'created';
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'mrp_notice' => $notice,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
