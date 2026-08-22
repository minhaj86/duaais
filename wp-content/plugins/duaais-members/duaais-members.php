<?php
/**
 * Plugin Name: DUAAIS Members
 * Description: Front-end registration with DU certificate upload, board approval, login, and profile management for University of Dhaka alumni in Sweden.
 * Version: 1.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: DUAAIS Sweden
 * Text Domain: duaais-members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DUAAIS_MEMBERS_VERSION = '1.1.0';
const DUAAIS_MEMBER_ROLE     = 'duaais_alumni';
const DUAAIS_PENDING_ROLE    = 'duaais_pending';

const DUAAIS_STATUS_PENDING  = 'pending';
const DUAAIS_STATUS_APPROVED = 'approved';
const DUAAIS_STATUS_REJECTED = 'rejected';

const DUAAIS_CERTIFICATE_DIR       = 'duaais-certificates';
const DUAAIS_CERTIFICATE_MAX_BYTES = 8388608;

/**
 * Create the member roles without removing existing capabilities on upgrades.
 */
function duaais_members_register_role() {
	$roles = array(
		DUAAIS_MEMBER_ROLE  => __( 'Alumni Member', 'duaais-members' ),
		DUAAIS_PENDING_ROLE => __( 'Pending Alumni Member', 'duaais-members' ),
	);

	foreach ( $roles as $role => $label ) {
		if ( ! get_role( $role ) ) {
			add_role( $role, $label, array( 'read' => true ) );
			continue;
		}

		$wp_roles = wp_roles();
		if ( isset( $wp_roles->roles[ $role ] ) && $label !== $wp_roles->roles[ $role ]['name'] ) {
			$wp_roles->roles[ $role ]['name'] = $label;
			$wp_roles->role_names[ $role ]    = $label;
			update_option( $wp_roles->role_key, $wp_roles->roles );
		}
	}
}
add_action( 'init', 'duaais_members_register_role' );

/**
 * Configure safe defaults when the plugin is activated.
 *
 * The WordPress registration screen stays closed because membership must go through the
 * reviewed [duaais_register] form; any account created elsewhere defaults to the pending role.
 */
function duaais_members_activate() {
	duaais_members_register_role();
	duaais_members_certificate_dir();
	update_option( 'users_can_register', 0 );
	update_option( 'default_role', DUAAIS_PENDING_ROLE );
}
register_activation_hook( __FILE__, 'duaais_members_activate' );

/**
 * Apply the activation defaults after the plugin files have been updated in place.
 */
function duaais_members_maybe_upgrade() {
	if ( DUAAIS_MEMBERS_VERSION === get_option( 'duaais_members_version' ) ) {
		return;
	}

	duaais_members_activate();
	update_option( 'duaais_members_version', DUAAIS_MEMBERS_VERSION );
}
add_action( 'init', 'duaais_members_maybe_upgrade', 11 );

/**
 * List the accepted answers for the Swedish residence status question.
 *
 * @return array<string, string>
 */
function duaais_members_residence_statuses() {
	return array(
		'citizen'               => __( 'Swedish citizen', 'duaais-members' ),
		'permanent'             => __( 'Permanent residence permit', 'duaais-members' ),
		'temporary'             => __( 'Temporary residence permit', 'duaais-members' ),
		'work_permit'           => __( 'Work permit', 'duaais-members' ),
		'student_permit'        => __( 'Student permit', 'duaais-members' ),
		'eu_right_of_residence' => __( 'EU/EEA right of residence', 'duaais-members' ),
		'other'                 => __( 'Other', 'duaais-members' ),
	);
}

/**
 * Translate a stored residence status into its label.
 *
 * @param string $status Stored status key.
 * @return string
 */
function duaais_members_residence_label( $status ) {
	$statuses = duaais_members_residence_statuses();

	return isset( $statuses[ $status ] ) ? $statuses[ $status ] : '';
}

/**
 * Resolve the membership status of a user, including accounts created before approvals existed.
 *
 * @param int|WP_User $user User or user ID.
 * @return string
 */
function duaais_members_status( $user ) {
	$user = $user instanceof WP_User ? $user : get_user_by( 'id', (int) $user );

	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return '';
	}

	$roles = (array) $user->roles;

	// A role granted outside this plugin wins, so a manual promotion cannot lock an account out.
	if ( in_array( DUAAIS_MEMBER_ROLE, $roles, true ) ) {
		return DUAAIS_STATUS_APPROVED;
	}

	if ( $roles && ! in_array( DUAAIS_PENDING_ROLE, $roles, true ) ) {
		return '';
	}

	$status = (string) get_user_meta( $user->ID, 'duaais_membership_status', true );
	if ( in_array( $status, array( DUAAIS_STATUS_PENDING, DUAAIS_STATUS_APPROVED, DUAAIS_STATUS_REJECTED ), true ) ) {
		return $status;
	}

	return in_array( DUAAIS_PENDING_ROLE, $roles, true ) ? DUAAIS_STATUS_PENDING : '';
}

/**
 * Keep the membership status in step with roles changed from the users screen.
 *
 * @param int    $user_id User whose role changed.
 * @param string $role    New role.
 */
function duaais_members_sync_status_with_role( $user_id, $role ) {
	if ( DUAAIS_MEMBER_ROLE === $role ) {
		update_user_meta( $user_id, 'duaais_membership_status', DUAAIS_STATUS_APPROVED );

		return;
	}

	if ( DUAAIS_PENDING_ROLE === $role ) {
		update_user_meta( $user_id, 'duaais_membership_status', DUAAIS_STATUS_PENDING );

		return;
	}

	if ( '' !== $role ) {
		delete_user_meta( $user_id, 'duaais_membership_status' );
	}
}
add_action( 'set_user_role', 'duaais_members_sync_status_with_role', 10, 2 );

/**
 * Address used for board notifications about membership applications.
 *
 * @return string
 */
function duaais_members_admin_email() {
	/**
	 * Filter the address that receives membership application notifications.
	 *
	 * @param string $email Notification address.
	 */
	$email = (string) apply_filters( 'duaais_members_admin_email', (string) get_option( 'admin_email' ) );

	return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
}

/**
 * Send a plain-text notification without breaking registration when mail fails.
 *
 * @param string $to      Recipient.
 * @param string $subject Subject line.
 * @param string $message Message body.
 * @return bool
 */
function duaais_members_send_mail( $to, $subject, $message ) {
	if ( ! is_email( $to ) ) {
		return false;
	}

	$sent = wp_mail( $to, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );

	if ( ! $sent ) {
		duaais_members_log( sprintf( 'Unable to send membership email to %s (%s).', $to, $subject ) );
	}

	return (bool) $sent;
}

/**
 * Write a diagnostic line only while debugging is enabled.
 *
 * @param string $message Message to log.
 */
