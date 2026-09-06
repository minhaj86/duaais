<?php
/**
 * Plugin Name: DUAAIS SMTP Mailer
 * Description: Sends WordPress email through authenticated SMTP, with presets for one.com and Gmail.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Dhaka University Alumni Association In Sweden
 * Text Domain: duaais-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DUAAIS_SMTP_VERSION               = '1.0.0';
const DUAAIS_SMTP_OPTION                = 'duaais_smtp_settings';
const DUAAIS_SMTP_PAGE_SLUG             = 'duaais-smtp';
const DUAAIS_SMTP_SECRET_PREFIX         = 'v1:';
const DUAAIS_SMTP_TEST_NOTICE_PREFIX    = 'duaais_smtp_test_notice_';
const DUAAIS_SMTP_CONNECTION_TIMEOUT    = 20;
const DUAAIS_SMTP_CONNECTION_TIME_LIMIT = 30;

/**
 * Return the supported mailer presets.
 *
 * @return array<string, array<string, mixed>>
 */
function duaais_smtp_mailer_presets() {
	return array(
		'onecom' => array(
			'label'          => __( 'one.com', 'duaais-smtp' ),
			'host'           => 'send.one.com',
			'port'           => 465,
			'encryption'     => 'ssl',
			'auto_tls'       => true,
			'authentication' => true,
		),
		'gmail'  => array(
			'label'          => __( 'Google / Gmail (app password)', 'duaais-smtp' ),
			'host'           => 'smtp.gmail.com',
			'port'           => 587,
			'encryption'     => 'tls',
			'auto_tls'       => true,
			'authentication' => true,
		),
	);
}

/**
 * Return the initial plugin settings.
 *
 * @return array<string, mixed>
 */
function duaais_smtp_default_settings() {
	return array(
		'enabled'          => false,
		'mailer'           => 'onecom',
		'from_email'       => sanitize_email( (string) get_option( 'admin_email', '' ) ),
		'from_name'        => sanitize_text_field( (string) get_bloginfo( 'name' ) ),
		'force_from_email' => true,
		'force_from_name'  => true,
		'return_path'      => true,
		'host'             => 'send.one.com',
		'port'             => 465,
		'encryption'       => 'ssl',
		'auto_tls'         => true,
		'authentication'   => true,
		'username'         => '',
		'password'         => '',
	);
}

/**
 * Return normalized saved settings.
 *
 * Preset transport values are applied when settings are read as well as when they are saved, so a
 * direct database edit cannot silently change the Gmail or one.com endpoint.
 *
 * @return array<string, mixed>
 */
function duaais_smtp_get_settings() {
	$stored   = get_option( DUAAIS_SMTP_OPTION, array() );
	$settings = wp_parse_args( is_array( $stored ) ? $stored : array(), duaais_smtp_default_settings() );
	$presets  = duaais_smtp_mailer_presets();

	$settings['mailer'] = sanitize_key( (string) $settings['mailer'] );
	if ( ! in_array( $settings['mailer'], array( 'smtp', 'onecom', 'gmail' ), true ) ) {
		$settings['mailer'] = 'smtp';
	}

	if ( isset( $presets[ $settings['mailer'] ] ) ) {
		$preset                     = $presets[ $settings['mailer'] ];
		$settings['host']           = $preset['host'];
		$settings['port']           = $preset['port'];
		$settings['encryption']     = $preset['encryption'];
		$settings['auto_tls']       = $preset['auto_tls'];
		$settings['authentication'] = $preset['authentication'];
	}

	$settings['enabled']          = ! empty( $settings['enabled'] );
	$settings['force_from_email'] = ! empty( $settings['force_from_email'] );
	$settings['force_from_name']  = ! empty( $settings['force_from_name'] );
	$settings['return_path']      = ! empty( $settings['return_path'] );
	$settings['auto_tls']         = ! empty( $settings['auto_tls'] );
	$settings['authentication']   = ! empty( $settings['authentication'] );
	$settings['port']             = absint( $settings['port'] );

	return $settings;
}

/**
 * Return whether wp-config.php supplies the SMTP password.
 *
 * @return bool
 */
function duaais_smtp_has_password_constant() {
	return defined( 'DUAAIS_SMTP_PASSWORD' )
		&& is_scalar( DUAAIS_SMTP_PASSWORD )
		&& '' !== (string) DUAAIS_SMTP_PASSWORD;
}

/**
 * Derive a binary encryption key from the WordPress authentication salts.
 *
 * @return string
 */
function duaais_smtp_secret_key() {
	return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
}

/**
 * Remove unsafe control characters without changing valid password punctuation.
 *
 * @param mixed $value Raw password value.
 * @return string
 */
