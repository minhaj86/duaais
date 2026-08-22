resource "azurerm_container_registry" "main" {
  name                = substr("cr${local.compact_name}${random_string.suffix.result}", 0, 50)
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name
  sku                 = "Basic"
  admin_enabled       = false
  tags                = local.tags
}

# A user-assigned identity is used so the pull permission exists before the container app
# is created; a system-assigned identity would only exist after the first deployment.
resource "azurerm_user_assigned_identity" "app" {
  name                = "id-${local.base_name}-wp"
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name
  tags                = local.tags
}

resource "azurerm_role_assignment" "acr_pull" {
  scope                = azurerm_container_registry.main.id
  role_definition_name = "AcrPull"
  principal_id         = azurerm_user_assigned_identity.app.principal_id
}

# Role assignments need a moment to propagate before the first image pull is attempted.
resource "time_sleep" "acr_pull_propagation" {
  depends_on      = [azurerm_role_assignment.acr_pull]
  create_duration = "60s"
}
