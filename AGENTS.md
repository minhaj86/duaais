# AGENTS.md

Guidance for AI coding agents working in this repository.

## What this project is

`duaais` is a containerized, English-language **WordPress** site for the Dhaka University Alumni
Association in Sweden (DUAAIS). It is not a PHP application in the general sense: it is a custom
WordPress **theme** plus two custom **plugins**, delivered either as a Docker image (Azure Container
Apps) or as an SFTP upload (one.com shared hosting). WordPress core is never vendored into this
repository.

## Repository layout

| Path | Purpose |
| --- | --- |
| `wp-content/themes/duaais/` | The `DUAAIS Sweden` theme: classic PHP templates, `theme.json`, `style.css`, assets |
| `wp-content/plugins/duaais-members/` | Membership: registration, DU certificate upload, board approval, login, `My Account` |
| `wp-content/plugins/duaais-setup/` | `Tools → DUAAIS setup` admin screen plus `seed.php`, the idempotent content seeder |
| `scripts/bootstrap.sh` | WP-CLI bootstrap: installs core, activates theme/plugins, runs `seed.php` |
| `scripts/duaais-entrypoint.sh` | Container entrypoint; runs the bootstrap when `DUAAIS_BOOTSTRAP=1` |
| `scripts/deploy-onecom.sh` | Mirrors the theme and both plugins to one.com over SFTP (`lftp`, else OpenSSH `sftp`) |
| `scripts/php-uploads.ini` | Raises `upload_max_filesize` to 8M for certificate uploads |
| `infra/terraform/` | Azure Container Apps + MySQL flexible server + file share + ACR |
| `docs/deploy-onecom.md` | Shared-hosting deployment runbook |
| `Dockerfile`, `docker-compose.yml` | Deployable image and the local three-service stack |

**Only the theme and the two plugins are deployable payload.** `scripts/deploy-onecom.sh` uploads
exactly those three directories, and the `Dockerfile` copies exactly those three plus
`scripts/`. Anything a site needs at runtime must live in one of them — never in `scripts/` alone,
because shared hosting never runs `scripts/`.

## The one rule that is easy to get wrong

`seed.php` must run in **two** environments:

1. `wp eval-file` through WP-CLI (`scripts/bootstrap.sh`, local Docker and Azure), and
2. plain wp-admin with no WP-CLI at all (`Tools → DUAAIS setup` on one.com).

So `seed.php` may not use `WP_CLI` unguarded — the tail of the file already shows the pattern
(`if ( class_exists( 'WP_CLI' ) )`). It must stay idempotent and re-runnable. When you change seeded
pages, menus, or options, bump `DUAAIS_SEED_CONTENT_VERSION` in
`wp-content/plugins/duaais-setup/seed.php`, otherwise existing sites keep the old content: the
version gate in `$GLOBALS['duaais_seed_refresh_content']` is what allows updates.

## Coding conventions

Follow WordPress Coding Standards; the existing files already do.

- **Tabs** for indentation in PHP and shell. Two spaces in CSS, `theme.json`, and YAML.
- **Procedural PHP with prefixed function names.** No classes, no namespaces, no autoloader, no
  Composer. Prefixes: `duaais_` (theme), `duaais_members_` (members plugin), `duaais_setup_` and
  `duaais_seed_` (setup plugin).
