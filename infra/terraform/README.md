# Azure deployment

Terraform in this directory deploys the DUAAIS Sweden WordPress site to Azure Container Apps.

## What is created

| Resource | Purpose |
| --- | --- |
| Container Apps environment | Runs the site, VNet integrated, HTTPS ingress with a managed certificate |
| Container app | WordPress 6.8.2 on PHP 8.3 with the `duaais` theme and the `duaais-members` and `duaais-setup` plugins |
| Container registry | Holds the image built from the repository `Dockerfile` |
| MySQL flexible server | WordPress database, private access only, TLS required |
| Storage account and file share | Persists `wp-content/uploads` across restarts and revisions |
| Virtual network | One subnet for the Container Apps environment, one delegated to MySQL |
| Private DNS zone | Resolves the MySQL private endpoint from inside the VNet |
| Log Analytics workspace | Container logs and console output |
| User-assigned managed identity | Pulls the image from the registry without registry credentials |

The database is never exposed publicly, the registry admin account stays disabled, and the
database password, WordPress administrator password and the eight WordPress salts are generated
by Terraform and stored as container app secrets.

## Prerequisites

- Terraform 1.6 or newer
- Azure CLI, logged in with `az login` and pointed at the target subscription
- Contributor and User Access Administrator (or Owner) on the subscription, because the
  deployment creates an `AcrPull` role assignment

## Deploy

```sh
cd infra/terraform
cp terraform.tfvars.example terraform.tfvars   # set wp_admin_email at minimum
terraform init
terraform apply
```

A single apply is enough. Terraform builds the container image with ACR Tasks (`az acr build`),
creates the infrastructure, and the container bootstraps WordPress on first start: it installs
core, activates the theme and plugins, and seeds the association pages using `scripts/bootstrap.sh`.

Afterwards:

```sh
terraform output site_url
terraform output -raw wp_admin_password
```

The first revision needs a few minutes because the MySQL server and the environment are created
before the site starts.

## Updating the site

Any change to `Dockerfile`, `wp-content/**` or `scripts/**` changes the derived image tag, so
`terraform apply` rebuilds the image and rolls out a new revision.

To publish images from a pipeline instead, set `build_image_on_apply = false` and pass an explicit
`container_image_tag`.

## Operating notes

- **The webroot is immutable.** `/var/www/html` is rebuilt from the image on every start, so only
  `wp-content/uploads` survives restarts. Plugins and themes belong in this repository and ship
  through a new image. `DISALLOW_FILE_MODS` is enabled to make that explicit; set
  `disallow_file_mods = false` if you accept that dashboard-installed plugins disappear on restart.
- **One replica.** `max_replicas` defaults to 1. Raising it requires shared `wp-content`, an object
  cache and a session-safe login setup.
- **Email.** WordPress cannot send mail out of the box on Azure. Configure an SMTP plugin or relay
  before relying on password resets or registration notifications.
- **Custom domain.** Set `custom_domain` after binding the hostname and certificate to the
  container app, so `WP_HOME` and `WP_SITEURL` match what visitors use.
- **Backups.** MySQL keeps `mysql_backup_retention_days` of automatic backups. The uploads share is
  covered by storage account replication only; add Azure Backup for file shares if you need
  point-in-time recovery of media.

## Useful commands

```sh
# Follow container logs
az containerapp logs show --name "$(terraform output -raw container_app_name)" \
  --resource-group "$(terraform output -raw resource_group_name)" --follow

# Re-run the idempotent content bootstrap by hand
eval "$(terraform output -raw bootstrap_command)"

# Open a shell in the running container
az containerapp exec --name "$(terraform output -raw container_app_name)" \
  --resource-group "$(terraform output -raw resource_group_name)" --command /bin/bash

# Remove everything
terraform destroy
```

## State

State is local by default. For shared use, add a backend, for example:

```hcl
terraform {
  backend "azurerm" {
    resource_group_name  = "rg-tfstate"
    storage_account_name = "sttfstateduaais"
    container_name       = "tfstate"
    key                  = "duaais-prod.tfstate"
  }
}
```

Terraform state contains the generated passwords and the storage account key, so keep it private.
