<?php
/**
 * Idempotent local content seeder, executed through `wp eval-file`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DUAAIS_SEED_CONTENT_VERSION = '3.1.0';

$GLOBALS['duaais_seed_refresh_content'] = version_compare(
	(string) get_option( 'duaais_seed_content_version', '1.0.0' ),
	DUAAIS_SEED_CONTENT_VERSION,
	'<'
);

/**
 * Return the primary administrator ID for seeded content ownership.
 *
 * @return int
 */
function duaais_seed_author_id() {
	$administrators = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);

	return isset( $administrators[0] ) ? (int) $administrators[0] : 1;
}

/**
 * Create a published page when its slug does not already exist.
 *
 * @param string $slug    Page slug.
 * @param string $title   Page title.
 * @param string $excerpt Page excerpt.
 * @param string $content Page content.
 * @return int
 */
function duaais_seed_page( $slug, $title, $excerpt, $content ) {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	if ( $existing instanceof WP_Post ) {
		if ( ! empty( $GLOBALS['duaais_seed_refresh_content'] ) ) {
			$result = wp_update_post(
				array(
					'ID'           => $existing->ID,
					'post_title'   => $title,
					'post_excerpt' => $excerpt,
					'post_content' => $content,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
		}

		return $existing->ID;
	}

	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_excerpt' => $excerpt,
			'post_content' => $content,
			'post_author'  => duaais_seed_author_id(),
		),
		true
	);

	if ( is_wp_error( $page_id ) ) {
		throw new RuntimeException( $page_id->get_error_message() );
	}

	return (int) $page_id;
}

/**
 * Create or return a category.
 *
 * @param string $slug Category slug.
 * @param string $name Category name.
 * @return int
 */
function duaais_seed_category( $slug, $name ) {
	$existing = term_exists( $slug, 'category' );
	if ( is_array( $existing ) ) {
		if ( ! empty( $GLOBALS['duaais_seed_refresh_content'] ) ) {
			wp_update_term( (int) $existing['term_id'], 'category', array( 'name' => $name ) );
		}

		return (int) $existing['term_id'];
	}
	if ( is_int( $existing ) ) {
		if ( ! empty( $GLOBALS['duaais_seed_refresh_content'] ) ) {
			wp_update_term( $existing, 'category', array( 'name' => $name ) );
		}

		return $existing;
	}

	$term = wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
	if ( is_wp_error( $term ) ) {
		throw new RuntimeException( $term->get_error_message() );
	}

	return (int) $term['term_id'];
}

/**
 * Copy a theme image into uploads and attach it as a featured image.
 *
 * @param int    $post_id    Parent post ID.
 * @param string $source     Absolute source path.
 * @param string $title      Attachment title.
 * @param string $alt_text   Image alt text.
 */
function duaais_seed_featured_image( $post_id, $source, $title, $alt_text ) {
	if ( ! is_readable( $source ) ) {
		return;
	}

	$current_thumbnail_id = get_post_thumbnail_id( $post_id );
	if ( $current_thumbnail_id ) {
		$current_file = get_attached_file( $current_thumbnail_id );
		if ( $current_file && basename( $current_file ) === basename( $source ) ) {
			return;
		}

		if ( empty( $GLOBALS['duaais_seed_refresh_content'] ) ) {
			return;
		}
	}

	$contents = file_get_contents( $source );
	if ( false === $contents ) {
		return;
	}

	$upload = wp_upload_bits( basename( $source ), null, $contents );
	if ( ! empty( $upload['error'] ) ) {
		throw new RuntimeException( $upload['error'] );
	}

	$file_type = wp_check_filetype( $upload['file'] );
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $file_type['type'],
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => duaais_seed_author_id(),
		),
		$upload['file'],
		$post_id,
		true
	);

	if ( is_wp_error( $attachment_id ) ) {
		throw new RuntimeException( $attachment_id->get_error_message() );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
	wp_update_attachment_metadata( $attachment_id, $metadata );
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
	update_post_meta( $attachment_id, '_duaais_seed_source', basename( $source ) );
	set_post_thumbnail( $post_id, $attachment_id );
}

/**
 * Create a published post and ensure it has a featured image.
 *
 * @param array<string, mixed> $post Post properties.
 * @return int
 */