function duaais_smtp_sanitize_secret( $value ) {
	$secret    = wp_check_invalid_utf8( (string) $value );
	$sanitized = preg_replace( '/[\x00-\x1F\x7F]/', '', $secret );

	return is_string( $sanitized ) ? $sanitized : '';
}

/**
 * Encrypt an SMTP password for database storage.
 *
 * @param string $secret Plain-text SMTP password.
 * @return string|WP_Error
 */
function duaais_smtp_encrypt_secret( $secret ) {
	if ( '' === $secret ) {
		return '';
	}

	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return new WP_Error(
			'duaais_smtp_openssl_missing',
			__( 'The OpenSSL PHP extension is required to store an SMTP password securely.', 'duaais-smtp' )
		);
	}

	try {
		$iv = random_bytes( 12 );
	} catch ( Exception $error ) {
		return new WP_Error(
			'duaais_smtp_random_failed',
			__( 'WordPress could not generate secure random data for the SMTP password.', 'duaais-smtp' )
		);
	}

	$tag        = '';
	$ciphertext = openssl_encrypt(
		$secret,
		'aes-256-gcm',
		duaais_smtp_secret_key(),
		OPENSSL_RAW_DATA,
		$iv,
		$tag
	);

	if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
		return new WP_Error(
			'duaais_smtp_encrypt_failed',
			__( 'WordPress could not encrypt the SMTP password.', 'duaais-smtp' )
		);
	}

	return DUAAIS_SMTP_SECRET_PREFIX . base64_encode( $iv . $tag . $ciphertext );
}

/**
 * Decrypt an SMTP password from the database.
 *
 * @param string $encrypted Encrypted password payload.
 * @return string|WP_Error
 */
function duaais_smtp_decrypt_secret( $encrypted ) {
	if ( '' === $encrypted ) {
		return '';
	}

	if ( ! function_exists( 'openssl_decrypt' ) || ! str_starts_with( $encrypted, DUAAIS_SMTP_SECRET_PREFIX ) ) {
		return new WP_Error(
			'duaais_smtp_secret_invalid',
			__( 'The saved SMTP password cannot be decrypted. Enter and save it again.', 'duaais-smtp' )
		);
	}

	$payload = base64_decode( substr( $encrypted, strlen( DUAAIS_SMTP_SECRET_PREFIX ) ), true );
	if ( false === $payload || 28 >= strlen( $payload ) ) {
		return new WP_Error(
			'duaais_smtp_secret_invalid',
			__( 'The saved SMTP password cannot be decrypted. Enter and save it again.', 'duaais-smtp' )
		);
	}

	$iv         = substr( $payload, 0, 12 );
	$tag        = substr( $payload, 12, 16 );
	$ciphertext = substr( $payload, 28 );
	$secret     = openssl_decrypt(
		$ciphertext,
		'aes-256-gcm',
		duaais_smtp_secret_key(),
		OPENSSL_RAW_DATA,
		$iv,
		$tag
	);

	if ( false === $secret ) {
		return new WP_Error(
			'duaais_smtp_secret_invalid',
			__( 'The saved SMTP password cannot be decrypted. Enter and save it again.', 'duaais-smtp' )
		);
	}

	return $secret;
}

/**
 * Return the configured SMTP password without exposing it to the settings page.
 *
 * @param array<string, mixed>|null $settings Optional settings override.
 * @return string|WP_Error
 */
function duaais_smtp_get_password( $settings = null ) {
	if ( duaais_smtp_has_password_constant() ) {
		return duaais_smtp_sanitize_secret( DUAAIS_SMTP_PASSWORD );
	}

	if ( null === $settings ) {
		$settings = duaais_smtp_get_settings();
	}

	return duaais_smtp_decrypt_secret( (string) $settings['password'] );
}

/**
 * Validate an SMTP hostname or IP address.
 *
 * @param string $host SMTP host.
 * @return bool
 */
function duaais_smtp_is_valid_host( $host ) {
	if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return true;
	}

	return 1 === preg_match(
		'/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i',
		$host
	);
}

/**
 * Return problems that prevent the mailer from connecting.
 *
 * @param array<string, mixed> $settings SMTP settings.
 * @return array<int, string>
 */
