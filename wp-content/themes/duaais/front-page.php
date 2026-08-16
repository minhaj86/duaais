<?php
/**
 * Front page template.
 *
 * @package DUAAIS
 */

get_header();
?>

<main id="main-content">
	<section class="home-hero" aria-labelledby="hero-title">
		<img class="home-hero-image" src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/curzon-hall-panorama.jpg' ); ?>" alt="" fetchpriority="high">
		<div class="hero-content">
			<img class="hero-logo" src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/du-logo.jpg' ); ?>" alt="<?php esc_attr_e( 'Dhaka University Alumni Association in Sweden', 'duaais' ); ?>" fetchpriority="high">
			<p class="hero-kicker"><?php esc_html_e( 'University of Dhaka alumni in Sweden', 'duaais' ); ?></p>
			<h1 class="hero-title" id="hero-title">DUAAIS Sweden</h1>
			<p class="hero-copy"><?php esc_html_e( 'A social and cultural community for University of Dhaka graduates living in Sweden. Reconnect with old friends, build new relationships, and keep the DU spirit alive.', 'duaais' ); ?></p>
			<div class="hero-actions">
				<a class="button button-highlight" href="<?php echo esc_url( duaais_page_url( 'bli-medlem' ) ); ?>"><?php esc_html_e( 'Join the association', 'duaais' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( duaais_page_url( 'om-foreningen' ) ); ?>"><?php esc_html_e( 'About DUAAIS', 'duaais' ); ?></a>
			</div>
		</div>
	</section>

	<section class="purpose-strip" aria-label="<?php esc_attr_e( 'Our purpose', 'duaais' ); ?>">
		<div class="content-shell">
			<ul class="purpose-list">
				<li>
					<span class="purpose-number">01</span>
					<span class="purpose-copy"><strong><?php esc_html_e( 'Reconnect', 'duaais' ); ?></strong><span><?php esc_html_e( 'Find friends and classmates from DU', 'duaais' ); ?></span></span>
				</li>
				<li>
					<span class="purpose-number">02</span>
					<span class="purpose-copy"><strong><?php esc_html_e( 'Belong', 'duaais' ); ?></strong><span><?php esc_html_e( 'Build community across Sweden', 'duaais' ); ?></span></span>
				</li>
				<li>
					<span class="purpose-number">03</span>
					<span class="purpose-copy"><strong><?php esc_html_e( 'Celebrate', 'duaais' ); ?></strong><span><?php esc_html_e( 'Share culture, sports, and achievements', 'duaais' ); ?></span></span>
				</li>
			</ul>
		</div>
	</section>

	<section class="section">
		<div class="content-shell welcome-layout">
			<div class="welcome-media">
				<img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/picnic-2.jpg' ); ?>" alt="<?php esc_attr_e( 'DUAAIS members and families at a summer picnic', 'duaais' ); ?>" loading="lazy">
				<div class="welcome-note">
					<strong><?php esc_html_e( 'From Dhaka to Sweden', 'duaais' ); ?></strong>
					<p><?php esc_html_e( 'Connecting generations, faculties, and cities since 1996.', 'duaais' ); ?></p>
				</div>
			</div>

			<div class="welcome-copy">
				<span class="section-label"><?php esc_html_e( 'Our DU community', 'duaais' ); ?></span>
				<h2><?php esc_html_e( 'Dhaka roots, life in Sweden, and a shared future', 'duaais' ); ?></h2>
				<p><?php esc_html_e( 'DUAAIS Sweden brings together University of Dhaka graduates who reside in Sweden. Alumni from different generations, faculties, professions, and perspectives meet in an independent, non-political community.', 'duaais' ); ?></p>
				<ul class="benefit-list">
					<li><?php esc_html_e( 'Social, cultural, and family activities throughout the year', 'duaais' ); ?></li>
					<li><?php esc_html_e( 'Youth culture, sports, literature, and Bengali festivities', 'duaais' ); ?></li>
					<li><?php esc_html_e( 'Recognition of Bangladeshi achievements in Swedish society', 'duaais' ); ?></li>
				</ul>
				<a class="text-link" href="<?php echo esc_url( duaais_page_url( 'om-foreningen' ) ); ?>"><?php esc_html_e( 'Read our aims and goals', 'duaais' ); ?></a>
			</div>
		</div>
	</section>

	<section class="section legacy-campus" aria-labelledby="campus-title">
		<div class="content-shell">
			<div class="section-heading-row">
				<div class="section-heading">
					<span class="section-label"><?php esc_html_e( 'From the original DUAAIS website', 'duaais' ); ?></span>
					<h2 id="campus-title"><?php esc_html_e( 'University of Dhaka landmarks', 'duaais' ); ?></h2>
				</div>
			</div>
			<div class="campus-grid">
				<figure><img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/arts-building.jpg' ); ?>" alt="<?php esc_attr_e( 'Arts Building at the University of Dhaka', 'duaais' ); ?>"><figcaption><?php esc_html_e( 'Arts Building', 'duaais' ); ?></figcaption></figure>
				<figure><img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/oporajeyo-bangla.jpg' ); ?>" alt="<?php esc_attr_e( 'Oporajeyo Bangla sculpture', 'duaais' ); ?>"><figcaption><?php esc_html_e( 'Oporajeyo Bangla', 'duaais' ); ?></figcaption></figure>
				<figure><img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/curzon-hall.jpg' ); ?>" alt="<?php esc_attr_e( 'Curzon Hall at the University of Dhaka', 'duaais' ); ?>"><figcaption><?php esc_html_e( 'Curzon Hall', 'duaais' ); ?></figcaption></figure>
			</div>
		</div>
	</section>

	<section class="section latest-section" aria-labelledby="latest-title">
		<div class="content-shell">
			<div class="section-heading-row">
				<div class="section-heading">
					<span class="section-label"><?php esc_html_e( 'Latest updates', 'duaais' ); ?></span>
					<h2 id="latest-title"><?php esc_html_e( 'From DUAAIS Sweden', 'duaais' ); ?></h2>
					<p><?php esc_html_e( 'Official activities and community updates synced from duaais.com.', 'duaais' ); ?></p>
				</div>
				<a class="text-link" href="<?php echo esc_url( duaais_page_url( 'nyheter' ) ); ?>"><?php esc_html_e( 'All news', 'duaais' ); ?></a>
			</div>

			<div class="post-grid">
				<?php
				$latest_posts = new WP_Query(
					array(
						'post_type'           => 'post',
						'posts_per_page'      => 3,
						'ignore_sticky_posts' => true,
					)
				);

				if ( $latest_posts->have_posts() ) :
					while ( $latest_posts->have_posts() ) :
						$latest_posts->the_post();
						get_template_part( 'template-parts/content', 'card' );
					endwhile;
					wp_reset_postdata();
				else :
					?>
					<div class="empty-state">
						<h3><?php esc_html_e( 'The first update is coming soon', 'duaais' ); ?></h3>
						<p><?php esc_html_e( 'Sign in to WordPress to publish an association update.', 'duaais' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</section>

	<section class="community-band" aria-labelledby="community-title">
		<img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/stockholm.jpg' ); ?>" alt="" loading="lazy">
		<div class="content-shell community-content">
			<div class="community-copy">
				<span class="section-label"><?php esc_html_e( 'Our mission', 'duaais' ); ?></span>
				<h2 class="visually-hidden" id="community-title"><?php esc_html_e( 'DUAAIS aims and goals', 'duaais' ); ?></h2>
				<blockquote><?php esc_html_e( 'Promoting social and cultural relations among University of Dhaka graduates presently residing in Sweden.', 'duaais' ); ?></blockquote>
				<cite><?php esc_html_e( 'DUAAIS Aim and Goals', 'duaais' ); ?></cite>
			</div>
		</div>
	</section>

	<section class="join-band">
		<div class="content-shell join-layout">
			<div>
				<h2><?php esc_html_e( 'Are you a DU graduate living in Sweden?', 'duaais' ); ?></h2>
				<p><?php esc_html_e( 'Create your free account and join Dhaka University Alumni Association in Sweden.', 'duaais' ); ?></p>
			</div>
			<a class="button" href="<?php echo esc_url( duaais_page_url( 'bli-medlem' ) ); ?>"><?php esc_html_e( 'Create an alumni account', 'duaais' ); ?></a>
		</div>
	</section>
</main>

<?php
get_footer();