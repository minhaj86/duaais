resource "azurerm_log_analytics_workspace" "main" {
  name                = "log-${local.base_name}"
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name
  sku                 = "PerGB2018"
  retention_in_days   = 30
  tags                = local.tags
}

resource "azurerm_container_app_environment" "main" {
  name                = "cae-${local.base_name}"
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name

  log_analytics_workspace_id     = azurerm_log_analytics_workspace.main.id
  infrastructure_subnet_id       = azurerm_subnet.container_apps.id
  internal_load_balancer_enabled = false

  workload_profile {
    name                  = "Consumption"
    workload_profile_type = "Consumption"
  }

  tags = local.tags
}

resource "azurerm_container_app_environment_storage" "uploads" {
  name                         = "wordpress-uploads"
  container_app_environment_id = azurerm_container_app_environment.main.id
  account_name                 = azurerm_storage_account.main.name
  share_name                   = azurerm_storage_share.uploads.name
  access_key                   = azurerm_storage_account.main.primary_access_key
  access_mode                  = "ReadWrite"
}

resource "azurerm_container_app" "wordpress" {
  name                         = local.app_name
  resource_group_name          = azurerm_resource_group.main.name
  container_app_environment_id = azurerm_container_app_environment.main.id
  revision_mode                = "Single"
  workload_profile_name        = "Consumption"
  tags                         = local.tags

  identity {
    type         = "UserAssigned"
    identity_ids = [azurerm_user_assigned_identity.app.id]
  }

  registry {
    server   = azurerm_container_registry.main.login_server
    identity = azurerm_user_assigned_identity.app.id
  }

  secret {
    name  = "mysql-password"
    value = local.mysql_admin_password
  }

  secret {
    name  = "wp-admin-password"
    value = local.wp_admin_password
  }

  dynamic "secret" {
    for_each = toset(local.salt_names)

    content {
      name  = lower(replace(secret.value, "_", "-"))
      value = random_password.salt[secret.value].result
    }
  }

  ingress {
    external_enabled           = true
    target_port                = 80
    transport                  = "auto"
    allow_insecure_connections = false

    traffic_weight {
      latest_revision = true
      percentage      = 100
    }
  }

  template {
    min_replicas = var.min_replicas
    max_replicas = var.max_replicas

    volume {
      name         = "uploads"
      storage_type = "AzureFile"
      storage_name = azurerm_container_app_environment_storage.uploads.name
      # www-data is uid/gid 33 in the Debian based WordPress image.
      mount_options = var.uploads_mount_options
    }

    container {
      name   = "wordpress"
      image  = local.container_image
      cpu    = var.container_cpu
      memory = var.container_memory

      volume_mounts {
        name = "uploads"
        path = "/var/www/html/wp-content/uploads"
      }

      env {
        name  = "WORDPRESS_DB_HOST"
        value = azurerm_mysql_flexible_server.main.fqdn
      }

      env {
        name  = "WORDPRESS_DB_NAME"
        value = azurerm_mysql_flexible_database.wordpress.name
      }

      env {
        name  = "WORDPRESS_DB_USER"
        value = var.mysql_admin_username
      }

      env {
        name        = "WORDPRESS_DB_PASSWORD"
        secret_name = "mysql-password"
      }

      env {
        name  = "WORDPRESS_TABLE_PREFIX"
        value = "wp_"
      }

      env {
        name  = "WORDPRESS_DEBUG"
        value = var.wordpress_debug
      }

      env {
        name  = "WORDPRESS_CONFIG_EXTRA"
        value = local.wordpress_config_extra
      }

      dynamic "env" {
        for_each = toset(local.salt_names)

        content {
          name        = "WORDPRESS_${env.value}"
          secret_name = lower(replace(env.value, "_", "-"))
        }
      }

      # Consumed by scripts/bootstrap.sh when it is run inside the container.
      env {
        name  = "SITE_URL"
        value = local.site_url
      }

      env {
        name  = "SITE_TITLE"
        value = var.site_title
      }

      env {
        name  = "WP_ADMIN_USER"
        value = var.wp_admin_user
      }

      env {
        name  = "WP_ADMIN_EMAIL"
        value = var.wp_admin_email
      }

      env {
        name        = "WP_ADMIN_PASSWORD"
        secret_name = "wp-admin-password"
      }

      env {
        name  = "DUAAIS_BOOTSTRAP"
        value = var.run_bootstrap_on_start ? "1" : "0"
      }

      startup_probe {
        transport               = "HTTP"
        port                    = 80
        path                    = "/wp-login.php"
        interval_seconds        = 10
        timeout                 = 5
        failure_count_threshold = 30
      }

      readiness_probe {
        transport               = "HTTP"
        port                    = 80
        path                    = "/wp-login.php"
        interval_seconds        = 15
        timeout                 = 5
        failure_count_threshold = 5
        success_count_threshold = 1
      }

      liveness_probe {
        transport               = "HTTP"
        port                    = 80
        path                    = "/wp-login.php"
        initial_delay           = 30
        interval_seconds        = 30
        timeout                 = 10
        failure_count_threshold = 5
      }
    }
  }

  depends_on = [
    time_sleep.acr_pull_propagation,
    azurerm_mysql_flexible_server_configuration.require_secure_transport,
    terraform_data.wordpress_image,
  ]
}