function duaais_smtp_configuration_errors( $settings ) {
	$errors = array();

	if ( ! is_email( (string) $settings['from_email'] ) ) {
		$errors[] = __( 'Enter a valid From Email address.', 'duaais-smtp' );
	}

	if ( '' === trim( (string) $settings['from_name'] ) ) {
		$errors[] = __( 'Enter a From Name.', 'duaais-smtp' );
	}

	if ( ! duaais_smtp_is_valid_host( (string) $settings['host'] ) ) {
		$errors[] = __( 'Enter a valid SMTP host without a URL scheme or port.', 'duaais-smtp' );
	}

	if ( 1 > (int) $settings['port'] || 65535 < (int) $settings['port'] ) {
		$errors[] = __( 'Enter an SMTP port between 1 and 65535.', 'duaais-smtp' );
	}

	if ( ! in_array( $settings['encryption'], array( 'none', 'tls', 'ssl' ), true ) ) {
		$errors[] = __( 'Choose a supported SMTP encryption mode.', 'duaais-smtp' );
	}

	if ( ! empty( $settings['authentication'] ) ) {
		if ( '' === trim( (string) $settings['username'] ) ) {
			$errors[] = __( 'Enter the SMTP username.', 'duaais-smtp' );
		}

		$password = duaais_smtp_get_password( $settings );
		if ( is_wp_error( $password ) ) {
			$errors[] = $password->get_error_message();
		} elseif ( '' === $password ) {
			$errors[] = __( 'Enter the SMTP password.', 'duaais-smtp' );
		}
	}

	if ( 'gmail' === $settings['mailer'] && ! is_email( (string) $settings['username'] ) ) {
		$errors[] = __( 'Enter the full Gmail or Google Workspace email address as the username.', 'duaais-smtp' );
	}

	return array_values( array_unique( $errors ) );
}

/**
 * Sanitize and validate settings submitted through options.php.
 *
 * @param mixed $input Raw settings.
 * @return array<string, mixed>
 */
function duaais_smtp_sanitize_settings( $input ) {
	$input    = is_array( $input ) ? $input : array();
	$previous = duaais_smtp_get_settings();
	$settings = duaais_smtp_default_settings();
	$presets  = duaais_smtp_mailer_presets();

	$settings['enabled']          = ! empty( $input['enabled'] );
	$settings['force_from_email'] = ! empty( $input['force_from_email'] );
	$settings['force_from_name']  = ! empty( $input['force_from_name'] );
	$settings['return_path']      = ! empty( $input['return_path'] );
	$settings['auto_tls']         = ! empty( $input['auto_tls'] );
	$settings['authentication']   = ! empty( $input['authentication'] );
	$settings['mailer']           = isset( $input['mailer'] ) ? sanitize_key( (string) $input['mailer'] ) : 'smtp';
	$settings['from_email']       = isset( $input['from_email'] ) ? sanitize_email( (string) $input['from_email'] ) : '';
	$settings['from_name']        = isset( $input['from_name'] ) ? sanitize_text_field( (string) $input['from_name'] ) : '';
	$settings['host']             = isset( $input['host'] ) ? strtolower( sanitize_text_field( (string) $input['host'] ) ) : '';
	$settings['port']             = isset( $input['port'] ) ? absint( $input['port'] ) : 0;
	$settings['encryption']       = isset( $input['encryption'] ) ? sanitize_key( (string) $input['encryption'] ) : 'none';
	$settings['username']         = isset( $input['username'] ) ? sanitize_text_field( (string) $input['username'] ) : '';
	$settings['password']         = (string) $previous['password'];

	if ( ! in_array( $settings['mailer'], array( 'smtp', 'onecom', 'gmail' ), true ) ) {
		$settings['mailer'] = 'smtp';
	}

	if ( isset( $presets[ $settings['mailer'] ] ) ) {
		$preset                     = $presets[ $settings['mailer'] ];
		$settings['host']           = $preset['host'];
		$settings['port']           = $preset['port'];
		$settings['encryption']     = $preset['encryption'];
		$settings['auto_tls']       = $preset['auto_tls'];
		$settings['authentication'] = $preset['authentication'];
	}

	if ( ! in_array( $settings['encryption'], array( 'none', 'tls', 'ssl' ), true ) ) {
		$settings['encryption'] = 'none';
	}

	if ( ! duaais_smtp_has_password_constant() && ! empty( $input['clear_password'] ) ) {
		$settings['password'] = '';
	}

	if ( ! duaais_smtp_has_password_constant() && isset( $input['password'] ) && '' !== (string) $input['password'] ) {
		$password = duaais_smtp_sanitize_secret( $input['password'] );
		if ( 'gmail' === $settings['mailer'] ) {
			$password = preg_replace( '/\s+/', '', $password );
			$password = is_string( $password ) ? $password : '';
		}

		$encrypted = duaais_smtp_encrypt_secret( $password );
		if ( is_wp_error( $encrypted ) ) {
			add_settings_error(
				DUAAIS_SMTP_OPTION,
				'duaais_smtp_password',
				$encrypted->get_error_message(),
				'error'
			);
		} else {
			$settings['password'] = $encrypted;
		}
	}

	if ( $settings['enabled'] ) {
		$errors = duaais_smtp_configuration_errors( $settings );
		if ( ! empty( $errors ) ) {
			$settings['enabled'] = false;

			foreach ( $errors as $index => $message ) {
				add_settings_error(
					DUAAIS_SMTP_OPTION,
					'duaais_smtp_invalid_' . $index,
					sprintf(
						/* translators: %s: configuration error. */
						__( 'SMTP was not enabled: %s', 'duaais-smtp' ),
						$message
					),
					'error'
				);
			}
		}
	}

	return $settings;
}

