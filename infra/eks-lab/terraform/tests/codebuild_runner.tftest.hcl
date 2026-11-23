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
}
run "disabled" {
  command = plan
  variables { enable_codebuild_runner = false }
  assert {
    condition     = length(aws_codebuild_project.runner) == 0 && length(aws_codebuild_webhook.runner) == 0 && length(aws_iam_role.codebuild_runner) == 0 && length(aws_security_group.codebuild_runner) == 0
    error_message = "Disabled runner must create no runner resources."
  }
}
run "reject_unsafe_runner" {
  command = plan
  variables {
    enable_codebuild_runner  = true
    codebuild_repository_url = "https://github.com/example/repo"
    codebuild_connection_arn = "arn:aws:codeconnections:us-east-1:123456789012:connection/12345678-1234-1234-1234-123456789abc"
  }
  expect_failures = [var.enable_codebuild_runner]
}
