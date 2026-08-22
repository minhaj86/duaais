---
name: duaais-local-site
description: Run, inspect, reset, and verify the DUAAIS WordPress site locally with Docker Compose, and lint theme/plugin changes. WHEN "start the site", "run WordPress locally", "site is down", "white screen", "lint the PHP", "verify my theme change", "reset the local database", "run the bootstrap", "check WordPress logs", "wp-cli command".
---

# Run and verify the DUAAIS site locally

The local stack is three services in `docker-compose.yml`: `database` (MariaDB 11.8), `wordpress`
(WordPress 6.8.2 / PHP 8.3 / Apache), and `wpcli` (in the `tools` profile, so it only runs on
demand). The theme and both plugins are bind-mounted into the `wordpress` container, so **PHP, CSS,
and JS edits are live on refresh** — no rebuild, no restart.

PHP is not installed on the host. Every PHP command goes through Docker.

## Start the site

```sh
[ -f .env ] || cp .env.example .env
docker compose up -d
docker compose run --rm wpcli sh /scripts/bootstrap.sh
```

`bootstrap.sh` installs core if needed, activates the theme and both plugins, and runs `seed.php`.
It is idempotent — re-run it any time. Site: <http://localhost:8080>, admin:
<http://localhost:8080/wp-admin/> with the credentials from `.env`.

Wait for health before assuming failure; the `wordpress` service has a 30-retry healthcheck and the
first boot copies all of WordPress into the volume.

```sh
docker compose ps
```

## Lint after every PHP edit

A parse error white-screens the entire site, and shared hosting has no WP-CLI to recover with, so
never hand back PHP that has not been linted.

```sh
docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  sh -c 'find wp-content -name "*.php" -print0 | xargs -0 -n1 php -l'
```

Shell scripts: `shellcheck scripts/*.sh`.

## Run WP-CLI

```sh
docker compose run --rm wpcli wp --path=/var/www/html plugin list
docker compose run --rm wpcli wp --path=/var/www/html user list --fields=user_login,roles
docker compose run --rm wpcli wp --path=/var/www/html option get duaais_seed_content_version
```

`wpcli` runs as uid 33 (`www-data`) against the shared `wordpress_data` volume. Do not add
`--allow-root`; it is not root.

## Diagnose a broken site

```sh
docker compose logs --tail=100 wordpress
docker compose exec wordpress tail -50 /var/www/html/wp-content/debug.log
```

`WORDPRESS_DEBUG=1` in `.env` enables `WP_DEBUG_LOG` with `WP_DEBUG_DISPLAY` off, so PHP notices go
to `debug.log` rather than the page. Set `WORDPRESS_DEBUG=0` to reproduce production behaviour.

Checklist for a white screen or a broken page:

1. Lint the PHP (above) — parse errors are the usual cause.
2. Read `debug.log` for the fatal.
3. `docker compose ps` — is `wordpress` healthy and is `database` up?
4. Every page 404 except the front page → permalinks. Re-run the bootstrap, or
   `wp --path=/var/www/html rewrite flush --hard`.
5. Missing pages, menus, or theme mods → re-run the bootstrap.

## Rebuild and reset

```sh
docker compose up -d --build   # only after Dockerfile / compose / php-uploads.ini changes
docker compose down            # stop, keep data
docker compose down -v         # delete WordPress and database data entirely
```

After `down -v` the next start needs the bootstrap again. Reach for a full reset when verifying
first-run behaviour, role creation, or the seeder from scratch — that is the only way to test what a
fresh one.com or Azure deployment will do.

## Verifying membership changes

Certificate uploads land in `wp-content/uploads/duaais-certificates/` inside the container, not in
the repository:

```sh
docker compose exec wordpress ls -la /var/www/html/wp-content/uploads/duaais-certificates/
docker compose exec wordpress cat /var/www/html/wp-content/uploads/duaais-certificates/.htaccess
```

The directory must stay `.htaccess`-denied, and the file must only be reachable through the
nonce-protected wp-admin download. Confirm the 8 MB cap still holds after touching
`scripts/php-uploads.ini`:

```sh
docker compose exec wordpress php -i | grep -E 'upload_max_filesize|post_max_size'
```
