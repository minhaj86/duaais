#!/usr/bin/env bash
# Deploy the DUAAIS site to one.com shared hosting over SFTP.
#
# Without arguments the script uploads the theme and the plugins, which is all a running site
# needs. With --first-run it also installs WordPress core, writes wp-config.php, creates the
# administrator, activates everything, and seeds the content, so a fresh web space ends up as a
# finished site in one command.
#
# The one.com Control Panel has no API, so creating the database, choosing the PHP version, and
# enabling SFTP stay manual. See docs/deploy-onecom.md.
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
env_file="${ONECOM_ENV_FILE:-$repo_root/.env.onecom}"

if [ -f "$env_file" ]; then
	set -a
	# shellcheck disable=SC1090
	. "$env_file"
	set +a
fi

ONECOM_PORT="${ONECOM_PORT:-22}"
ONECOM_REMOTE_ROOT="${ONECOM_REMOTE_ROOT:-httpd.www}"
ONECOM_PASSWORD="${ONECOM_PASSWORD:-}"
ONECOM_TRUST_HOST_KEY="${ONECOM_TRUST_HOST_KEY:-no}"
ONECOM_SITE_URL="${ONECOM_SITE_URL:-}"
ONECOM_WP_VERSION="${ONECOM_WP_VERSION:-latest}"
ONECOM_TABLE_PREFIX="${ONECOM_TABLE_PREFIX:-wp_}"
ONECOM_SITE_TITLE="${ONECOM_SITE_TITLE:-DUAAIS Sweden}"
ONECOM_DB_NAME="${ONECOM_DB_NAME:-}"
ONECOM_DB_USER="${ONECOM_DB_USER:-}"
ONECOM_DB_PASSWORD="${ONECOM_DB_PASSWORD:-}"
ONECOM_DB_HOST="${ONECOM_DB_HOST:-}"
ONECOM_WP_ADMIN_USER="${ONECOM_WP_ADMIN_USER:-}"
ONECOM_WP_ADMIN_PASSWORD="${ONECOM_WP_ADMIN_PASSWORD:-}"
ONECOM_WP_ADMIN_EMAIL="${ONECOM_WP_ADMIN_EMAIL:-}"

dry_run="no"
force_sftp="no"
first_run="no"

usage() {
	cat <<'USAGE'
Usage: scripts/deploy-onecom.sh [--first-run] [--dry-run] [--sftp]

Uploads wp-content/themes/duaais, wp-content/plugins/duaais-members, and
wp-content/plugins/duaais-setup to a one.com web space.

Options:
  --first-run  Also install WordPress core, write wp-config.php, create the administrator,
               activate the theme and plugins, and seed the content. Safe to repeat: every
               step is skipped when it is already done.
  --dry-run    Show what would be transferred without writing anything. Requires lftp.
  --sftp       Use OpenSSH sftp even when lftp is installed. Uploads without deleting
               files that were removed from the repository.
  -h, --help   Show this help.

Configuration comes from .env.onecom in the repository root, or from the environment:
  ONECOM_HOST               SFTP hostname from Control Panel -> SFTP & SSH administration
  ONECOM_USER               SFTP username (usually the domain name)
  ONECOM_PASSWORD           SFTP password. Optional; prompted for when unset.
  ONECOM_PORT               Defaults to 22.
  ONECOM_REMOTE_ROOT        Web root. Defaults to httpd.www; newer servers use webroots/<hash>.
  ONECOM_TRUST_HOST_KEY     Set to yes to accept an unknown host key without prompting.

Only needed for --first-run:
  ONECOM_SITE_URL           Public site address, for example https://duaais.se
  ONECOM_DB_NAME            Database name from Control Panel -> Database settings
  ONECOM_DB_USER            Database user
  ONECOM_DB_PASSWORD        Database password
  ONECOM_DB_HOST            Database host, for example duaais.se.mysql
  ONECOM_WP_ADMIN_USER      WordPress administrator login to create
  ONECOM_WP_ADMIN_PASSWORD  WordPress administrator password
  ONECOM_WP_ADMIN_EMAIL     WordPress administrator email
  ONECOM_SITE_TITLE         Defaults to DUAAIS Sweden.
  ONECOM_WP_VERSION         WordPress version to install. Defaults to latest.
  ONECOM_TABLE_PREFIX       Defaults to wp_.
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--dry-run) dry_run="yes" ;;
		--sftp) force_sftp="yes" ;;
		--first-run) first_run="yes" ;;
		-h|--help) usage; exit 0 ;;
		*) printf 'Unknown option: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
	esac
	shift
