# Deploying DUAAIS to one.com

one.com is shared hosting. It has no Docker, no Terraform, and no Git deployment, so the container
image and the Azure configuration in [`infra/terraform`](../infra/terraform) are not used here.
The site is installed as an ordinary WordPress instance and this repository supplies the theme and
the two plugins.

SSH — and therefore WP-CLI — is only available on the Enthusiast and Guru plans. This guide assumes
the smaller Beginner and Explorer plans, so every step runs through the Control Panel, wp-admin, and
SFTP. It works unchanged on the larger plans.

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

## 1. Create the database

Control Panel → Advanced settings → **Database settings**. Create a database and note the user, the
password, and the hostname. The hostname is **not** `localhost`; it looks like
`yourdomain.tld.mysql`.

## 2. Install WordPress

- **Explorer and above:** use the 1-click WordPress installation in the Control Panel.
- **Beginner:** install manually. Upload the WordPress release into the web root, open the site, and
  complete the installer with the database details from step 1.

Then set PHP 8.3 or newer for the domain under Advanced settings → **PHP**.

The one-click installer adds a `one.com` companion plugin. It can stay, and it can also be removed.

## 3. Harden `wp-config.php`

Edit `wp-config.php` in the web root through SFTP or the Control Panel file manager:

```php
define( 'WP_DEBUG', false );
define( 'DISALLOW_FILE_EDIT', true );
define( 'WP_AUTO_UPDATE_CORE', 'minor' );
```

Replace the eight salt values with fresh ones from
<https://api.wordpress.org/secret-key/1.1/salt/> if WordPress was installed manually.

## 4. Upload the theme and plugins

Enable SFTP first: Control Panel → Advanced settings → **SFTP & SSH administration**, turn on SFTP
access, and set a password. The connection details shown there go into `.env.onecom`:

```sh
cp .env.onecom.example .env.onecom
# fill in ONECOM_HOST, ONECOM_USER, ONECOM_REMOTE_ROOT
ssh-keyscan -p 22 "$ONECOM_HOST" >> ~/.ssh/known_hosts
./scripts/deploy-onecom.sh --dry-run   # requires lftp
./scripts/deploy-onecom.sh
```

`.env.onecom` is gitignored. The script uploads three directories into the web root:

- `wp-content/themes/duaais`
- `wp-content/plugins/duaais-members`
- `wp-content/plugins/duaais-setup`

With `lftp` installed (`brew install lftp`) the script mirrors and removes files that were deleted
from the repository, and supports `--dry-run`. Without it, the script falls back to OpenSSH `sftp`,
which uploads but never deletes, and always prompts for the password.

The web root is `httpd.www` on older web spaces. Newer servers use a hashed folder such as
`webroots/5dfa4a5d`, shown in Control Panel → Subdomains under **Folder**. Set `ONECOM_REMOTE_ROOT`
accordingly.

## 5. Activate and seed

In wp-admin:

1. **Appearance → Themes:** activate *DUAAIS Sweden*.
2. **Plugins:** activate *DUAAIS Members*, then *DUAAIS Setup*.
3. **Tools → DUAAIS setup:** press *Run DUAAIS setup*.

That screen runs the same [`seed.php`](../wp-content/plugins/duaais-setup/seed.php) that
`scripts/bootstrap.sh` feeds to WP-CLI locally, so hosting without SSH gets identical content. It
creates the pages, the three posts with their featured images, the categories, both navigation
menus, and the site settings. Running it again updates that content in place instead of duplicating
it.

It also rewrites the site title, tagline, timezone, date formats, permalink structure, front page,
and posts page to the DUAAIS defaults. Re-running it therefore discards later changes to those
particular settings.

Delete the *DUAAIS Setup* plugin once the site is live if you would rather not leave the button in
wp-admin; the seeded content stays. Upload it again whenever the content needs to be re-seeded.

## 6. Permalinks

The seeder switches to `/%postname%/` permalinks, which needs rewrite rules in the web root
`.htaccess`. When that file is not writable, every page except the front page returns 404. The setup
screen reports whether the file is writable and prints the exact rules to paste when it is not.

Alternatively, open **Settings → Permalinks** and press *Save Changes* once WordPress can write the
file.

## 7. HTTPS

The Let's Encrypt wildcard certificate is issued automatically, but the redirect is not. Add this
above the WordPress block in the web root `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

Then confirm that **Settings → General** has `https://` in both the WordPress Address and the Site
Address.

## 8. Email

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

## 9. Verify the certificate store

The membership plugin creates `wp-content/uploads/duaais-certificates/` with an `.htaccess` that
denies direct access. After the first application, request the file directly in a browser and
confirm the server answers 403. one.com disables some Apache directives, so this is worth checking
rather than assuming.

## 10. Scheduled tasks

There is no cron scheduler in the Control Panel on these plans. WordPress falls back to `wp-cron.php`
on page load, which is enough for a low-traffic site. For reliable scheduling, point an external
cron service at `https://yourdomain.tld/wp-cron.php?doing_wp_cron`.

## Updating the site later

```sh
./scripts/deploy-onecom.sh
```

Theme and plugin changes take effect immediately. Re-run **Tools → DUAAIS setup** only when the
seeded content itself changed; `DUAAIS_SEED_CONTENT_VERSION` in `seed.php` controls whether existing
pages and posts are refreshed.

Keep WordPress core and any third-party plugins updated from wp-admin, and take backups from the
Control Panel before large changes.
