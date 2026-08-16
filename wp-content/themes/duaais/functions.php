<?php
/**
 * Theme setup and shared helpers.
 *
 * @package DUAAIS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configure WordPress theme features.
 */
function duaais_setup() {
	load_theme_textdomain( 'duaais', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
	);
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 96,
			'width'       => 320,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);
	add_theme_support(
		'custom-background',
		array(
			'default-color' => 'f5f6f2',
		)
	);

	register_nav_menus(
		array(
			'primary' => __( 'Primary navigation', 'duaais' ),
			'footer'  => __( 'Footer navigation', 'duaais' ),
		)
	);

	add_image_size( 'duaais-card', 900, 675, true );
	add_image_size( 'duaais-featured', 1600, 900, true );
}
add_action( 'after_setup_theme', 'duaais_setup' );

/**
 * Load public assets with file-based versions during development.
 */
function duaais_enqueue_assets() {
	$theme = wp_get_theme();
	$style = get_stylesheet_directory() . '/style.css';
	$script = get_template_directory() . '/assets/js/site.js';

	wp_enqueue_style(
		'duaais-style',
		get_stylesheet_uri(),
		array(),
		file_exists( $style ) ? (string) filemtime( $style ) : $theme->get( 'Version' )
	);

	wp_enqueue_script(
		'duaais-site',
		get_template_directory_uri() . '/assets/js/site.js',
		array(),
		file_exists( $script ) ? (string) filemtime( $script ) : $theme->get( 'Version' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'duaais_enqueue_assets' );

/**
 * Return a page permalink by slug, with a reliable fallback.
 *
 * @param string $slug Page slug.
 * @return string
 */
function duaais_page_url( $slug ) {
	$page = get_page_by_path( sanitize_title( $slug ) );

	return $page instanceof WP_Post ? get_permalink( $page ) : home_url( '/' . sanitize_title( $slug ) . '/' );
}

/**
 * Render a useful navigation before menus have been configured.
 */
function duaais_primary_menu_fallback() {
	$items = array(
		__( 'Home', 'duaais' )       => home_url( '/' ),
		__( 'About', 'duaais' )      => duaais_page_url( 'om-foreningen' ),
		__( 'Activities', 'duaais' ) => duaais_page_url( 'activities' ),
		__( 'Committee', 'duaais' )  => duaais_page_url( 'executive-committee' ),
		__( 'News', 'duaais' )       => duaais_page_url( 'nyheter' ),
		__( 'Contact', 'duaais' )    => duaais_page_url( 'kontakt' ),
	);

	echo '<ul class="primary-menu">';
	foreach ( $items as $label => $url ) {
		printf(
			'<li><a href="%1$s">%2$s</a></li>',
			esc_url( $url ),
			esc_html( $label )
		);
	}
	echo '</ul>';
}

/**
 * Render the footer menu fallback.
 */
function duaais_footer_menu_fallback() {
	$items = array(
		__( 'About DUAAIS', 'duaais' ) => duaais_page_url( 'om-foreningen' ),
		__( 'Activities', 'duaais' )   => duaais_page_url( 'activities' ),
		__( 'Resources', 'duaais' )    => duaais_page_url( 'resources' ),
		__( 'Join', 'duaais' )         => duaais_page_url( 'bli-medlem' ),
		__( 'News', 'duaais' )         => duaais_page_url( 'nyheter' ),
		__( 'Privacy', 'duaais' )      => duaais_page_url( 'integritetspolicy' ),
	);

	echo '<ul class="footer-menu">';
	foreach ( $items as $label => $url ) {
		printf(
			'<li><a href="%1$s">%2$s</a></li>',
			esc_url( $url ),
			esc_html( $label )
		);
	}
	echo '</ul>';
}

/**
 * Keep card excerpts compact.
 *
 * @return int
 */
function duaais_excerpt_length() {
	return 24;
}
add_filter( 'excerpt_length', 'duaais_excerpt_length', 20 );

/**
 * Use a cleaner excerpt ending.
 *
 * @return string
 */
function duaais_excerpt_more() {
	return '&hellip;';
}
add_filter( 'excerpt_more', 'duaais_excerpt_more' );

/**
 * Remove redundant labels from archive headings.
 *
 * @param string $title Archive title.
 * @return string
 */
function duaais_archive_title( $title ) {
	if ( is_category() ) {
		return single_cat_title( '', false );
	}

	if ( is_tag() ) {
		return single_tag_title( '', false );
	}

	return $title;
}
add_filter( 'get_the_archive_title', 'duaais_archive_title' );

/**
 * Provide a deterministic local fallback for posts without featured images.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function duaais_post_fallback_image( $post_id = 0 ) {
	$images = array( 'graduation.jpg', 'picnic-2.jpg', 'stockholm.jpg' );
	$index  = absint( $post_id ) % count( $images );

	return get_template_directory_uri() . '/assets/images/' . $images[ $index ];
}

/**
 * Print the first category for a post.
 */
function duaais_post_category() {
	$categories = get_the_category();
	if ( empty( $categories ) ) {
		return;
	}

	echo '<span class="post-card-category">' . esc_html( $categories[0]->name ) . '</span>';
}