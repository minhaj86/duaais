locals {
  source_root = abspath("${path.module}/../..")

  # Any change to the Dockerfile, theme, plugin or bootstrap scripts produces a new image tag,
  # which in turn triggers a new container app revision.
  image_source_files = sort(concat(
    ["${local.source_root}/Dockerfile"],
    [for f in fileset("${local.source_root}/wp-content", "**") : "${local.source_root}/wp-content/${f}"],
    [for f in fileset("${local.source_root}/scripts", "**") : "${local.source_root}/scripts/${f}"],
  ))

  image_source_hash = sha256(join("", [for f in local.image_source_files : filesha256(f)]))
  image_tag         = var.container_image_tag != "" ? var.container_image_tag : substr(local.image_source_hash, 0, 12)
  container_image   = "${azurerm_container_registry.main.login_server}/${var.container_image_name}:${local.image_tag}"

  acr_build_subscription = coalesce(var.subscription_id, "") != "" ? " --subscription ${var.subscription_id}" : ""
}

# Builds the WordPress image with ACR Tasks so that a single `terraform apply` produces a
# running site. Set build_image_on_apply = false to push the image through your own pipeline.
resource "terraform_data" "wordpress_image" {
  count = var.build_image_on_apply ? 1 : 0

  triggers_replace = {
    registry = azurerm_container_registry.main.name
    image    = "${var.container_image_name}:${local.image_tag}"
  }

  provisioner "local-exec" {
    working_dir = local.source_root
    command     = "az acr build --registry ${azurerm_container_registry.main.name}${local.acr_build_subscription} --image ${var.container_image_name}:${local.image_tag} --image ${var.container_image_name}:latest --platform linux/amd64 --file Dockerfile ."
  }
}