done

if [ "$first_run" = "yes" ] && [ "$dry_run" = "yes" ]; then
	printf -- '--first-run and --dry-run cannot be combined.\n' >&2
	exit 2
fi

require_config() {
	local missing="" name
	for name in "$@"; do
		if [ -z "${!name:-}" ]; then
			missing="$missing $name"
		fi
	done

	if [ -n "$missing" ]; then
		printf 'Missing configuration:%s\n' "$missing" >&2
		printf 'Copy .env.onecom.example to .env.onecom and fill it in, or see --help.\n' >&2
		exit 2
	fi
}

require_config ONECOM_HOST ONECOM_USER

# Local directory -> path below the web root.
payload=(
	"wp-content/themes/duaais"
	"wp-content/plugins/duaais-members"
	"wp-content/plugins/duaais-setup"
)

for item in "${payload[@]}"; do
	if [ ! -d "$repo_root/$item" ]; then
		printf 'Missing local directory: %s\n' "$repo_root/$item" >&2
		exit 1
	fi
done

transport="sftp"
if command -v lftp >/dev/null 2>&1 && [ "$force_sftp" = "no" ]; then
	transport="lftp"
fi

if [ "$dry_run" = "yes" ] && [ "$transport" != "lftp" ]; then
	printf -- '--dry-run needs lftp. Install it with: brew install lftp\n' >&2
	exit 2
fi

if [ "$transport" = "sftp" ]; then
	if [ "$force_sftp" = "no" ]; then
		printf 'lftp is not installed, falling back to OpenSSH sftp.\n'
		printf 'Files deleted from the repository will stay on the server. Install lftp for mirroring: brew install lftp\n'
	fi

	if [ -n "$ONECOM_PASSWORD" ]; then
		printf 'OpenSSH sftp cannot read ONECOM_PASSWORD; enter the password when prompted.\n'
	fi
fi

# Transfer operations are collected first so that the whole upload runs in a single session and
# the password is asked for at most once. Each entry is "kind<tab>local<tab>remote".
operations=()

queue_directory() {
	operations+=("dir	$1	$2")
}

queue_file() {
	operations+=("file	$1	$2")
}

# Uploads the contents of a local directory into a remote directory, leaving anything else there
# untouched. Used for WordPress core, which unpacks into the web root alongside wp-config.php.
queue_tree() {
	operations+=("tree	$1	$2")
}

emit_sftp_mkdirs() {
	local path="$1" prefix="" segment
	local IFS='/'
	for segment in $path; do
		if [ -z "$segment" ] || [ "$segment" = "." ]; then
			continue
		fi
		prefix="${prefix:+$prefix/}$segment"
		printf -- '-mkdir "%s"\n' "$prefix"
	done
}

