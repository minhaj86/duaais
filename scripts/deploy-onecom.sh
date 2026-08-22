#!/usr/bin/env bash
# Upload the DUAAIS theme and plugins to one.com shared hosting over SFTP.
#
# one.com offers no SSH, Git deployment, or Docker on the Beginner and Explorer plans, so the
# deployable part of this repository is the theme plus the two plugins. WordPress itself, the
# database, and wp-config.php are set up once in the one.com control panel; see
# docs/deploy-onecom.md.
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

dry_run="no"
force_sftp="no"

usage() {
	cat <<'USAGE'
Usage: scripts/deploy-onecom.sh [--dry-run] [--sftp]

Uploads wp-content/themes/duaais, wp-content/plugins/duaais-members, and
wp-content/plugins/duaais-setup to a one.com web space.

Options:
  --dry-run   Show what would be transferred without writing anything. Requires lftp.
  --sftp      Use OpenSSH sftp even when lftp is installed. Uploads without deleting
              files that were removed from the repository.
  -h, --help  Show this help.

Configuration comes from .env.onecom in the repository root, or from the environment:
  ONECOM_HOST             SFTP hostname from Control Panel -> SFTP & SSH administration
  ONECOM_USER             SFTP username (usually the domain name)
  ONECOM_PASSWORD         SFTP password. Optional; prompted for when unset.
  ONECOM_PORT             Defaults to 22.
  ONECOM_REMOTE_ROOT      Web root. Defaults to httpd.www; newer servers use webroots/<hash>.
  ONECOM_TRUST_HOST_KEY   Set to yes to accept an unknown host key without prompting.
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--dry-run) dry_run="yes" ;;
		--sftp) force_sftp="yes" ;;
		-h|--help) usage; exit 0 ;;
		*) printf 'Unknown option: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
	esac
	shift
done

missing=""
for required in ONECOM_HOST ONECOM_USER; do
	if [ -z "${!required:-}" ]; then
		missing="$missing $required"
	fi
done

if [ -n "$missing" ]; then
	printf 'Missing configuration:%s\n' "$missing" >&2
	printf 'Copy .env.onecom.example to .env.onecom and fill it in, or see --help.\n' >&2
	exit 2
fi

# Local directory -> remote directory, relative to the web root.
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

printf 'Deploying to %s@%s:%s/%s\n' "$ONECOM_USER" "$ONECOM_HOST" "$ONECOM_PORT" "$ONECOM_REMOTE_ROOT"

if command -v lftp >/dev/null 2>&1 && [ "$force_sftp" = "no" ]; then
	auto_confirm="no"
	if [ "$ONECOM_TRUST_HOST_KEY" = "yes" ]; then
		auto_confirm="yes"
	fi

	mirror_flags="--reverse --delete --verbose --exclude-glob .DS_Store --exclude-glob *.log"
	if [ "$dry_run" = "yes" ]; then
		mirror_flags="$mirror_flags --dry-run"
	fi

	# The password would be visible in the process list if it were passed as an argument.
	script_file="$(mktemp)"
	chmod 600 "$script_file"
	trap 'rm -f "$script_file"' EXIT

	{
		printf 'set cmd:fail-exit yes\n'
		printf 'set net:timeout 20\n'
		printf 'set net:max-retries 3\n'
		printf 'set sftp:auto-confirm %s\n' "$auto_confirm"
		if [ -n "$ONECOM_PASSWORD" ]; then
			printf 'open -p %s -u "%s","%s" sftp://%s\n' "$ONECOM_PORT" "$ONECOM_USER" "$ONECOM_PASSWORD" "$ONECOM_HOST"
		else
			printf 'open -p %s -u "%s" sftp://%s\n' "$ONECOM_PORT" "$ONECOM_USER" "$ONECOM_HOST"
		fi
		for item in "${payload[@]}"; do
			printf 'mirror %s "%s" "%s/%s"\n' "$mirror_flags" "$repo_root/$item" "$ONECOM_REMOTE_ROOT" "$item"
		done
		printf 'bye\n'
	} > "$script_file"

	lftp -f "$script_file"
else
	if [ "$dry_run" = "yes" ]; then
		printf '--dry-run needs lftp. Install it with: brew install lftp\n' >&2
		exit 2
	fi

	if [ "$force_sftp" = "no" ]; then
		printf 'lftp is not installed, falling back to OpenSSH sftp.\n'
		printf 'Files deleted from the repository will stay on the server. Install lftp for mirroring: brew install lftp\n'
	fi

	if [ -n "$ONECOM_PASSWORD" ]; then
		printf 'OpenSSH sftp cannot read ONECOM_PASSWORD; enter the password when prompted.\n'
	fi

	# sftp -b implies BatchMode=yes, which disables the password prompt. ssh honours the first
	# value it is given for an option, so this has to come before -b on the command line.
	sftp_options=(-o "BatchMode=no" -P "$ONECOM_PORT")
	if [ "$ONECOM_TRUST_HOST_KEY" = "yes" ]; then
		sftp_options+=(-o "StrictHostKeyChecking=accept-new")
	fi

	batch_file="$(mktemp)"
	trap 'rm -f "$batch_file"' EXIT

	{
		printf -- '-mkdir "%s/wp-content"\n' "$ONECOM_REMOTE_ROOT"
		printf -- '-mkdir "%s/wp-content/themes"\n' "$ONECOM_REMOTE_ROOT"
		printf -- '-mkdir "%s/wp-content/plugins"\n' "$ONECOM_REMOTE_ROOT"
		for item in "${payload[@]}"; do
			# put -r copies the source directory into an existing target directory.
			printf 'put -r "%s" "%s/%s"\n' "$repo_root/$item" "$ONECOM_REMOTE_ROOT" "$(dirname "$item")"
		done
	} > "$batch_file"

	sftp "${sftp_options[@]}" -b "$batch_file" "$ONECOM_USER@$ONECOM_HOST"
fi

if [ "$dry_run" = "yes" ]; then
	printf '\nDry run finished. Nothing was uploaded.\n'
	exit 0
fi

cat <<'NEXT'

Upload finished. In wp-admin:
  1. Appearance -> Themes: activate "DUAAIS Sweden".
  2. Plugins: activate "DUAAIS Members" and "DUAAIS Setup".
  3. Tools -> DUAAIS setup: run the content bootstrap.
NEXT
