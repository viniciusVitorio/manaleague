variable "enable_deploy_runtime_registry" {
  type    = bool
  default = false
}

resource "aws_ecr_repository" "deploy_runtime" {
  count                = var.enable_deploy_runtime_registry ? 1 : 0
  name                 = "${local.name}-deploy-runtime"
  image_tag_mutability = "IMMUTABLE"
  force_delete         = false
  image_scanning_configuration { scan_on_push = true }
  encryption_configuration { encryption_type = "AES256" }
}

resource "aws_ecr_lifecycle_policy" "deploy_runtime" {
  count      = var.enable_deploy_runtime_registry ? 1 : 0
  repository = aws_ecr_repository.deploy_runtime[0].name
  policy = jsonencode({ rules = [
    { rulePriority = 1, description = "Expire untagged images after one day", selection = { tagStatus = "untagged", countType = "sinceImagePushed", countUnit = "days", countNumber = 1 }, action = { type = "expire" } }
  ] })
}

output "deploy_runtime_repository_url" {
  value = var.enable_deploy_runtime_registry ? aws_ecr_repository.deploy_runtime[0].repository_url : null
}
