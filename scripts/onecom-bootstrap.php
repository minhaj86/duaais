<?php
/**
 * One-time bootstrap endpoint for one.com, or any other host without SSH.
 *
 * scripts/deploy-onecom.sh uploads this file into the web root with a random token, drives it
 * over HTTPS, and deletes it again. It is not part of the site and must never be left behind.
 *
 * Steps:
 *   status   Report whether core, wp-config.php, and the database tables are in place.
 *   core     Download and unpack WordPress core into the web root.
 *   install  Create the database tables and the administrator account.
 *   activate Switch to the DUAAIS theme and activate the DUAAIS plugins.
 *   seed     Run wp-content/plugins/duaais-setup/seed.php.
 *   cleanup  Delete this file.
 */

// Replaced with a random value when the file is uploaded.
define( 'DUAAIS_BOOTSTRAP_TOKEN', '__DUAAIS_BOOTSTRAP_TOKEN__' );

header( 'Content-Type: text/plain; charset=utf-8' );
header( 'X-Robots-Tag: noindex, nofollow' );

/**
 * Stop with an error message and a 500 status.
 *
 * @param string $message Error description.
 */
function duaais_bootstrap_fail( $message ) {
	http_response_code( 500 );
	echo 'error: ' . $message . "\n";
	exit;
}

/**
 * Read a request value.
 *
 * @param string $key     Field name.
 * @param string $default Fallback value.
 * @return string
 */
function duaais_bootstrap_input( $key, $default = '' ) {
	if ( isset( $_POST[ $key ] ) ) {
		return (string) wp_unslash_compat( $_POST[ $key ] );
	}

	if ( isset( $_GET[ $key ] ) ) {
		return (string) wp_unslash_compat( $_GET[ $key ] );
	}

	return $default;
}

/**
 * Undo magic-quote style slashing without depending on WordPress being loaded.
 *
 * @param mixed $value Raw request value.
 * @return string
 */
function wp_unslash_compat( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : '';
}

/**
 * Fetch a URL with whichever transport the host allows.
 *
 * @param string $url Remote URL.
 * @return string|false
 */
function duaais_bootstrap_get( $url ) {
	if ( function_exists( 'curl_init' ) ) {
		$handle = curl_init( $url );
		curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $handle, CURLOPT_TIMEOUT, 180 );
		curl_setopt( $handle, CURLOPT_USERAGENT, 'duaais-bootstrap' );
		$body   = curl_exec( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		curl_close( $handle );

		if ( false !== $body && 200 === $status ) {
			return $body;
		}
	}

	if ( ini_get( 'allow_url_fopen' ) ) {
		$context = stream_context_create( array( 'http' => array( 'timeout' => 180 ) ) );
		$body    = @file_get_contents( $url, false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $body ) {
			return $body;
		}
	}

	return false;
}

/**
 * Move everything from one directory into another, overwriting existing files.
 *
 * @param string $from Source directory.
 * @param string $to   Destination directory.
 * @return bool
 */
