variable "subscription_id" {
  description = "Azure subscription ID to deploy into. Defaults to the ARM_SUBSCRIPTION_ID environment variable when left empty."
  type        = string
  default     = null
}

variable "location" {
  description = "Azure region for all resources."
  type        = string
  default     = "swedencentral"
}

variable "name_prefix" {
  description = "Short lowercase prefix used to build resource names."
  type        = string
  default     = "duaais"

  validation {
    condition     = can(regex("^[a-z][a-z0-9]{2,11}$", var.name_prefix))
    error_message = "name_prefix must be 3-12 characters, lowercase letters and digits, starting with a letter."
  }
}

variable "environment" {
  description = "Environment name used in resource names and tags."
  type        = string
  default     = "prod"

  validation {
    condition     = can(regex("^[a-z0-9]{2,8}$", var.environment))
    error_message = "environment must be 2-8 lowercase alphanumeric characters."
  }
}

variable "resource_group_name" {
  description = "Optional explicit resource group name. Generated from the prefix when empty."
  type        = string
  default     = ""
}

variable "tags" {
  description = "Additional tags applied to every resource."
  type        = map(string)
  default     = {}
}

# --------------------------------------------------------------------------------------
# Site
# --------------------------------------------------------------------------------------

variable "site_title" {
  description = "WordPress site title used by the content bootstrap."
  type        = string
  default     = "DUAAIS Sweden"
}

variable "custom_domain" {
  description = "Optional custom domain (for example www.duaais.com). When set, WP_HOME/WP_SITEURL use it instead of the generated Container Apps hostname. Bind the domain and certificate separately."
  type        = string
  default     = ""
}

variable "wp_admin_user" {
  description = "WordPress administrator login created by the bootstrap script."
  type        = string
  default     = "duaais-admin"
}

variable "wp_admin_email" {
  description = "WordPress administrator email address."
  type        = string
}

variable "wp_admin_password" {
  description = "WordPress administrator password. A strong password is generated when left empty."
  type        = string
  default     = ""
  sensitive   = true
}

variable "wordpress_debug" {
  description = "Value of WORDPRESS_DEBUG in the deployed container. Keep 0 for public environments."
  type        = string
  default     = "0"
}

variable "run_bootstrap_on_start" {
  description = "Run the idempotent scripts/bootstrap.sh content bootstrap when a container starts. It installs WordPress, activates the theme and plugins, and seeds the association pages."
  type        = bool
  default     = true
}

# --------------------------------------------------------------------------------------
# Container image
# --------------------------------------------------------------------------------------

variable "container_image_name" {
  description = "Repository name of the WordPress image inside the container registry."
  type        = string
  default     = "duaais-wordpress"
}

variable "container_image_tag" {
  description = "Tag of the WordPress image to run. Leave empty to derive the tag from a hash of the Dockerfile, theme, plugin and scripts."
  type        = string
  default     = ""
}

variable "build_image_on_apply" {
  description = "Build and push the WordPress image with ACR Tasks during apply. Requires a logged-in Azure CLI. Disable when an external pipeline publishes the image."
  type        = bool
  default     = true
}

variable "disallow_file_mods" {
  description = "Set DISALLOW_FILE_MODS in WordPress. The webroot is rebuilt from the image on every restart, so dashboard-installed plugins and themes would not survive."
  type        = bool
  default     = true
}

variable "container_cpu" {
  description = "vCPU allocated to the WordPress container."
  type        = number
  default     = 1.0
}

variable "container_memory" {
  description = "Memory allocated to the WordPress container. Must pair with container_cpu (1.0 vCPU maps to 2Gi)."
  type        = string
  default     = "2Gi"
}

variable "min_replicas" {
  description = "Minimum container app replicas. Keep at 1 so the site never cold starts."
  type        = number
  default     = 1
}

variable "max_replicas" {
  description = "Maximum container app replicas. WordPress needs shared wp-content and a session-safe setup above 1."
  type        = number
  default     = 1
}

# --------------------------------------------------------------------------------------
# Database
# --------------------------------------------------------------------------------------

variable "mysql_sku_name" {
  description = "SKU of the MySQL flexible server."
  type        = string
  default     = "B_Standard_B1ms"
}

variable "mysql_version" {
  description = "MySQL engine version."
  type        = string
  default     = "8.0.21"
}

variable "mysql_storage_gb" {
  description = "Storage size of the MySQL flexible server in GB."
  type        = number
  default     = 32
}

variable "mysql_backup_retention_days" {
  description = "Number of days MySQL backups are retained."
  type        = number
  default     = 14
}

variable "mysql_admin_username" {
  description = "MySQL administrator login. WordPress connects with this account."
  type        = string
  default     = "wpadmin"
}

variable "mysql_admin_password" {
  description = "MySQL administrator password. A strong password is generated when left empty."
  type        = string
  default     = ""
  sensitive   = true
}

variable "mysql_database_name" {
  description = "Name of the WordPress database."
  type        = string
  default     = "wordpress"
}

# --------------------------------------------------------------------------------------
# Storage and networking
# --------------------------------------------------------------------------------------

variable "uploads_share_quota_gb" {
  description = "Quota of the Azure file share that backs wp-content/uploads."
  type        = number
  default     = 100
}

variable "uploads_mount_options" {
  description = "CIFS mount options for the uploads share. The defaults give the Apache user (uid/gid 33) ownership of uploaded media."
  type        = string
  default     = "uid=33,gid=33,dir_mode=0755,file_mode=0644"
}

variable "storage_account_replication_type" {
  description = "Replication type of the storage account holding the uploads share."
  type        = string
  default     = "ZRS"
}

variable "vnet_address_space" {
  description = "Address space of the virtual network."
  type        = string
  default     = "10.20.0.0/16"
}

variable "container_apps_subnet_prefix" {
  description = "Address prefix of the Container Apps infrastructure subnet. Must be /23 or larger."
  type        = string
  default     = "10.20.0.0/23"
}

variable "mysql_subnet_prefix" {
  description = "Address prefix of the subnet delegated to MySQL flexible server."
  type        = string
  default     = "10.20.2.0/24"
}
