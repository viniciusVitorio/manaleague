mock_provider "aws" {
  mock_data "aws_availability_zones" { defaults = { names = ["us-east-1a", "us-east-1b"] } }
  mock_data "aws_caller_identity" { defaults = { account_id = "123456789012" } }
  mock_data "aws_eks_addon_version" { defaults = { version = "v1.0.0-eksbuild.1" } }
  mock_resource "aws_eks_cluster" { defaults = { identity = [{ oidc = [{ issuer = "https://oidc.eks.us-east-1.amazonaws.com/id/LAB" }] }] } }
}
variables {
  expected_account_id       = "123456789012"
  provisioner_principal_arn = "arn:aws:iam::123456789012:role/lab-operator"
  github_oidc_provider_arn  = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
  api_public_access_cidrs   = ["203.0.113.10/32"]
  allowed_client_cidrs      = ["198.51.100.10/32"]
}
run "network_policy_enabled" {
  command = plan
  assert {
    condition     = try(jsondecode(aws_eks_addon.cni.configuration_values).enableNetworkPolicy == "true", false)
    error_message = "VPC CNI must explicitly enable enforcement of the supplied NetworkPolicies using the string true."
  }
}
run "ingress_uses_terraform_security_groups" {
  command = plan
  assert {
    condition = try(
      jsondecode(file("${path.module}/../kubernetes/base/ingress.yaml")).metadata.annotations["alb.ingress.kubernetes.io/load-balancer-name"] == "manaleague-eks-lab" &&
      jsondecode(file("${path.module}/../kubernetes/base/ingress.yaml")).metadata.annotations["alb.ingress.kubernetes.io/security-groups"] == "__ALB_SECURITY_GROUP_ID__" &&
      jsondecode(file("${path.module}/../kubernetes/base/ingress.yaml")).metadata.annotations["alb.ingress.kubernetes.io/manage-backend-security-group-rules"] == "false",
      false
    )
    error_message = "Ingress must use the exact lab ALB name and Terraform-owned SG without controller rule mutations."
  }
}
run "immutable_repository_subject" {
  command = plan
  variables { github_oidc_subject = "repo:viniciusVitorio@123456/manaleague@789012:environment:eks-lab" }
  assert {
    condition     = jsondecode(aws_iam_role.github_deploy.assume_role_policy).Statement[0].Condition.StringEquals["token.actions.githubusercontent.com:sub"] == var.github_oidc_subject
    error_message = "Immutable owner/repository IDs must be preserved exactly in StringEquals trust."
  }
}
run "deploy_can_describe_only_lab_repositories" {
  command = plan
  override_resource {
    override_during = plan
    target          = aws_ecr_repository.images["app"]
    values          = { arn = "arn:aws:ecr:us-east-1:123456789012:repository/manaleague-eks-lab-app" }
  }
  override_resource {
    override_during = plan
    target          = aws_ecr_repository.images["web"]
    values          = { arn = "arn:aws:ecr:us-east-1:123456789012:repository/manaleague-eks-lab-web" }
  }
  override_resource {
    override_during = plan
    target          = aws_eks_cluster.lab
    values = {
      arn      = "arn:aws:eks:us-east-1:123456789012:cluster/manaleague-eks-lab"
      identity = [{ oidc = [{ issuer = "https://oidc.eks.us-east-1.amazonaws.com/id/LAB" }] }]
    }
  }
  assert {
    condition = contains(jsondecode(aws_iam_role_policy.github_deploy.policy).Statement[1].Action, "ecr:DescribeRepositories") && toset(jsondecode(aws_iam_role_policy.github_deploy.policy).Statement[1].Resource) == toset([
      "arn:aws:ecr:us-east-1:123456789012:repository/manaleague-eks-lab-app",
      "arn:aws:ecr:us-east-1:123456789012:repository/manaleague-eks-lab-web"
    ])
    error_message = "Repository preflight permission must be scoped to both lab ECR repositories, never a wildcard."
  }
}
run "reject_world_clients" {
  command = plan
  variables { allowed_client_cidrs = ["0.0.0.0/0"] }
  expect_failures = [var.allowed_client_cidrs]
}
run "reject_empty_clients" {
  command = plan
  variables { allowed_client_cidrs = [] }
  expect_failures = [var.allowed_client_cidrs]
}
run "reject_broad_clients" {
  command = plan
  variables { allowed_client_cidrs = ["198.51.100.0/23"] }
  expect_failures = [var.allowed_client_cidrs]
}
run "reject_ipv6_clients" {
  command = plan
  variables { allowed_client_cidrs = ["2001:db8::/128"] }
  expect_failures = [var.allowed_client_cidrs]
}
run "reject_malformed_clients" {
  command = plan
  variables { allowed_client_cidrs = ["198.51.100.10/33"] }
  expect_failures = [var.allowed_client_cidrs]
}
run "reject_immutable_wildcard_subject" {
  command = plan
  variables { github_oidc_subject = "repo:viniciusVitorio@*/manaleague@789012:environment:eks-lab" }
  expect_failures = [var.github_oidc_subject]
}
run "reject_nonnumeric_immutable_ids" {
  command = plan
  variables { github_oidc_subject = "repo:viniciusVitorio@owner/manaleague@789012:environment:eks-lab" }
  expect_failures = [var.github_oidc_subject]
}
run "reject_partial_immutable_ids" {
  command = plan
  variables { github_oidc_subject = "repo:viniciusVitorio@123456/manaleague:environment:eks-lab" }
  expect_failures = [var.github_oidc_subject]
}
run "reject_wrong_environment" {
  command = plan
  variables { github_oidc_subject = "repo:viniciusVitorio@123456/manaleague@789012:environment:production" }
  expect_failures = [var.github_oidc_subject]
}