run_transfer() {
	if [ "${#operations[@]}" -eq 0 ]; then
		return 0
	fi

	local batch entry kind local_path remote_path
	batch="$(mktemp)"
	chmod 600 "$batch"

	if [ "$transport" = "lftp" ]; then
		local auto_confirm="no" mirror_flags
		if [ "$ONECOM_TRUST_HOST_KEY" = "yes" ]; then
			auto_confirm="yes"
		fi

		mirror_flags="--reverse --delete --verbose --parallel=4 --exclude-glob .DS_Store --exclude-glob *.log"
		if [ "$dry_run" = "yes" ]; then
			mirror_flags="$mirror_flags --dry-run"
		fi

		{
			printf 'set cmd:fail-exit yes\n'
			printf 'set net:timeout 20\n'
			printf 'set net:max-retries 3\n'
			printf 'set sftp:auto-confirm %s\n' "$auto_confirm"
			# The password would show up in the process list if it were a command argument.
			if [ -n "$ONECOM_PASSWORD" ]; then
				printf 'open -p %s -u "%s","%s" sftp://%s\n' "$ONECOM_PORT" "$ONECOM_USER" "$ONECOM_PASSWORD" "$ONECOM_HOST"
			else
				printf 'open -p %s -u "%s" sftp://%s\n' "$ONECOM_PORT" "$ONECOM_USER" "$ONECOM_HOST"
			fi

			for entry in "${operations[@]}"; do
				IFS=$'\t' read -r kind local_path remote_path <<< "$entry"
				if [ "$kind" = "dir" ]; then
					printf 'mirror %s "%s" "%s"\n' "$mirror_flags" "$local_path" "$remote_path"
				elif [ "$kind" = "tree" ]; then
					printf 'mkdir -p -f "%s"\n' "$remote_path"
					printf 'mirror --reverse --verbose --parallel=4 "%s" "%s"\n' "$local_path" "$remote_path"
				else
					printf 'mkdir -p -f "%s"\n' "$(dirname "$remote_path")"
					printf 'put "%s" -o "%s"\n' "$local_path" "$remote_path"
				fi
			done
			printf 'bye\n'
		} > "$batch"

		lftp -f "$batch"
		rm -f "$batch"
		return 0
	fi

	{
		for entry in "${operations[@]}"; do
			IFS=$'\t' read -r kind local_path remote_path <<< "$entry"

			if [ "$kind" = "tree" ]; then
				emit_sftp_mkdirs "$remote_path"
				local child
				for child in "$local_path"/* "$local_path"/.[!.]*; do
					if [ ! -e "$child" ]; then
						continue
					fi
					if [ -d "$child" ]; then
						printf 'put -r "%s" "%s"\n' "$child" "$remote_path"
					else
						printf 'put "%s" "%s/%s"\n' "$child" "$remote_path" "$(basename "$child")"
					fi
				done
				continue
			fi

			emit_sftp_mkdirs "$(dirname "$remote_path")"
			if [ "$kind" = "dir" ]; then
				# put -r copies the source directory into an existing target directory.
				printf 'put -r "%s" "%s"\n' "$local_path" "$(dirname "$remote_path")"
			else
				printf 'put "%s" "%s"\n' "$local_path" "$remote_path"
			fi
		done
	} > "$batch"

	# sftp -b implies BatchMode=yes, which disables the password prompt. ssh honours the first
	# value it is given for an option, so this has to come before -b on the command line.
	local sftp_options=(-o "BatchMode=no" -P "$ONECOM_PORT")
	if [ "$ONECOM_TRUST_HOST_KEY" = "yes" ]; then
		sftp_options+=(-o "StrictHostKeyChecking=accept-new")
	fi

	sftp "${sftp_options[@]}" -b "$batch" "$ONECOM_USER@$ONECOM_HOST"
	rm -f "$batch"
}

http_status() {
	curl -s -o /dev/null -m 30 -w '%{http_code}' "$1" || printf '000'
}

php_quote() {
	printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g"
}

random_string() {
	LC_ALL=C tr -dc "$1" </dev/urandom 2>/dev/null | head -c "$2" || true
}

generate_salts() {
	local salts name
	salts="$(curl -fsS -m 30 https://api.wordpress.org/secret-key/1.1/salt/ 2>/dev/null || true)"

	if printf '%s' "$salts" | grep -q 'AUTH_KEY'; then
		printf '%s\n' "$salts"
		return 0
	fi

	for name in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
		printf "define( '%s', '%s' );\n" "$name" "$(random_string 'A-Za-z0-9!@#$%^&*()_+=-' 64)"
	done
}

# Returns the path component of a URL, without a trailing slash, or an empty string when there is
# none. Used to keep the upload target and the public address consistent.
url_path() {
	local rest="${1#*://}"
	case "$rest" in
		*/*)
			rest="/${rest#*/}"
			printf '%s' "${rest%/}"
			;;
		*)
			printf ''
			;;
	esac
}

