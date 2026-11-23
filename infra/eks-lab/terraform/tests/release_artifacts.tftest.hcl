mock_provider "aws" {
  mock_data "aws_availability_zones" { defaults = { names = ["us-east-1a", "us-east-1b"] } }
  mock_data "aws_caller_identity" { defaults = { account_id = "123456789012" } }
  mock_data "aws_eks_addon_version" { defaults = { version = "v1.0.0-eksbuild.1" } }
  mock_resource "aws_eks_cluster" { defaults = { identity = [{ oidc = [{ issuer = "https://oidc.eks.us-east-1.amazonaws.com/id/LAB" }] }] } }
  mock_resource "aws_launch_template" { defaults = { id = "lt-1234567890abcdef0" } }
  mock_resource "aws_s3_bucket" { defaults = { id = "manaleague-eks-lab-releases-123456789012-us-east-1" } }
  mock_resource "aws_iam_policy" { defaults = { arn = "arn:aws:iam::123456789012:policy/mock" } }
  mock_resource "aws_iam_role" { defaults = { arn = "arn:aws:iam::123456789012:role/mock" } }
  mock_resource "aws_eip" { defaults = { public_ip = "203.0.113.1" } }
  mock_resource "aws_db_instance" { defaults = { master_user_secret = [{ secret_arn = "arn:aws:secretsmanager:us-east-1:123456789012:secret:mock" }] } }
}
variables {
  expected_account_id       = "123456789012"
  provisioner_principal_arn = "arn:aws:iam::123456789012:role/lab-operator"
  github_oidc_provider_arn  = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
  github_oidc_subject       = "repo:example/repo:environment:eks-lab"
  api_public_access_cidrs   = ["203.0.113.10/32"]
  allowed_client_cidrs      = ["198.51.100.10/32"]
  enable_lab_https          = false
  lab_dns_zone_id           = ""
  enable_deploy_broker      = false
  enable_release_artifacts  = false
}
run "release_disabled" {
  command = plan
  assert {
    condition     = length(aws_s3_bucket.release_artifacts) == 0 && output.release_artifact_bucket == null
    error_message = "Release storage must be opt-in."
  }
}

run "release_enabled_security" {
  command = apply
  variables { enable_release_artifacts = true }
  assert {
    condition     = aws_s3_bucket.release_artifacts[0].bucket == "manaleague-eks-lab-releases-123456789012-us-east-1" && !aws_s3_bucket.release_artifacts[0].force_destroy && output.release_artifact_prefix == "releases/"
    error_message = "Dedicated bucket and fixed release prefix required; never force-delete."
  }
  assert {
    condition     = aws_s3_bucket_public_access_block.release_artifacts[0].block_public_acls && aws_s3_bucket_public_access_block.release_artifacts[0].block_public_policy && aws_s3_bucket_public_access_block.release_artifacts[0].ignore_public_acls && aws_s3_bucket_public_access_block.release_artifacts[0].restrict_public_buckets
    error_message = "All public access controls must be enabled."
  }
  assert {
    condition     = one(one(aws_s3_bucket_server_side_encryption_configuration.release_artifacts[0].rule).apply_server_side_encryption_by_default).sse_algorithm == "AES256" && aws_s3_bucket_lifecycle_configuration.release_artifacts[0].rule[0].expiration[0].days == 30
    error_message = "SSE-S3 and 30-day expiration required."
  }
  assert {
    condition     = jsondecode(aws_iam_role.release_publisher[0].assume_role_policy).Statement[0].Condition.StringEquals == { "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com", "token.actions.githubusercontent.com:sub" = "repo:example/repo:ref:refs/heads/eks-lab" } && jsondecode(aws_iam_role.release_publisher[0].assume_role_policy).Statement[0].Principal.Federated == var.github_oidc_provider_arn
    error_message = "Release-source trust must match its exact repository, branch and audience."
  }
  assert {
    condition     = !strcontains(split("  # Experimental", split("  release_source:", file("${path.module}/../../../.github/workflows/eks-lab.yml"))[1])[0], "    environment:") && strcontains(split("  # Experimental", split("  release_source:", file("${path.module}/../../../.github/workflows/eks-lab.yml"))[1])[0], "role/manaleague-eks-lab-release-publisher")
    error_message = "Release-source job must use the branch-subject publisher role without an Environment; re-evaluate IAM trust if this changes."
  }
  assert {
    condition     = local.release_reader_policy.Statement == [{ Effect = "Allow", Action = "s3:GetObject", Resource = "arn:aws:s3:::manaleague-eks-lab-releases-123456789012-us-east-1/releases/*" }]
    error_message = "Build reader must be limited to objects in the same release bucket and prefix."
  }
  assert {
    condition     = output.release_artifact_bucket == aws_s3_bucket.release_artifacts[0].id && local.deploy_runtime_environment.RELEASE_ARTIFACT_BUCKET == output.release_artifact_bucket && local.deploy_runtime_environment.RELEASE_ARTIFACT_PREFIX == output.release_artifact_prefix && output.release_publisher_role_arn == aws_iam_role.release_publisher[0].arn
    error_message = "Outputs and build runtime must point to the created release resources."
  }
  assert {
    condition     = jsondecode(aws_iam_role_policy.release_publisher[0].policy).Statement == [{ Effect = "Allow", Action = "s3:PutObject", Resource = "arn:aws:s3:::manaleague-eks-lab-releases-123456789012-us-east-1/releases/*" }]
    error_message = "Publisher may only put release objects."
  }
  assert {
    condition     = length(aws_iam_role_policy.deploy_release_reader) == 0 && local.release_object_arn == "arn:aws:s3:::manaleague-eks-lab-releases-123456789012-us-east-1/releases/*" && local.deploy_runtime_environment.RELEASE_ARTIFACT_PREFIX == "releases/"
    error_message = "Reader remains disabled with broker and runtime gets the fixed prefix."
  }
  assert {
    condition     = jsondecode(aws_s3_bucket_policy.release_artifacts[0].policy).Statement[0].Condition.Bool["aws:SecureTransport"] == "false"
    error_message = "Plain HTTP must be denied."
  }
}