function duaais_seed_post( $post ) {
	$existing = get_page_by_path( $post['slug'], OBJECT, 'post' );

	if ( $existing instanceof WP_Post ) {
		$post_id = $existing->ID;

		if ( ! empty( $GLOBALS['duaais_seed_refresh_content'] ) ) {
			$result = wp_update_post(
				array(
					'ID'            => $post_id,
					'post_title'    => $post['title'],
					'post_excerpt'  => $post['excerpt'],
					'post_content'  => $post['content'],
					'post_category' => array( $post['category_id'] ),
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
		}
	} else {
		$post_id = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_name'     => $post['slug'],
				'post_title'    => $post['title'],
				'post_excerpt'  => $post['excerpt'],
				'post_content'  => $post['content'],
				'post_category' => array( $post['category_id'] ),
				'post_date'     => $post['date'],
				'post_author'   => duaais_seed_author_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( $post_id->get_error_message() );
		}
	}

	duaais_seed_featured_image( (int) $post_id, $post['image'], $post['title'], $post['image_alt'] );

	return (int) $post_id;
}

/**
 * Create a menu and add any page links that are not already present.
 *
 * @param string               $name  Menu name.
 * @param array<int, string>   $pages Map of page IDs to labels.
 * @return int
 */
function duaais_seed_menu( $name, $pages ) {
	$menu = wp_get_nav_menu_object( $name );
	if ( ! $menu ) {
		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			throw new RuntimeException( $menu_id->get_error_message() );
		}
	} else {
		$menu_id = (int) $menu->term_id;
	}

	$items             = wp_get_nav_menu_items( $menu_id );
	$existing_by_page  = array();
	$position          = 1;

	foreach ( $items ?: array() as $item ) {
		$existing_by_page[ (int) $item->object_id ] = (int) $item->ID;
	}

	foreach ( $pages as $page_id => $label ) {
		$item_id = $existing_by_page[ (int) $page_id ] ?? 0;
		if ( $item_id && empty( $GLOBALS['duaais_seed_refresh_content'] ) ) {
			++$position;
			continue;
		}

		$result = wp_update_nav_menu_item(
			$menu_id,
			$item_id,
			array(
				'menu-item-object-id' => (int) $page_id,
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
				'menu-item-title'     => $label,
				'menu-item-position'  => $position,
			)
		);

		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}

		++$position;
	}

	return (int) $menu_id;
}

update_option( 'blogname', getenv( 'SITE_TITLE' ) ?: 'DUAAIS Sweden' );
update_option( 'blogdescription', 'University of Dhaka alumni in Sweden' );
update_option( 'timezone_string', 'Europe/Stockholm' );
update_option( 'date_format', 'F j, Y' );
update_option( 'time_format', 'g:i a' );
update_option( 'start_of_week', 1 );
update_option( 'default_comment_status', 'open' );
// Membership goes through the reviewed [duaais_register] form, so the WordPress
// registration screen stays closed and new accounts default to the pending role.
update_option( 'users_can_register', 0 );
update_option( 'default_role', 'duaais_pending' );
update_option( 'permalink_structure', '/%postname%/' );

$author_id = duaais_seed_author_id();
wp_update_user(
	array(
		'ID'           => $author_id,
		'display_name' => 'DUAAIS Sweden',
		'nickname'     => 'DUAAIS Sweden',
	)
);

$about_content = <<<'HTML'
<!-- wp:heading --><h2 class="wp-block-heading">Aim and Goals</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>DUAAIS promotes social and cultural relations among University of Dhaka graduates who are presently residing in Sweden.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>The association promotes youth culture, sports, literature, festivities, and other social activities that enhance comfort, solidarity, and knowledge among members and their families.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>DUAAIS also encourages and honours the achievements of Bangladeshis at different levels of Swedish society.</p><!-- /wp:paragraph -->
<!-- wp:quote --><blockquote class="wp-block-quote"><p>DUAAIS is an independent and non-political organisation.</p></blockquote><!-- /wp:quote -->
<!-- wp:heading --><h2 class="wp-block-heading">A community since 1996</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>DUAAIS Sweden brings together alumni from different years, faculties, professions, and cities. University of Dhaka graduates residing in Sweden are welcome to join the association and its activities.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><a href="https://www.duaais.com/aim.htm">View the original Aim and Goals page on duaais.com</a>.</p><!-- /wp:paragraph -->
HTML;

