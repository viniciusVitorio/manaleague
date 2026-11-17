resource "aws_ecr_repository" "images" {
  for_each             = toset(["app", "web"])
  name                 = "${local.name}-${each.key}"
  image_tag_mutability = "IMMUTABLE"
  force_delete         = false
  image_scanning_configuration { scan_on_push = true }
  encryption_configuration { encryption_type = "AES256" }
}
resource "aws_ecr_lifecycle_policy" "images" {
  for_each   = aws_ecr_repository.images
  repository = each.value.name
  policy = jsonencode({ rules = [
    { rulePriority = 1, description = "Expire untagged images after one day", selection = { tagStatus = "untagged", countType = "sinceImagePushed", countUnit = "days", countNumber = 1 }, action = { type = "expire" } },
    { rulePriority = 2, description = "Retain at most 10 immutable tagged builds; running/rollback digests must be checked before cleanup", selection = { tagStatus = "tagged", tagPatternList = ["*"], countType = "imageCountMoreThan", countNumber = 10 }, action = { type = "expire" } }
  ] })
}
resource "aws_s3_bucket" "avatars" {
  bucket        = "${local.name}-${var.expected_account_id}-${var.aws_region}-avatars"
  force_destroy = false
}
resource "aws_s3_bucket_public_access_block" "avatars" {
  bucket                  = aws_s3_bucket.avatars.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}
resource "aws_s3_bucket_ownership_controls" "avatars" {
  bucket = aws_s3_bucket.avatars.id
  rule { object_ownership = "BucketOwnerEnforced" }
}
resource "aws_s3_bucket_server_side_encryption_configuration" "avatars" {
  bucket = aws_s3_bucket.avatars.id
  rule {
    apply_server_side_encryption_by_default { sse_algorithm = "AES256" }
  }
}
# Versioning intentionally remains disabled. No retained historical avatars or Object Lock.
resource "aws_s3_bucket_lifecycle_configuration" "avatars" {
  bucket = aws_s3_bucket.avatars.id
  rule {
    id     = "abort-incomplete-uploads"
    status = "Enabled"
    filter {}
    abort_incomplete_multipart_upload { days_after_initiation = 1 }
  }
}
resource "aws_s3_bucket_policy" "avatars" {
  bucket = aws_s3_bucket.avatars.id
  policy = jsonencode({ Version = "2012-10-17", Statement = [{
    Sid       = "DenyInsecureTransport", Effect = "Deny", Principal = "*", Action = "s3:*"
    Resource  = [aws_s3_bucket.avatars.arn, "${aws_s3_bucket.avatars.arn}/*"]
    Condition = { Bool = { "aws:SecureTransport" = "false" } }
  }] })
}
resource "aws_iam_role_policy" "app_avatars" {
  name = "${local.name}-avatars-prefix"
  role = aws_iam_role.irsa["app"].id
  policy = jsonencode({ Version = "2012-10-17", Statement = [
    { Effect = "Allow", Action = "s3:GetBucketLocation", Resource = aws_s3_bucket.avatars.arn },
    { Effect = "Allow", Action = ["s3:ListBucket"], Resource = aws_s3_bucket.avatars.arn, Condition = { StringLike = { "s3:prefix" = ["avatars", "avatars/*"] } } },
    { Effect = "Allow", Action = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject", "s3:AbortMultipartUpload", "s3:ListMultipartUploadParts"], Resource = "${aws_s3_bucket.avatars.arn}/avatars/*" }
  ] })
}
