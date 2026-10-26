############################################
# Amazon S3 — fotos de perfil
#
# - Bucket privado: Block Public Access completo e ACLs desligadas (BucketOwnerEnforced);
# - criptografia SSE-S3 e TLS obrigatorio (policy nega acesso sem HTTPS);
# - a aplicacao le/grava so o prefixo avatars/ usando a IAM Role da EC2 (sem chave estatica);
# - o navegador recebe apenas URLs assinadas de curta duracao (presigned GET).
############################################

data "aws_caller_identity" "current" {}

locals {
  # Nome de bucket e global na AWS: o ID da conta evita colisao.
  avatar_bucket_name = "${var.project_name}-avatars-${data.aws_caller_identity.current.account_id}"
}

resource "aws_s3_bucket" "avatars" {
  bucket = local.avatar_bucket_name

  tags = {
    Name        = local.avatar_bucket_name
    Environment = "academic"
    ManagedBy   = "Terraform"
  }
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

  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "avatars" {
  bucket = aws_s3_bucket.avatars.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "avatars" {
  bucket = aws_s3_bucket.avatars.id

  rule {
    id     = "abort-incomplete-uploads"
    status = "Enabled"

    filter {}

    abort_incomplete_multipart_upload {
      days_after_initiation = 1
    }
  }
}

data "aws_iam_policy_document" "avatars_bucket" {
  statement {
    sid     = "DenyInsecureTransport"
    effect  = "Deny"
    actions = ["s3:*"]
    resources = [
      aws_s3_bucket.avatars.arn,
      "${aws_s3_bucket.avatars.arn}/*",
    ]

    principals {
      type        = "*"
      identifiers = ["*"]
    }

    condition {
      test     = "Bool"
      variable = "aws:SecureTransport"
      values   = ["false"]
    }
  }
}

resource "aws_s3_bucket_policy" "avatars" {
  bucket = aws_s3_bucket.avatars.id
  policy = data.aws_iam_policy_document.avatars_bucket.json

  depends_on = [aws_s3_bucket_public_access_block.avatars]
}

# Permissao minima da EC2: so objetos em avatars/ (sem listar, sem mexer em ACL/policy do bucket).
data "aws_iam_policy_document" "avatars_rw" {
  statement {
    sid       = "ProfilePhotoObjects"
    actions   = ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"]
    resources = ["${aws_s3_bucket.avatars.arn}/avatars/*"]
  }
}

resource "aws_iam_role_policy" "avatars_rw" {
  name   = "s3-profile-photos"
  role   = aws_iam_role.app.id
  policy = data.aws_iam_policy_document.avatars_rw.json
}
