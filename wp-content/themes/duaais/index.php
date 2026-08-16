<?php
/**
 * Blog index, archive, and search fallback.
 *
 * @package DUAAIS
 */

get_header();

$archive_title       = __( 'News', 'duaais' );
$archive_description = __( 'Official activities and updates from University of Dhaka alumni in Sweden.', 'duaais' );

if ( is_search() ) {
	$archive_title       = sprintf( __( 'Search results for: %s', 'duaais' ), get_search_query() );
	$archive_description = __( 'Posts and pages matching your search.', 'duaais' );
} elseif ( is_archive() ) {
	$archive_title       = get_the_archive_title();
	$archive_description = wp_strip_all_tags( get_the_archive_description() );
} elseif ( is_home() && get_option( 'page_for_posts' ) ) {
	$archive_title = get_the_title( (int) get_option( 'page_for_posts' ) );
}
?>

<main id="main-content">
	<header class="page-hero">
		<div class="content-shell">
			<h1><?php echo esc_html( $archive_title ); ?></h1>
			<?php if ( $archive_description ) : ?>
				<p class="archive-description"><?php echo esc_html( $archive_description ); ?></p>
			<?php endif; ?>
		</div>
	</header>

	<div class="content-shell blog-layout">
		<?php if ( have_posts() ) : ?>
			<div class="post-grid">
				<?php while ( have_posts() ) : ?>
					<?php
					the_post();
					get_template_part( 'template-parts/content', 'card' );
					?>
				<?php endwhile; ?>
			</div>

			<?php
			the_posts_pagination(
				array(
					'mid_size'  => 1,
					'prev_text' => __( 'Previous', 'duaais' ),
					'next_text' => __( 'Next', 'duaais' ),
				)
			);
			?>
		<?php else : ?>
			<div class="empty-state">
				<h2><?php esc_html_e( 'Nothing found', 'duaais' ); ?></h2>
				<p><?php esc_html_e( 'Try another search or return to the homepage.', 'duaais' ); ?></p>
				<?php get_search_form(); ?>
			</div>
		<?php endif; ?>
	</div>
</main>

<?php
get_footer();