$activities_content = <<<'HTML'
<!-- wp:heading --><h2 class="wp-block-heading">Calendar of Activities for 2026</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Dhaka University Alumni Association in Sweden has scheduled the following cultural, social, and community programmes for 2026.</p><!-- /wp:paragraph -->
<!-- wp:html -->
<ol class="activity-calendar">
<li><time datetime="2026-02-08">February 8</time><div><strong>Student Reception</strong><p>A formal welcome event for new and prospective students.</p></div></li>
<li><time datetime="2026-05-01">May 1</time><div><strong>Annual General Meeting &amp; Pahela Boishakh Celebration</strong><p>The association's statutory AGM followed by the Bengali New Year celebration.</p></div></li>
<li><time datetime="2026-07-01">July 1–4</time><div><strong>Summer Excursion to Bergen, Norway</strong><p>A four-day recreational tour.</p></div></li>
<li><time datetime="2026-08-01">August 1</time><div><strong>Summer Picnic and Barbecue</strong><p>A family-oriented outdoor gathering.</p></div></li>
<li><time datetime="2026-08-29">August 29</time><div><strong>Sports Day</strong><p>Athletic competitions and recreational sports for members and families.</p></div></li>
<li><time datetime="2026-09-27">September 27</time><div><strong>Pitha Utshob</strong><p>A traditional Bengali cake festival celebrating culinary heritage.</p></div></li>
<li><time datetime="2026-11-06">November 6</time><div><strong>DUAAIS 30th Anniversary Jubilee and Annual Dinner</strong><p>A formal dinner commemorating three decades of service.</p></div></li>
<li><time datetime="2026-12-19">December 19</time><div><strong>Christmas Dinner</strong><p>A festive end-of-year social gathering.</p></div></li>
</ol>
<!-- /wp:html -->
<!-- wp:paragraph --><p><a href="https://www.duaais.com/activities.htm">View the original activity calendar on duaais.com</a>.</p><!-- /wp:paragraph -->
HTML;

$executive_content = <<<'HTML'
<!-- wp:paragraph --><p>The following executive committee details were synchronized from the public DUAAIS website on August 16, 2026.</p><!-- /wp:paragraph -->
<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Designation</th><th>Name</th><th>Mobile</th><th>Email</th></tr></thead><tbody>
<tr><td>President</td><td>Abul Kalam Bhuiyan</td><td><a href="tel:+46706456125">+46 70 645 6125</a></td><td><a href="mailto:abul.kalam@bhuiyan.se">abul.kalam@bhuiyan.se</a></td></tr>
<tr><td>Vice President</td><td>Sultan Ahmed Emon</td><td><a href="tel:+46769430491">+46 76 943 0491</a></td><td><a href="mailto:bmbsahmed@gmail.com">bmbsahmed@gmail.com</a></td></tr>
<tr><td>General Secretary</td><td>Sheik Atiqul Alam</td><td><a href="tel:+46734947277">+46 73 494 7277</a></td><td><a href="mailto:atique.arla@gmail.com">atique.arla@gmail.com</a></td></tr>
<tr><td>Finance Secretary</td><td>Ahasan Habib</td><td><a href="tel:+46735546908">+46 73 554 6908</a></td><td><a href="mailto:habib.ahasan74@gmail.com">habib.ahasan74@gmail.com</a></td></tr>
<tr><td>Cultural Secretary</td><td>Rokeya Sultana Rekha</td><td><a href="tel:+46735500689">+46 73 550 0689</a></td><td><a href="mailto:rokeya.sultana.rekha@gmail.com">rokeya.sultana.rekha@gmail.com</a></td></tr>
<tr><td>ICT Secretary</td><td>Md. Tareq Hasan</td><td><a href="tel:+46738661084">+46 73 866 1084</a></td><td><a href="mailto:tareq.hasan2011@gmail.com">tareq.hasan2011@gmail.com</a></td></tr>
<tr><td>Member</td><td>Emdadul Quader</td><td><a href="tel:+46707272728">+46 70 727 2728</a></td><td><a href="mailto:emdadulq@gmail.com">emdadulq@gmail.com</a></td></tr>
<tr><td>Member</td><td>Jamal Nuruzzaman</td><td><a href="tel:+46703314843">+46 70 331 4843</a></td><td><a href="mailto:jnuruzzaman@hotmail.com">jnuruzzaman@hotmail.com</a></td></tr>
<tr><td>Member</td><td>Manowar Hossain Hira</td><td><a href="tel:+46709510587">+46 70 951 0587</a></td><td><a href="mailto:hossain.manowar.hira@gmail.com">hossain.manowar.hira@gmail.com</a></td></tr>
<tr><td>Member</td><td>Mahbuba Jahan</td><td><a href="tel:+46737481121">+46 73 748 1121</a></td><td><a href="mailto:amijolls@yahoo.com">amijolls@yahoo.com</a></td></tr>
<tr><td>Member</td><td>Md. Mostofa Azad</td><td><a href="tel:+46722332244">+46 72 233 2244</a></td><td><a href="mailto:mostofa@gmail.com">mostofa@gmail.com</a></td></tr>
</tbody></table></figure><!-- /wp:table -->
<!-- wp:paragraph --><p><a href="https://www.duaais.com/excecutive.htm">View the original Executive Committee page on duaais.com</a>.</p><!-- /wp:paragraph -->
HTML;

