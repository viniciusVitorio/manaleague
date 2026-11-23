# Separate, opt-in storage for per-release deploy inputs. Enabling this creates
# paid AWS resources and requires explicit operator approval.
variable "enable_release_artifacts" {
  type    = bool
  default = false
}

locals {
  release_artifact_prefix      = "releases/"
  release_artifact_bucket_name = "${local.name}-releases-${var.expected_account_id}-${var.aws_region}"
  # release_source has no GitHub Environment, so its OIDC sub identifies the branch.
  # The separate publish job uses the eks-lab Environment and its own deploy role.
  release_publisher_subject = "repo:${split(":", var.github_oidc_subject)[1]}:ref:refs/heads/eks-lab"
  release_object_arn        = "arn:aws:s3:::${local.release_artifact_bucket_name}/${local.release_artifact_prefix}*"
  release_reader_policy     = { Version = "2012-10-17", Statement = [{ Effect = "Allow", Action = "s3:GetObject", Resource = local.release_object_arn }] }
}

resource "aws_s3_bucket" "release_artifacts" {
  count         = var.enable_release_artifacts ? 1 : 0
  bucket        = local.release_artifact_bucket_name
  force_destroy = false
  lifecycle { prevent_destroy = true }
}

resource "aws_s3_bucket_public_access_block" "release_artifacts" {
  count                   = var.enable_release_artifacts ? 1 : 0
  bucket                  = aws_s3_bucket.release_artifacts[0].id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "release_artifacts" {
  count  = var.enable_release_artifacts ? 1 : 0
  bucket = aws_s3_bucket.release_artifacts[0].id
  rule {
    apply_server_side_encryption_by_default { sse_algorithm = "AES256" }
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "release_artifacts" {
  count  = var.enable_release_artifacts ? 1 : 0
  bucket = aws_s3_bucket.release_artifacts[0].id
  rule {
    id     = "expire-releases"
    status = "Enabled"
    filter { prefix = local.release_artifact_prefix }
    expiration { days = 30 }
    abort_incomplete_multipart_upload { days_after_initiation = 7 }
  }
}

resource "aws_s3_bucket_policy" "release_artifacts" {
  count  = var.enable_release_artifacts ? 1 : 0
  bucket = aws_s3_bucket.release_artifacts[0].id
  policy = jsonencode({ Version = "2012-10-17", Statement = [{
    Effect = "Deny", Principal = "*", Action = "s3:*", Resource = ["arn:aws:s3:::${local.release_artifact_bucket_name}", "arn:aws:s3:::${local.release_artifact_bucket_name}/*"], Condition = { Bool = { "aws:SecureTransport" = "false" } }
  }] })
}

resource "aws_iam_role" "release_publisher" {
  count = var.enable_release_artifacts ? 1 : 0
  name  = "${local.name}-release-publisher"
  assume_role_policy = jsonencode({ Version = "2012-10-17", Statement = [{
    Effect = "Allow", Principal = { Federated = var.github_oidc_provider_arn }, Action = "sts:AssumeRoleWithWebIdentity", Condition = { StringEquals = { "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com", "token.actions.githubusercontent.com:sub" = local.release_publisher_subject } }
  }] })
}

resource "aws_iam_role_policy" "release_publisher" {
  count = var.enable_release_artifacts ? 1 : 0
  role  = aws_iam_role.release_publisher[0].id
  policy = jsonencode({ Version = "2012-10-17", Statement = [{
    Effect = "Allow", Action = "s3:PutObject", Resource = local.release_object_arn
  }] })
}

resource "aws_iam_role_policy" "deploy_release_reader" {
  count  = var.enable_release_artifacts && var.enable_deploy_broker ? 1 : 0
  role   = aws_iam_role.deploy_build[0].id
  policy = jsonencode(local.release_reader_policy)
}

output "release_artifact_bucket" {
  value = try(aws_s3_bucket.release_artifacts[0].id, null)
}
output "release_artifact_prefix" {
  value = var.enable_release_artifacts ? local.release_artifact_prefix : null
}
output "release_publisher_role_arn" {
  value = try(aws_iam_role.release_publisher[0].arn, null)
}
