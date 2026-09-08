<?php
/**
 * Standalone full-page template for the designated game page.
 *
 * Served through the `template_include` filter when the requested page id matches
 * MRP_OPTION_GAME_PAGE_ID (spec section 8.5). It is a complete document rather than a
 * theme template so the game fills the viewport with no theme chrome, while still calling
 * wp_head() and wp_footer() so enqueued assets, MRP_CONFIG and the admin bar behave.
 *
 * The shell itself is rendered by the shortcode when no $config array was supplied by the
 * including class, so this template works whether the Plugin passes a prepared config or
 * simply includes the file.
 *
 * @var array $config Optional config array; see templates/game-shell.php.
 *
 * @package MrPresident
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'mrp-fullscreen' ); ?>>
<?php
if ( isset( $config ) && is_array( $config ) ) {
	include __DIR__ . '/game-shell.php';
} else {
	echo do_shortcode( '[mr_president_game]' );
}

wp_footer();
?>
</body>
</html>