$document_base = get_template_directory_uri() . '/assets/documents/';
$resources_content = <<<HTML
<!-- wp:heading --><h2 class="wp-block-heading">Constitution</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li><a href="{$document_base}constitution-swedish.pdf">DUAAIS Constitution — Swedish (PDF)</a></li><li><a href="{$document_base}constitution-bengali.pdf">DUAAIS Constitution — Bengali (PDF)</a></li></ul><!-- /wp:list -->
<!-- wp:heading --><h2 class="wp-block-heading">Important Links</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li><a href="https://www.uhr.se/en/start/">Swedish Council for Higher Education</a></li><li><a href="http://bangladesh.freehomepage.com/">Bangladesh Homepage</a></li><li><a href="https://stockholm.mofa.gov.bd/">Embassy of Bangladesh in Stockholm</a></li></ul><!-- /wp:list -->
<!-- wp:heading --><h2 class="wp-block-heading">Bangla Newspapers</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li><a href="https://www.allbanglanewspaper.xyz/">All Bangla Newspapers</a></li><li><a href="https://www.ittefaq.com.bd/">Ittefaq</a></li><li><a href="https://www.prothomalo.com/">Prothom Alo</a></li></ul><!-- /wp:list -->
<!-- wp:paragraph --><p>Links and documents were synchronized from the public resources on <a href="https://www.duaais.com/">duaais.com</a>.</p><!-- /wp:paragraph -->
HTML;

$contact_content = <<<'HTML'
<!-- wp:heading --><h2 class="wp-block-heading">Contact the Association</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>For membership questions, activities, partnerships, and website comments, email <a href="mailto:info@duaais.com">info@duaais.com</a>.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Publisher and Editor</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Abul Kalam Bhuiyan<br><a href="mailto:abul.kalam@bhuiyan.se">abul.kalam@bhuiyan.se</a><br><a href="tel:+46706456125">+46 70 645 6125</a></p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Executive committee contacts are available on the <a href="/executive-committee/">Executive Committee page</a>.</p><!-- /wp:paragraph -->
HTML;

$privacy_content = <<<'HTML'
<!-- wp:paragraph --><p><strong>Last updated: August 16, 2026.</strong></p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">Data Controller</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>DUAAIS Sweden is responsible for processing personal information in the membership register. Send privacy questions to <a href="mailto:info@duaais.com">info@duaais.com</a>.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">Information We Process</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>When you register, we store your name, email address, University of Dhaka faculty or department, graduation year, and any optional information you provide about your studies and city in Sweden. WordPress stores passwords only as secure hashes.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">Purpose and Legal Basis</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>We use the information to administer membership, communicate association news, and plan relevant activities. Processing is based on the consent you provide during registration.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">Retention and Your Rights</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>We retain the information while you are a member or until you withdraw consent. You may request access, correction, deletion, or export of your data. You may also submit a complaint to the Swedish Authority for Privacy Protection (IMY).</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">Cookies</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>WordPress uses essential cookies for login and secure session management. The site does not use advertising cookies.</p><!-- /wp:paragraph -->
HTML;

