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
- University of Dhaka alumni who register through **Join DUAAIS** receive the restricted `Alumni Member` role and manage their DU faculty/department, graduation year, field of study, and Swedish city on **My Account**.
- Member fields are stored as WordPress user metadata; no additional database tables are used.

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

For a public deployment, use HTTPS, configure transactional email/SMTP for password resets, verify the synchronized public contact details, set strong database/admin credentials, disable `WORDPRESS_DEBUG`, and establish backups and WordPress update monitoring.