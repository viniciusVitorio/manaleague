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
run "disabled_and_policy_scoped" {
  command = plan
  assert {
    condition     = toset(local.deploy_egress_tcp_ports) == toset([80, 443])
    error_message = "HTTP and HTTPS smoke checks must both be reachable."
  }
  assert {
    condition = local.deploy_runtime_ecr_permissions == {
      Effect   = "Allow"
      Action   = ["ecr:DescribeRepositories", "ecr:DescribeImages"]
      Resource = [for key in ["app", "web"] : "arn:aws:ecr:us-east-1:123456789012:repository/manaleague-eks-lab-${key}"]
    }
    error_message = "Runtime must inspect only the two lab image repositories."
  }
  assert {
    condition     = length(aws_iam_role.github_broker_invoker) == 0 && output.github_broker_invoker_role_arn == null
    error_message = "Disabled broker must not create or expose an invoker role."
  }
  assert {
    condition     = local.github_broker_invoker_trust == jsondecode(aws_iam_role.github_deploy.assume_role_policy)
    error_message = "Broker trust must match the restricted legacy GitHub OIDC trust."
  }
  assert {
    condition     = local.github_broker_invoker_trust.Version == "2012-10-17" && length(local.github_broker_invoker_trust.Statement) == 1 && local.github_broker_invoker_trust.Statement[0].Effect == "Allow" && local.github_broker_invoker_trust.Statement[0].Action == "sts:AssumeRoleWithWebIdentity" && local.github_broker_invoker_trust.Statement[0].Principal == { Federated = var.github_oidc_provider_arn } && local.github_broker_invoker_trust.Statement[0].Condition == { StringEquals = { "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com", "token.actions.githubusercontent.com:sub" = var.github_oidc_subject } }
    error_message = "Trust must require the exact GitHub provider, STS audience and environment subject."
  }
  assert {
    condition     = local.github_broker_invoker_permissions == { Version = "2012-10-17", Statement = [{ Effect = "Allow", Action = "lambda:InvokeFunction", Resource = "arn:aws:lambda:us-east-1:123456789012:function:manaleague-eks-lab-deploy-broker" }] }
    error_message = "Invoker must only invoke the exact broker, with no ECR, secrets or EKS permissions."
  }
  assert {
    condition     = length(aws_codebuild_project.deploy) == 0 && length(aws_lambda_function.deploy_broker) == 0 && length(aws_iam_role_policy.github_broker) == 0
    error_message = "Disabled broker must create no project, Lambda or invocation grant."
  }
  assert {
    condition     = local.deploy_project_name == "manaleague-eks-lab-deploy" && local.deploy_broker_name == "manaleague-eks-lab-deploy-broker"
    error_message = "Deploy names are fixed protocol identifiers."
  }
  assert {
    condition     = local.deploy_broker_permissions.Statement[1].Action == "codebuild:BatchGetBuilds" && local.deploy_broker_permissions.Statement[1].Resource == local.deploy_project_arn
    error_message = "Status permission must use the CodeBuild project ARN."
  }
  assert {
    condition     = length([for statement in local.deploy_broker_permissions.Statement : statement if statement.Effect == "Allow" && statement.Action == "codebuild:StopBuild" && statement.Resource == "arn:aws:codebuild:us-east-1:123456789012:project/manaleague-eks-lab-deploy"]) == 1 && length(local.deploy_broker_permissions.Statement) == 3
    error_message = "Cancellation must be allowed only for the fixed deploy project, without extra permissions."
  }
  assert {
    condition     = local.deploy_broker_permissions.Statement[0].Action == "codebuild:StartBuild" && local.deploy_broker_permissions.Statement[0].Resource == "arn:aws:codebuild:us-east-1:123456789012:project/manaleague-eks-lab-deploy"
    error_message = "The broker may start only the fixed deploy project."
  }
}

run "runtime_contract" {
  command = plan
  variables {
    deploy_runtime_image  = "123456789012.dkr.ecr.us-east-1.amazonaws.com/manaleague-eks-lab-deploy-runtime@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
    deploy_app_secret_arn = "arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-Ab1234"
  }
  assert {
    condition     = local.deploy_runtime_image == var.deploy_runtime_image && strcontains(local.deploy_runtime_buildspec, "python3 /opt/manaleague-deploy/deploy.py")
    error_message = "Pinned image must execute the fixed embedded runtime."
  }
  assert {
    condition     = local.deploy_app_secret_permission.Resource == var.deploy_app_secret_arn && local.deploy_app_secret_permission.Action == "secretsmanager:GetSecretValue"
    error_message = "Application secret access must be exact."
  }
  assert {
    condition     = local.deploy_build_kubernetes_groups == aws_eks_access_entry.deploy.kubernetes_groups
    error_message = "Deploy role must use the existing RBAC group."
  }
  assert {
    condition     = local.deploy_runtime_environment.AWS_ACCOUNT_ID == var.expected_account_id && local.deploy_runtime_environment.AWS_REGION == var.aws_region && local.deploy_runtime_environment.LAB_APP_SECRET_ARN == var.deploy_app_secret_arn && length(setsubtract(toset(["AVATAR_BUCKET", "VPC_CIDR", "APP_ROLE_ARN", "ALLOWED_CIDRS", "SUBNET_IDS", "ALB_SECURITY_GROUP_ID", "DB_HOST", "VPC_ID", "RDS_MASTER_SECRET_ARN"]), toset(keys(local.deploy_runtime_environment)))) == 0
    error_message = "Runtime must receive all required trusted lab bindings."
  }
}

run "reject_tagged" {
  command = plan
  variables {
    deploy_runtime_image = "123456789012.dkr.ecr.us-east-1.amazonaws.com/runtime:latest"
  }
  expect_failures = [var.deploy_runtime_image]
}

run "reject_other_account" {
  command = plan
  variables {
    deploy_runtime_image = "999999999999.dkr.ecr.us-east-1.amazonaws.com/runtime@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
  }
  expect_failures = [var.deploy_runtime_image]
}

run "reject_other_region" {
  command = plan
  variables {
    deploy_runtime_image = "123456789012.dkr.ecr.us-west-2.amazonaws.com/runtime@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
  }
  expect_failures = [var.deploy_runtime_image]
}

run "reject_wildcard_secret" {
  command = plan
  variables {
    deploy_app_secret_arn = "arn:aws:secretsmanager:us-east-1:123456789012:secret:manaleague-eks-lab-app-*"
  }
  expect_failures = [var.deploy_app_secret_arn]
}