$home_id       = duaais_seed_page( 'hem', 'Home', 'University of Dhaka alumni in Sweden.', '' );
$about_id      = duaais_seed_page( 'om-foreningen', 'Aim and Goals', 'The official aims of Dhaka University Alumni Association in Sweden.', $about_content );
$activities_id = duaais_seed_page( 'activities', 'Activities', 'The official DUAAIS calendar of activities for 2026.', $activities_content );
$executive_id  = duaais_seed_page( 'executive-committee', 'Executive Committee', 'Current DUAAIS committee members and public contact details.', $executive_content );
$resources_id  = duaais_seed_page( 'resources', 'Resources', 'Constitutions, important links, and Bangla newspapers.', $resources_content );
$news_id       = duaais_seed_page( 'nyheter', 'News', 'Official DUAAIS activities and community updates.', '' );
$member_id     = duaais_seed_page( 'bli-medlem', 'Join DUAAIS', 'For University of Dhaka graduates currently residing in Sweden.', '[duaais_register]' );
$login_id      = duaais_seed_page( 'logga-in', 'Log in', 'Access the DUAAIS member portal.', '[duaais_login]' );
$account_id    = duaais_seed_page( 'mitt-konto', 'My Account', 'Manage your DU alumni profile.', '[duaais_account]' );
$contact_id    = duaais_seed_page( 'kontakt', 'Contact', 'Contact Dhaka University Alumni Association in Sweden.', $contact_content );
$privacy_id    = duaais_seed_page( 'integritetspolicy', 'Privacy Policy', 'How DUAAIS Sweden processes personal information.', $privacy_content );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home_id );
update_option( 'page_for_posts', $news_id );
update_option( 'wp_page_for_privacy_policy', $privacy_id );

foreach ( array( 'hello-world', 'sample-page' ) as $default_slug ) {
	$default_post = get_page_by_path( $default_slug, OBJECT, array( 'post', 'page' ) );
	if ( $default_post instanceof WP_Post ) {
		wp_delete_post( $default_post->ID, true );
	}
}

$association_category = duaais_seed_category( 'association', 'Association' );
$activity_category    = duaais_seed_category( 'activities', 'Activities' );
$image_directory      = get_template_directory() . '/assets/images/';

if ( ! empty( $GLOBALS['duaais_seed_refresh_content'] ) ) {
	foreach ( array( 'alumntraff-i-stockholm', 'mentorprogrammet-oppnar', 'lokala-alumntraffar' ) as $retired_slug ) {
		$retired_post = get_page_by_path( $retired_slug, OBJECT, 'post' );
		if ( $retired_post instanceof WP_Post ) {
			wp_delete_post( $retired_post->ID, true );
		}
	}

	$legacy_primary_menu = wp_get_nav_menu_object( 'Huvudmeny' );
	if ( $legacy_primary_menu ) {
		wp_update_term( (int) $legacy_primary_menu->term_id, 'nav_menu', array( 'name' => 'Primary Navigation' ) );
	}

	$legacy_footer_menu = wp_get_nav_menu_object( 'Sidfotsmeny' );
	if ( $legacy_footer_menu ) {
		wp_update_term( (int) $legacy_footer_menu->term_id, 'nav_menu', array( 'name' => 'Footer Navigation' ) );
	}
}

$calendar_content = <<<'HTML'
<!-- wp:paragraph --><p>Dhaka University Alumni Association in Sweden has announced its calendar of cultural, social, and community programmes for 2026.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">2026 Programme</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li><strong>February 8:</strong> Student Reception</li><li><strong>May 1:</strong> Annual General Meeting and Pahela Boishakh Celebration</li><li><strong>July 1–4:</strong> Summer Excursion to Bergen, Norway</li><li><strong>August 1:</strong> Summer Picnic and Barbecue</li><li><strong>August 29:</strong> Sports Day</li><li><strong>September 27:</strong> Pitha Utshob</li><li><strong>November 6:</strong> DUAAIS 30th Anniversary Jubilee and Annual Dinner</li><li><strong>December 19:</strong> Christmas Dinner</li></ul><!-- /wp:list -->
<!-- wp:paragraph --><p>See the <a href="/activities/">Activities page</a> for descriptions of every programme. This calendar was synchronized from the public <a href="https://www.duaais.com/activities.htm">duaais.com activity page</a>.</p><!-- /wp:paragraph -->
HTML;

