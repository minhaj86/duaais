<?php
/**
 * Not found template.
 *
 * @package DUAAIS
 */

get_header();
?>

<main id="main-content">
	<header class="page-hero">
		<div class="content-shell">
			<p class="hero-kicker">404</p>
			<h1><?php esc_html_e( 'Page not found', 'duaais' ); ?></h1>
			<p><?php esc_html_e( 'The link may be outdated or the page may have moved.', 'duaais' ); ?></p>
		</div>
	</header>

	<div class="section narrow-shell">
		<div class="empty-state">
			<h2><?php esc_html_e( 'What are you looking for?', 'duaais' ); ?></h2>
			<?php get_search_form(); ?>
			<p><a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Go to homepage', 'duaais' ); ?></a></p>
		</div>
	</div>
</main>

<?php
get_footer();