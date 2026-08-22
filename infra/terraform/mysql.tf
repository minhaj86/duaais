resource "azurerm_mysql_flexible_server" "main" {
  name                = "mysql-${local.base_name}-${random_string.suffix.result}"
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name

  administrator_login    = var.mysql_admin_username
  administrator_password = local.mysql_admin_password
  sku_name               = var.mysql_sku_name
  version                = var.mysql_version
  backup_retention_days  = var.mysql_backup_retention_days

  delegated_subnet_id = azurerm_subnet.mysql.id
  private_dns_zone_id = azurerm_private_dns_zone.mysql.id

  storage {
    size_gb           = var.mysql_storage_gb
    auto_grow_enabled = true
  }

  tags = local.tags

  depends_on = [azurerm_private_dns_zone_virtual_network_link.mysql]

  lifecycle {
    ignore_changes = [zone]
  }
}

resource "azurerm_mysql_flexible_database" "wordpress" {
  name                = var.mysql_database_name
  resource_group_name = azurerm_resource_group.main.name
  server_name         = azurerm_mysql_flexible_server.main.name
  charset             = "utf8mb4"
  collation           = "utf8mb4_unicode_ci"
}

# WordPress connects over TLS; the container trusts the public CA bundle shipped in the image.
resource "azurerm_mysql_flexible_server_configuration" "require_secure_transport" {
  name                = "require_secure_transport"
  resource_group_name = azurerm_resource_group.main.name
  server_name         = azurerm_mysql_flexible_server.main.name
  value               = "ON"
}
