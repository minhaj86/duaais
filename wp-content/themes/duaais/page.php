<?php
/**
 * Standard page template.
 *
 * @package DUAAIS
 */

get_header();
?>

<main id="main-content">
	<?php while ( have_posts() ) : ?>
		<?php the_post(); ?>
		<header class="page-hero">
			<div class="content-shell page-hero-layout">
				<?php if ( is_page( array( 'om-foreningen', 'activities', 'executive-committee', 'resources', 'bli-medlem', 'logga-in', 'mitt-konto' ) ) ) : ?>
					<img class="page-hero-logo" src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/du-logo.jpg' ); ?>" alt="">
				<?php endif; ?>
				<div>
					<h1><?php the_title(); ?></h1>
					<?php if ( has_excerpt() ) : ?>
						<p><?php echo esc_html( get_the_excerpt() ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</header>

		<div class="section">
			<article <?php post_class( 'content-shell page-content' ); ?>>
				<?php the_content(); ?>
				<?php
				wp_link_pages(
					array(
						'before' => '<nav class="page-links" aria-label="' . esc_attr__( 'Pagination', 'duaais' ) . '">',
						'after'  => '</nav>',
					)
				);
				?>
			</article>
		</div>
	<?php endwhile; ?>
</main>

<?php
get_footer();