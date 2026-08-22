# GitHub Copilot instructions — DUAAIS Sweden

A containerized WordPress site for the Dhaka University Alumni Association in Sweden. This
repository contains **only** a custom theme and two custom plugins; WordPress core is never
vendored here.

See [`AGENTS.md`](../AGENTS.md) for the full contributor guide. The essentials:

## Structure

- `wp-content/themes/duaais/` — classic PHP templates, `theme.json`, `style.css`
- `wp-content/plugins/duaais-members/` — registration, certificate upload, board approval, login
- `wp-content/plugins/duaais-setup/` — `Tools → DUAAIS setup` screen and `seed.php` content seeder
- `scripts/` — WP-CLI bootstrap, container entrypoint, one.com SFTP deploy
- `infra/terraform/` — Azure Container Apps deployment

Only the theme and the two plugins are deployable payload. Runtime code must live there, never in
`scripts/` alone, because shared hosting never executes `scripts/`.

## Style

- WordPress Coding Standards. **Tabs** in PHP and shell, two spaces in CSS/JSON/YAML.
- Procedural PHP with prefixed functions: `duaais_`, `duaais_members_`, `duaais_setup_`,
  `duaais_seed_`. No classes, namespaces, Composer, or npm.
- Space inside parentheses: `function duaais_members_status( $user ) {`.
- Start every PHP file with a docblock and `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- Docblock every function with `@param` / `@return`.
- Escape all output (`esc_html_e`, `esc_html__`, `esc_attr`, `esc_url`); sanitize all input
  (`sanitize_text_field`, `sanitize_email`, `absint`).
- Text domains match folder names: `duaais`, `duaais-members`, `duaais-setup`. All strings are
  translated and written in English.
- Use constants for magic values (`DUAAIS_MEMBER_ROLE`, `DUAAIS_CERTIFICATE_MAX_BYTES`, …).
- Comment *why*, not *what*.

## Rules that must not regress

- Member data lives in WordPress **user metadata** prefixed `duaais_`. No custom tables.
- Public WordPress registration stays disabled; every membership goes through the reviewed
  `[duaais_register]` form and board approval (`duaais_pending` → `duaais_alumni`).
- DU certificates stay outside the media library in `wp-content/uploads/duaais-certificates/`,
  `.htaccess`-protected, 8 MB max, downloadable only through the nonce-protected wp-admin route
  gated on `edit_users`, and deleted with the user.
- GDPR-relevant site: no personal data in logs, no third-party trackers or external CDNs, no public
  republishing of member details.
- `seed.php` runs both under WP-CLI and in plain wp-admin. Guard any `WP_CLI` use, keep it
  idempotent, and bump `DUAAIS_SEED_CONTENT_VERSION` whenever seeded content changes.
- Bump a plugin's `Version:` header and its `DUAAIS_*_VERSION` constant together.
- Never commit secrets. `.env`, `.env.onecom`, `*.tfvars`, and `*.tfstate` stay gitignored.

## Verify changes

PHP is not installed on the host — use Docker. There is no test suite.

```sh
docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  sh -c 'find wp-content -name "*.php" -print0 | xargs -0 -n1 php -l'
shellcheck scripts/*.sh
docker compose up -d && docker compose run --rm wpcli sh /scripts/bootstrap.sh
```

The theme and plugins are bind-mounted into the local stack, so PHP and CSS edits are live on
refresh at <http://localhost:8080>. Always lint PHP after editing: a parse error white-screens the
whole site, and shared hosting has no WP-CLI to recover with.
