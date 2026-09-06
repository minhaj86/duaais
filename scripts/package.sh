#!/usr/bin/env bash
# Build installable ZIP archives of the theme and plugins.
#
# wp-admin and the one.com file manager both accept ZIP uploads, so these archives are what makes
# a deployment possible without SFTP. Each archive contains exactly one top-level folder named
# after the theme or plugin, which is what WordPress expects.
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
dist_dir="$repo_root/dist"

if ! command -v zip >/dev/null 2>&1; then
	printf 'The zip command is required but was not found.\n' >&2
	exit 1
fi

# Source directory -> slug of the folder inside the archive.
payload=(
	"wp-content/themes/duaais"
	"wp-content/plugins/duaais-members"
	"wp-content/plugins/duaais-setup"
	"wp-content/plugins/duaais-smtp"
	"wp-content/plugins/duaais-anniversary"
)

# Read the Version header out of style.css or the plugin bootstrap file.
read_version() {
	local source="$1" slug="$2" file=""

	if [ -f "$source/style.css" ]; then
		file="$source/style.css"
	elif [ -f "$source/$slug.php" ]; then
		file="$source/$slug.php"
	fi

	if [ -z "$file" ]; then
		printf 'unknown'
		return 0
	fi

	local version
	version="$(sed -n 's/^[[:space:]]*\*\{0,1\}[[:space:]]*Version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' "$file" | head -n 1)"
	printf '%s' "${version:-unknown}"
}

rm -rf "$dist_dir"
mkdir -p "$dist_dir"

staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT

for item in "${payload[@]}"; do
	source="$repo_root/$item"
	slug="$(basename "$item")"

	if [ ! -d "$source" ]; then
		printf 'Missing local directory: %s\n' "$source" >&2
		exit 1
	fi

	version="$(read_version "$source" "$slug")"
	archive="$dist_dir/$slug-$version.zip"

	# Copy into a staging directory first so the archive holds one clean top-level folder.
	rm -rf "${staging:?}/$slug"
	cp -R "$source" "$staging/$slug"
	find "$staging/$slug" \( -name '.DS_Store' -o -name '*.log' -o -name '._*' \) -delete

	( cd "$staging" && zip -r -q -X "$archive" "$slug" )

	printf '%-24s %-8s %s\n' "$slug" "$version" "$(du -h "$archive" | cut -f1)"
done

printf '\nArchives written to %s\n' "$dist_dir"
cat <<'NEXT'

Install them in wp-admin, in this order:
  1. Appearance -> Themes -> Add New -> Upload Theme      duaais-*.zip, then Activate.
  2. Plugins -> Add New -> Upload Plugin                  duaais-members-*.zip, then Activate.
  3. Plugins -> Add New -> Upload Plugin                  duaais-setup-*.zip, then Activate.
  4. Plugins -> Add New -> Upload Plugin                  duaais-smtp-*.zip, then Activate.
  5. Plugins -> Add New -> Upload Plugin                  duaais-anniversary-*.zip.
  6. Settings -> DUAAIS SMTP -> Configure the mailer and send a test email.
  7. Tools -> DUAAIS setup -> Run DUAAIS setup.
  8. Activate "DUAAIS Anniversary Flyer" whenever the homepage flyer is needed.

Uploading a newer archive over an existing install is fine; WordPress asks to replace it.
NEXT
