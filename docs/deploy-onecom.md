# Deploying DUAAIS to one.com

one.com is shared hosting. It has no Docker, Terraform, or Git deployment, so this repository
provides two deployment methods:

| Method | Use when | Guide |
| --- | --- | --- |
| SFTP deployment script | You can enable SFTP and run the repository scripts | [Deploy with the script](deploy-onecom-script.md) |
| Manual browser upload | You want to use only the one.com file manager and wp-admin | [Deploy manually](deploy-onecom-manual.md) |

The script is the recommended method because it can install WordPress on the first run and mirror
later theme and plugin updates. The manual method packages the same theme and plugins as WordPress
ZIP archives and uploads them through wp-admin.

Both methods deploy only:

- `wp-content/themes/duaais/`
- `wp-content/plugins/duaais-anniversary/` (optional; activation controls the homepage flyer)
- `wp-content/plugins/duaais-members/`
- `wp-content/plugins/duaais-setup/`
- `wp-content/plugins/duaais-smtp/`

Choose one guide and follow it through the initial installation. Both guides also cover later
updates, HTTPS, SMTP, certificate protection, subdirectory installations, and troubleshooting.
