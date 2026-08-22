<?php
/**
 * Plugin Name: DUAAIS Setup
 * Description: Runs the idempotent DUAAIS content bootstrap from wp-admin, for shared hosting without SSH or WP-CLI.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: DUAAIS Sweden
 * Text Domain: duaais-setup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DUAAIS_SETUP_THEME        = 'duaais';
const DUAAIS_SETUP_ERROR_OPTION = 'duaais_setup_last_error';

/**
 * Absolute path of the seeder shared with scripts/bootstrap.sh.
 *
 * @return string
 */
function duaais_setup_seed_file() {
	return __DIR__ . '/seed.php';
}

/**
 * List the conditions that stop the seeder from running.
 *
 * @return array<int, string>
 */
function duaais_setup_blockers() {
	$blockers = array();

	if ( ! is_readable( duaais_setup_seed_file() ) ) {
		$blockers[] = __( 'seed.php is missing from the plugin folder. Upload the complete duaais-setup folder.', 'duaais-setup' );
	}

	if ( DUAAIS_SETUP_THEME !== get_template() ) {
		$blockers[] = __( 'The DUAAIS Sweden theme is not active. Activate it under Appearance → Themes.', 'duaais-setup' );
	}

	if ( ! defined( 'DUAAIS_MEMBERS_VERSION' ) ) {
		$blockers[] = __( 'The DUAAIS Members plugin is not active. Activate it under Plugins so that the member roles exist.', 'duaais-setup' );
	}

	return $blockers;
}

/**
 * Report whether WordPress can write the permalink rules into the web root .htaccess.
 *
 * Shared hosting often ships a read-only .htaccess, which leaves every page except the front
 * page returning 404 after the seeder switches to postname permalinks.
 *
 * @return bool
 */
function duaais_setup_htaccess_is_writable() {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$htaccess = get_home_path() . '.htaccess';

	if ( file_exists( $htaccess ) ) {
		return is_writable( $htaccess );
	}

	return is_writable( get_home_path() );
}

/**
 * Return the mod_rewrite block that has to be pasted when .htaccess is read-only.
 *
 * @return string
 */
function duaais_setup_rewrite_rules() {
	global $wp_rewrite;

	if ( ! $wp_rewrite instanceof WP_Rewrite ) {
		return '';
	}

	return $wp_rewrite->mod_rewrite_rules();
}

/**
 * Add the setup screen under Tools.
 */
function duaais_setup_admin_menu() {
	add_management_page(
		__( 'DUAAIS setup', 'duaais-setup' ),
		__( 'DUAAIS setup', 'duaais-setup' ),
		'manage_options',
		'duaais-setup',
		'duaais_setup_render_page'
	);
}
add_action( 'admin_menu', 'duaais_setup_admin_menu' );

/**
 * Build the URL of the setup screen.
 *
 * @return string
 */
function duaais_setup_page_url() {
	return admin_url( 'tools.php?page=duaais-setup' );
}

/**
 * Render the setup screen.
 */
