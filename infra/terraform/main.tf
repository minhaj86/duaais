locals {
  base_name    = "${var.name_prefix}-${var.environment}"
  compact_name = "${var.name_prefix}${var.environment}"

  resource_group_name = var.resource_group_name != "" ? var.resource_group_name : "rg-${local.base_name}"
  app_name            = "ca-${local.base_name}-wp"

  tags = merge(
    {
      application = "duaais-wordpress"
      environment = var.environment
      managed_by  = "terraform"
    },
    var.tags,
  )

  mysql_admin_password = var.mysql_admin_password != "" ? var.mysql_admin_password : random_password.mysql_admin.result
  wp_admin_password    = var.wp_admin_password != "" ? var.wp_admin_password : random_password.wp_admin.result

  site_host = var.custom_domain != "" ? var.custom_domain : "${local.app_name}.${azurerm_container_app_environment.main.default_domain}"
  site_url  = "https://${local.site_host}"

  wordpress_config_extra = <<-PHP
    define( 'MYSQL_CLIENT_FLAGS', MYSQLI_CLIENT_SSL );
    if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
    	$_SERVER['HTTPS'] = 'on';
    }
    define( 'WP_HOME', '${local.site_url}' );
    define( 'WP_SITEURL', '${local.site_url}' );
    define( 'DISALLOW_FILE_EDIT', true );
    define( 'DISALLOW_FILE_MODS', ${var.disallow_file_mods ? "true" : "false"} );
    define( 'FS_METHOD', 'direct' );
    define( 'WP_DEBUG_LOG', false );
    define( 'WP_DEBUG_DISPLAY', false );
    @ini_set( 'display_errors', '0' );
  PHP

  # WordPress salts are generated here so container restarts do not invalidate sessions.
  salt_names = [
    "AUTH_KEY",
    "SECURE_AUTH_KEY",
    "LOGGED_IN_KEY",
    "NONCE_KEY",
    "AUTH_SALT",
    "SECURE_AUTH_SALT",
    "LOGGED_IN_SALT",
    "NONCE_SALT",
  ]
}

resource "random_string" "suffix" {
  length  = 6
  lower   = true
  upper   = false
  numeric = true
  special = false
}

resource "random_password" "mysql_admin" {
  length           = 28
  min_lower        = 2
  min_upper        = 2
  min_numeric      = 2
  min_special      = 2
  override_special = "!#%*-_=+"
}

resource "random_password" "wp_admin" {
  length           = 24
  min_lower        = 2
  min_upper        = 2
  min_numeric      = 2
  min_special      = 2
  override_special = "!#%*-_=+"
}

resource "random_password" "salt" {
  for_each = toset(local.salt_names)

  length  = 64
  special = true
  # Kept free of quotes, backslashes and dollar signs so the values stay safe inside wp-config.php.
  override_special = "!#%()*+,-./:;<=>?@[]^_{|}~"
}

resource "azurerm_resource_group" "main" {
  name     = local.resource_group_name
  location = var.location
  tags     = local.tags
}
