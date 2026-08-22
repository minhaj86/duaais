# DUAAIS Sweden WordPress website

A containerized English-language WordPress website for Dhaka University Alumni Association in Sweden. It serves people who studied at University of Dhaka and now reside in Sweden. The site includes blogging, front-end member registration and login, DU alumni profiles, and GDPR-oriented consent handling.

## Content synchronization

Public association content was synchronized from [duaais.com](https://www.duaais.com/) on August 16, 2026:

- Aim and Goals
- 2026 Calendar of Activities
- Executive Committee and its publicly listed contact details
- Swedish and Bengali constitutions
- Important links and Bangla newspaper links
- Official association and publisher contact details
- Legacy DU campus thumbnails and the DUAAIS logo

The legacy `members.xls` spreadsheet was intentionally not imported or republished. Member information belongs in the authenticated WordPress membership system rather than a public download.

## Local setup

Requirements: Docker with Docker Compose.

```sh
cp .env.example .env
docker compose up -d
docker compose run --rm wpcli sh /scripts/bootstrap.sh
```

Open [http://localhost:8080](http://localhost:8080). The WordPress dashboard is at [http://localhost:8080/wp-admin/](http://localhost:8080/wp-admin/).

The example administrator is `admin` with password `change-this-local-password`. Change these values in `.env` before the first bootstrap whenever the site will be reachable by anyone else.

## Content management

- Publish and edit blog posts under **Posts** in WordPress.
- Manage pages and navigation through the standard WordPress dashboard.
- Re-create the DUAAIS pages, posts, menus, and site settings at any time from **Tools → DUAAIS
  setup**, which runs the same idempotent seeder as `scripts/bootstrap.sh` without needing WP-CLI.
- University of Dhaka alumni apply through **Join DUAAIS** and manage their contact details, DU subject, attested hall, examination year, residence status in Sweden, and certificate copy on **My Account**.
- Member fields are stored as WordPress user metadata; no additional database tables are used.

## Membership applications and approval

The **Join DUAAIS** form mirrors the official DUAAIS application form: name, address, postal code,
city, mobile phone, work telephone, email, subject, attested hall, examination year, residence
status in Sweden, reference in Sweden, and a mandatory copy of the DU certificate. The declaration
checkbox acts as the electronic signature and is stored together with the application date.

1. The applicant submits the form and receives the `Pending Alumni Member` role. Login is blocked
   until the board makes a decision, and the applicant gets a confirmation email.
2. The address in **Settings → General → Administration Email Address** receives an email with the
   full application and a link to the review screen. Use the `duaais_members_admin_email` filter to
   send the notification somewhere else.
3. The board reviews applications under **Users → Membership applications**, opens the attached
   certificate, and approves or rejects each application. The **Membership** column on the users
   list shows the status of every account.
4. Approval assigns the `Alumni Member` role and emails the member that they can log in. Rejection
   removes all roles, keeps the account locked, and emails the applicant.

Certificates are stored outside the media library in `wp-content/uploads/duaais-certificates/`,
which denies direct web access through an `.htaccess` file. Only users with the `edit_users`
capability can open a certificate, through a nonce-protected download in wp-admin. Deleting a user
also deletes their certificate file. On a non-Apache web server, block that directory in the server
configuration as well.

The certificate upload is capped at 8 MB, which requires the PHP limits in
[`scripts/php-uploads.ini`](scripts/php-uploads.ini); the file is baked into the deployed image and
mounted into the local `wordpress` container. When the server allows less, the form and the error
messages fall back to the real limit.

The WordPress registration screen at `wp-login.php?action=register` stays disabled, because every
membership has to go through the reviewed application form. Any account created outside that form
gets the `Pending Alumni Member` role and still needs approval.

Approval and rejection emails depend on working outbound mail. Configure SMTP before going live so
that applicants and the board are notified.

## Deploying to one.com

one.com is shared hosting without Docker, Terraform, or SSH on the Beginner and Explorer plans, so
WordPress is installed through the Control Panel and this repository supplies the theme and plugins.

```sh
cp .env.onecom.example .env.onecom
# fill in the SFTP details from the one.com Control Panel
./scripts/deploy-onecom.sh
```

Then activate the theme and both plugins in wp-admin and run **Tools → DUAAIS setup**, which
executes the same content seeder that WP-CLI runs locally. See
[`docs/deploy-onecom.md`](docs/deploy-onecom.md) for the database, PHP version, HTTPS, permalink,
and SMTP steps.

## Deploying to Azure

Terraform under [`infra/terraform`](infra/terraform) deploys the site to Azure Container Apps with
Azure Database for MySQL flexible server, an Azure file share for uploaded media, and a container
registry holding an image with the theme and plugin baked in.

```sh
cd infra/terraform
cp terraform.tfvars.example terraform.tfvars
terraform init
terraform apply
terraform output site_url
```

See [`infra/terraform/README.md`](infra/terraform/README.md) for the full resource list, required
permissions, and operating notes.

## Useful commands

```sh
# Start the site
docker compose up -d

# Re-run the idempotent content bootstrap
docker compose run --rm wpcli sh /scripts/bootstrap.sh

# View service status
docker compose ps

# Stop services without deleting data
docker compose down

# Delete all local WordPress and database data
docker compose down -v
```

For a public deployment, use HTTPS, configure transactional email/SMTP for password resets and membership approval notifications, verify the synchronized public contact details, set strong database/admin credentials, disable `WORDPRESS_DEBUG`, and establish backups and WordPress update monitoring. The Terraform configuration in `infra/terraform` covers HTTPS, credentials, debug settings, and database backups; transactional email still has to be configured.