/**
 * Add the settings option when the plugin is activated.
 *
 * @return void
 */
function duaais_smtp_activate() {
	add_option( DUAAIS_SMTP_OPTION, duaais_smtp_default_settings(), '', false );
}
register_activation_hook( __FILE__, 'duaais_smtp_activate' );

/**
 * Remove saved credentials and settings when the plugin is uninstalled.
 *
 * @return void
 */
function duaais_smtp_uninstall() {
	delete_option( DUAAIS_SMTP_OPTION );
}
register_uninstall_hook( __FILE__, 'duaais_smtp_uninstall' );

/**
 * Register the plugin settings.
 *
 * @return void
 */
function duaais_smtp_register_settings() {
	register_setting(
		'duaais_smtp',
		DUAAIS_SMTP_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'duaais_smtp_sanitize_settings',
			'default'           => duaais_smtp_default_settings(),
		)
	);
}
add_action( 'admin_init', 'duaais_smtp_register_settings' );

/**
 * Return the SMTP settings page URL.
 *
 * @return string
 */
function duaais_smtp_page_url() {
	return admin_url( 'options-general.php?page=' . DUAAIS_SMTP_PAGE_SLUG );
}

/**
 * Add the SMTP settings screen.
 *
 * @return void
 */
function duaais_smtp_admin_menu() {
	add_options_page(
		__( 'DUAAIS SMTP', 'duaais-smtp' ),
		__( 'DUAAIS SMTP', 'duaais-smtp' ),
		'manage_options',
		DUAAIS_SMTP_PAGE_SLUG,
		'duaais_smtp_render_settings_page'
	);
}
add_action( 'admin_menu', 'duaais_smtp_admin_menu' );

/**
 * Add a Settings shortcut to the Plugins screen.
 *
 * @param array<int, string> $links Existing plugin action links.
 * @return array<int, string>
 */
function duaais_smtp_plugin_action_links( $links ) {
	array_unshift(
		$links,
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( duaais_smtp_page_url() ),
			esc_html__( 'Settings', 'duaais-smtp' )
		)
	);

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'duaais_smtp_plugin_action_links' );

/**
 * Load the settings screen assets.
 *
 * @param string $hook_suffix Current admin page hook.
 * @return void
 */
