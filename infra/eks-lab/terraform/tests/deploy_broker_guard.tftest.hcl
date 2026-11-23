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
run "broker_disabled_by_default" {
  command = plan
  assert {
    condition     = var.enable_deploy_broker == false
    error_message = "The deploy broker must be disabled by default."
  }
}
run "broker_enabled_with_prerequisites" {
  command = plan
  variables {
    enable_deploy_broker           = true
    enable_release_artifacts       = true
    enable_deploy_runtime_registry = true
    deploy_runtime_image           = "123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-deploy-runtime@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
    deploy_app_secret_arn          = "arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-Ab1234"
    # Mock plan only: the file stands in for an operator-packaged ZIP.
    deploy_broker_zip_path = "../orchestrator/deploy_broker.py"
  }
  assert {
    condition     = length(aws_lambda_function.deploy_broker) == 1 && length(aws_codebuild_project.deploy) == 1 && length(aws_eks_access_entry.deploy_build) == 1
    error_message = "Enabled broker must plan its Lambda, CodeBuild project and cluster access entry."
  }
}

run "missing_artifacts" {
  command = plan
  variables {
    enable_deploy_broker           = true
    enable_release_artifacts       = false
    enable_deploy_runtime_registry = true
    deploy_runtime_image           = "123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-deploy-runtime@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
    deploy_app_secret_arn          = "arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-Ab1234"
    deploy_broker_zip_path         = "../orchestrator/deploy_broker.py"
  }
  expect_failures = [var.enable_deploy_broker]
}

run "missing_registry" {
  command = plan
  variables {
    enable_deploy_broker           = true
    enable_release_artifacts       = true
    enable_deploy_runtime_registry = false
    deploy_runtime_image           = "123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-deploy-runtime@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
    deploy_app_secret_arn          = "arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-Ab1234"
    deploy_broker_zip_path         = "../orchestrator/deploy_broker.py"
  }
  expect_failures = [var.enable_deploy_broker]
}

run "missing_image" {
  command = plan
  variables {
    enable_deploy_broker           = true
    enable_release_artifacts       = true
    enable_deploy_runtime_registry = true
    deploy_runtime_image           = ""
    deploy_app_secret_arn          = "arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-Ab1234"
    deploy_broker_zip_path         = "../orchestrator/deploy_broker.py"
  }
  expect_failures = [aws_codebuild_project.deploy]
}

run "missing_secret" {
  command = plan
  variables {
    enable_deploy_broker           = true
    enable_release_artifacts       = true
    enable_deploy_runtime_registry = true
    deploy_runtime_image           = "123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-deploy-runtime@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
    deploy_app_secret_arn          = ""
    deploy_broker_zip_path         = "../orchestrator/deploy_broker.py"
  }
  expect_failures = [aws_codebuild_project.deploy]
}

run "missing_package" {
  command = plan
  variables {
    enable_deploy_broker           = true
    enable_release_artifacts       = true
    enable_deploy_runtime_registry = true
    deploy_runtime_image           = "123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-deploy-runtime@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
    deploy_app_secret_arn          = "arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-Ab1234"
    deploy_broker_zip_path         = ""
  }
  expect_failures = [aws_lambda_function.deploy_broker]
}
