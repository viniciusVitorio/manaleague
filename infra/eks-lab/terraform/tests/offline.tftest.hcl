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
  allowed_client_cidrs      = ["198.51.100.10/32", "198.51.100.0/24"]
  # Pinned here so an operator tfvars with HTTPS enabled cannot change this fixture.
  enable_lab_https = false
  lab_dns_zone_id  = ""
}
run "safe_defaults" {
  command = plan
  assert {
    condition     = toset([for rule in aws_vpc_security_group_ingress_rule.alb_http : rule.cidr_ipv4]) == toset(var.allowed_client_cidrs) && alltrue([for rule in aws_vpc_security_group_ingress_rule.alb_http : rule.cidr_ipv4 != "0.0.0.0/0" && rule.from_port == 80 && rule.to_port == 80])
    error_message = "ALB HTTP must never permit world ingress."
  }
  assert {
    condition     = aws_eks_cluster.lab.upgrade_policy[0].support_type == "STANDARD" && aws_eks_cluster.lab.version == "1.37"
    error_message = "Lab must use an explicit standard-support Kubernetes version."
  }
  assert {
    condition     = aws_launch_template.nodes.metadata_options[0].http_tokens == "required" && aws_launch_template.nodes.metadata_options[0].http_put_response_hop_limit == 1
    error_message = "Nodes must require IMDSv2 and block ordinary pod IMDS access."
  }
  assert {
    condition     = length(aws_eks_node_group.lab) == 1 && aws_eks_node_group.lab["0"].scaling_config[0].desired_size == 1
    error_message = "Default must be one managed node."
  }
  assert {
    condition     = aws_eks_access_entry.deploy.kubernetes_groups == toset(["manaleague-eks-lab-deploy"])
    error_message = "Deploy role must use the limited RBAC group, not cluster admin."
  }
  assert {
    condition     = aws_eks_cluster.lab.vpc_config[0].endpoint_private_access && aws_eks_cluster.lab.vpc_config[0].public_access_cidrs == toset(["203.0.113.10/32"])
    error_message = "EKS private access plus restricted public API are mandatory."
  }
  assert {
    condition     = aws_s3_bucket_public_access_block.avatars.block_public_acls && aws_s3_bucket_public_access_block.avatars.block_public_policy && aws_s3_bucket.avatars.force_destroy == false
    error_message = "Avatars must stay private and require explicit cleanup."
  }
}
run "two_nodes_two_azs" {
  command = plan
  variables { node_count = 2 }
  assert {
    condition     = length(aws_eks_node_group.lab) == 2 && alltrue([for group in aws_eks_node_group.lab : length(group.subnet_ids) == 1 && group.scaling_config[0].desired_size == 1])
    error_message = "Two-node option must create one fixed node per AZ."
  }
}
run "reject_world_api" {
  command = plan
  variables { api_public_access_cidrs = ["0.0.0.0/0"] }
  expect_failures = [var.api_public_access_cidrs]
}
run "reject_wrong_account" {
  command = plan
  variables { expected_account_id = "not-an-account" }
  expect_failures = [var.expected_account_id]
}
run "reject_old_version" {
  command = plan
  variables { kubernetes_version = "1.30" }
  expect_failures = [var.kubernetes_version]
}
