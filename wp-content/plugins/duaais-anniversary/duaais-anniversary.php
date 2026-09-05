<?php
/**
 * Plugin Name: DUAAIS Anniversary Flyer
 * Description: Displays the DUAAIS 30th anniversary flyer on the homepage while the plugin is active.
 * Version: 1.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: DUAAIS Sweden
 * Text Domain: duaais-anniversary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DUAAIS_ANNIVERSARY_VERSION   = '1.1.0';
const DUAAIS_ANNIVERSARY_POST_SLUG = 'duaais-30th-anniversary-jubilee';
const DUAAIS_ANNIVERSARY_DATE      = '2026-11-06';

/**
 * Return the anniversary announcement URL with a news-page fallback.
 *
 * @return string
 */
function duaais_anniversary_post_url() {
	$post = get_page_by_path( DUAAIS_ANNIVERSARY_POST_SLUG, OBJECT, 'post' );
	if ( $post instanceof WP_Post ) {
		$permalink = get_permalink( $post );
		if ( $permalink ) {
			return $permalink;
		}
	}

	$news_page = get_page_by_path( 'nyheter', OBJECT, 'page' );
	if ( $news_page instanceof WP_Post ) {
		$permalink = get_permalink( $news_page );
		if ( $permalink ) {
			return $permalink;
		}
	}

	return home_url( '/nyheter/' );
}

/**
 * Load the flyer stylesheet only where the flyer can appear.
 *
 * @return void
 */
function duaais_anniversary_enqueue_assets() {
	if ( ! is_front_page() || 'duaais' !== get_template() ) {
		return;
	}

	$stylesheet = plugin_dir_path( __FILE__ ) . 'assets/css/flyer.css';
	$version    = is_readable( $stylesheet ) ? (string) filemtime( $stylesheet ) : DUAAIS_ANNIVERSARY_VERSION;

	wp_enqueue_style(
		'duaais-anniversary',
		plugin_dir_url( __FILE__ ) . 'assets/css/flyer.css',
		array( 'duaais-style' ),
		$version
	);
}
add_action( 'wp_enqueue_scripts', 'duaais_anniversary_enqueue_assets', 20 );

/**
 * Render the anniversary flyer beside the homepage hero copy.
 *
 * @return void
 */
function duaais_anniversary_render_flyer() {
	if ( ! is_front_page() ) {
		return;
	}
	?>
	<aside class="anniversary-flyer" aria-labelledby="anniversary-title">
		<div class="anniversary-art" aria-hidden="true">
			<div class="anniversary-mark">
				<span class="anniversary-year-range">1996 - 2026</span>
				<strong class="anniversary-number">30</strong>
				<span class="anniversary-unit"><?php esc_html_e( 'Years together', 'duaais-anniversary' ); ?></span>
			</div>
		</div>

		<div class="anniversary-copy">
			<span class="anniversary-kicker"><?php esc_html_e( 'Save the date', 'duaais-anniversary' ); ?></span>
			<h2 id="anniversary-title"><?php esc_html_e( 'Celebrating 30 years', 'duaais-anniversary' ); ?></h2>
			<p class="anniversary-event-name">
				<strong><?php esc_html_e( 'DUAAIS Sweden Jubilee', 'duaais-anniversary' ); ?></strong>
				<span><?php esc_html_e( 'Annual Dinner', 'duaais-anniversary' ); ?></span>
			</p>
			<time class="anniversary-date" datetime="<?php echo esc_attr( DUAAIS_ANNIVERSARY_DATE ); ?>">
				<strong>06</strong>
				<span>
					<b><?php esc_html_e( 'November', 'duaais-anniversary' ); ?></b>
					<small><?php esc_html_e( 'Friday, 2026', 'duaais-anniversary' ); ?></small>
				</span>
			</time>
			<a class="button button-highlight" href="<?php echo esc_url( duaais_anniversary_post_url() ); ?>"><?php esc_html_e( 'View celebration details', 'duaais-anniversary' ); ?></a>
		</div>
	</aside>
	<?php
}
add_action( 'duaais_home_hero_after_content', 'duaais_anniversary_render_flyer' );
