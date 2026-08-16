<?php
/**
 * Plugin Name: DUAAIS Members
 * Description: Front-end registration, login, and profile management for University of Dhaka alumni in Sweden.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: DUAAIS Sweden
 * Text Domain: duaais-members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DUAAIS_MEMBERS_VERSION = '1.0.0';
const DUAAIS_MEMBER_ROLE     = 'duaais_alumni';

/**
 * Create the member role without removing existing capabilities on upgrades.
 */
function duaais_members_register_role() {
	if ( ! get_role( DUAAIS_MEMBER_ROLE ) ) {
		add_role(
			DUAAIS_MEMBER_ROLE,
			__( 'Alumni Member', 'duaais-members' ),
			array( 'read' => true )
		);
	} else {
		$roles = wp_roles();
		if ( isset( $roles->roles[ DUAAIS_MEMBER_ROLE ] ) && 'Alumni Member' !== $roles->roles[ DUAAIS_MEMBER_ROLE ]['name'] ) {
			$roles->roles[ DUAAIS_MEMBER_ROLE ]['name'] = 'Alumni Member';
			$roles->role_names[ DUAAIS_MEMBER_ROLE ]     = 'Alumni Member';
			update_option( $roles->role_key, $roles->roles );
		}
	}
}
add_action( 'init', 'duaais_members_register_role' );

/**
 * Configure safe defaults when the plugin is activated.
 */
function duaais_members_activate() {
	duaais_members_register_role();
	update_option( 'users_can_register', 1 );
	update_option( 'default_role', DUAAIS_MEMBER_ROLE );
}
register_activation_hook( __FILE__, 'duaais_members_activate' );

/**
 * Register public shortcodes.
 */
function duaais_members_register_shortcodes() {
	add_shortcode( 'duaais_register', 'duaais_members_registration_shortcode' );
	add_shortcode( 'duaais_login', 'duaais_members_login_shortcode' );
	add_shortcode( 'duaais_account', 'duaais_members_account_shortcode' );
}
add_action( 'init', 'duaais_members_register_shortcodes' );

/**
 * Load the plugin stylesheet only on pages that use a member shortcode.
 */
function duaais_members_enqueue_assets() {
	if ( ! is_singular() ) {
		return;
	}

	$post = get_post();
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$shortcodes = array( 'duaais_register', 'duaais_login', 'duaais_account' );
	foreach ( $shortcodes as $shortcode ) {
		if ( has_shortcode( $post->post_content, $shortcode ) ) {
			wp_enqueue_style(
				'duaais-members',
				plugins_url( 'assets/members.css', __FILE__ ),
				array(),
				DUAAIS_MEMBERS_VERSION
			);
			break;
		}
	}
}
add_action( 'wp_enqueue_scripts', 'duaais_members_enqueue_assets' );

/**
 * Resolve one of the member pages without depending on a theme helper.
 *
 * @param string $slug Page slug.
 * @return string
 */
function duaais_members_page_url( $slug ) {
	$page = get_page_by_path( sanitize_title( $slug ) );

	return $page instanceof WP_Post ? get_permalink( $page ) : home_url( '/' . sanitize_title( $slug ) . '/' );
}

/**
 * Redirect to a known member page with a status message.
 *
 * @param string $slug   Page slug.
 * @param string $status Status key.
 */
function duaais_members_redirect( $slug, $status ) {
	$url = add_query_arg( 'duaais_status', sanitize_key( $status ), duaais_members_page_url( $slug ) );
	wp_safe_redirect( $url );
	exit;
}

/**
 * Read and sanitize a text field from a POST request.
 *
 * @param string $key Field key.
 * @return string
 */
function duaais_members_post_text( $key ) {
	if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
		return '';
	}

	return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
}

/**
 * Read a password without altering valid special characters.
 *
 * @param string $key Field key.
 * @return string
 */
function duaais_members_post_password( $key ) {
	if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
		return '';
	}

	return wp_unslash( $_POST[ $key ] );
}

/**
 * Verify a request nonce without exposing a generic wp-admin error page.
 *
 * @param string $action Nonce action.
 * @return bool
 */