write_wp_config() {
	cat > "$1" <<PHP
<?php
/**
 * Generated by scripts/deploy-onecom.sh.
 */

define( 'DB_NAME', '$(php_quote "$ONECOM_DB_NAME")' );
define( 'DB_USER', '$(php_quote "$ONECOM_DB_USER")' );
define( 'DB_PASSWORD', '$(php_quote "$ONECOM_DB_PASSWORD")' );
define( 'DB_HOST', '$(php_quote "$ONECOM_DB_HOST")' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$(generate_salts)
\$table_prefix = '$(php_quote "$ONECOM_TABLE_PREFIX")';

define( 'WP_DEBUG', false );
define( 'DISALLOW_FILE_EDIT', true );
define( 'WP_AUTO_UPDATE_CORE', 'minor' );

// Pinned rather than guessed from the request, so that HTTPS behind one.com's proxy and any
// subdirectory in the address are both recorded correctly at install time.
define( 'WP_SITEURL', '$(php_quote "$ONECOM_SITE_URL")' );
define( 'WP_HOME', '$(php_quote "$ONECOM_SITE_URL")' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
PHP
	chmod 600 "$1"
}

call_bootstrap() {
	local step="$1"
	shift

	local response status body
	response="$(curl -sS -m 900 -w '\n%{http_code}' -X POST "$bootstrap_url" -d "token=$bootstrap_token" -d "step=$step" "$@" 2>/dev/null || printf '\n000')"
	status="${response##*$'\n'}"
	body="${response%$'\n'*}"

	printf '%s' "$body"

	[ "$status" = "200" ]
}

remove_bootstrap() {
	printf 'Removing the bootstrap file\n'
	call_bootstrap cleanup >/dev/null 2>&1 || true

	if [ "$(http_status "$bootstrap_url")" != "404" ]; then
		printf 'The bootstrap file is still reachable at %s. Delete it over SFTP.\n' "$bootstrap_url" >&2
		return 1
	fi
}

run_step() {
	local step="$1" label="$2" output
	shift 2

	printf '%s\n' "$label"

	if ! output="$(call_bootstrap "$step" "$@")"; then
		printf '  failed: %s\n' "${output:-no response from the server}" >&2
		remove_bootstrap || true
		exit 1
	fi

	printf '  %s\n' "$(printf '%s' "$output" | tr '\n' ' ')"
}

# Used when the server cannot reach wordpress.org itself, which happens on hosts that block
# outbound HTTPS from PHP or intercept TLS.
upload_core_from_local() {
	local name archive expected actual

	if [ "$ONECOM_WP_VERSION" = "latest" ]; then
		name="latest"
	else
		name="wordpress-$ONECOM_WP_VERSION"
	fi

	core_workdir="$(mktemp -d)"
	archive="$core_workdir/wordpress.zip"

	printf '  downloading https://wordpress.org/%s.zip\n' "$name"
	if ! curl -fsSL -m 600 -o "$archive" "https://wordpress.org/$name.zip"; then
		printf 'Could not download WordPress. Install it from the Control Panel and run the script again.\n' >&2
		exit 1
	fi

	expected="$(curl -fsS -m 60 "https://wordpress.org/$name.zip.sha1" 2>/dev/null | tr -d '[:space:]' || true)"
	if [ -n "$expected" ]; then
		actual="$(shasum -a 1 "$archive" | awk '{ print $1 }')"
		if [ "$actual" != "$expected" ]; then
			printf 'The WordPress download did not match its published sha1 checksum.\n' >&2
			exit 1
		fi
	fi

	if ! unzip -q "$archive" -d "$core_workdir"; then
		printf 'Could not unpack the WordPress archive.\n' >&2
		exit 1
	fi

	printf '  uploading WordPress core, this takes a while\n'
	operations=()
	queue_tree "$core_workdir/wordpress" "$ONECOM_REMOTE_ROOT"
	run_transfer
	operations=()

	rm -rf "$core_workdir"
	core_workdir=""
}

bootstrap_token=""
bootstrap_url=""
bootstrap_local=""
config_local=""
core_workdir=""
needs_core="no"
needs_config="no"
needs_install="no"

cleanup_local() {
	if [ -n "$bootstrap_local" ]; then
		rm -f "$bootstrap_local"
	fi

	if [ -n "$config_local" ]; then
		rm -f "$config_local"
	fi

	if [ -n "$core_workdir" ]; then
		rm -rf "$core_workdir"
	fi
}
trap cleanup_local EXIT

printf 'Deploying to %s@%s:%s/%s\n' "$ONECOM_USER" "$ONECOM_HOST" "$ONECOM_PORT" "$ONECOM_REMOTE_ROOT"

if [ "$first_run" = "yes" ]; then
	require_config ONECOM_SITE_URL
	ONECOM_SITE_URL="${ONECOM_SITE_URL%/}"
	bootstrap_url="$ONECOM_SITE_URL/duaais-bootstrap.php"

	# A subdirectory install only works when the upload target and the public path agree, and
	# getting this wrong uploads a whole WordPress into the wrong folder. Warn before that happens.
	site_path="$(url_path "$ONECOM_SITE_URL")"
	if [ -n "$site_path" ]; then
		case "${ONECOM_REMOTE_ROOT%/}" in
			*"$site_path")
				;;
			*)
				printf 'Warning: ONECOM_SITE_URL ends in %s but ONECOM_REMOTE_ROOT is %s.\n' \
					"$site_path" "$ONECOM_REMOTE_ROOT" >&2
				printf 'For a subdirectory install the remote root must end in %s too.\n' "$site_path" >&2
				;;
		esac
	fi

	printf 'Checking %s\n' "$ONECOM_SITE_URL"
	core_status="$(http_status "$ONECOM_SITE_URL/wp-includes/version.php")"
	config_status="$(http_status "$ONECOM_SITE_URL/wp-config.php")"
	login_status="$(http_status "$ONECOM_SITE_URL/wp-login.php")"

	if [ "$core_status" = "000" ]; then
		printf 'The site did not answer. Check ONECOM_SITE_URL and that the domain resolves.\n' >&2
		exit 1
	fi

	if [ "$core_status" = "404" ]; then
		needs_core="yes"
	fi

	if [ "$config_status" = "404" ]; then
		needs_config="yes"
	fi

	if [ "$login_status" != "200" ]; then
		needs_install="yes"
	fi

	if [ "$needs_install" = "yes" ] && [ "$needs_config" = "no" ] && [ "$config_status" = "000" ]; then
		printf 'Could not determine whether wp-config.php exists. Aborting rather than guessing.\n' >&2
		exit 1
	fi

	printf '  WordPress core     %s\n' "$([ "$needs_core" = "yes" ] && printf 'missing, will be installed' || printf 'present')"
	printf '  wp-config.php      %s\n' "$([ "$needs_config" = "yes" ] && printf 'missing, will be written' || printf 'present, left alone')"
	printf '  Database tables    %s\n' "$([ "$needs_install" = "yes" ] && printf 'missing, will be created' || printf 'present')"

	if [ "$needs_config" = "yes" ]; then
		require_config ONECOM_DB_NAME ONECOM_DB_USER ONECOM_DB_PASSWORD ONECOM_DB_HOST
	fi

	if [ "$needs_install" = "yes" ]; then
		require_config ONECOM_WP_ADMIN_USER ONECOM_WP_ADMIN_PASSWORD ONECOM_WP_ADMIN_EMAIL
	fi

	bootstrap_token="$(random_string 'a-f0-9' 48)"
	bootstrap_local="$(mktemp)"
	chmod 600 "$bootstrap_local"
	sed "s/__DUAAIS_BOOTSTRAP_TOKEN__/$bootstrap_token/g" "$repo_root/scripts/onecom-bootstrap.php" > "$bootstrap_local"
	queue_file "$bootstrap_local" "$ONECOM_REMOTE_ROOT/duaais-bootstrap.php"

	if [ "$needs_config" = "yes" ]; then
		config_local="$(mktemp)"
		write_wp_config "$config_local"
		queue_file "$config_local" "$ONECOM_REMOTE_ROOT/wp-config.php"
	fi
