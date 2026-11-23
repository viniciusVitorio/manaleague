mock_provider "aws" {
  mock_data "aws_availability_zones" { defaults = { names = ["us-east-1a", "us-east-1b"] } }
  mock_data "aws_caller_identity" { defaults = { account_id = "123456789012" } }
  mock_data "aws_eks_addon_version" { defaults = { version = "v1.0.0-eksbuild.1" } }
  mock_resource "aws_eks_cluster" { defaults = { identity = [{ oidc = [{ issuer = "https://oidc.eks.us-east-1.amazonaws.com/id/LAB" }] }] } }
  mock_resource "aws_launch_template" { defaults = { id = "lt-1234567890abcdef0" } }
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
}
run "disabled" {
  command = plan
  variables { enable_deploy_runtime_registry = false }
  assert {
    condition     = length(aws_ecr_repository.deploy_runtime) == 0
    error_message = "Runtime registry must be opt-in."
  }
}
run "enabled" {
  command = plan
  variables { enable_deploy_runtime_registry = true }
  assert {
    condition     = aws_ecr_repository.deploy_runtime[0].name == "manaleague-eks-lab-deploy-runtime" && aws_ecr_repository.deploy_runtime[0].image_tag_mutability == "IMMUTABLE" && !aws_ecr_repository.deploy_runtime[0].force_delete && aws_ecr_repository.deploy_runtime[0].image_scanning_configuration[0].scan_on_push && aws_ecr_repository.deploy_runtime[0].encryption_configuration[0].encryption_type == "AES256"
    error_message = "Runtime registry must be scoped and protected."
  }
  assert {
    condition     = length(jsondecode(aws_ecr_lifecycle_policy.deploy_runtime[0].policy).rules) == 1 && jsondecode(aws_ecr_lifecycle_policy.deploy_runtime[0].policy).rules[0].selection.tagStatus == "untagged"
    error_message = "Lifecycle must only expire untagged images."
  }
}
run "reject_wrong_runtime_repository" {
  command = plan
  variables { deploy_runtime_image = "123456789012.dkr.ecr.us-east-1.amazonaws.com/other@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" }
  expect_failures = [var.deploy_runtime_image]
}

run "broker_missing_bucket" {
  command = plan
  variables {
    enable_deploy_broker           = true
    enable_deploy_runtime_registry = true
    enable_release_artifacts       = false
  }
  expect_failures = [var.enable_deploy_broker]
}
run "broker_missing_registry" {
  command = plan
  variables {
    enable_deploy_broker           = true
    enable_deploy_runtime_registry = false
    enable_release_artifacts       = true
  }
  expect_failures = [var.enable_deploy_broker]
}
run "broker_with_dependencies_still_needs_inputs" {
  command = plan
  variables {
    enable_deploy_broker           = true
    enable_deploy_runtime_registry = true
    enable_release_artifacts       = true
    deploy_broker_zip_path         = ""
    deploy_runtime_image           = ""
    deploy_app_secret_arn          = ""
  }
  expect_failures = [aws_lambda_function.deploy_broker, aws_codebuild_project.deploy]
}