function duaais_smtp_admin_assets( $hook_suffix ) {
	if ( 'settings_page_' . DUAAIS_SMTP_PAGE_SLUG !== $hook_suffix ) {
		return;
	}

	$css = plugin_dir_path( __FILE__ ) . 'assets/admin.css';
	$js  = plugin_dir_path( __FILE__ ) . 'assets/admin.js';

	wp_enqueue_style(
		'duaais-smtp-admin',
		plugin_dir_url( __FILE__ ) . 'assets/admin.css',
		array(),
		is_readable( $css ) ? (string) filemtime( $css ) : DUAAIS_SMTP_VERSION
	);
	wp_enqueue_script(
		'duaais-smtp-admin',
		plugin_dir_url( __FILE__ ) . 'assets/admin.js',
		array(),
		is_readable( $js ) ? (string) filemtime( $js ) : DUAAIS_SMTP_VERSION,
		true
	);

	wp_localize_script(
		'duaais-smtp-admin',
		'duaaisSmtpAdmin',
		array(
			'presets' => duaais_smtp_mailer_presets(),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'duaais_smtp_admin_assets' );

/**
 * Return the current administrator's one-time test result.
 *
 * @return array<string, string>
 */
function duaais_smtp_get_test_notice() {
	$key    = DUAAIS_SMTP_TEST_NOTICE_PREFIX . get_current_user_id();
	$notice = get_transient( $key );

	delete_transient( $key );

	if ( ! is_array( $notice ) || empty( $notice['type'] ) || empty( $notice['message'] ) ) {
		return array();
	}

	return array(
		'type'    => sanitize_key( (string) $notice['type'] ),
		'message' => sanitize_text_field( (string) $notice['message'] ),
	);
}

/**
 * Store a one-time test result for the current administrator.
 *
 * @param string $type    Notice type.
 * @param string $message Notice text.
 * @return void
 */
function duaais_smtp_set_test_notice( $type, $message ) {
	set_transient(
		DUAAIS_SMTP_TEST_NOTICE_PREFIX . get_current_user_id(),
		array(
			'type'    => sanitize_key( $type ),
			'message' => sanitize_text_field( $message ),
		),
		MINUTE_IN_SECONDS
	);
}

/**
 * Render the SMTP settings and test-email screen.
 *
 * @return void
 */
function duaais_smtp_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to configure SMTP.', 'duaais-smtp' ) );
	}

	$settings          = duaais_smtp_get_settings();
	$presets           = duaais_smtp_mailer_presets();
	$errors            = duaais_smtp_configuration_errors( $settings );
	$test_notice       = duaais_smtp_get_test_notice();
	$password_constant = duaais_smtp_has_password_constant();
	$password_saved    = '' !== (string) $settings['password'];
	$mailer_labels     = array(
		'smtp'  => __( 'Other SMTP', 'duaais-smtp' ),
		'onecom' => $presets['onecom']['label'],
		'gmail' => $presets['gmail']['label'],
	);
	?>
	<div class="wrap duaais-smtp">
		<h1><?php esc_html_e( 'DUAAIS SMTP Mailer', 'duaais-smtp' ); ?></h1>
		<p><?php esc_html_e( 'Route WordPress email through a reliable SMTP server. Message contents and recipient addresses are never logged by this plugin.', 'duaais-smtp' ); ?></p>

		<?php settings_errors(); ?>

		<?php if ( ! empty( $test_notice ) ) : ?>
			<div class="notice notice-<?php echo 'success' === $test_notice['type'] ? 'success' : 'error'; ?> is-dismissible">
				<p><?php echo esc_html( $test_notice['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<div class="duaais-smtp-status <?php echo $settings['enabled'] && empty( $errors ) ? 'is-ready' : 'is-disabled'; ?>">
			<div>
				<strong><?php esc_html_e( 'Mailer status', 'duaais-smtp' ); ?></strong>
				<span>
					<?php
					echo $settings['enabled'] && empty( $errors )
						? esc_html__( 'Enabled and configured', 'duaais-smtp' )
						: esc_html__( 'Disabled', 'duaais-smtp' );
					?>
				</span>
			</div>
			<div>
				<strong><?php esc_html_e( 'Mailer', 'duaais-smtp' ); ?></strong>
				<span><?php echo esc_html( $mailer_labels[ $settings['mailer'] ] ); ?></span>
			</div>
			<div>
				<strong><?php esc_html_e( 'Endpoint', 'duaais-smtp' ); ?></strong>
				<span><code><?php echo esc_html( $settings['host'] . ':' . $settings['port'] ); ?></code></span>
			</div>
			<div>
				<strong><?php esc_html_e( 'Credential source', 'duaais-smtp' ); ?></strong>
				<span>
					<?php
					if ( $password_constant ) {
						esc_html_e( 'DUAAIS_SMTP_PASSWORD in wp-config.php', 'duaais-smtp' );
					} elseif ( $password_saved ) {
						esc_html_e( 'Encrypted WordPress option', 'duaais-smtp' );
					} else {
						esc_html_e( 'No saved password', 'duaais-smtp' );
					}
					?>
				</span>
			</div>
		</div>

		<?php if ( ! empty( $errors ) ) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'Complete these settings before enabling SMTP:', 'duaais-smtp' ); ?></strong></p>
				<ul class="ul-disc">
					<?php foreach ( $errors as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'duaais_smtp' ); ?>

			<h2><?php esc_html_e( 'Connection', 'duaais-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable SMTP', 'duaais-smtp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>>
								<?php esc_html_e( 'Send all WordPress email through this mailer', 'duaais-smtp' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="duaais-smtp-mailer"><?php esc_html_e( 'Mailer', 'duaais-smtp' ); ?></label></th>
						<td>
							<select id="duaais-smtp-mailer" name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[mailer]">
								<?php foreach ( $mailer_labels as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['mailer'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" data-duaais-mailer-help="onecom">
								<?php esc_html_e( 'Uses send.one.com on port 465 with SSL/TLS. Enter the full one.com mailbox address and its password below.', 'duaais-smtp' ); ?>
							</p>
							<p class="description" data-duaais-mailer-help="gmail">
								<?php esc_html_e( 'Uses smtp.gmail.com on port 587 with STARTTLS. Enable Google 2-Step Verification and create an app password; a normal Google password will not work.', 'duaais-smtp' ); ?>
								<a href="<?php echo esc_url( 'https://support.google.com/accounts/answer/185833' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google app-password help', 'duaais-smtp' ); ?></a>
							</p>
							<p class="description" data-duaais-mailer-help="smtp">
								<?php esc_html_e( 'Use any standards-compliant SMTP server, including a local mail catcher.', 'duaais-smtp' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="duaais-smtp-host"><?php esc_html_e( 'SMTP Host', 'duaais-smtp' ); ?></label></th>
						<td><input class="regular-text code" id="duaais-smtp-host" name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[host]" type="text" value="<?php echo esc_attr( $settings['host'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="duaais-smtp-port"><?php esc_html_e( 'SMTP Port', 'duaais-smtp' ); ?></label></th>
						<td><input class="small-text" id="duaais-smtp-port" name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[port]" type="number" min="1" max="65535" value="<?php echo esc_attr( (string) $settings['port'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="duaais-smtp-encryption"><?php esc_html_e( 'Encryption', 'duaais-smtp' ); ?></label></th>
						<td>
							<select id="duaais-smtp-encryption" name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[encryption]">
								<option value="none" <?php selected( $settings['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'duaais-smtp' ); ?></option>
								<option value="tls" <?php selected( $settings['encryption'], 'tls' ); ?>><?php esc_html_e( 'TLS / STARTTLS', 'duaais-smtp' ); ?></option>
								<option value="ssl" <?php selected( $settings['encryption'], 'ssl' ); ?>><?php esc_html_e( 'SSL/TLS', 'duaais-smtp' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto TLS', 'duaais-smtp' ); ?></th>
						<td>
							<label>
								<input id="duaais-smtp-auto-tls" name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[auto_tls]" type="checkbox" value="1" <?php checked( $settings['auto_tls'] ); ?>>
								<?php esc_html_e( 'Automatically use TLS when the server offers it', 'duaais-smtp' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Authentication', 'duaais-smtp' ); ?></th>
						<td>
							<label>
								<input id="duaais-smtp-authentication" name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[authentication]" type="checkbox" value="1" <?php checked( $settings['authentication'] ); ?>>
								<?php esc_html_e( 'Authenticate with the SMTP server', 'duaais-smtp' ); ?>
							</label>
						</td>
					</tr>
					<tr data-duaais-auth-row>
						<th scope="row"><label for="duaais-smtp-username"><?php esc_html_e( 'SMTP Username', 'duaais-smtp' ); ?></label></th>
						<td>
							<input class="regular-text" id="duaais-smtp-username" name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[username]" type="text" autocomplete="username" value="<?php echo esc_attr( $settings['username'] ); ?>">
							<p class="description"><?php esc_html_e( 'For one.com, Gmail, and Google Workspace, use the full email address.', 'duaais-smtp' ); ?></p>
						</td>
					</tr>
					<tr data-duaais-auth-row>
						<th scope="row"><label for="duaais-smtp-password"><?php esc_html_e( 'SMTP Password', 'duaais-smtp' ); ?></label></th>
						<td>
							<input class="regular-text" id="duaais-smtp-password" name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[password]" type="password" autocomplete="new-password" value="" <?php disabled( $password_constant ); ?>>
							<?php if ( $password_constant ) : ?>
								<p class="description"><?php esc_html_e( 'The DUAAIS_SMTP_PASSWORD constant is set in wp-config.php and overrides any saved password.', 'duaais-smtp' ); ?></p>
							<?php elseif ( $password_saved ) : ?>
								<p class="description"><?php esc_html_e( 'A password is saved in encrypted form. Leave this field empty to keep it.', 'duaais-smtp' ); ?></p>
								<label>
									<input name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[clear_password]" type="checkbox" value="1">
									<?php esc_html_e( 'Remove the saved password', 'duaais-smtp' ); ?>
								</label>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Gmail requires a 16-character app password, not the account password.', 'duaais-smtp' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Sender', 'duaais-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="duaais-smtp-from-email"><?php esc_html_e( 'From Email', 'duaais-smtp' ); ?></label></th>
						<td>
							<input class="regular-text" id="duaais-smtp-from-email" name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[from_email]" type="email" value="<?php echo esc_attr( $settings['from_email'] ); ?>" required>
							<label class="duaais-smtp-block-label">
								<input name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[force_from_email]" type="checkbox" value="1" <?php checked( $settings['force_from_email'] ); ?>>
								<?php esc_html_e( 'Force this address for all WordPress email', 'duaais-smtp' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="duaais-smtp-from-name"><?php esc_html_e( 'From Name', 'duaais-smtp' ); ?></label></th>
						<td>
							<input class="regular-text" id="duaais-smtp-from-name" name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[from_name]" type="text" value="<?php echo esc_attr( $settings['from_name'] ); ?>" required>
							<label class="duaais-smtp-block-label">
								<input name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[force_from_name]" type="checkbox" value="1" <?php checked( $settings['force_from_name'] ); ?>>
								<?php esc_html_e( 'Force this name for all WordPress email', 'duaais-smtp' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Return Path', 'duaais-smtp' ); ?></th>
						<td>
							<label>
								<input name="<?php echo esc_attr( DUAAIS_SMTP_OPTION ); ?>[return_path]" type="checkbox" value="1" <?php checked( $settings['return_path'] ); ?>>
								<?php esc_html_e( 'Use the From Email as the return path for delivery failures', 'duaais-smtp' ); ?>
							</label>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save SMTP Settings', 'duaais-smtp' ) ); ?>
		</form>

		<hr>

		<h2><?php esc_html_e( 'Send a Test Email', 'duaais-smtp' ); ?></h2>
		<p><?php esc_html_e( 'Save and enable a complete configuration, then send a message to confirm delivery.', 'duaais-smtp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="duaais_smtp_send_test">
			<?php wp_nonce_field( 'duaais_smtp_send_test', 'duaais_smtp_nonce' ); ?>
			<label for="duaais-smtp-test-email"><strong><?php esc_html_e( 'Send To', 'duaais-smtp' ); ?></strong></label>
			<input class="regular-text" id="duaais-smtp-test-email" name="test_email" type="email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
			<?php
			submit_button(
				__( 'Send Test Email', 'duaais-smtp' ),
				'secondary',
				'submit',
				false,
				$settings['enabled'] && empty( $errors ) ? array() : array( 'disabled' => 'disabled' )
			);
			?>
		</form>
	</div>
	<?php
}

/**
 * Override the From Email when the configured sender is forced.
 *
 * @param string $from_email Existing From Email.
 * @return string
 */
function duaais_smtp_filter_from_email( $from_email ) {
	$settings = duaais_smtp_get_settings();

	if ( $settings['enabled'] && $settings['force_from_email'] ) {
		return (string) $settings['from_email'];
	}

	return $from_email;
}
add_filter( 'wp_mail_from', 'duaais_smtp_filter_from_email', 999 );

/**
 * Override the From Name when the configured sender is forced.
 *
 * @param string $from_name Existing From Name.
 * @return string
 */
function duaais_smtp_filter_from_name( $from_name ) {
	$settings = duaais_smtp_get_settings();

	if ( $settings['enabled'] && $settings['force_from_name'] ) {
		return (string) $settings['from_name'];
	}

	return $from_name;
}
add_filter( 'wp_mail_from_name', 'duaais_smtp_filter_from_name', 999 );

/**
 * Stop an invalid enabled configuration from silently falling back to PHP mail.
 *
 * @param null|bool               $return Short-circuit value from another callback.
 * @param array<string, mixed>    $atts   Mail attributes.
 * @return null|bool
 */
function duaais_smtp_block_invalid_mail( $return, $atts ) {
	if ( null !== $return ) {
		return $return;
	}

	$settings = duaais_smtp_get_settings();
	if ( ! $settings['enabled'] ) {
		return $return;
	}

	$errors = duaais_smtp_configuration_errors( $settings );
	if ( empty( $errors ) ) {
		return $return;
	}

	do_action(
		'wp_mail_failed',
		new WP_Error(
			'duaais_smtp_invalid_configuration',
			__( 'DUAAIS SMTP is enabled but its configuration is incomplete.', 'duaais-smtp' )
		)
	);

	return false;
}
add_filter( 'pre_wp_mail', 'duaais_smtp_block_invalid_mail', 10, 2 );

/**
 * Configure WordPress's bundled PHPMailer instance.
 *
 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
 * @return void
 */
function duaais_smtp_configure_phpmailer( $phpmailer ) {
	$settings = duaais_smtp_get_settings();
	if ( ! $settings['enabled'] || ! empty( duaais_smtp_configuration_errors( $settings ) ) ) {
		return;
	}

	$password = duaais_smtp_get_password( $settings );

	$phpmailer->isSMTP();
	$phpmailer->Host          = (string) $settings['host'];
	$phpmailer->Port          = (int) $settings['port'];
	$phpmailer->SMTPAuth      = (bool) $settings['authentication'];
	$phpmailer->Username      = (string) $settings['username'];
	$phpmailer->Password      = is_wp_error( $password ) ? '' : $password;
	$phpmailer->Timeout       = DUAAIS_SMTP_CONNECTION_TIMEOUT;
	$phpmailer->Timelimit     = DUAAIS_SMTP_CONNECTION_TIME_LIMIT;
	$phpmailer->SMTPKeepAlive = false;
	$phpmailer->SMTPDebug     = 0;

	if ( 'none' === $settings['encryption'] ) {
		$phpmailer->SMTPSecure = '';
	} else {
		$phpmailer->SMTPSecure = (string) $settings['encryption'];
	}
	$phpmailer->SMTPAutoTLS = (bool) $settings['auto_tls'];

	if ( $settings['return_path'] ) {
		$phpmailer->Sender = (string) $settings['from_email'];
	}
}
add_action( 'phpmailer_init', 'duaais_smtp_configure_phpmailer' );

/**
 * Capture the standard WordPress mail error during a test send.
 *
 * @param WP_Error $error Mail error.
 * @return void
 */
function duaais_smtp_capture_test_error( $error ) {
	if ( $error instanceof WP_Error ) {
		$GLOBALS['duaais_smtp_test_error'] = sanitize_text_field( $error->get_error_message() );
	}
}

/**
 * Send a nonce-protected test email from the settings screen.
 *
 * @return void
 */
function duaais_smtp_handle_test_email() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to test SMTP.', 'duaais-smtp' ) );
	}

	check_admin_referer( 'duaais_smtp_send_test', 'duaais_smtp_nonce' );

	$settings = duaais_smtp_get_settings();
	$errors   = duaais_smtp_configuration_errors( $settings );
	if ( ! $settings['enabled'] || ! empty( $errors ) ) {
		duaais_smtp_set_test_notice( 'error', __( 'Save and enable a complete SMTP configuration before sending a test.', 'duaais-smtp' ) );
		wp_safe_redirect( duaais_smtp_page_url() );
		exit;
	}

	$recipient = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
	if ( ! is_email( $recipient ) ) {
		duaais_smtp_set_test_notice( 'error', __( 'Enter a valid test recipient address.', 'duaais-smtp' ) );
		wp_safe_redirect( duaais_smtp_page_url() );
		exit;
	}

	$GLOBALS['duaais_smtp_test_error'] = '';
	add_action( 'wp_mail_failed', 'duaais_smtp_capture_test_error' );

	$sent = wp_mail(
		$recipient,
		sprintf(
			/* translators: %s: site name. */
			__( 'SMTP test from %s', 'duaais-smtp' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		),
		sprintf(
			/* translators: 1: site URL, 2: current date and time. */
			__( "DUAAIS SMTP successfully handed this message to the configured mail server.\n\nSite: %1\$s\nSent: %2\$s", 'duaais-smtp' ),
			home_url( '/' ),
			wp_date( 'Y-m-d H:i:s T' )
		),
		array( 'Content-Type: text/plain; charset=UTF-8' )
	);

	remove_action( 'wp_mail_failed', 'duaais_smtp_capture_test_error' );

	if ( $sent ) {
		duaais_smtp_set_test_notice( 'success', __( 'The SMTP server accepted the test email. Confirm that it arrived at the destination.', 'duaais-smtp' ) );
	} else {
		$error = (string) $GLOBALS['duaais_smtp_test_error'];
		duaais_smtp_set_test_notice(
			'error',
			'' !== $error
				? sprintf(
					/* translators: %s: mail transport error. */
					__( 'The test email failed: %s', 'duaais-smtp' ),
					$error
				)
				: __( 'The test email failed before the SMTP server accepted it.', 'duaais-smtp' )
		);
	}

	unset( $GLOBALS['duaais_smtp_test_error'] );
	wp_safe_redirect( duaais_smtp_page_url() );
	exit;
}
add_action( 'admin_post_duaais_smtp_send_test', 'duaais_smtp_handle_test_email' );

/**
 * Warn administrators if a previously valid credential can no longer be decrypted.
 *
 * @return void
 */
function duaais_smtp_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = duaais_smtp_get_settings();
	if ( ! $settings['enabled'] || empty( duaais_smtp_configuration_errors( $settings ) ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( $screen instanceof WP_Screen && 'settings_page_' . DUAAIS_SMTP_PAGE_SLUG === $screen->id ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<?php esc_html_e( 'DUAAIS SMTP cannot send mail because its configuration needs attention.', 'duaais-smtp' ); ?>
			<a href="<?php echo esc_url( duaais_smtp_page_url() ); ?>"><?php esc_html_e( 'Open SMTP settings', 'duaais-smtp' ); ?></a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'duaais_smtp_admin_notice' );