function duaais_members_log( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[duaais-members] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

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
		'registered'           => array( 'success', __( 'Welcome! Your alumni account has been created.', 'duaais-members' ) ),
		'pending'              => array( 'success', __( 'Thank you. Your membership application has been received and is waiting for approval by the DUAAIS board. You will get an email as soon as it has been reviewed.', 'duaais-members' ) ),
		'logged_in'            => array( 'success', __( 'You are now logged in.', 'duaais-members' ) ),
		'updated'              => array( 'success', __( 'Your profile has been updated.', 'duaais-members' ) ),
		'logged_out'           => array( 'success', __( 'You are now logged out.', 'duaais-members' ) ),
		'required'             => array( 'error', __( 'Complete all required fields.', 'duaais-members' ) ),
		'email_invalid'        => array( 'error', __( 'Enter a valid email address.', 'duaais-members' ) ),
		'email_exists'         => array( 'error', __( 'An account already exists for that email address.', 'duaais-members' ) ),
		'password_short'       => array( 'error', __( 'Your password must contain at least 10 characters.', 'duaais-members' ) ),
		'password_mismatch'    => array( 'error', __( 'The passwords do not match.', 'duaais-members' ) ),
		'consent_required'     => array( 'error', __( 'You must accept the privacy policy to register.', 'duaais-members' ) ),
		'declaration_required' => array( 'error', __( 'Confirm the declaration to sign your application.', 'duaais-members' ) ),
		'year_invalid'         => array( 'error', __( 'Enter a valid examination year.', 'duaais-members' ) ),
		'residence_invalid'    => array( 'error', __( 'Select your residence status in Sweden.', 'duaais-members' ) ),
		'certificate_required' => array( 'error', __( 'Attach a copy of your DU certificate.', 'duaais-members' ) ),
		'certificate_type'     => array( 'error', __( 'The certificate must be a PDF, JPG, or PNG file.', 'duaais-members' ) ),
		'certificate_large'    => array(
			'error',
			sprintf(
				/* translators: %s: maximum upload size, for example 2 MB. */
				__( 'The certificate file is too large. The maximum size is %s.', 'duaais-members' ),
				size_format( duaais_members_max_certificate_bytes() )
			),
		),
		'certificate_failed'   => array( 'error', __( 'The certificate could not be uploaded. Try again with another file.', 'duaais-members' ) ),
		'login_failed'         => array( 'error', __( 'The email address or password is incorrect.', 'duaais-members' ) ),
		'account_pending'      => array( 'error', __( 'Your membership application is still waiting for approval by the DUAAIS board. You will get an email when it has been reviewed.', 'duaais-members' ) ),
		'account_rejected'     => array( 'error', __( 'Your membership application was not approved. Contact the association if you think this is a mistake.', 'duaais-members' ) ),
		'nonce_invalid'        => array( 'error', __( 'The form has expired. Please try again.', 'duaais-members' ) ),
		'rate_limited'         => array( 'error', __( 'Too many attempts. Wait a moment and try again.', 'duaais-members' ) ),
		'failed'               => array( 'error', __( 'Something went wrong. Try again or contact the association.', 'duaais-members' ) ),
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
 * Create and protect the private folder that stores DU certificates.
 *
 * @return string Absolute directory path, or an empty string when it cannot be created.
 */
function duaais_members_certificate_dir() {
	$uploads = wp_get_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return '';
	}

	$directory = trailingslashit( $uploads['basedir'] ) . DUAAIS_CERTIFICATE_DIR;
	if ( ! wp_mkdir_p( $directory ) ) {
		return '';
	}

	$htaccess = $directory . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	$index = $directory . '/index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	return $directory;
}

/**
 * Largest certificate the server will actually accept.
 *
 * @return int Size in bytes.
 */
function duaais_members_max_certificate_bytes() {
	$server_limit = (int) wp_max_upload_size();

	if ( $server_limit > 0 && $server_limit < DUAAIS_CERTIFICATE_MAX_BYTES ) {
		return $server_limit;
	}

	return DUAAIS_CERTIFICATE_MAX_BYTES;
}

/**
 * Accepted certificate file types.
 *
 * @return array<string, string>
 */
function duaais_members_certificate_mimes() {
	return array(
		'pdf'          => 'application/pdf',
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
	);
}

/**
 * Validate an uploaded certificate before any account is created.
 *
 * @param string $key      File input name.
 * @param bool   $required Whether a missing file is an error.
 * @return string Empty string when valid, otherwise a status key.
 */
function duaais_members_validate_certificate( $key, $required = true ) {
	$file = duaais_members_uploaded_file( $key );

	if ( array() === $file ) {
		return $required ? 'certificate_required' : '';
	}

	$error = (int) $file['error'];
	if ( UPLOAD_ERR_NO_FILE === $error || '' === $file['name'] ) {
		return $required ? 'certificate_required' : '';
	}

	if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
		return 'certificate_large';
	}

	if ( UPLOAD_ERR_OK !== $error || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return 'certificate_failed';
	}

	if ( $file['size'] > duaais_members_max_certificate_bytes() ) {
		return 'certificate_large';
	}

	$name    = sanitize_file_name( $file['name'] );
	$checked = wp_check_filetype_and_ext( $file['tmp_name'], $name, duaais_members_certificate_mimes() );

	if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
		return 'certificate_type';
	}

	return '';
}

/**
 * Read a single-file upload entry, rejecting array-shaped input.
 *
 * @param string $key File input name.
 * @return array<string, mixed> Normalized entry, or an empty array when nothing usable was sent.
 */
function duaais_members_uploaded_file( $key ) {
	$file = isset( $_FILES[ $key ] ) && is_array( $_FILES[ $key ] ) ? $_FILES[ $key ] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	if ( ! isset( $file['name'], $file['tmp_name'], $file['error'], $file['size'] ) ) {
		return array();
	}

	if ( ! is_string( $file['name'] ) || ! is_string( $file['tmp_name'] ) || is_array( $file['error'] ) ) {
		return array();
	}

	return array(
		'name'     => wp_unslash( $file['name'] ),
		'tmp_name' => $file['tmp_name'],
		'error'    => (int) $file['error'],
		'size'     => (int) $file['size'],
	);
}

/**
 * Move a validated certificate into the protected uploads folder.
 *
 * @param int    $user_id Owner of the certificate.
 * @param string $key     File input name.
 * @return bool
 */
