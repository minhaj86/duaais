<?php
/**
 * Site header.
 *
 * @package DUAAIS
 */
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#main-content"><?php esc_html_e( 'Skip to content', 'duaais' ); ?></a>

<header class="site-header" data-site-header>
	<div class="header-shell">
		<?php if ( has_custom_logo() ) : ?>
			<?php the_custom_logo(); ?>
		<?php else : ?>
			<a class="site-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
				<img class="brand-logo" src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/du-logo.jpg' ); ?>" alt="">
				<span class="brand-copy">
					<span class="brand-name">DUAAIS</span>
					<span class="brand-country"><?php esc_html_e( 'Sweden', 'duaais' ); ?></span>
				</span>
			</a>
		<?php endif; ?>

		<button class="menu-toggle" type="button" aria-expanded="false" aria-controls="primary-navigation">
			<span class="menu-toggle-lines" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Open menu', 'duaais' ); ?></span>
		</button>

		<div class="header-navigation" id="primary-navigation" data-navigation>
			<nav aria-label="<?php esc_attr_e( 'Primary navigation', 'duaais' ); ?>">
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'primary',
						'container'      => false,
						'menu_class'     => 'primary-menu',
						'fallback_cb'    => 'duaais_primary_menu_fallback',
						'depth'          => 1,
					)
				);
				?>
			</nav>

			<div class="header-actions">
				<?php if ( is_user_logged_in() ) : ?>
					<a class="button button-small button-secondary" href="<?php echo esc_url( duaais_page_url( 'mitt-konto' ) ); ?>">
						<?php esc_html_e( 'My account', 'duaais' ); ?>
					</a>
				<?php else : ?>
					<a class="button button-small button-secondary" href="<?php echo esc_url( duaais_page_url( 'logga-in' ) ); ?>">
						<?php esc_html_e( 'Log in', 'duaais' ); ?>
					</a>
				<?php endif; ?>
				<a class="button button-small button-highlight" href="<?php echo esc_url( duaais_page_url( 'bli-medlem' ) ); ?>">
					<?php esc_html_e( 'Join DUAAIS', 'duaais' ); ?>
				</a>
			</div>
		</div>
	</div>
</header>