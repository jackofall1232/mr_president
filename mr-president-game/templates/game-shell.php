<?php
/**
 * The game shell.
 *
 * Rendered by MrPresident\Plugin\Shortcode for the [mr_president_game] shortcode and
 * reused by templates/game-page.php on the designated full-page game page.
 *
 * The markup is deliberately minimal: a single root element the front-end script owns,
 * plus the two states the browser can be in before JavaScript takes over — no script,
 * and not logged in. Everything the script needs beyond this comes from MRP_CONFIG.
 *
 * Expected variable, provided by the including class:
 *
 * @var array $config {
 *     @type bool   $is_logged_in Whether the current visitor is logged in.
 *     @type string $login_url    URL to send logged-out visitors to.
 * }
 *
 * @package MrPresident
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mrp_config       = ( isset( $config ) && is_array( $config ) ) ? $config : array();
$mrp_is_logged_in = ! empty( $mrp_config['is_logged_in'] );
$mrp_login_url    = isset( $mrp_config['login_url'] ) ? (string) $mrp_config['login_url'] : '';
?>
<div
	id="mrp-app"
	class="mrp-app"
	data-mrp
	data-mrp-state="<?php echo esc_attr( $mrp_is_logged_in ? 'ready' : 'locked' ); ?>"
>
	<noscript>
		<div class="mrp-noscript">
			<div class="mrp-gate__inner">
				<p class="mrp-hero__kicker"><?php esc_html_e( 'Executive Office', 'mr-president-game' ); ?></p>
				<h1 class="mrp-hero__title"><?php esc_html_e( 'Mr. President', 'mr-president-game' ); ?></h1>
				<p class="mrp-gate__text">
					<?php esc_html_e( 'This game needs JavaScript. Enable it in your browser and reload the page to take the oath.', 'mr-president-game' ); ?>
				</p>
			</div>
		</div>
	</noscript>

	<?php if ( ! $mrp_is_logged_in ) : ?>
		<div class="mrp-gate">
			<div class="mrp-gate__inner">
				<p class="mrp-hero__kicker"><?php esc_html_e( 'Executive Office', 'mr-president-game' ); ?></p>
				<h1 class="mrp-hero__title"><?php esc_html_e( 'Mr. President', 'mr-president-game' ); ?></h1>
				<div class="mrp-hero__rule"></div>
				<p class="mrp-gate__text">
					<?php esc_html_e( 'Saved administrations belong to an account, so you need to sign in before taking the oath.', 'mr-president-game' ); ?>
				</p>
				<?php if ( '' !== $mrp_login_url ) : ?>
					<a class="mrp-btn mrp-btn--primary mrp-btn--lg mrp-gate__link" href="<?php echo esc_url( $mrp_login_url ); ?>">
						<?php esc_html_e( 'Log in to play', 'mr-president-game' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
	<?php else : ?>
		<div class="mrp-loading" role="status">
			<div class="mrp-spinner" aria-hidden="true"></div>
			<p class="mrp-loading__label"><?php esc_html_e( 'Opening the situation room', 'mr-president-game' ); ?></p>
		</div>
	<?php endif; ?>
</div>