function duaais_members_store_certificate( $user_id, $key ) {
	$file = duaais_members_uploaded_file( $key );
	if ( array() === $file || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return false;
	}

	$directory = duaais_members_certificate_dir();
	if ( ! $directory ) {
		duaais_members_log( 'The certificate directory could not be created.' );

		return false;
	}

	$original = sanitize_file_name( $file['name'] );
	$checked  = wp_check_filetype_and_ext( $file['tmp_name'], $original, duaais_members_certificate_mimes() );
	if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
		return false;
	}

	$filename    = sprintf( 'du-certificate-%d-%s.%s', (int) $user_id, wp_generate_password( 20, false, false ), $checked['ext'] );
	$destination = trailingslashit( $directory ) . $filename;

	if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
		duaais_members_log( sprintf( 'The certificate for user %d could not be moved into place.', (int) $user_id ) );

		return false;
	}

	chmod( $destination, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

	duaais_members_delete_certificate( $user_id );

	update_user_meta( $user_id, 'duaais_certificate_file', DUAAIS_CERTIFICATE_DIR . '/' . $filename );
	update_user_meta( $user_id, 'duaais_certificate_name', $original );
	update_user_meta( $user_id, 'duaais_certificate_type', $checked['type'] );
	update_user_meta( $user_id, 'duaais_certificate_uploaded_at', current_time( 'mysql', true ) );

	return true;
}

/**
 * Absolute path of a stored certificate, if the file still exists.
 *
 * @param int $user_id Owner of the certificate.
 * @return string
 */
function duaais_members_certificate_path( $user_id ) {
	$relative = (string) get_user_meta( $user_id, 'duaais_certificate_file', true );
	if ( ! $relative ) {
		return '';
	}

	$uploads = wp_get_upload_dir();
	$path    = trailingslashit( $uploads['basedir'] ) . ltrim( $relative, '/' );

	if ( 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( trailingslashit( $uploads['basedir'] ) . DUAAIS_CERTIFICATE_DIR ) ) ) {
		return '';
	}

	return file_exists( $path ) ? $path : '';
}

/**
 * Remove a stored certificate and its metadata.
 *
 * @param int $user_id Owner of the certificate.
 */
function duaais_members_delete_certificate( $user_id ) {
	$path = duaais_members_certificate_path( $user_id );
	if ( $path ) {
		wp_delete_file( $path );
	}

	delete_user_meta( $user_id, 'duaais_certificate_file' );
	delete_user_meta( $user_id, 'duaais_certificate_name' );
	delete_user_meta( $user_id, 'duaais_certificate_type' );
	delete_user_meta( $user_id, 'duaais_certificate_uploaded_at' );
}

/**
 * Clean up private files when an account is deleted.
 *
 * @param int $user_id Deleted user.
 */
function duaais_members_delete_user_files( $user_id ) {
	duaais_members_delete_certificate( (int) $user_id );
}
add_action( 'delete_user', 'duaais_members_delete_user_files' );

/**
 * Build the admin-only download link for a certificate.
 *
 * @param int $user_id Owner of the certificate.
 * @return string
 */
function duaais_members_certificate_url( $user_id ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'duaais_certificate',
				'user'   => (int) $user_id,
			),
			admin_url( 'admin-post.php' )
		),
		'duaais_certificate_' . (int) $user_id
	);
}

/**
 * Stream a certificate to an authorized board member.
 */