function duaais_setup_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to run the DUAAIS setup.', 'duaais-setup' ) );
	}

	$notice           = isset( $_GET['duaais_notice'] ) ? sanitize_key( wp_unslash( $_GET['duaais_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$blockers         = duaais_setup_blockers();
	$installed        = (string) get_option( 'duaais_seed_content_version', '' );
	$last_error       = (string) get_option( DUAAIS_SETUP_ERROR_OPTION, '' );
	$htaccess_ok      = duaais_setup_htaccess_is_writable();
	$permalinks_ready = '' !== (string) get_option( 'permalink_structure', '' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'DUAAIS setup', 'duaais-setup' ); ?></h1>
		<p><?php esc_html_e( 'Creates the DUAAIS pages, posts, categories, navigation menus, and site settings. Running it again is safe: existing content is updated in place, never duplicated.', 'duaais-setup' ); ?></p>

		<?php if ( 'done' === $notice ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'The DUAAIS content bootstrap finished.', 'duaais-setup' ); ?></p></div>
		<?php elseif ( 'failed' === $notice ) : ?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'The DUAAIS content bootstrap failed. Nothing else was changed after the error.', 'duaais-setup' ); ?></p>
				<?php if ( '' !== $last_error ) : ?>
					<p><code><?php echo esc_html( $last_error ); ?></code></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Status', 'duaais-setup' ); ?></h2>
		<table class="widefat striped" style="max-width:52rem;">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Seeded content version', 'duaais-setup' ); ?></td>
					<td><?php echo '' === $installed ? esc_html__( 'Never run', 'duaais-setup' ) : esc_html( $installed ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Active theme', 'duaais-setup' ); ?></td>
					<td><?php echo esc_html( get_template() ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'DUAAIS Members plugin', 'duaais-setup' ); ?></td>
					<td><?php echo defined( 'DUAAIS_MEMBERS_VERSION' ) ? esc_html( DUAAIS_MEMBERS_VERSION ) : esc_html__( 'Not active', 'duaais-setup' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Web root .htaccess', 'duaais-setup' ); ?></td>
					<td><?php echo $htaccess_ok ? esc_html__( 'Writable', 'duaais-setup' ) : esc_html__( 'Read-only — permalinks must be added by hand', 'duaais-setup' ); ?></td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $blockers ) ) : ?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Resolve the following before running the setup:', 'duaais-setup' ); ?></p>
				<ul class="ul-disc">
					<?php foreach ( $blockers as $blocker ) : ?>
						<li><?php echo esc_html( $blocker ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="duaais_run_setup">
			<?php wp_nonce_field( 'duaais_run_setup', 'duaais_nonce' ); ?>
			<?php
			submit_button(
				'' === $installed ? __( 'Run DUAAIS setup', 'duaais-setup' ) : __( 'Re-run DUAAIS setup', 'duaais-setup' ),
				'primary',
				'submit',
				true,
				empty( $blockers ) ? array() : array( 'disabled' => 'disabled' )
			);
			?>
		</form>

		<?php if ( ! $htaccess_ok && $permalinks_ready ) : ?>
			<h2><?php esc_html_e( 'Permalink rules', 'duaais-setup' ); ?></h2>
			<p><?php esc_html_e( 'WordPress cannot write to the .htaccess file in your web root, so pretty permalinks will return 404 until these rules are added there manually.', 'duaais-setup' ); ?></p>
			<textarea rows="12" class="large-text code" readonly onclick="this.select();"><?php echo esc_textarea( duaais_setup_rewrite_rules() ); ?></textarea>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Execute the shared seeder and record the outcome.
 */
function duaais_setup_handle_run() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to run the DUAAIS setup.', 'duaais-setup' ) );
	}

	check_admin_referer( 'duaais_run_setup', 'duaais_nonce' );

	$blockers = duaais_setup_blockers();
	if ( ! empty( $blockers ) ) {
		update_option( DUAAIS_SETUP_ERROR_OPTION, $blockers[0] );
		wp_safe_redirect( add_query_arg( 'duaais_notice', 'failed', duaais_setup_page_url() ) );
		exit;
	}

	// The seeder can create attachments, so give it the same helpers WP-CLI loads.
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';

	try {
		require duaais_setup_seed_file();
	} catch ( Throwable $error ) {
		update_option( DUAAIS_SETUP_ERROR_OPTION, $error->getMessage() );
		wp_safe_redirect( add_query_arg( 'duaais_notice', 'failed', duaais_setup_page_url() ) );
		exit;
	}

	delete_option( DUAAIS_SETUP_ERROR_OPTION );
	wp_safe_redirect( add_query_arg( 'duaais_notice', 'done', duaais_setup_page_url() ) );
	exit;
}
add_action( 'admin_post_duaais_run_setup', 'duaais_setup_handle_run' );

/**
 * Point administrators at the setup screen until the content has been seeded.
 */
function duaais_setup_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( '' !== (string) get_option( 'duaais_seed_content_version', '' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( $screen instanceof WP_Screen && 'tools_page_duaais-setup' === $screen->id ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
		esc_html__( 'The DUAAIS content has not been created yet.', 'duaais-setup' ),
		esc_url( duaais_setup_page_url() ),
		esc_html__( 'Open Tools → DUAAIS setup', 'duaais-setup' )
	);
}
add_action( 'admin_notices', 'duaais_setup_admin_notice' );
