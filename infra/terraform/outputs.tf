output "site_url" {
  description = "Public address of the WordPress site."
  value       = local.site_url
}

output "container_app_fqdn" {
  description = "Hostname assigned to the container app by Azure Container Apps."
  value       = azurerm_container_app.wordpress.ingress[0].fqdn
}

output "wordpress_admin_url" {
  description = "WordPress dashboard address."
  value       = "${local.site_url}/wp-admin/"
}

output "resource_group_name" {
  description = "Resource group holding every deployed resource."
  value       = azurerm_resource_group.main.name
}

output "container_app_name" {
  description = "Name of the container app running WordPress."
  value       = azurerm_container_app.wordpress.name
}

output "container_registry_name" {
  description = "Name of the container registry holding the WordPress image."
  value       = azurerm_container_registry.main.name
}

output "container_image" {
  description = "Fully qualified image reference deployed to the container app."
  value       = local.container_image
}

output "mysql_server_fqdn" {
  description = "Private FQDN of the MySQL flexible server."
  value       = azurerm_mysql_flexible_server.main.fqdn
}

output "uploads_share_name" {
  description = "Azure file share mounted at wp-content/uploads."
  value       = azurerm_storage_share.uploads.name
}

output "wp_admin_user" {
  description = "WordPress administrator login."
  value       = var.wp_admin_user
}

output "wp_admin_password" {
  description = "WordPress administrator password. Read it with: terraform output -raw wp_admin_password"
  value       = local.wp_admin_password
  sensitive   = true
}

output "mysql_admin_password" {
  description = "MySQL administrator password. Read it with: terraform output -raw mysql_admin_password"
  value       = local.mysql_admin_password
  sensitive   = true
}

output "bootstrap_command" {
  description = "Command that installs WordPress and seeds the DUAAIS content inside the running container."
  value       = "az containerapp exec --name ${azurerm_container_app.wordpress.name} --resource-group ${azurerm_resource_group.main.name} --command 'sh /scripts/bootstrap.sh'"
}
