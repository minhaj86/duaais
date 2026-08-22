---
name: duaais-deploy
description: Ship the DUAAIS theme and plugins to one.com shared hosting over SFTP, or to Azure Container Apps with Terraform, and run the post-deploy checklist. WHEN "deploy the site", "push to one.com", "upload the theme", "deploy to production", "deploy-onecom.sh", "terraform apply", "release the plugin", "go live", "the live site is missing my change".
---

# Deploy DUAAIS

Two very different targets share one payload: `wp-content/themes/duaais`,
`wp-content/plugins/duaais-members`, and `wp-content/plugins/duaais-setup`. Nothing else is deployed
code. If a change has to reach production, it must land in one of those three directories.

## Before either deployment

```sh
docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  sh -c 'find wp-content -name "*.php" -print0 | xargs -0 -n1 php -l'
shellcheck scripts/*.sh
git status --short          # nothing uncommitted that the deploy would ship or miss
```

Confirm the versions were bumped: a plugin's `Version:` header **and** its `DUAAIS_*_VERSION`
constant, plus `DUAAIS_SEED_CONTENT_VERSION` if seeded content changed. Version drift is the usual
reason a live site does not pick up a change — `duaais_members_maybe_upgrade()` and the seeder's
refresh gate both key off those constants.

## one.com (shared hosting, primary target)

The Beginner and Explorer plans have no SSH, no WP-CLI, no cron, and no Docker. WordPress itself,
the database, and `wp-config.php` are managed once through the Control Panel; this repository only
supplies the theme and plugins. Full runbook: `docs/deploy-onecom.md`.

```sh
cp .env.onecom.example .env.onecom     # first time; fill in from Control Panel → SFTP & SSH
./scripts/deploy-onecom.sh --dry-run   # needs lftp; skip if lftp is unavailable
./scripts/deploy-onecom.sh
```

- With `lftp` the script **mirrors** (`--reverse --delete`), so files deleted from the repository are
  deleted on the server. Without it, it falls back to OpenSSH `sftp`, which only uploads — stale
  files linger and have to be removed by hand.
- `ONECOM_REMOTE_ROOT` defaults to `httpd.www`; newer one.com servers use `webroots/<hash>`. A
  successful run that changes nothing on the live site usually means the wrong web root.
- `.env.onecom` holds an SFTP password and is gitignored. Never commit it, never echo it, and never
  pass a password as a command-line argument — the script writes credentials to a `chmod 600`
  temporary file precisely to keep them out of the process list.

After upload, in wp-admin:

1. **Appearance → Themes** — activate *DUAAIS Sweden*.
2. **Plugins** — activate *DUAAIS Members* and *DUAAIS Setup*.
3. **Tools → DUAAIS setup** — run the content bootstrap (this replaces `scripts/bootstrap.sh`).

Then verify: front page renders, an interior page loads (404s everywhere but the front page mean a
read-only `.htaccess` blocked the permalink rules), **Join DUAAIS** submits, the approval email
arrives, `Users → Membership applications` lists the application, the certificate downloads from
wp-admin, and the direct URL to `wp-content/uploads/duaais-certificates/` is **denied**.

## Azure Container Apps

```sh
cd infra/terraform
cp terraform.tfvars.example terraform.tfvars   # first time
terraform init
terraform fmt -check && terraform validate
terraform plan
terraform apply
terraform output site_url
```

Terraform builds and pushes the image from the repository `Dockerfile` into ACR, and runs Container
Apps against Azure Database for MySQL flexible server with an Azure file share for uploads.
`scripts/duaais-entrypoint.sh` runs the bootstrap in the background when `DUAAIS_BOOTSTRAP=1`, so
**keep the container app at a single replica** — concurrent bootstraps are not safe.

`terraform.tfvars` and state files contain credentials and are gitignored. Keep them that way.

## Production checklist

- HTTPS enforced, and `WP_DEBUG` / `WORDPRESS_DEBUG` off.
- SMTP configured — password resets and the approval/rejection emails silently fail without it.
- Strong admin and database credentials; the `change-this-local-password` default never leaves the
  laptop.
- `DISALLOW_FILE_EDIT` on, salts freshly generated on a manual install.
- Certificate directory not publicly reachable; on non-Apache servers block it in the server config
  because the `.htaccess` will not be read.
- Backups and WordPress update monitoring in place.
