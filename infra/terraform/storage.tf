resource "azurerm_storage_account" "main" {
  name                = substr("st${local.compact_name}${random_string.suffix.result}", 0, 24)
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name

  account_tier             = "Standard"
  account_replication_type = var.storage_account_replication_type
  account_kind             = "StorageV2"

  https_traffic_only_enabled      = true
  min_tls_version                 = "TLS1_2"
  public_network_access_enabled   = true
  allow_nested_items_to_be_public = false

  # Container Apps mounts Azure file shares with the storage account key.
  shared_access_key_enabled = true

  tags = local.tags
}

resource "azurerm_storage_share" "uploads" {
  name               = "wordpress-uploads"
  storage_account_id = azurerm_storage_account.main.id
  quota              = var.uploads_share_quota_gb
}
