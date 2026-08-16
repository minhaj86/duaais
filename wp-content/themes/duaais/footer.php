<?php
/**
 * Site footer.
 *
 * @package DUAAIS
 */
?>
<footer class="site-footer">
	<div class="content-shell footer-grid">
		<div>
			<a class="footer-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/du-logo.jpg' ); ?>" alt="">
				<span>DUAAIS Sweden</span>
			</a>
			<p class="footer-about"><?php esc_html_e( 'Dhaka University Alumni Association in Sweden connects DU graduates who live, work, and build their lives across Sweden.', 'duaais' ); ?></p>
		</div>

		<div>
			<h2 class="footer-heading"><?php esc_html_e( 'Explore', 'duaais' ); ?></h2>
			<nav aria-label="<?php esc_attr_e( 'Footer navigation', 'duaais' ); ?>">
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'footer',
						'container'      => false,
						'menu_class'     => 'footer-menu',
						'fallback_cb'    => 'duaais_footer_menu_fallback',
						'depth'          => 1,
					)
				);
				?>
			</nav>
		</div>

		<div>
			<h2 class="footer-heading"><?php esc_html_e( 'Contact', 'duaais' ); ?></h2>
			<ul class="footer-contact">
				<li><a href="mailto:info@duaais.com">info@duaais.com</a></li>
				<li><?php esc_html_e( 'For DU alumni throughout Sweden', 'duaais' ); ?></li>
				<li><a href="<?php echo esc_url( duaais_page_url( 'kontakt' ) ); ?>"><?php esc_html_e( 'Contact the association', 'duaais' ); ?></a></li>
			</ul>
		</div>
	</div>

	<div class="content-shell footer-bottom">
		<p>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php esc_html_e( 'Dhaka University Alumni Association In Sweden. All rights reserved.', 'duaais' ); ?></p>
		<p><?php esc_html_e( 'Publisher and editor: DUAAIS Technology Team', 'duaais' ); ?></p>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>