function duaais_members_handle_certificate_download() {
	$user_id = isset( $_GET['user'] ) ? absint( wp_unslash( $_GET['user'] ) ) : 0;

	if ( ! $user_id || ! current_user_can( 'edit_users' ) ) {
		wp_die( esc_html__( 'You are not allowed to view this certificate.', 'duaais-members' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'duaais_certificate_' . $user_id );

	$path = duaais_members_certificate_path( $user_id );
	if ( ! $path ) {
		wp_die( esc_html__( 'The certificate is no longer available.', 'duaais-members' ), '', array( 'response' => 404 ) );
	}

	$type = (string) get_user_meta( $user_id, 'duaais_certificate_type', true );
	$name = (string) get_user_meta( $user_id, 'duaais_certificate_name', true );

	nocache_headers();
	header( 'Content-Type: ' . ( $type ? $type : 'application/octet-stream' ) );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $name ? $name : basename( $path ) ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}
add_action( 'admin_post_duaais_certificate', 'duaais_members_handle_certificate_download' );

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
			<p><?php esc_html_e( 'Complete the membership application and attach a copy of your DU certificate. The board reviews every application before the account is activated.', 'duaais-members' ); ?></p>
			<p><?php esc_html_e( 'Membership is free.', 'duaais-members' ); ?></p>
		</div>

		<form class="member-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
			<h2><?php esc_html_e( 'Membership application', 'duaais-members' ); ?></h2>
			<?php duaais_members_status_notice(); ?>
			<input type="hidden" name="action" value="duaais_register">
			<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( duaais_members_max_certificate_bytes() ); ?>">
			<?php wp_nonce_field( 'duaais_register', 'duaais_nonce' ); ?>

			<div class="duaais-honeypot" aria-hidden="true" hidden>
				<label for="duaais_website"><?php esc_html_e( 'Website', 'duaais-members' ); ?></label>
				<input id="duaais_website" name="duaais_website" type="text" tabindex="-1" autocomplete="off">
			</div>

			<fieldset class="form-section">
				<legend><?php esc_html_e( 'Contact details', 'duaais-members' ); ?></legend>
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
						<label for="address"><?php esc_html_e( 'Address', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="address" name="address" type="text" autocomplete="street-address" required>
					</div>
					<div class="form-field">
						<label for="postal_code"><?php esc_html_e( 'Postal code', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="postal_code" name="postal_code" type="text" autocomplete="postal-code" required>
					</div>
					<div class="form-field">
						<label for="city"><?php esc_html_e( 'City in Sweden', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="city" name="city" type="text" autocomplete="address-level2" required>
					</div>
					<div class="form-field">
						<label for="mobile_phone"><?php esc_html_e( 'Mobile phone', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="mobile_phone" name="mobile_phone" type="tel" autocomplete="tel" required>
					</div>
					<div class="form-field">
						<label for="work_phone"><?php esc_html_e( 'Telephone (work)', 'duaais-members' ); ?></label>
						<input id="work_phone" name="work_phone" type="tel" autocomplete="tel-extension">
					</div>
					<div class="form-field form-field-wide">
						<label for="email"><?php esc_html_e( 'Email address', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="email" name="email" type="email" autocomplete="email" required>
					</div>
				</div>
			</fieldset>

			<fieldset class="form-section">
				<legend><?php esc_html_e( 'University of Dhaka details', 'duaais-members' ); ?></legend>
				<div class="form-grid">
					<div class="form-field">
						<label for="subject"><?php esc_html_e( 'Subject', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="subject" name="subject" type="text" autocomplete="off" required>
						<span class="field-help"><?php esc_html_e( 'The department or subject you studied at DU.', 'duaais-members' ); ?></span>
					</div>
					<div class="form-field">
						<label for="hall"><?php esc_html_e( 'Attested hall', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="hall" name="hall" type="text" autocomplete="off" required>
					</div>
					<div class="form-field">
						<label for="examination_year"><?php esc_html_e( 'Examination year', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="examination_year" name="examination_year" type="number" min="1900" max="<?php echo esc_attr( wp_date( 'Y' ) ); ?>" inputmode="numeric" required>
					</div>
					<div class="form-field">
						<label for="certificate"><?php esc_html_e( 'DU certificate copy', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="certificate" name="certificate" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
						<span class="field-help">
							<?php
							printf(
								/* translators: %s: maximum upload size, for example 8 MB. */
								esc_html__( 'PDF, JPG, or PNG, up to %s. Only the board can open the file.', 'duaais-members' ),
								esc_html( size_format( duaais_members_max_certificate_bytes() ) )
							);
							?>
						</span>
					</div>
				</div>
			</fieldset>

			<fieldset class="form-section">
				<legend><?php esc_html_e( 'Status in Sweden', 'duaais-members' ); ?></legend>
				<div class="form-grid">
					<div class="form-field">
						<label for="residence_status"><?php esc_html_e( 'Residence status in Sweden', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<select id="residence_status" name="residence_status" required>
							<option value=""><?php esc_html_e( 'Select an option', 'duaais-members' ); ?></option>
							<?php foreach ( duaais_members_residence_statuses() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="form-field">
						<label for="reference"><?php esc_html_e( 'Reference in Sweden (if any)', 'duaais-members' ); ?></label>
						<input id="reference" name="reference" type="text" autocomplete="off">
						<span class="field-help"><?php esc_html_e( 'A current DUAAIS member who can vouch for your application.', 'duaais-members' ); ?></span>
					</div>
				</div>
			</fieldset>

			<fieldset class="form-section">
				<legend><?php esc_html_e( 'Account and signature', 'duaais-members' ); ?></legend>
				<div class="form-grid">
					<div class="form-field">
						<label for="password"><?php esc_html_e( 'Password', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="password" name="password" type="password" minlength="10" autocomplete="new-password" required>
						<span class="field-help"><?php esc_html_e( 'At least 10 characters.', 'duaais-members' ); ?></span>
					</div>
					<div class="form-field">
						<label for="password_confirm"><?php esc_html_e( 'Confirm password', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="password_confirm" name="password_confirm" type="password" minlength="10" autocomplete="new-password" required>
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
					<div class="form-field form-field-wide checkbox-field">
						<input id="declaration" name="declaration" type="checkbox" value="1" required>
						<label for="declaration"><?php esc_html_e( 'I declare that the information above is correct and that the attached certificate is mine. This confirmation is my electronic signature.', 'duaais-members' ); ?></label>
					</div>
					<p class="form-field form-field-wide field-help">
						<?php
						printf(
							/* translators: %s: current date. */
							esc_html__( 'Date of application: %s', 'duaais-members' ),
							esc_html( wp_date( get_option( 'date_format' ) ) )
						);
						?>
					</p>
				</div>
			</fieldset>

			<div class="form-actions">
				<button type="submit"><?php esc_html_e( 'Submit application', 'duaais-members' ); ?></button>
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
	$address          = duaais_members_post_text( 'address' );
	$postal_code      = duaais_members_post_text( 'postal_code' );
	$city             = duaais_members_post_text( 'city' );
	$mobile_phone     = duaais_members_post_text( 'mobile_phone' );
	$work_phone       = duaais_members_post_text( 'work_phone' );
	$subject          = duaais_members_post_text( 'subject' );
	$hall             = duaais_members_post_text( 'hall' );
	$examination_year = duaais_members_post_text( 'examination_year' );
	$residence_status = sanitize_key( duaais_members_post_text( 'residence_status' ) );
	$reference        = duaais_members_post_text( 'reference' );
	$privacy_consent  = duaais_members_post_text( 'privacy_consent' );
	$declaration      = duaais_members_post_text( 'declaration' );

	$required = array( $first_name, $last_name, $email, $password, $address, $postal_code, $city, $mobile_phone, $subject, $hall, $examination_year );
	foreach ( $required as $value ) {
		if ( '' === $value ) {
			duaais_members_redirect( 'bli-medlem', 'required' );
		}
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

	if ( '1' !== $declaration ) {
		duaais_members_redirect( 'bli-medlem', 'declaration_required' );
	}

	if ( ! duaais_members_valid_year( $examination_year ) ) {
		duaais_members_redirect( 'bli-medlem', 'year_invalid' );
	}

	if ( ! duaais_members_residence_label( $residence_status ) ) {
		duaais_members_redirect( 'bli-medlem', 'residence_invalid' );
	}

	$certificate_error = duaais_members_validate_certificate( 'certificate' );
	if ( $certificate_error ) {
		duaais_members_redirect( 'bli-medlem', $certificate_error );
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => duaais_members_username_from_email( $email ),
			'user_email'   => $email,
			'user_pass'    => $password,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
			'role'         => DUAAIS_PENDING_ROLE,
		)
	);

	if ( is_wp_error( $user_id ) ) {
		duaais_members_redirect( 'bli-medlem', 'failed' );
	}

	if ( ! duaais_members_store_certificate( $user_id, 'certificate' ) ) {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_delete_user( $user_id );
		duaais_members_redirect( 'bli-medlem', 'certificate_failed' );
	}

	$now = current_time( 'mysql', true );

	update_user_meta( $user_id, 'duaais_subject', $subject );
	update_user_meta( $user_id, 'duaais_hall', $hall );
	update_user_meta( $user_id, 'duaais_graduation_year', $examination_year );
	update_user_meta( $user_id, 'duaais_address', $address );
	update_user_meta( $user_id, 'duaais_postal_code', $postal_code );
	update_user_meta( $user_id, 'duaais_city', $city );
	update_user_meta( $user_id, 'duaais_mobile_phone', $mobile_phone );
	update_user_meta( $user_id, 'duaais_work_phone', $work_phone );
	update_user_meta( $user_id, 'duaais_residence_status', $residence_status );
	update_user_meta( $user_id, 'duaais_reference', $reference );
	update_user_meta( $user_id, 'duaais_membership_status', DUAAIS_STATUS_PENDING );
	update_user_meta( $user_id, 'duaais_applied_at', $now );
	update_user_meta( $user_id, 'duaais_declaration_at', $now );
	update_user_meta( $user_id, 'duaais_privacy_consent_at', $now );

	duaais_members_notify_admin( $user_id );
	duaais_members_notify_applicant_pending( $user_id );

	/**
	 * Fires after a membership application has been stored and is awaiting review.
	 *
	 * @param int $user_id Applicant user ID.
	 */
	do_action( 'duaais_members_application_submitted', $user_id );

	duaais_members_redirect( 'bli-medlem', 'pending' );
}
add_action( 'admin_post_nopriv_duaais_register', 'duaais_members_handle_registration' );
add_action( 'admin_post_duaais_register', 'duaais_members_handle_registration' );

/**
 * Collect the application details used in emails and the review screen.
 *
 * @param int $user_id Applicant user ID.
 * @return array<string, string>
 */
function duaais_members_application_summary( $user_id ) {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user instanceof WP_User ) {
		return array();
	}

	$applied_at = (string) get_user_meta( $user_id, 'duaais_applied_at', true );

	return array(
		__( 'Name', 'duaais-members' )                       => $user->display_name,
		__( 'Address', 'duaais-members' )                    => (string) get_user_meta( $user_id, 'duaais_address', true ),
		__( 'Postal code', 'duaais-members' )                => (string) get_user_meta( $user_id, 'duaais_postal_code', true ),
		__( 'City', 'duaais-members' )                       => (string) get_user_meta( $user_id, 'duaais_city', true ),
		__( 'Mobile phone', 'duaais-members' )               => (string) get_user_meta( $user_id, 'duaais_mobile_phone', true ),
		__( 'Telephone (work)', 'duaais-members' )           => (string) get_user_meta( $user_id, 'duaais_work_phone', true ),
		__( 'Email', 'duaais-members' )                      => $user->user_email,
		__( 'Subject', 'duaais-members' )                    => duaais_members_subject( $user_id ),
		__( 'Attested hall', 'duaais-members' )              => (string) get_user_meta( $user_id, 'duaais_hall', true ),
		__( 'Examination year', 'duaais-members' )           => (string) get_user_meta( $user_id, 'duaais_graduation_year', true ),
		__( 'Residence status in Sweden', 'duaais-members' ) => duaais_members_residence_label( (string) get_user_meta( $user_id, 'duaais_residence_status', true ) ),
		__( 'Reference in Sweden', 'duaais-members' )        => (string) get_user_meta( $user_id, 'duaais_reference', true ),
		__( 'DU certificate', 'duaais-members' )             => (string) get_user_meta( $user_id, 'duaais_certificate_name', true ),
		__( 'Application date', 'duaais-members' )           => $applied_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $applied_at . ' UTC' ) ) : '',
	);
}

/**
 * Read the DU subject, falling back to metadata written by earlier versions.
 *
 * @param int $user_id Member user ID.
 * @return string
 */
function duaais_members_subject( $user_id ) {
	foreach ( array( 'duaais_subject', 'duaais_department', 'duaais_program', 'duaais_university' ) as $key ) {
		$value = (string) get_user_meta( $user_id, $key, true );
		if ( $value ) {
			return $value;
		}
	}

	return '';
}

/**
 * Email the board when a new application needs a decision.
 *
 * @param int $user_id Applicant user ID.
 * @return bool
 */
function duaais_members_notify_admin( $user_id ) {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user instanceof WP_User ) {
		return false;
	}

	$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	$subject = sprintf(
		/* translators: 1: site name, 2: applicant name. */
		__( '[%1$s] Membership application from %2$s', 'duaais-members' ),
		$site,
		$user->display_name
	);

	$lines = array(
		__( 'A new DUAAIS membership application is waiting for approval.', 'duaais-members' ),
		'',
	);

	foreach ( duaais_members_application_summary( $user_id ) as $label => $value ) {
		$lines[] = sprintf( '%s: %s', $label, '' !== $value ? $value : __( '(not provided)', 'duaais-members' ) );
	}

	$lines[] = '';
	$lines[] = __( 'Review the application and the attached DU certificate:', 'duaais-members' );
	$lines[] = duaais_members_applications_url();

	return duaais_members_send_mail( duaais_members_admin_email(), $subject, implode( "\n", $lines ) );
}

/**
 * Confirm to the applicant that the application is under review.
 *
 * @param int $user_id Applicant user ID.
 * @return bool
 */
function duaais_members_notify_applicant_pending( $user_id ) {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user instanceof WP_User ) {
		return false;
	}

	$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

	$message = implode(
		"\n",
		array(
			sprintf(
				/* translators: %s: applicant first name. */
				__( 'Hello %s,', 'duaais-members' ),
				$user->first_name ? $user->first_name : $user->display_name
			),
			'',
			__( 'Thank you for applying for DUAAIS Sweden membership. The board reviews every application together with the attached DU certificate.', 'duaais-members' ),
			__( 'You will receive another email as soon as a decision has been made. You can log in once your membership has been approved.', 'duaais-members' ),
			'',
			$site,
			home_url( '/' ),
		)
	);

	return duaais_members_send_mail(
		$user->user_email,
		sprintf(
			/* translators: %s: site name. */
			__( '[%s] We have received your membership application', 'duaais-members' ),
			$site
		),
		$message
	);
}

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
		$code = $user->get_error_code();
		duaais_members_record_attempt( 'login', 15 * MINUTE_IN_SECONDS );

		if ( 'duaais_pending' === $code ) {
			duaais_members_redirect( 'logga-in', 'account_pending' );
		}

		if ( 'duaais_rejected' === $code ) {
			duaais_members_redirect( 'logga-in', 'account_rejected' );
		}

		duaais_members_redirect( 'logga-in', 'login_failed' );
	}

	delete_transient( $key );
	duaais_members_redirect( 'mitt-konto', 'logged_in' );
}
add_action( 'admin_post_nopriv_duaais_login', 'duaais_members_handle_login' );
add_action( 'admin_post_duaais_login', 'duaais_members_handle_login' );

/**
 * Keep unapproved applicants out of the site until the board decides.
 *
 * The password is verified first so that the status of an application is never disclosed
 * to someone who only knows the email address.
 *
 * @param WP_User|WP_Error $user     Authenticated user or error.
 * @param string           $password Submitted password.
 * @return WP_User|WP_Error
 */
function duaais_members_block_unapproved_login( $user, $password ) {
	if ( ! $user instanceof WP_User ) {
		return $user;
	}

	$status = duaais_members_status( $user );

	if ( DUAAIS_STATUS_PENDING !== $status && DUAAIS_STATUS_REJECTED !== $status ) {
		return $user;
	}

	if ( ! is_string( $password ) || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
		return $user;
	}

	if ( DUAAIS_STATUS_PENDING === $status ) {
		return new WP_Error(
			'duaais_pending',
			__( '<strong>Membership pending:</strong> your application is waiting for approval by the DUAAIS board.', 'duaais-members' )
		);
	}

	return new WP_Error(
		'duaais_rejected',
		__( '<strong>Membership not approved:</strong> contact the association if you think this is a mistake.', 'duaais-members' )
	);
}
add_filter( 'wp_authenticate_user', 'duaais_members_block_unapproved_login', 10, 2 );

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

	$user             = wp_get_current_user();
	$subject          = duaais_members_subject( $user->ID );
	$hall             = get_user_meta( $user->ID, 'duaais_hall', true );
	$graduation_year  = get_user_meta( $user->ID, 'duaais_graduation_year', true );
	$address          = get_user_meta( $user->ID, 'duaais_address', true );
	$postal_code      = get_user_meta( $user->ID, 'duaais_postal_code', true );
	$city             = get_user_meta( $user->ID, 'duaais_city', true );
	$mobile_phone     = get_user_meta( $user->ID, 'duaais_mobile_phone', true );
	$work_phone       = get_user_meta( $user->ID, 'duaais_work_phone', true );
	$residence_status = (string) get_user_meta( $user->ID, 'duaais_residence_status', true );
	$reference        = get_user_meta( $user->ID, 'duaais_reference', true );
	$certificate_name = (string) get_user_meta( $user->ID, 'duaais_certificate_name', true );

	ob_start();
	?>
	<div class="member-layout duaais-members">
		<div class="member-intro">
			<span class="section-label"><?php esc_html_e( 'Your member profile', 'duaais-members' ); ?></span>
			<h2><?php esc_html_e( 'Keep the DU network up to date', 'duaais-members' ); ?></h2>
			<p><?php esc_html_e( 'Your DU background and city in Sweden help the association plan relevant activities and local gatherings.', 'duaais-members' ); ?></p>
			<a class="text-link" href="<?php echo esc_url( wp_logout_url( add_query_arg( 'duaais_status', 'logged_out', duaais_members_page_url( 'logga-in' ) ) ) ); ?>"><?php esc_html_e( 'Log out', 'duaais-members' ); ?></a>
		</div>

		<form class="member-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
			<div class="account-summary">
				<div class="account-avatar"><?php echo get_avatar( $user->ID, 64 ); ?></div>
				<div>
					<h2><?php echo esc_html( $user->display_name ); ?></h2>
					<p><?php esc_html_e( 'DU Alumni Member', 'duaais-members' ); ?></p>
				</div>
			</div>
			<?php duaais_members_status_notice(); ?>
			<input type="hidden" name="action" value="duaais_update_profile">
			<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( duaais_members_max_certificate_bytes() ); ?>">
			<?php wp_nonce_field( 'duaais_update_profile', 'duaais_nonce' ); ?>

			<fieldset class="form-section">
				<legend><?php esc_html_e( 'Contact details', 'duaais-members' ); ?></legend>
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
					<div class="form-field form-field-wide">
						<label for="account_address"><?php esc_html_e( 'Address', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="account_address" name="address" type="text" value="<?php echo esc_attr( $address ); ?>" autocomplete="street-address" required>
					</div>
					<div class="form-field">
						<label for="account_postal_code"><?php esc_html_e( 'Postal code', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="account_postal_code" name="postal_code" type="text" value="<?php echo esc_attr( $postal_code ); ?>" autocomplete="postal-code" required>
					</div>
					<div class="form-field">
						<label for="account_city"><?php esc_html_e( 'City in Sweden', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="account_city" name="city" type="text" value="<?php echo esc_attr( $city ); ?>" autocomplete="address-level2" required>
					</div>
					<div class="form-field">
						<label for="account_mobile_phone"><?php esc_html_e( 'Mobile phone', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="account_mobile_phone" name="mobile_phone" type="tel" value="<?php echo esc_attr( $mobile_phone ); ?>" autocomplete="tel" required>
					</div>
					<div class="form-field">
						<label for="account_work_phone"><?php esc_html_e( 'Telephone (work)', 'duaais-members' ); ?></label>
						<input id="account_work_phone" name="work_phone" type="tel" value="<?php echo esc_attr( $work_phone ); ?>">
					</div>
				</div>
			</fieldset>

			<fieldset class="form-section">
				<legend><?php esc_html_e( 'University of Dhaka details', 'duaais-members' ); ?></legend>
				<div class="form-grid">
					<div class="form-field">
						<label for="account_subject"><?php esc_html_e( 'Subject', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="account_subject" name="subject" type="text" value="<?php echo esc_attr( $subject ); ?>" autocomplete="off" required>
					</div>
					<div class="form-field">
						<label for="account_hall"><?php esc_html_e( 'Attested hall', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="account_hall" name="hall" type="text" value="<?php echo esc_attr( $hall ); ?>" autocomplete="off" required>
					</div>
					<div class="form-field">
						<label for="account_year"><?php esc_html_e( 'Examination year', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<input id="account_year" name="examination_year" type="number" min="1900" max="<?php echo esc_attr( wp_date( 'Y' ) ); ?>" value="<?php echo esc_attr( $graduation_year ); ?>" inputmode="numeric" required>
					</div>
					<div class="form-field">
						<label for="account_certificate"><?php esc_html_e( 'DU certificate copy', 'duaais-members' ); ?></label>
						<input id="account_certificate" name="certificate" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
						<span class="field-help">
							<?php if ( $certificate_name ) : ?>
								<?php
								printf(
									/* translators: %s: file name of the stored certificate. */
									esc_html__( 'On file: %s. Upload a new file only if you want to replace it.', 'duaais-members' ),
									esc_html( $certificate_name )
								);
								?>
							<?php else : ?>
								<?php
								printf(
									/* translators: %s: maximum upload size, for example 8 MB. */
									esc_html__( 'No certificate on file. PDF, JPG, or PNG, up to %s.', 'duaais-members' ),
									esc_html( size_format( duaais_members_max_certificate_bytes() ) )
								);
								?>
							<?php endif; ?>
						</span>
					</div>
				</div>
			</fieldset>

			<fieldset class="form-section">
				<legend><?php esc_html_e( 'Status in Sweden', 'duaais-members' ); ?></legend>
				<div class="form-grid">
					<div class="form-field">
						<label for="account_residence_status"><?php esc_html_e( 'Residence status in Sweden', 'duaais-members' ); ?> <span aria-hidden="true">*</span></label>
						<select id="account_residence_status" name="residence_status" required>
							<option value=""><?php esc_html_e( 'Select an option', 'duaais-members' ); ?></option>
							<?php foreach ( duaais_members_residence_statuses() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $residence_status, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="form-field">
						<label for="account_reference"><?php esc_html_e( 'Reference in Sweden (if any)', 'duaais-members' ); ?></label>
						<input id="account_reference" name="reference" type="text" value="<?php echo esc_attr( $reference ); ?>">
					</div>
				</div>
			</fieldset>

			<fieldset class="form-section">
				<legend><?php esc_html_e( 'Password', 'duaais-members' ); ?></legend>
				<div class="form-grid">
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
			</fieldset>

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
	$address          = duaais_members_post_text( 'address' );
	$postal_code      = duaais_members_post_text( 'postal_code' );
	$city             = duaais_members_post_text( 'city' );
	$mobile_phone     = duaais_members_post_text( 'mobile_phone' );
	$work_phone       = duaais_members_post_text( 'work_phone' );
	$subject          = duaais_members_post_text( 'subject' );
	$hall             = duaais_members_post_text( 'hall' );
	$examination_year = duaais_members_post_text( 'examination_year' );
	$residence_status = sanitize_key( duaais_members_post_text( 'residence_status' ) );
	$reference        = duaais_members_post_text( 'reference' );
	$new_password     = duaais_members_post_password( 'new_password' );
	$password_confirm = duaais_members_post_password( 'new_password_confirm' );

	$required = array( $first_name, $last_name, $email, $address, $postal_code, $city, $mobile_phone, $subject, $hall, $examination_year );
	foreach ( $required as $value ) {
		if ( '' === $value ) {
			duaais_members_redirect( 'mitt-konto', 'required' );
		}
	}

	if ( ! is_email( $email ) ) {
		duaais_members_redirect( 'mitt-konto', 'email_invalid' );
	}

	$email_owner = email_exists( $email );
	if ( $email_owner && (int) $email_owner !== $user_id ) {
		duaais_members_redirect( 'mitt-konto', 'email_exists' );
	}

	if ( ! duaais_members_valid_year( $examination_year ) ) {
		duaais_members_redirect( 'mitt-konto', 'year_invalid' );
	}

	if ( ! duaais_members_residence_label( $residence_status ) ) {
		duaais_members_redirect( 'mitt-konto', 'residence_invalid' );
	}

	if ( $new_password && strlen( $new_password ) < 10 ) {
		duaais_members_redirect( 'mitt-konto', 'password_short' );
	}

	if ( $new_password && ! hash_equals( $new_password, $password_confirm ) ) {
		duaais_members_redirect( 'mitt-konto', 'password_mismatch' );
	}

	$certificate_error = duaais_members_validate_certificate( 'certificate', false );
	if ( $certificate_error ) {
		duaais_members_redirect( 'mitt-konto', $certificate_error );
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

	$certificate = duaais_members_uploaded_file( 'certificate' );
	if ( array() !== $certificate && UPLOAD_ERR_NO_FILE !== $certificate['error'] && ! duaais_members_store_certificate( $user_id, 'certificate' ) ) {
		duaais_members_redirect( 'mitt-konto', 'certificate_failed' );
	}

	update_user_meta( $user_id, 'duaais_subject', $subject );
	update_user_meta( $user_id, 'duaais_hall', $hall );
	update_user_meta( $user_id, 'duaais_graduation_year', $examination_year );
	update_user_meta( $user_id, 'duaais_address', $address );
	update_user_meta( $user_id, 'duaais_postal_code', $postal_code );
	update_user_meta( $user_id, 'duaais_city', $city );
	update_user_meta( $user_id, 'duaais_mobile_phone', $mobile_phone );
	update_user_meta( $user_id, 'duaais_work_phone', $work_phone );
	update_user_meta( $user_id, 'duaais_residence_status', $residence_status );
	update_user_meta( $user_id, 'duaais_reference', $reference );

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

	if ( ! $user->exists() ) {
		return false;
	}

	$roles = (array) $user->roles;

	return in_array( DUAAIS_MEMBER_ROLE, $roles, true ) || in_array( DUAAIS_PENDING_ROLE, $roles, true );
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

/**
 * Address of the board review screen.
 *
 * @return string
 */
function duaais_members_applications_url() {
	return admin_url( 'users.php?page=duaais-members-applications' );
}

/**
 * Fetch the applications waiting for a decision.
 *
 * @return WP_User[]
 */
function duaais_members_pending_applications() {
	$query = new WP_User_Query(
		array(
			'role'    => DUAAIS_PENDING_ROLE,
			'orderby' => 'registered',
			'order'   => 'ASC',
			'number'  => 200,
		)
	);

	return $query->get_results();
}

/**
 * Add the board review screen under Users with a pending-count badge.
 */
function duaais_members_admin_menu() {
	$pending = count( duaais_members_pending_applications() );
	$label   = __( 'Membership applications', 'duaais-members' );

	if ( $pending > 0 ) {
		$label .= sprintf( ' <span class="update-plugins count-%1$d"><span class="update-count">%1$d</span></span>', $pending );
	}

	add_users_page(
		__( 'Membership applications', 'duaais-members' ),
		$label,
		'edit_users',
		'duaais-members-applications',
		'duaais_members_render_applications_page'
	);
}
add_action( 'admin_menu', 'duaais_members_admin_menu' );

/**
 * Render the list of applications waiting for approval.
 */
function duaais_members_render_applications_page() {
	if ( ! current_user_can( 'edit_users' ) ) {
		wp_die( esc_html__( 'You are not allowed to review membership applications.', 'duaais-members' ) );
	}

	$notice = isset( $_GET['duaais_notice'] ) ? sanitize_key( wp_unslash( $_GET['duaais_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notices = array(
		'approved' => array( 'success', __( 'The membership was approved and the member has been notified.', 'duaais-members' ) ),
		'rejected' => array( 'success', __( 'The application was rejected and the applicant has been notified.', 'duaais-members' ) ),
		'failed'   => array( 'error', __( 'The application could not be updated.', 'duaais-members' ) ),
	);

	$applications = duaais_members_pending_applications();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Membership applications', 'duaais-members' ); ?></h1>
		<p><?php esc_html_e( 'Approve an application to activate the alumni account. Applicants cannot log in until they are approved.', 'duaais-members' ); ?></p>

		<?php if ( isset( $notices[ $notice ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?>"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
		<?php endif; ?>

		<?php if ( empty( $applications ) ) : ?>
			<p><?php esc_html_e( 'There are no applications waiting for approval.', 'duaais-members' ); ?></p>
		<?php else : ?>
			<?php foreach ( $applications as $applicant ) : ?>
				<div class="card" style="max-width:100%;margin-bottom:1.5rem;">
					<h2><?php echo esc_html( $applicant->display_name ); ?></h2>
					<table class="widefat striped">
						<tbody>
						<?php foreach ( duaais_members_application_summary( $applicant->ID ) as $label => $value ) : ?>
							<tr>
								<th scope="row" style="width:16rem;"><?php echo esc_html( $label ); ?></th>
								<td><?php echo '' !== $value ? esc_html( $value ) : '<em>' . esc_html__( 'Not provided', 'duaais-members' ) . '</em>'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<p>
						<?php if ( duaais_members_certificate_path( $applicant->ID ) ) : ?>
							<a class="button" href="<?php echo esc_url( duaais_members_certificate_url( $applicant->ID ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'View DU certificate', 'duaais-members' ); ?>
							</a>
						<?php else : ?>
							<em><?php esc_html_e( 'No certificate file is stored for this application.', 'duaais-members' ); ?></em>
						<?php endif; ?>
					</p>

					<div class="duaais-application-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:0.5rem;">
							<input type="hidden" name="action" value="duaais_approve_member">
							<input type="hidden" name="user_id" value="<?php echo esc_attr( $applicant->ID ); ?>">
							<?php wp_nonce_field( 'duaais_decide_member_' . $applicant->ID, 'duaais_nonce' ); ?>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Approve membership', 'duaais-members' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
							<input type="hidden" name="action" value="duaais_reject_member">
							<input type="hidden" name="user_id" value="<?php echo esc_attr( $applicant->ID ); ?>">
							<?php wp_nonce_field( 'duaais_decide_member_' . $applicant->ID, 'duaais_nonce' ); ?>
							<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Reject application', 'duaais-members' ); ?></button>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Validate a board decision request and return the applicant.
 *
 * @return int Applicant user ID.
 */
function duaais_members_verify_decision_request() {
	if ( ! current_user_can( 'edit_users' ) ) {
		wp_die( esc_html__( 'You are not allowed to review membership applications.', 'duaais-members' ), '', array( 'response' => 403 ) );
	}

	$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
	check_admin_referer( 'duaais_decide_member_' . $user_id, 'duaais_nonce' );

	return $user_id;
}

/**
 * Send the board decision back to the review screen.
 *
 * @param string $notice Notice key.
 */
function duaais_members_redirect_to_applications( $notice ) {
	wp_safe_redirect( add_query_arg( 'duaais_notice', sanitize_key( $notice ), duaais_members_applications_url() ) );
	exit;
}

/**
 * Approve a membership application and activate the account.
 */
function duaais_members_handle_approval() {
	$user_id = duaais_members_verify_decision_request();
	$user    = get_user_by( 'id', $user_id );

	if ( ! $user instanceof WP_User ) {
		duaais_members_redirect_to_applications( 'failed' );
	}

	$user->set_role( DUAAIS_MEMBER_ROLE );
	update_user_meta( $user_id, 'duaais_membership_status', DUAAIS_STATUS_APPROVED );
	update_user_meta( $user_id, 'duaais_reviewed_at', current_time( 'mysql', true ) );
	update_user_meta( $user_id, 'duaais_reviewed_by', get_current_user_id() );

	duaais_members_notify_decision( $user_id, DUAAIS_STATUS_APPROVED );

	/**
	 * Fires after a membership application has been approved.
	 *
	 * @param int $user_id Approved member ID.
	 */
	do_action( 'duaais_members_application_approved', $user_id );

	duaais_members_redirect_to_applications( 'approved' );
}
add_action( 'admin_post_duaais_approve_member', 'duaais_members_handle_approval' );

/**
 * Reject a membership application and keep the account locked.
 */
function duaais_members_handle_rejection() {
	$user_id = duaais_members_verify_decision_request();
	$user    = get_user_by( 'id', $user_id );

	if ( ! $user instanceof WP_User ) {
		duaais_members_redirect_to_applications( 'failed' );
	}

	$user->set_role( '' );
	update_user_meta( $user_id, 'duaais_membership_status', DUAAIS_STATUS_REJECTED );
	update_user_meta( $user_id, 'duaais_reviewed_at', current_time( 'mysql', true ) );
	update_user_meta( $user_id, 'duaais_reviewed_by', get_current_user_id() );

	duaais_members_notify_decision( $user_id, DUAAIS_STATUS_REJECTED );

	/**
	 * Fires after a membership application has been rejected.
	 *
	 * @param int $user_id Rejected applicant ID.
	 */
	do_action( 'duaais_members_application_rejected', $user_id );

	duaais_members_redirect_to_applications( 'rejected' );
}
add_action( 'admin_post_duaais_reject_member', 'duaais_members_handle_rejection' );

/**
 * Tell the applicant about the board decision.
 *
 * @param int    $user_id Applicant user ID.
 * @param string $status  Decision status.
 * @return bool
 */
function duaais_members_notify_decision( $user_id, $status ) {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user instanceof WP_User ) {
		return false;
	}

	$site  = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	$greet = sprintf(
		/* translators: %s: member first name. */
		__( 'Hello %s,', 'duaais-members' ),
		$user->first_name ? $user->first_name : $user->display_name
	);

	if ( DUAAIS_STATUS_APPROVED === $status ) {
		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Your membership has been approved', 'duaais-members' ),
			$site
		);
		$body    = array(
			$greet,
			'',
			__( 'Your DUAAIS Sweden membership application has been approved. You can now log in with the email address and password you chose when you applied.', 'duaais-members' ),
			duaais_members_page_url( 'logga-in' ),
		);
	} else {
		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] About your membership application', 'duaais-members' ),
			$site
		);
		$body    = array(
			$greet,
			'',
			__( 'Thank you for your interest in DUAAIS Sweden. The board was unable to approve your membership application at this time.', 'duaais-members' ),
			__( 'If you believe this was a mistake, reply to this email or contact the association and we will look at your application again.', 'duaais-members' ),
		);
	}

	$body[] = '';
	$body[] = $site;
	$body[] = home_url( '/' );

	return duaais_members_send_mail( $user->user_email, $subject, implode( "\n", $body ) );
}

/**
 * Add a membership status column to the users list.
 *
 * @param array<string, string> $columns Existing columns.
 * @return array<string, string>
 */
function duaais_members_users_columns( $columns ) {
	$columns['duaais_membership'] = __( 'Membership', 'duaais-members' );

	return $columns;
}
add_filter( 'manage_users_columns', 'duaais_members_users_columns' );

/**
 * Print the membership status for a user row.
 *
 * @param string $output      Column output.
 * @param string $column_name Column key.
 * @param int    $user_id     User ID.
 * @return string
 */
function duaais_members_users_column_content( $output, $column_name, $user_id ) {
	if ( 'duaais_membership' !== $column_name ) {
		return $output;
	}

	$labels = array(
		DUAAIS_STATUS_PENDING  => __( 'Pending approval', 'duaais-members' ),
		DUAAIS_STATUS_APPROVED => __( 'Approved', 'duaais-members' ),
		DUAAIS_STATUS_REJECTED => __( 'Rejected', 'duaais-members' ),
	);

	$status = duaais_members_status( $user_id );
	if ( ! isset( $labels[ $status ] ) ) {
		return '&mdash;';
	}

	if ( DUAAIS_STATUS_PENDING === $status ) {
		return sprintf(
			'%1$s &middot; <a href="%2$s">%3$s</a>',
			esc_html( $labels[ $status ] ),
			esc_url( duaais_members_applications_url() ),
			esc_html__( 'Review', 'duaais-members' )
		);
	}

	return esc_html( $labels[ $status ] );
}
add_filter( 'manage_users_custom_column', 'duaais_members_users_column_content', 10, 3 );

/**
 * Point the board at waiting applications from anywhere in wp-admin.
 */
function duaais_members_pending_admin_notice() {
	$screen = get_current_screen();

	if ( ! current_user_can( 'edit_users' ) || ! $screen instanceof WP_Screen ) {
		return;
	}

	if ( in_array( $screen->id, array( 'users_page_duaais-members-applications' ), true ) ) {
		return;
	}

	$pending = count( duaais_members_pending_applications() );
	if ( $pending < 1 ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
		esc_html(
			sprintf(
				/* translators: %d: number of applications. */
				_n(
					'%d DUAAIS membership application is waiting for approval.',
					'%d DUAAIS membership applications are waiting for approval.',
					$pending,
					'duaais-members'
				),
				$pending
			)
		),
		esc_url( duaais_members_applications_url() ),
		esc_html__( 'Review applications', 'duaais-members' )
	);
}
add_action( 'admin_notices', 'duaais_members_pending_admin_notice' );