- **Yoda-free but spaced parentheses:** `function duaais_members_status( $user ) {`.
- Every PHP file starts with a docblock and `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- Every function gets a docblock with `@param` / `@return`.
- **Escape on output, always:** `esc_html_e()`, `esc_html__()`, `esc_attr()`, `esc_url()`. Sanitize
  on input: `sanitize_text_field()`, `sanitize_email()`, `sanitize_title()`, `absint()`.
- **Text domains:** `duaais` (theme), `duaais-members`, `duaais-setup`. They match the folder names
  and must not be mixed up. All user-facing strings are translated and written in English.
- **Constants for magic values** — see `DUAAIS_MEMBER_ROLE`, `DUAAIS_STATUS_PENDING`,
  `DUAAIS_CERTIFICATE_MAX_BYTES` in `duaais-members.php`.
- Comments explain *why*, not *what*. The existing comments are a good model; do not add noise.
- Bump the `Version:` header **and** the matching `DUAAIS_*_VERSION` constant together when plugin
  behaviour changes. `duaais_members_maybe_upgrade()` re-applies activation defaults off that
  constant, so a stale version means shared-hosting sites silently skip the upgrade.

## Membership domain rules (do not regress these)

- Member data is **WordPress user metadata only**. No custom database tables. Keys are prefixed
  `duaais_`, e.g. `duaais_membership_status`, `duaais_hall`, `duaais_subject`,
  `duaais_graduation_year`, `duaais_residence_status`, `duaais_certificate_file`.
- Public registration through `wp-login.php?action=register` stays **disabled**
  (`users_can_register = 0`), and `default_role` is the pending role, so any account created outside
  the reviewed form still needs board approval.
- New applicants get `duaais_pending`; login is blocked until approval promotes them to
  `duaais_alumni`. Rejection strips all roles and keeps the account locked.
- **DU certificates are private.** They are written to
  `wp-content/uploads/duaais-certificates/`, outside the media library, protected by a generated
  `.htaccess`, capped at 8 MB, served only through a nonce-protected wp-admin download gated on
  `edit_users`, and deleted with the user. Never move them into the media library, never expose a
  predictable public URL, and never widen the capability check.
- This is a GDPR-relevant site. Do not log personal data, do not add third-party trackers or
  external CDNs, and do not republish member details publicly. The legacy `members.xls` was
  deliberately never imported.
- Front-end entry points are the shortcodes `[duaais_register]`, `[duaais_login]`,
  `[duaais_account]`. Extension point: the `duaais_members_admin_email` filter.

## Build, run and verify

There is no Composer, no npm, and no test suite. PHP is **not** installed on the host; use Docker.

```sh
# Lint every PHP file (verified working)
docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  sh -c 'find wp-content -name "*.php" -print0 | xargs -0 -n1 php -l'

# Lint shell scripts
shellcheck scripts/*.sh

# Validate Terraform
terraform -chdir=infra/terraform fmt -check && terraform -chdir=infra/terraform validate

# Run the site locally
cp .env.example .env          # first time only
docker compose up -d
docker compose run --rm wpcli sh /scripts/bootstrap.sh   # idempotent, safe to re-run

# Reset local state completely
docker compose down -v
```

The site is at <http://localhost:8080>, wp-admin at <http://localhost:8080/wp-admin/>. The theme and
both plugins are bind-mounted, so PHP and CSS edits are live on refresh — no rebuild needed. Only
`Dockerfile`, `docker-compose.yml`, or `scripts/php-uploads.ini` changes require
`docker compose up -d --build`.

**Always lint PHP after editing it.** A parse error in a plugin white-screens the whole site, and on
shared hosting there is no WP-CLI to recover with.

## Deployment

- **one.com (shared hosting, primary):** `./scripts/deploy-onecom.sh` (use `--dry-run` first when
  `lftp` is available). Then activate the theme and both plugins in wp-admin and run
  **Tools → DUAAIS setup**. No SSH, no WP-CLI, no cron on the Beginner/Explorer plans — this is why
  the seeder is duplicated into an admin screen. See `docs/deploy-onecom.md`.
- **Azure:** `terraform -chdir=infra/terraform apply`, which builds and pushes the image. Keep the
  container app at a single replica; `duaais-entrypoint.sh` bootstraps in the background and assumes
  one bootstrapper.

## Hard boundaries

- **Never commit secrets.** `.env`, `.env.onecom`, `*.tfvars`, and `*.tfstate` are gitignored; keep
  it that way. `.env.example` and `.env.onecom.example` carry placeholders only.
- Do not add build tooling (Composer, npm, bundlers, SASS) — the deployment targets copy plain files
  and cannot run a build step.
- Do not add third-party plugins or frameworks to solve something the two custom plugins already do.
- Do not edit WordPress core or vendored core files; none are tracked here.
- Do not touch `images/` or `wp-content/themes/duaais/assets/documents/` sources without updating
  `assets/images/CREDITS.md` / `assets/documents/SOURCES.md`.
- Keep `README.md` and `docs/deploy-onecom.md` in step with behaviour changes; both are user-facing
  runbooks.
