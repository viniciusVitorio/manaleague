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
  # This legacy-subject fixture must not inherit an operator's immutable-subject tfvars.
  github_oidc_subject     = "repo:viniciusVitorio/manaleague:environment:eks-lab"
  api_public_access_cidrs = ["203.0.113.10/32"]
  allowed_client_cidrs    = ["198.51.100.10/32", "198.51.100.0/24"]
  # Pinned here so an operator tfvars with HTTPS enabled cannot change this fixture.
  enable_lab_https = false
  lab_dns_zone_id  = ""
}
run "reject_three_nodes" {
  command = plan
  variables { node_count = 3 }
  expect_failures = [var.node_count]
}
run "reject_cross_account_operator" {
  command = plan
  variables { provisioner_principal_arn = "arn:aws:iam::999999999999:role/not-authorized" }
  expect_failures = [aws_eks_cluster.lab]
}
run "reject_wildcard_subject" {
  command = plan
  variables { github_oidc_subject = "repo:viniciusVitorio/*:environment:eks-lab" }
  expect_failures = [var.github_oidc_subject]
}
run "reject_unknown_addon" {
  command = plan
  variables { addon_versions = { surprise = "v1.0.0-eksbuild.1" } }
  expect_failures = [var.addon_versions]
}
run "identity_and_storage_guards" {
  command = plan
  assert {
    condition     = aws_iam_role_policy_attachment.ebs_csi.policy_arn == "arn:aws:iam::aws:policy/AmazonEBSCSIDriverPolicyV2"
    error_message = "EBS CSI must use the official V2 managed policy ARN (no service-role path)."
  }
  assert {
    condition     = jsondecode(aws_iam_role.github_deploy.assume_role_policy).Statement[0].Condition.StringEquals["token.actions.githubusercontent.com:sub"] == "repo:viniciusVitorio/manaleague:environment:eks-lab" && jsondecode(aws_iam_role.github_deploy.assume_role_policy).Statement[0].Condition.StringEquals["token.actions.githubusercontent.com:aud"] == "sts.amazonaws.com"
    error_message = "GitHub trust must constrain exact subject AND audience."
  }
  assert {
    condition     = length(aws_subnet.public) == 2 && aws_launch_template.nodes.credit_specification[0].cpu_credits == "standard" && aws_launch_template.nodes.block_device_mappings[0].ebs[0].encrypted && aws_launch_template.nodes.block_device_mappings[0].ebs[0].volume_size == 20
    error_message = "Two public subnets, encrypted 20GB root and no surplus CPU billing are required."
  }
  assert {
    condition     = alltrue([for repo in aws_ecr_repository.images : repo.image_tag_mutability == "IMMUTABLE" && !repo.force_delete]) && length(aws_ecr_repository.images) == 2
    error_message = "Only app/web immutable ECR repositories are allowed."
  }
  assert {
    condition     = !aws_eks_cluster.lab.access_config[0].bootstrap_cluster_creator_admin_permissions && aws_eks_cluster.lab.access_config[0].authentication_mode == "API"
    error_message = "Implicit cluster creator permissions must remain disabled."
  }
}
