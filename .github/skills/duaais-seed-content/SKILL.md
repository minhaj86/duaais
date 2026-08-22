---
name: duaais-seed-content
description: Change the pages, posts, menus, or site options that the DUAAIS content seeder creates, keeping it idempotent and working both under WP-CLI and in wp-admin without WP-CLI. WHEN "add a page", "edit the seeded content", "change the navigation menu", "update the Aim and Goals page", "seeder", "seed.php", "Tools DUAAIS setup", "bootstrap content", "content did not update on the server".
---

# Change DUAAIS seeded content

`wp-content/plugins/duaais-setup/seed.php` is the single source of truth for the site's pages,
posts, menus, front page, permalinks, and theme mods. It ships **inside the plugin** rather than in
`scripts/` for one reason: it has to run in two very different places.

| Environment | How it runs |
| --- | --- |
| Local Docker, Azure | `scripts/bootstrap.sh` → `wp eval-file .../duaais-setup/seed.php` |
| one.com shared hosting | `Tools → DUAAIS setup` in wp-admin, no WP-CLI at all |

`wp-content/plugins/duaais-setup/duaais-setup.php` provides that admin screen, checks blockers
(seed file present, theme active, members plugin active, `.htaccess` writable), and stores the last
error in the `duaais_setup_last_error` option.

## The three rules

1. **Never call `WP_CLI` unguarded.** On one.com the class does not exist and a bare call is a fatal
   error. Use the existing pattern at the bottom of the file:
   `if ( class_exists( 'WP_CLI' ) ) { WP_CLI::success( … ); }`. Report other outcomes through return
   values or exceptions — `duaais-setup.php` catches `Throwable` and surfaces it in wp-admin.
2. **Stay idempotent.** Every helper looks the object up first (`get_page_by_path()`,
   existing-menu lookup) and only creates when it is absent. Running the seeder twice must produce
   the same site. Never blindly `wp_insert_post()`.
3. **Bump `DUAAIS_SEED_CONTENT_VERSION`** at the top of `seed.php` whenever the *content* of an
   existing page, post, or menu changes. Existing sites only accept updates when
   `$GLOBALS['duaais_seed_refresh_content']` is true, which is a `version_compare()` against the
   stored `duaais_seed_content_version` option. Forgetting the bump is the number one reason a
   change works on a fresh install and silently does nothing on the live site.

New slugs do not strictly need a bump — they are created because they are absent — but bump anyway
when the same change edits existing content.

## Workflow

1. Edit `seed.php`, reusing `duaais_seed_page()`, `duaais_seed_post()`, and `duaais_seed_menu()`
   rather than calling WordPress APIs directly.
2. Bump `DUAAIS_SEED_CONTENT_VERSION`.
3. If the page is linked from navigation, add it to the `primary` and/or `footer` menu arrays at the
   bottom of the file, keyed by the page ID the helper returned.
4. Lint:
   ```sh
   docker run --rm -v "$PWD":/app -w /app php:8.3-cli php -l wp-content/plugins/duaais-setup/seed.php
   ```
5. Verify the **update** path on an existing site:
   ```sh
   docker compose run --rm wpcli sh /scripts/bootstrap.sh
   docker compose run --rm wpcli wp --path=/var/www/html option get duaais_seed_content_version
   ```
6. Verify the **fresh install** path, because that is what a new deployment does:
   ```sh
   docker compose down -v && docker compose up -d
   docker compose run --rm wpcli sh /scripts/bootstrap.sh
   ```
7. Run it a second time and confirm nothing is duplicated — that is the idempotence check.

## Content conventions

- English only. Bengali and Swedish material exists as linked PDFs under
  `wp-content/themes/duaais/assets/documents/`, not as translated pages.
- Content mirrors the public association site; member-only data never goes into seeded pages. The
  legacy `members.xls` member list was deliberately never imported and must not be republished.
- Member-facing pages embed the shortcodes `[duaais_register]`, `[duaais_login]`, and
  `[duaais_account]` from the members plugin.
- The seeder ends with `flush_rewrite_rules()` after switching to `postname` permalinks. On shared
  hosting a read-only `.htaccess` breaks this — `duaais_setup_htaccess_is_writable()` warns about
  it, and the fix belongs in the admin notice, not in the seeder.

If you also change the pages described in `README.md`, update that section too.