fi

for item in "${payload[@]}"; do
	queue_directory "$repo_root/$item" "$ONECOM_REMOTE_ROOT/$item"
done

run_transfer

if [ "$dry_run" = "yes" ]; then
	printf '\nDry run finished. Nothing was uploaded.\n'
	exit 0
fi

if [ "$first_run" = "no" ]; then
	cat <<'NEXT'

Upload finished. In wp-admin:
  1. Appearance -> Themes: activate "DUAAIS Sweden".
  2. Plugins: activate "DUAAIS Members" and "DUAAIS Setup".
  3. Tools -> DUAAIS setup: run the content bootstrap.
NEXT
	exit 0
fi

if [ "$needs_core" = "yes" ]; then
	printf 'Installing WordPress core\n'
	if core_output="$(call_bootstrap core -d "version=$ONECOM_WP_VERSION")"; then
		printf '  %s\n' "$(printf '%s' "$core_output" | tr '\n' ' ')"
	else
		printf '  the server could not fetch WordPress: %s\n' "${core_output:-no response from the server}"
		upload_core_from_local
	fi
fi

if [ "$needs_install" = "yes" ]; then
	run_step install "Creating the database tables and the administrator" \
		-d "title=$ONECOM_SITE_TITLE" \
		-d "admin_user=$ONECOM_WP_ADMIN_USER" \
		-d "admin_password=$ONECOM_WP_ADMIN_PASSWORD" \
		-d "admin_email=$ONECOM_WP_ADMIN_EMAIL"
fi

run_step activate "Activating the theme and plugins"
run_step seed "Seeding the DUAAIS content"
remove_bootstrap || true

cat <<NEXT

$ONECOM_SITE_URL is live. Sign in at $ONECOM_SITE_URL/wp-admin/

Still manual, because the one.com Control Panel has no API:
  1. Force HTTPS by adding the redirect to .htaccess in the web root.
  2. Configure SMTP (send.one.com, port 465, SSL/TLS) so approval emails are delivered.
  3. Confirm that wp-content/uploads/duaais-certificates/ answers 403 in a browser.

docs/deploy-onecom.md has the details.
NEXT
