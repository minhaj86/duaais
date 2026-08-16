<?php
/**
 * Single blog article template.
 *
 * @package DUAAIS
 */

get_header();
?>

<main id="main-content" class="single-article">
	<?php while ( have_posts() ) : ?>
		<?php the_post(); ?>
		<article <?php post_class(); ?>>
			<header class="page-hero article-header">
				<div class="content-shell">
					<span class="section-label"><?php esc_html_e( 'From the DUAAIS community', 'duaais' ); ?></span>
					<h1><?php the_title(); ?></h1>
					<div class="article-meta">
						<span><?php echo esc_html( get_the_date() ); ?></span>
						<span><?php printf( esc_html__( 'By %s', 'duaais' ), esc_html( get_the_author() ) ); ?></span>
						<?php if ( get_the_category_list() ) : ?>
							<span><?php the_category( ', ' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<?php the_post_thumbnail( 'duaais-featured', array( 'class' => 'article-featured-image' ) ); ?>
			<?php endif; ?>

			<div class="narrow-shell entry-content">
				<?php the_content(); ?>
				<?php
				wp_link_pages(
					array(
						'before' => '<nav class="page-links" aria-label="' . esc_attr__( 'Pagination', 'duaais' ) . '">',
						'after'  => '</nav>',
					)
				);
				?>

				<?php if ( get_the_tag_list() ) : ?>
					<footer class="article-footer">
						<strong><?php esc_html_e( 'Topics:', 'duaais' ); ?></strong>
						<?php the_tags( '', ', ' ); ?>
					</footer>
				<?php endif; ?>

				<?php
				the_post_navigation(
					array(
						'prev_text' => '<span class="screen-reader-text">' . esc_html__( 'Previous post:', 'duaais' ) . '</span>%title',
						'next_text' => '<span class="screen-reader-text">' . esc_html__( 'Next post:', 'duaais' ) . '</span>%title',
					)
				);
				?>

				<?php if ( comments_open() || get_comments_number() ) : ?>
					<?php comments_template(); ?>
				<?php endif; ?>
			</div>
		</article>
	<?php endwhile; ?>
</main>

<?php
get_footer();