function duaais_members_verify_nonce( $action ) {
	$nonce = duaais_members_post_text( 'duaais_nonce' );

	return $nonce && wp_verify_nonce( $nonce, $action );
}

/**
 * Build a privacy-preserving request throttle key.
 *
 * @param string $action Throttled action.
 * @return string
 */
function duaais_members_throttle_key( $action ) {
	$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

	return 'duaais_' . sanitize_key( $action ) . '_' . hash_hmac( 'sha256', $address, wp_salt( 'nonce' ) );
}

/**
 * Increment an expiring attempt counter.
 *
 * @param string $action Throttled action.
 * @param int    $ttl    Counter lifetime.
 * @return int
 */
function duaais_members_record_attempt( $action, $ttl ) {
	$key      = duaais_members_throttle_key( $action );
	$attempts = (int) get_transient( $key ) + 1;
	set_transient( $key, $attempts, $ttl );

	return $attempts;
}

/**
 * Print a status notice from an allow-listed set of messages.
 */
function duaais_members_status_notice() {
	$status = isset( $_GET['duaais_status'] ) && is_string( $_GET['duaais_status'] )
		? sanitize_key( wp_unslash( $_GET['duaais_status'] ) )
		: '';

	$messages = array(
		'registered'        => array( 'success', __( 'Welcome! Your alumni account has been created.', 'duaais-members' ) ),
		'logged_in'         => array( 'success', __( 'You are now logged in.', 'duaais-members' ) ),
		'updated'           => array( 'success', __( 'Your profile has been updated.', 'duaais-members' ) ),
		'logged_out'        => array( 'success', __( 'You are now logged out.', 'duaais-members' ) ),
		'required'          => array( 'error', __( 'Complete all required fields.', 'duaais-members' ) ),
		'email_invalid'     => array( 'error', __( 'Enter a valid email address.', 'duaais-members' ) ),
		'email_exists'      => array( 'error', __( 'An account already exists for that email address.', 'duaais-members' ) ),
		'password_short'    => array( 'error', __( 'Your password must contain at least 10 characters.', 'duaais-members' ) ),
		'password_mismatch' => array( 'error', __( 'The passwords do not match.', 'duaais-members' ) ),
		'consent_required'  => array( 'error', __( 'You must accept the privacy policy to register.', 'duaais-members' ) ),
		'year_invalid'      => array( 'error', __( 'Enter a valid graduation year.', 'duaais-members' ) ),
		'login_failed'      => array( 'error', __( 'The email address or password is incorrect.', 'duaais-members' ) ),
		'nonce_invalid'     => array( 'error', __( 'The form has expired. Please try again.', 'duaais-members' ) ),
		'rate_limited'      => array( 'error', __( 'Too many attempts. Wait a moment and try again.', 'duaais-members' ) ),
		'failed'            => array( 'error', __( 'Something went wrong. Try again or contact the association.', 'duaais-members' ) ),
	);

	if ( ! isset( $messages[ $status ] ) ) {
		return;
	}

	$type = 'success' === $messages[ $status ][0] ? 'success' : 'error';
	printf(
		'<div class="form-notice form-notice-%1$s" role="%2$s">%3$s</div>',
		esc_attr( $type ),
		'error' === $type ? 'alert' : 'status',
		esc_html( $messages[ $status ][1] )
	);
}

/**
 * Validate an alumni graduation year.
 *
 * @param string $year Submitted year.
 * @return bool
 */
function duaais_members_valid_year( $year ) {
	$current_year = (int) wp_date( 'Y' );

	return ctype_digit( $year ) && (int) $year >= 1900 && (int) $year <= $current_year;
}

/**
 * Generate an available username based on an email address.
 *
 * @param string $email Valid email address.
 * @return string
 */
function duaais_members_username_from_email( $email ) {
	$local_part = strstr( $email, '@', true );
	$base       = sanitize_user( $local_part ? $local_part : 'alumn', true );
	$base       = $base ? $base : 'alumn';
	$candidate  = $base;
	$suffix     = 1;

	while ( username_exists( $candidate ) ) {
		$candidate = $base . $suffix;
		++$suffix;
	}

	return $candidate;
}

/**
 * Render the front-end registration form.
 *
 * @return string
 */
