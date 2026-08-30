# Deploying DUAAIS to one.com manually

This guide installs and updates DUAAIS entirely through the one.com Control Panel and WordPress
wp-admin. It does not require SFTP, SSH, WP-CLI, Docker, or the deployment script. For automated
SFTP deployment, use the [script guide](deploy-onecom-script.md).

one.com runs the site as an ordinary WordPress instance. This repository supplies only the DUAAIS
theme, the two plugins, and the seeded content.

## What one.com provides

The Beginner plan has everything the site needs. Larger plans raise these limits and may include a
1-click WordPress installer.

| Requirement | Beginner |
| --- | --- |
| PHP 8.1 or newer | PHP 8.0-8.5, selected per domain and subdomain |
| MariaDB | 1 database; phpMyAdmin included |
| Apache with `mod_rewrite` and `.htaccess` | Enabled |
| 8 MB certificate uploads | 256 MB upload and post limits |
| HTTPS | Free Let's Encrypt wildcard certificate |
| Outbound email | Mailboxes and SMTP on `send.one.com` |
| 1-click WordPress installer | Explorer and larger, or the Managed WP add-on |

## 1. Prepare the Control Panel

1. **Advanced settings -> Database settings:** create a database. Note the database name, user,
   password, and host. The host is not `localhost`; it resembles `yourdomain.tld.mysql`.
2. **Advanced settings -> PHP:** select PHP 8.3 or newer for the domain and, when applicable, its
   subdomain.
3. On Explorer or a larger plan, you may use **Control Panel -> 1-click WordPress installation**.
   If you do, skip the manual WordPress core installation below.

The Beginner plan includes one database. If another site already uses it, give WordPress a unique
table prefix during installation.

## 2. Build the upload archives

From the repository root, run:

```sh
./scripts/package.sh
```

This creates three versioned ZIP archives in `dist/`:

- `duaais-<version>.zip`
- `duaais-members-<version>.zip`
- `duaais-setup-<version>.zip`

Each archive contains one correctly named top-level folder, as WordPress requires.

## 3. Install WordPress

Skip this section if WordPress is already installed.

On the Beginner plan:

1. Download `https://wordpress.org/latest.zip`.
2. Open the domain's web root in the one.com file manager.
3. Upload `latest.zip`, select it, and click **Unzip**.
4. Move everything inside the extracted `wordpress` folder into the web root.
5. Delete the empty `wordpress` folder and `latest.zip`.
6. Open the domain in a browser and complete the WordPress installer using the database details from
   the Control Panel. WordPress writes `wp-config.php`.

## 4. Install the theme and plugins

In wp-admin:

1. Open **Appearance -> Themes -> Add New -> Upload Theme**, upload `duaais-<version>.zip`, and
   activate **DUAAIS Sweden**.
2. Open **Plugins -> Add New -> Upload Plugin**, upload `duaais-members-<version>.zip`, and activate
   **DUAAIS Members**.
3. Upload `duaais-setup-<version>.zip` the same way and activate **DUAAIS Setup**.
4. Open **Tools -> DUAAIS setup** and click **Run DUAAIS setup**.

The setup screen creates the pages, posts, featured images, categories, menus, and site settings.
It is idempotent and replaces the WP-CLI bootstrap on shared hosting.

## 5. Finish the setup

### Force HTTPS

The Let's Encrypt certificate is issued automatically, but the redirect is not. In the file
manager, add this above the `# BEGIN WordPress` block in the web root `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

Confirm that **Settings -> General** uses `https://` for both the WordPress Address and Site
Address.

### Configure email

Create a mailbox in the one.com Control Panel and configure an SMTP plugin such as WP Mail SMTP:

| Setting | Value |
| --- | --- |
| Host | `send.one.com` |
| Port | `465` |
| Encryption | SSL/TLS |
| Authentication | Enabled |
| Username | Full mailbox address |
| Password | Mailbox password |

Set **Settings -> General -> Administration Email Address** to the mailbox that should receive new
membership applications, or use the `duaais_members_admin_email` filter.

### Verify the certificate store

After the first membership application, request
`wp-content/uploads/duaais-certificates/` directly in a browser. The membership plugin creates an
`.htaccess` in that directory, and the server must return 403. Certificates must remain downloadable
only through the protected wp-admin route.

### Scheduled tasks

The Beginner and Explorer plans have no Control Panel cron scheduler. WordPress uses
`wp-cron.php` on page loads. For reliable scheduling, point an external cron service at
`https://yourdomain.tld/wp-cron.php?doing_wp_cron`.

## Installing under a subdirectory

To serve the site from `https://duaais.com/wproot`:

1. Create `wproot` in the file manager.
2. Upload and extract WordPress inside `wproot` rather than the domain web root.
3. Complete the installer at `https://duaais.com/wproot`.

On plans with the 1-click installer, select `wproot` as the installation folder. WordPress places
its `.htaccess` in `wproot` and generates the correct rewrite base. Theme and plugin installation is
otherwise unchanged.

## Updating the site later

1. Run `./scripts/package.sh` again.
2. Upload each new archive through the same wp-admin upload screen.
3. When WordPress detects the installed copy, choose **Replace current with uploaded**.
4. Run **Tools -> DUAAIS setup** only when seeded content changed.

`DUAAIS_SEED_CONTENT_VERSION` controls whether existing seeded pages and posts are refreshed. The
seeder also resets the site title, tagline, timezone, date formats, permalink structure, front page,
and posts page to the DUAAIS defaults.

Keep WordPress core and third-party plugins updated from wp-admin. Take a Control Panel backup before
large changes.

## If something goes wrong

- **Every page except the front page returns 404.** WordPress could not write rewrite rules to
  `.htaccess`. Open **Tools -> DUAAIS setup** for the rules to paste, or save
  **Settings -> Permalinks** once.
- **wp-admin asks for FTP credentials when installing a theme or plugin.** Add
  `define( 'FS_METHOD', 'direct' );` above the "stop editing" line in `wp-config.php` using the file
  manager editor.
- **WordPress rejects an archive.** Confirm that it came from `./scripts/package.sh` and that you
  used the theme upload screen for `duaais-<version>.zip` and the plugin upload screen for the other
  two archives.
- **The live site still shows an old version.** Check the installed version in wp-admin, clear any
  one.com cache, and confirm that WordPress replaced the existing theme or plugin.