$jubilee_content = <<<'HTML'
<!-- wp:paragraph --><p>DUAAIS Sweden will commemorate three decades of service with its 30th Anniversary Jubilee and Annual Dinner on November 6, 2026.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">Thirty years of community</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>The formal dinner is one of the official programmes in the association's 2026 activity calendar. It marks thirty years of social, cultural, and community work among University of Dhaka graduates in Sweden.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Further event information will be shared by the association. Contact <a href="mailto:info@duaais.com">info@duaais.com</a> with questions.</p><!-- /wp:paragraph -->
HTML;

$pitha_content = <<<'HTML'
<!-- wp:paragraph --><p>Pitha Utshob will take place on September 27, 2026 as part of the official DUAAIS activity calendar.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">Celebrating Bengali culinary heritage</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>The traditional Bengali cake festival brings members and families together around food and culture. It supports the association's aim of promoting festivities and social activities that strengthen solidarity among members and their families.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Contact <a href="mailto:info@duaais.com">info@duaais.com</a> for association updates.</p><!-- /wp:paragraph -->
HTML;

duaais_seed_post(
	array(
		'slug'        => 'duaais-2026-activity-calendar',
		'title'       => 'DUAAIS 2026 Activity Calendar',
		'excerpt'     => 'The official calendar of cultural, social, and community programmes for 2026.',
		'content'     => $calendar_content,
		'category_id' => $activity_category,
		'date'        => '2026-08-16 10:00:00',
		'image'       => $image_directory . 'stockholm.jpg',
		'image_alt'   => 'Stockholm at dusk',
	)
);

duaais_seed_post(
	array(
		'slug'        => 'duaais-30th-anniversary-jubilee',
		'title'       => 'DUAAIS 30th Anniversary Jubilee',
		'excerpt'     => 'The association will commemorate thirty years of service with its annual dinner on November 6.',
		'content'     => $jubilee_content,
		'category_id' => $association_category,
		'date'        => '2026-08-15 09:00:00',
		'image'       => $image_directory . 'picnic-2.jpg',
		'image_alt'   => 'DUAAIS families gathering by the water during a summer picnic',
	)
);

duaais_seed_post(
	array(
		'slug'        => 'pitha-utshob-2026',
		'title'       => 'Pitha Utshob 2026',
		'excerpt'     => 'A traditional Bengali cake festival celebrating culinary heritage on September 27.',
		'content'     => $pitha_content,
		'category_id' => $activity_category,
		'date'        => '2026-08-14 14:00:00',
		'image'       => $image_directory . 'pitha-utshob.jpg',
		'image_alt'   => 'DUAAIS members and families gathered for Pitha Utshob',
	)
);

$primary_menu_id = duaais_seed_menu(
	'Primary Navigation',
	array(
		$home_id       => 'Home',
		$about_id      => 'Aim and Goals',
		$activities_id => 'Activities',
		$executive_id  => 'Committee',
		$news_id       => 'News',
		$contact_id    => 'Contact',
	)
);

$footer_menu_id = duaais_seed_menu(
	'Footer Navigation',
	array(
		$about_id      => 'Aim and Goals',
		$activities_id => 'Activities',
		$executive_id  => 'Executive Committee',
		$resources_id  => 'Resources',
		$member_id     => 'Join DUAAIS',
		$privacy_id    => 'Privacy Policy',
	)
);

$locations            = get_theme_mod( 'nav_menu_locations', array() );
$locations['primary'] = $primary_menu_id;
$locations['footer']  = $footer_menu_id;
set_theme_mod( 'nav_menu_locations', $locations );
flush_rewrite_rules();
update_option( 'duaais_seed_content_version', DUAAIS_SEED_CONTENT_VERSION );

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::success( 'English DUAAIS content synchronized from duaais.com is ready.' );
}