function duaais_members_registration_shortcode() {
	if ( is_user_logged_in() ) {
		return sprintf(
			'<div class="form-notice form-notice-success">%1$s <a href="%2$s">%3$s</a></div>',
			esc_html__( 'You are already logged in.', 'duaais-members' ),
			esc_url( duaais_members_page_url( 'mitt-konto' ) ),
			esc_html__( 'Go to your account', 'duaais-members' )
		);
	}

	ob_start();
	?>
	<div class="member-layout duaais-members">
		<div class="member-intro">
			<span class="section-label"><?php esc_html_e( 'Membership', 'duaais-members' ); ?></span>
			<h2><?php esc_html_e( 'Join the DU community in Sweden', 'duaais-members' ); ?></h2>
			<p><?php esc_html_e( 'Membership is for University of Dhaka graduates who currently reside in Sweden.', 'duaais-members' ); ?></p>
			<p><?php esc_html_e( 'Create your profile to reconnect with DU friends, receive association news, and join our activities.', 'duaais-members' ); ?></p>
			<p><?php esc_html_e( 'Membership is free.', 'duaais-members' ); ?></p>
		</div>

		<form class="member-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<h2><?php esc_html_e( 'Create an account', 'duaais-members' ); ?></h2>
			<?php duaais_members_status_notice(); ?>
			<input type="hidden" name="action" value="duaais_register">
			<?php wp_nonce_field( 'duaais_register', 'duaais_nonce' ); ?>

			<div class="duaais-honeypot" aria-hidden="true" hidden>
				<label for="duaais_website"><?php esc_html_e( 'Website', 'duaais-members' ); ?></label>
				<input id="duaais_website" name="duaais_website" type="text" tabindex="-1" autocomplete="off">
			</div>

			<div class="form-grid">
				<div class="form-field">
					<label for="first_name"><?php esc_html_e( 'First name', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="first_name" name="first_name" type="text" autocomplete="given-name" required>
				</div>
				<div class="form-field">
					<label for="last_name"><?php esc_html_e( 'Last name', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="last_name" name="last_name" type="text" autocomplete="family-name" required>
				</div>
				<div class="form-field form-field-wide">
					<label for="email"><?php esc_html_e( 'Email address', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="email" name="email" type="email" autocomplete="email" required>
				</div>
				<div class="form-field">
					<label for="password"><?php esc_html_e( 'Password', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="password" name="password" type="password" minlength="10" autocomplete="new-password" required>
					<span class="field-help"><?php esc_html_e( 'At least 10 characters.', 'duaais-members' ); ?></span>
				</div>
				<div class="form-field">
					<label for="password_confirm"><?php esc_html_e( 'Confirm password', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="password_confirm" name="password_confirm" type="password" minlength="10" autocomplete="new-password" required>
				</div>
				<div class="form-field">
					<label for="department"><?php esc_html_e( 'Faculty or department at DU', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="department" name="department" type="text" autocomplete="organization" required>
				</div>
				<div class="form-field">
					<label for="graduation_year"><?php esc_html_e( 'DU graduation year', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="graduation_year" name="graduation_year" type="number" min="1900" max="<?php echo esc_attr( wp_date( 'Y' ) ); ?>" inputmode="numeric" required>
				</div>
				<div class="form-field">
					<label for="program"><?php esc_html_e( 'Degree or field of study at DU', 'duaais-members' ); ?></label>
					<input id="program" name="program" type="text" autocomplete="off">
				</div>
				<div class="form-field">
					<label for="city"><?php esc_html_e( 'City in Sweden', 'duaais-members' ); ?></label>
					<input id="city" name="city" type="text" autocomplete="address-level2">
				</div>
				<div class="form-field form-field-wide checkbox-field">
					<input id="privacy_consent" name="privacy_consent" type="checkbox" value="1" required>
					<label for="privacy_consent">
						<?php
						printf(
							wp_kses_post( __( 'I agree that DUAAIS Sweden may process my information according to the <a href="%s">privacy policy</a>.', 'duaais-members' ) ),
							esc_url( duaais_members_page_url( 'integritetspolicy' ) )
						);
						?>
					</label>
				</div>
			</div>

			<div class="form-actions">
				<button type="submit"><?php esc_html_e( 'Create alumni account', 'duaais-members' ); ?></button>
				<a href="<?php echo esc_url( duaais_members_page_url( 'logga-in' ) ); ?>"><?php esc_html_e( 'Already have an account?', 'duaais-members' ); ?></a>
			</div>
		</form>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * Process registration after nonce and rate-limit checks.
 */
function duaais_members_handle_registration() {
	if ( is_user_logged_in() ) {
		duaais_members_redirect( 'mitt-konto', 'registered' );
	}

	if ( ! duaais_members_verify_nonce( 'duaais_register' ) ) {
		duaais_members_redirect( 'bli-medlem', 'nonce_invalid' );
	}

	if ( duaais_members_post_text( 'duaais_website' ) ) {
		duaais_members_redirect( 'bli-medlem', 'failed' );
	}

	if ( duaais_members_record_attempt( 'registration', HOUR_IN_SECONDS ) > 5 ) {
		duaais_members_redirect( 'bli-medlem', 'rate_limited' );
	}

	$first_name       = duaais_members_post_text( 'first_name' );
	$last_name        = duaais_members_post_text( 'last_name' );
	$email            = sanitize_email( duaais_members_post_text( 'email' ) );
	$password         = duaais_members_post_password( 'password' );
	$password_confirm = duaais_members_post_password( 'password_confirm' );
	$department       = duaais_members_post_text( 'department' );
	$graduation_year  = duaais_members_post_text( 'graduation_year' );
	$program          = duaais_members_post_text( 'program' );
	$city             = duaais_members_post_text( 'city' );
	$privacy_consent  = duaais_members_post_text( 'privacy_consent' );

	if ( ! $first_name || ! $last_name || ! $email || ! $password || ! $department || ! $graduation_year ) {
		duaais_members_redirect( 'bli-medlem', 'required' );
	}

	if ( ! is_email( $email ) ) {
		duaais_members_redirect( 'bli-medlem', 'email_invalid' );
	}

	if ( email_exists( $email ) ) {
		duaais_members_redirect( 'bli-medlem', 'email_exists' );
	}

	if ( strlen( $password ) < 10 ) {
		duaais_members_redirect( 'bli-medlem', 'password_short' );
	}

	if ( ! hash_equals( $password, $password_confirm ) ) {
		duaais_members_redirect( 'bli-medlem', 'password_mismatch' );
	}

	if ( '1' !== $privacy_consent ) {
		duaais_members_redirect( 'bli-medlem', 'consent_required' );
	}

	if ( ! duaais_members_valid_year( $graduation_year ) ) {
		duaais_members_redirect( 'bli-medlem', 'year_invalid' );
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => duaais_members_username_from_email( $email ),
			'user_email'   => $email,
			'user_pass'    => $password,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
			'role'         => DUAAIS_MEMBER_ROLE,
		)
	);

	if ( is_wp_error( $user_id ) ) {
		duaais_members_redirect( 'bli-medlem', 'failed' );
	}

	update_user_meta( $user_id, 'duaais_department', $department );
	update_user_meta( $user_id, 'duaais_graduation_year', $graduation_year );
	update_user_meta( $user_id, 'duaais_program', $program );
	update_user_meta( $user_id, 'duaais_city', $city );
	update_user_meta( $user_id, 'duaais_privacy_consent_at', current_time( 'mysql', true ) );

	$user = get_user_by( 'id', $user_id );
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true, is_ssl() );
	do_action( 'wp_login', $user->user_login, $user );

	duaais_members_redirect( 'mitt-konto', 'registered' );
}
add_action( 'admin_post_nopriv_duaais_register', 'duaais_members_handle_registration' );
add_action( 'admin_post_duaais_register', 'duaais_members_handle_registration' );

/**
 * Render the front-end login form.
 *
 * @return string
 */
function duaais_members_login_shortcode() {
	if ( is_user_logged_in() ) {
		return sprintf(
			'<div class="form-notice form-notice-success">%1$s <a href="%2$s">%3$s</a></div>',
			esc_html__( 'You are already logged in.', 'duaais-members' ),
			esc_url( duaais_members_page_url( 'mitt-konto' ) ),
			esc_html__( 'Go to your account', 'duaais-members' )
		);
	}

	ob_start();
	?>
	<div class="member-layout duaais-members">
		<div class="member-intro">
			<span class="section-label"><?php esc_html_e( 'Member portal', 'duaais-members' ); ?></span>
			<h2><?php esc_html_e( 'Welcome back', 'duaais-members' ); ?></h2>
			<p><?php esc_html_e( 'Log in to update your DU alumni profile and manage your membership details.', 'duaais-members' ); ?></p>
		</div>

		<form class="member-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<h2><?php esc_html_e( 'Log in', 'duaais-members' ); ?></h2>
			<?php duaais_members_status_notice(); ?>
			<input type="hidden" name="action" value="duaais_login">
			<?php wp_nonce_field( 'duaais_login', 'duaais_nonce' ); ?>

			<div class="form-grid">
				<div class="form-field form-field-wide">
					<label for="login"><?php esc_html_e( 'Email address', 'duaais-members' ); ?></label>
					<input id="login" name="login" type="email" autocomplete="username" required>
				</div>
				<div class="form-field form-field-wide">
					<label for="login_password"><?php esc_html_e( 'Password', 'duaais-members' ); ?></label>
					<input id="login_password" name="password" type="password" autocomplete="current-password" required>
				</div>
				<div class="form-field form-field-wide checkbox-field">
					<input id="remember" name="remember" type="checkbox" value="1">
					<label for="remember"><?php esc_html_e( 'Keep me logged in', 'duaais-members' ); ?></label>
				</div>
			</div>

			<div class="form-actions">
				<button type="submit"><?php esc_html_e( 'Log in', 'duaais-members' ); ?></button>
				<a href="<?php echo esc_url( wp_lostpassword_url( duaais_members_page_url( 'logga-in' ) ) ); ?>"><?php esc_html_e( 'Forgot your password?', 'duaais-members' ); ?></a>
			</div>
		</form>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * Process a front-end login request.
 */
function duaais_members_handle_login() {
	if ( is_user_logged_in() ) {
		duaais_members_redirect( 'mitt-konto', 'logged_in' );
	}

	if ( ! duaais_members_verify_nonce( 'duaais_login' ) ) {
		duaais_members_redirect( 'logga-in', 'nonce_invalid' );
	}

	$key      = duaais_members_throttle_key( 'login' );
	$attempts = (int) get_transient( $key );
	if ( $attempts >= 10 ) {
		duaais_members_redirect( 'logga-in', 'rate_limited' );
	}

	$login    = sanitize_email( duaais_members_post_text( 'login' ) );
	$password = duaais_members_post_password( 'password' );
	$remember = '1' === duaais_members_post_text( 'remember' );

	if ( ! $login || ! $password ) {
		duaais_members_redirect( 'logga-in', 'required' );
	}

	$user = wp_signon(
		array(
			'user_login'    => $login,
			'user_password' => $password,
			'remember'      => $remember,
		),
		is_ssl()
	);

	if ( is_wp_error( $user ) ) {
		duaais_members_record_attempt( 'login', 15 * MINUTE_IN_SECONDS );
		duaais_members_redirect( 'logga-in', 'login_failed' );
	}

	delete_transient( $key );
	duaais_members_redirect( 'mitt-konto', 'logged_in' );
}
add_action( 'admin_post_nopriv_duaais_login', 'duaais_members_handle_login' );
add_action( 'admin_post_duaais_login', 'duaais_members_handle_login' );

/**
 * Render the current member profile form.
 *
 * @return string
 */
function duaais_members_account_shortcode() {
	if ( ! is_user_logged_in() ) {
		return sprintf(
			'<div class="empty-state"><h2>%1$s</h2><p>%2$s</p><a class="button" href="%3$s">%4$s</a></div>',
			esc_html__( 'Log in to view your account', 'duaais-members' ),
			esc_html__( 'Your profile is only available while you are logged in.', 'duaais-members' ),
			esc_url( duaais_members_page_url( 'logga-in' ) ),
			esc_html__( 'Log in', 'duaais-members' )
		);
	}

	$user            = wp_get_current_user();
	$department      = get_user_meta( $user->ID, 'duaais_department', true );
	if ( ! $department ) {
		$department = get_user_meta( $user->ID, 'duaais_university', true );
	}
	$graduation_year = get_user_meta( $user->ID, 'duaais_graduation_year', true );
	$program         = get_user_meta( $user->ID, 'duaais_program', true );
	$city            = get_user_meta( $user->ID, 'duaais_city', true );

	ob_start();
	?>
	<div class="member-layout duaais-members">
		<div class="member-intro">
			<span class="section-label"><?php esc_html_e( 'Your member profile', 'duaais-members' ); ?></span>
			<h2><?php esc_html_e( 'Keep the DU network up to date', 'duaais-members' ); ?></h2>
			<p><?php esc_html_e( 'Your DU background and city in Sweden help the association plan relevant activities and local gatherings.', 'duaais-members' ); ?></p>
			<a class="text-link" href="<?php echo esc_url( wp_logout_url( add_query_arg( 'duaais_status', 'logged_out', duaais_members_page_url( 'logga-in' ) ) ) ); ?>"><?php esc_html_e( 'Log out', 'duaais-members' ); ?></a>
		</div>

		<form class="member-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<div class="account-summary">
				<div class="account-avatar"><?php echo get_avatar( $user->ID, 64 ); ?></div>
				<div>
					<h2><?php echo esc_html( $user->display_name ); ?></h2>
					<p><?php esc_html_e( 'DU Alumni Member', 'duaais-members' ); ?></p>
				</div>
			</div>
			<?php duaais_members_status_notice(); ?>
			<input type="hidden" name="action" value="duaais_update_profile">
			<?php wp_nonce_field( 'duaais_update_profile', 'duaais_nonce' ); ?>

			<div class="form-grid">
				<div class="form-field">
					<label for="account_first_name"><?php esc_html_e( 'First name', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="account_first_name" name="first_name" type="text" value="<?php echo esc_attr( $user->first_name ); ?>" autocomplete="given-name" required>
				</div>
				<div class="form-field">
					<label for="account_last_name"><?php esc_html_e( 'Last name', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="account_last_name" name="last_name" type="text" value="<?php echo esc_attr( $user->last_name ); ?>" autocomplete="family-name" required>
				</div>
				<div class="form-field form-field-wide">
					<label for="account_email"><?php esc_html_e( 'Email address', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="account_email" name="email" type="email" value="<?php echo esc_attr( $user->user_email ); ?>" autocomplete="email" required>
				</div>
				<div class="form-field">
					<label for="account_department"><?php esc_html_e( 'Faculty or department at DU', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="account_department" name="department" type="text" value="<?php echo esc_attr( $department ); ?>" autocomplete="organization" required>
				</div>
				<div class="form-field">
					<label for="account_year"><?php esc_html_e( 'DU graduation year', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
					<input id="account_year" name="graduation_year" type="number" min="1900" max="<?php echo esc_attr( wp_date( 'Y' ) ); ?>" value="<?php echo esc_attr( $graduation_year ); ?>" inputmode="numeric" required>
				</div>
				<div class="form-field">
					<label for="account_program"><?php esc_html_e( 'Degree or field of study at DU', 'duaais-members' ); ?></label>
					<input id="account_program" name="program" type="text" value="<?php echo esc_attr( $program ); ?>">
				</div>
				<div class="form-field">
					<label for="account_city"><?php esc_html_e( 'City in Sweden', 'duaais-members' ); ?></label>
					<input id="account_city" name="city" type="text" value="<?php echo esc_attr( $city ); ?>" autocomplete="address-level2">
				</div>
				<div class="form-field">
					<label for="new_password"><?php esc_html_e( 'New password', 'duaais-members' ); ?></label>
					<input id="new_password" name="new_password" type="password" minlength="10" autocomplete="new-password">
					<span class="field-help"><?php esc_html_e( 'Leave blank to keep your current password.', 'duaais-members' ); ?></span>
				</div>
				<div class="form-field">
					<label for="new_password_confirm"><?php esc_html_e( 'Confirm new password', 'duaais-members' ); ?></label>
					<input id="new_password_confirm" name="new_password_confirm" type="password" minlength="10" autocomplete="new-password">
				</div>
			</div>

			<div class="form-actions">
				<button type="submit"><?php esc_html_e( 'Save profile', 'duaais-members' ); ?></button>
			</div>
		</form>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * Process profile and optional password updates.
 */
function duaais_members_handle_profile_update() {
	if ( ! is_user_logged_in() ) {
		duaais_members_redirect( 'logga-in', 'login_failed' );
	}

	if ( ! duaais_members_verify_nonce( 'duaais_update_profile' ) ) {
		duaais_members_redirect( 'mitt-konto', 'nonce_invalid' );
	}

	$user_id          = get_current_user_id();
	$first_name       = duaais_members_post_text( 'first_name' );
	$last_name        = duaais_members_post_text( 'last_name' );
	$email            = sanitize_email( duaais_members_post_text( 'email' ) );
	$department       = duaais_members_post_text( 'department' );
	$graduation_year  = duaais_members_post_text( 'graduation_year' );
	$program          = duaais_members_post_text( 'program' );
	$city             = duaais_members_post_text( 'city' );
	$new_password     = duaais_members_post_password( 'new_password' );
	$password_confirm = duaais_members_post_password( 'new_password_confirm' );

	if ( ! $first_name || ! $last_name || ! $email || ! $department || ! $graduation_year ) {
		duaais_members_redirect( 'mitt-konto', 'required' );
	}

	if ( ! is_email( $email ) ) {
		duaais_members_redirect( 'mitt-konto', 'email_invalid' );
	}

	$email_owner = email_exists( $email );
	if ( $email_owner && (int) $email_owner !== $user_id ) {
		duaais_members_redirect( 'mitt-konto', 'email_exists' );
	}

	if ( ! duaais_members_valid_year( $graduation_year ) ) {
		duaais_members_redirect( 'mitt-konto', 'year_invalid' );
	}

	if ( $new_password && strlen( $new_password ) < 10 ) {
		duaais_members_redirect( 'mitt-konto', 'password_short' );
	}

	if ( $new_password && ! hash_equals( $new_password, $password_confirm ) ) {
		duaais_members_redirect( 'mitt-konto', 'password_mismatch' );
	}

	$user_data = array(
		'ID'           => $user_id,
		'user_email'   => $email,
		'first_name'   => $first_name,
		'last_name'    => $last_name,
		'display_name' => trim( $first_name . ' ' . $last_name ),
	);

	if ( $new_password ) {
		$user_data['user_pass'] = $new_password;
	}

	$result = wp_update_user( $user_data );
	if ( is_wp_error( $result ) ) {
		duaais_members_redirect( 'mitt-konto', 'failed' );
	}

	update_user_meta( $user_id, 'duaais_department', $department );
	update_user_meta( $user_id, 'duaais_graduation_year', $graduation_year );
	update_user_meta( $user_id, 'duaais_program', $program );
	update_user_meta( $user_id, 'duaais_city', $city );

	if ( $new_password ) {
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
	}

	duaais_members_redirect( 'mitt-konto', 'updated' );
}
add_action( 'admin_post_duaais_update_profile', 'duaais_members_handle_profile_update' );
add_action( 'admin_post_nopriv_duaais_update_profile', 'duaais_members_handle_profile_update' );

/**
 * Identify front-end-only alumni accounts.
 *
 * @return bool
 */
function duaais_members_current_user_is_alumni() {
	$user = wp_get_current_user();

	return $user->exists() && in_array( DUAAIS_MEMBER_ROLE, (array) $user->roles, true );
}

/**
 * Keep alumni out of wp-admin while preserving admin-post handlers.
 */
function duaais_members_restrict_admin() {
	global $pagenow;

	if (
		duaais_members_current_user_is_alumni() &&
		! wp_doing_ajax() &&
		'admin-post.php' !== $pagenow
	) {
		wp_safe_redirect( duaais_members_page_url( 'mitt-konto' ) );
		exit;
	}
}
add_action( 'admin_init', 'duaais_members_restrict_admin' );

/**
 * Hide the WordPress toolbar for alumni accounts.
 *
 * @param bool $show Whether the toolbar should be shown.
 * @return bool
 */
function duaais_members_admin_bar( $show ) {
	return duaais_members_current_user_is_alumni() ? false : $show;
}
add_filter( 'show_admin_bar', 'duaais_members_admin_bar' );