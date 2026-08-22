#!/bin/sh
set -eu

WP="wp --path=/var/www/html"
SITE_URL="${SITE_URL:-http://localhost:${WORDPRESS_PORT:-8080}}"
SITE_TITLE="${SITE_TITLE:-DUAAIS Sweden}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-change-this-local-password}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.test}"

if ! $WP core is-installed >/dev/null 2>&1; then
	$WP core install \
		--url="$SITE_URL" \
		--title="$SITE_TITLE" \
		--admin_user="$WP_ADMIN_USER" \
		--admin_password="$WP_ADMIN_PASSWORD" \
		--admin_email="$WP_ADMIN_EMAIL" \
		--skip-email
fi

$WP site switch-language en_US >/dev/null
$WP theme activate duaais >/dev/null
$WP plugin activate duaais-members duaais-setup >/dev/null
# The seeder ships inside duaais-setup so that hosting without WP-CLI can run the same file
# from Tools -> DUAAIS setup.
$WP eval-file /var/www/html/wp-content/plugins/duaais-setup/seed.php

printf '\nDUAAIS Sweden is ready at %s\n' "$SITE_URL"
printf 'WordPress admin: %s/wp-admin/\n' "$SITE_URL"
printf 'Admin user: %s\n' "$WP_ADMIN_USER"
printf 'Change the local default password before any public deployment.\n'