# Deploying DUAAIS to one.com

one.com is shared hosting. It has no Docker, no Terraform, and no Git deployment, so the container
image and the Azure configuration in [`infra/terraform`](../infra/terraform) are not used here.
The site runs as an ordinary WordPress instance and this repository supplies the theme, the two
plugins, and the content.

SSH — and therefore WP-CLI — is only available on the Enthusiast and Guru plans. This guide assumes
the smaller Beginner and Explorer plans and needs neither. It works unchanged on the larger plans.

## How much is automated

The one.com Control Panel has no public API, CLI, or Terraform provider, so anything that lives in
the Control Panel has to be clicked once by a human. Everything after that is scripted.

| Step | |
| --- | --- |
| Create the database and note its credentials | Control Panel |
| Set the PHP version for the domain | Control Panel |
| Enable SFTP and set its password | Control Panel |
| Install WordPress core | `--first-run` |
| Write `wp-config.php` with fresh salts | `--first-run` |
| Create the database tables and the administrator | `--first-run` |
| Upload the theme and both plugins | script |
| Activate the theme and plugins | `--first-run` |
| Seed pages, posts, images, menus, and settings | `--first-run` |
| Force HTTPS, configure SMTP | manual, once |

## What one.com provides

| Requirement | one.com |
| --- | --- |
| PHP 8.1 or newer, required by the theme and plugins | PHP 8.0–8.5, selected per domain in the Control Panel |
| MariaDB | Included; phpMyAdmin in the Control Panel |
| Apache with `mod_rewrite` and `.htaccess` | Yes, which is what protects the certificate folder |
| 8 MB certificate uploads | `upload_max_filesize` is fixed at 256 MB on every plan |
| HTTPS | Free Let's Encrypt wildcard certificate, issued automatically |
| Outbound email | Mailboxes with SMTP on `send.one.com` |
| SSH, WP-CLI, cron | Enthusiast and Guru plans only |

Because the platform limits already exceed what the membership form needs,
[`scripts/php-uploads.ini`](../scripts/php-uploads.ini) is irrelevant on one.com. Custom `php.ini`
and `.user.ini` overrides are not supported there in any case.

## 1. Prepare the Control Panel

1. **Advanced settings → Database settings:** create a database. Note the name, user, password, and
   host. The host is never `localhost`; it looks like `yourdomain.tld.mysql`.
2. **Advanced settings → PHP:** set PHP 8.3 or newer for the domain.
3. **Advanced settings → SFTP & SSH administration:** switch SFTP on and set a password. The
   hostname, port, and username shown there are what the script connects with.

Do **not** use the 1-click WordPress installer. `--first-run` installs core itself, and doing both
means the script finds an installation it did not configure. If WordPress is already installed, the
script detects that and skips straight to uploading and seeding.

## 2. Configure the deployment

```sh
cp .env.onecom.example .env.onecom
```

Fill in the SFTP details, `ONECOM_SITE_URL`, the database credentials, and the WordPress
administrator to create. `.env.onecom` holds passwords and is gitignored.

Pre-seed the SSH host key so the transfer does not stop on a prompt:

```sh
ssh-keyscan -p 22 ssh.example.com >> ~/.ssh/known_hosts
```

`brew install lftp` is recommended. With lftp the script mirrors, removes files deleted from the
repository, supports `--dry-run`, and can read the password from `.env.onecom`. Without it the
script falls back to OpenSSH `sftp`, which uploads but never deletes and always prompts for the
password.

## 3. Run the first deployment

```sh
./scripts/deploy-onecom.sh --first-run
```

The script:

1. Probes the site to see whether core, `wp-config.php`, and the database tables already exist.
2. Uploads a one-time bootstrap file with a random token, `wp-config.php` when it is missing, and
   the theme and both plugins — all in a single SFTP session.
3. Tells the server to download and unpack WordPress, verifying the published sha1 checksum. If the
   server cannot reach wordpress.org, the script downloads the archive itself and uploads it.
4. Creates the database tables and the administrator account.
5. Activates the DUAAIS theme and the two plugins.
6. Runs [`seed.php`](../wp-content/plugins/duaais-setup/seed.php), the same seeder that WP-CLI runs
   locally, which creates the pages, posts with featured images, categories, both navigation menus,
   and the site settings.
7. Deletes the bootstrap file and confirms that it returns 404.

Every step is skipped when it is already done, so the command is safe to repeat. If a step fails,
the bootstrap file is removed before the script exits.

The bootstrap file only answers requests carrying the random token, and it exists for the length of
one deployment. If the script ever reports that it is still reachable, delete
`duaais-bootstrap.php` from the web root over SFTP.

## 4. Finish the setup by hand

### Force HTTPS

The Let's Encrypt wildcard certificate is issued automatically, but the redirect is not. Add this
above the `# BEGIN WordPress` block in the web root `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

Then confirm that **Settings → General** has `https://` in both the WordPress Address and the Site
Address.

### Email

Membership approvals, rejections, and password resets all depend on outbound mail, and PHP `mail()`
on shared hosting is unreliable. Create a mailbox in the Control Panel and configure an SMTP plugin
such as WP Mail SMTP with:

| Setting | Value |
| --- | --- |
| Host | `send.one.com` |
| Port | `465` |
| Encryption | SSL/TLS |
| Username | the full mailbox address |
| Password | the mailbox password |

Set **Settings → General → Administration Email Address** to the address that should receive new
membership applications, or hook the `duaais_members_admin_email` filter.

### Verify the certificate store

The membership plugin creates `wp-content/uploads/duaais-certificates/` with an `.htaccess` that
denies direct access. After the first application, request the file directly in a browser and
confirm the server answers 403. one.com disables some Apache directives, so this is worth checking
rather than assuming.

### Scheduled tasks

There is no cron scheduler in the Control Panel on these plans. WordPress falls back to
`wp-cron.php` on page load, which is enough for a low-traffic site. For reliable scheduling, point
an external cron service at `https://yourdomain.tld/wp-cron.php?doing_wp_cron`.

## Updating the site later

```sh
./scripts/deploy-onecom.sh --dry-run   # requires lftp
./scripts/deploy-onecom.sh
```

This uploads only the theme and the plugins, which is all a running site needs. Changes take effect
immediately.

Re-run the content seeder only when the seeded content itself changed, either with
`--first-run` or from **Tools → DUAAIS setup** in wp-admin. `DUAAIS_SEED_CONTENT_VERSION` in
`seed.php` controls whether existing pages and posts are refreshed. The seeder also resets the site
title, tagline, timezone, date formats, permalink structure, front page, and posts page to the
DUAAIS defaults, so later changes to those particular settings are discarded.

Keep WordPress core and any third-party plugins updated from wp-admin, and take backups from the
Control Panel before large changes.

## If something goes wrong

- **Every page except the front page returns 404.** WordPress could not write the rewrite rules
  into the web root `.htaccess`. **Tools → DUAAIS setup** reports whether the file is writable and
  prints the rules to paste. Saving **Settings → Permalinks** once has the same effect when the file
  is writable.
- **The script cannot tell whether `wp-config.php` exists.** It stops rather than risk overwriting a
  working configuration. Check that `ONECOM_SITE_URL` points at the right domain.
- **`--first-run` reports that the server could not fetch WordPress.** That is only a warning; the
  script uploads core over SFTP instead, which is slower but equivalent.
- **Manual fallback.** The theme and plugins are ordinary WordPress extensions. They can be
  uploaded through the Control Panel file manager and activated in wp-admin, and the content can be
  created from **Tools → DUAAIS setup**, without using the script at all.
