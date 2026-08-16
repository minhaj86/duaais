<?php
/**
 * Post card used on archives and the front page.
 *
 * @package DUAAIS
 */
?>
<article <?php post_class( 'post-card' ); ?>>
	<a class="post-card-image" href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1">
		<?php if ( has_post_thumbnail() ) : ?>
			<?php the_post_thumbnail( 'duaais-card', array( 'loading' => 'lazy' ) ); ?>
		<?php else : ?>
			<img src="<?php echo esc_url( duaais_post_fallback_image( get_the_ID() ) ); ?>" alt="" loading="lazy">
		<?php endif; ?>
		<?php duaais_post_category(); ?>
	</a>
	<p class="post-card-meta">
		<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
	</p>
	<h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
	<div class="post-card-excerpt"><?php the_excerpt(); ?></div>
	<a class="text-link" href="<?php the_permalink(); ?>"><?php esc_html_e( 'Read more', 'duaais' ); ?><span class="screen-reader-text">: <?php the_title(); ?></span></a>
</article>