function duaais_bootstrap_move_tree( $from, $to ) {
	$entries = scandir( $from );
	if ( false === $entries ) {
		return false;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$source      = $from . '/' . $entry;
		$destination = $to . '/' . $entry;

		if ( is_dir( $source ) ) {
			if ( ! is_dir( $destination ) && ! mkdir( $destination, 0755 ) && ! is_dir( $destination ) ) {
				return false;
			}

			if ( ! duaais_bootstrap_move_tree( $source, $destination ) ) {
				return false;
			}

			rmdir( $source );
			continue;
		}

		if ( file_exists( $destination ) ) {
			unlink( $destination );
		}

		if ( ! rename( $source, $destination ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Delete a directory and everything below it.
 *
 * @param string $path Directory to remove.
 */
function duaais_bootstrap_remove_tree( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}

	$entries = scandir( $path );
	foreach ( false === $entries ? array() : $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$child = $path . '/' . $entry;
		if ( is_dir( $child ) ) {
			duaais_bootstrap_remove_tree( $child );
			continue;
		}

		unlink( $child );
	}

	rmdir( $path );
}

$token = duaais_bootstrap_input( 'token' );

// A real token is hex, so the untouched placeholder can never authenticate.
if ( 1 !== preg_match( '/^[a-f0-9]{32,}$/', DUAAIS_BOOTSTRAP_TOKEN ) || ! hash_equals( DUAAIS_BOOTSTRAP_TOKEN, $token ) ) {
	http_response_code( 403 );
	echo "forbidden\n";
	exit;
}

$duaais_root = __DIR__;
$duaais_step = duaais_bootstrap_input( 'step', 'status' );

if ( 'cleanup' === $duaais_step ) {
	if ( ! unlink( __FILE__ ) ) {
		duaais_bootstrap_fail( 'could not delete the bootstrap file, remove it over SFTP' );
	}

	echo "deleted\n";
	exit;
}

if ( 'core' === $duaais_step ) {
	if ( file_exists( $duaais_root . '/wp-includes/version.php' ) ) {
		echo "already-present\n";
		exit;
	}

	if ( ! class_exists( 'ZipArchive' ) ) {
		duaais_bootstrap_fail( 'the ZipArchive PHP extension is missing, upload WordPress over SFTP instead' );
	}

	$version = duaais_bootstrap_input( 'version', 'latest' );
	$name    = 'latest' === $version ? 'latest' : 'wordpress-' . $version;
	$archive = 'https://wordpress.org/' . $name . '.zip';

	$payload = duaais_bootstrap_get( $archive );
	if ( false === $payload ) {
		duaais_bootstrap_fail( 'could not download ' . $archive . ', upload WordPress over SFTP instead' );
	}

	$expected = duaais_bootstrap_get( $archive . '.sha1' );
	if ( false !== $expected ) {
		$expected = trim( $expected );
		if ( '' !== $expected && ! hash_equals( strtolower( $expected ), sha1( $payload ) ) ) {
			duaais_bootstrap_fail( 'the WordPress download did not match its published sha1 checksum' );
		}
	}

	$zip_path = $duaais_root . '/duaais-core.zip';
	if ( false === file_put_contents( $zip_path, $payload ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		duaais_bootstrap_fail( 'could not write ' . $zip_path );
	}
	unset( $payload );

	$staging = $duaais_root . '/duaais-core-staging';
	duaais_bootstrap_remove_tree( $staging );
	if ( ! mkdir( $staging, 0755 ) && ! is_dir( $staging ) ) {
		duaais_bootstrap_fail( 'could not create ' . $staging );
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path ) ) {
		duaais_bootstrap_fail( 'could not open the downloaded archive' );
	}

	if ( ! $zip->extractTo( $staging ) ) {
		$zip->close();
		duaais_bootstrap_fail( 'could not unpack the downloaded archive' );
	}
	$zip->close();
	unlink( $zip_path );

	if ( ! duaais_bootstrap_move_tree( $staging . '/wordpress', $duaais_root ) ) {
		duaais_bootstrap_fail( 'could not move WordPress into the web root' );
	}

	duaais_bootstrap_remove_tree( $staging );

	echo "installed-core\n";
	exit;
}

if ( ! file_exists( $duaais_root . '/wp-load.php' ) ) {
	echo "core=no\nconfig=no\ninstalled=no\n";
	exit;
}

if ( ! file_exists( $duaais_root . '/wp-config.php' ) ) {
	echo "core=yes\nconfig=no\ninstalled=no\n";
	exit;
}

if ( 'status' === $duaais_step || 'install' === $duaais_step ) {
	// wp-load.php redirects to the installer unless WordPress is told it is installing.
	define( 'WP_INSTALLING', true );
}

require_once $duaais_root . '/wp-load.php';

if ( 'status' === $duaais_step ) {
	printf(
		"core=yes\nconfig=yes\ninstalled=%s\n",
		is_blog_installed() ? 'yes' : 'no'
	);
	exit;
}

if ( 'install' === $duaais_step ) {
	if ( is_blog_installed() ) {
		echo "already-installed\n";
		exit;
	}

	$title    = duaais_bootstrap_input( 'title', 'DUAAIS Sweden' );
	$user     = duaais_bootstrap_input( 'admin_user' );
	$email    = duaais_bootstrap_input( 'admin_email' );
	$password = duaais_bootstrap_input( 'admin_password' );

	if ( '' === $user || '' === $email || '' === $password ) {
		duaais_bootstrap_fail( 'admin_user, admin_email, and admin_password are required' );
	}

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$result = wp_install( $title, $user, $email, true, '', $password );

	if ( is_wp_error( $result ) ) {
		duaais_bootstrap_fail( $result->get_error_message() );
	}

	echo "installed\n";
	exit;
}

if ( ! is_blog_installed() ) {
	duaais_bootstrap_fail( 'WordPress is not installed yet' );
}

if ( 'activate' === $duaais_step ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$theme = wp_get_theme( 'duaais' );
	if ( ! $theme->exists() ) {
		duaais_bootstrap_fail( 'the duaais theme is missing from wp-content/themes' );
	}

	switch_theme( 'duaais' );

	foreach ( array( 'duaais-members/duaais-members.php', 'duaais-setup/duaais-setup.php' ) as $plugin ) {
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			duaais_bootstrap_fail( 'the plugin ' . $plugin . ' is missing from wp-content/plugins' );
		}

		$activated = activate_plugin( $plugin );
		if ( is_wp_error( $activated ) ) {
			duaais_bootstrap_fail( $activated->get_error_message() );
		}
	}

	echo "activated\n";
	exit;
}

if ( 'seed' === $duaais_step ) {
	$seed = WP_PLUGIN_DIR . '/duaais-setup/seed.php';

	if ( ! is_readable( $seed ) ) {
		duaais_bootstrap_fail( 'the seeder is missing from wp-content/plugins/duaais-setup' );
	}

	if ( 'duaais' !== get_template() ) {
		duaais_bootstrap_fail( 'the duaais theme is not active' );
	}

	// The seeder creates attachments and rewrites permalinks, exactly as it does in wp-admin.
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';

	try {
		require $seed;
	} catch ( Throwable $error ) {
		duaais_bootstrap_fail( $error->getMessage() );
	}

	echo "seeded\n";
	exit;
}

duaais_bootstrap_fail( 'unknown step ' . $duaais_step );
