# Offline only: every AWS operation is handled by Terraform's mock provider.
mock_provider "aws" {
  override_during = plan
  mock_resource "aws_subnet" { override_during = plan }
  mock_resource "aws_vpc" { override_during = plan }
  mock_resource "aws_security_group" { override_during = plan }
  mock_resource "aws_route_table" { override_during = plan }
  mock_resource "aws_internet_gateway" { override_during = plan }
  mock_resource "aws_eip" {
    override_during = plan
    defaults        = { id = "eipalloc-0123456789abcdef0", public_ip = "192.0.2.25" }
  }
  mock_resource "aws_nat_gateway" {
    override_during = plan
    defaults        = { id = "nat-0123456789abcdef0" }
  }
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
  node_count                = 1
}
run "private_nodes_and_control_plane" {
  command = plan
  assert {
    condition     = aws_eks_cluster.lab.vpc_config[0].subnet_ids == toset(aws_subnet.database[*].id) && aws_eks_node_group.lab["0"].subnet_ids == toset([aws_subnet.database[0].id])
    error_message = "Control-plane ENIs must use both private subnets and the default node must stay beside the single NAT in AZ 0."
  }
  assert {
    condition     = length(aws_launch_template.nodes.network_interfaces) == 1 && !aws_launch_template.nodes.network_interfaces[0].associate_public_ip_address && aws_launch_template.nodes.network_interfaces[0].security_groups == toset([aws_security_group.nodes.id]) && aws_launch_template.nodes.vpc_security_group_ids == null
    error_message = "The launch template must forbid public IPv4 explicitly and attach only the dedicated node SG without conflicting top-level SGs."
  }
  assert {
    condition     = aws_launch_template.nodes.metadata_options[0].http_tokens == "required" && aws_launch_template.nodes.metadata_options[0].http_put_response_hop_limit == 1 && aws_launch_template.nodes.block_device_mappings[0].ebs[0].encrypted && aws_launch_template.nodes.credit_specification[0].cpu_credits == "standard"
    error_message = "Private networking must preserve IMDSv2/hop1, encrypted root storage and standard credits."
  }
  assert {
    condition     = alltrue([for s in aws_subnet.database : !s.map_public_ip_on_launch && s.vpc_id == aws_vpc.lab.id]) && aws_db_subnet_group.lab.subnet_ids == toset(aws_subnet.database[*].id) && !aws_db_instance.lab.publicly_accessible && !aws_db_instance.lab.multi_az && length(aws_security_group.database.egress) == 0
    error_message = "Shared EKS/RDS subnets remain private; RDS remains private Single-AZ with no initiated egress."
  }
}
run "nat_routes_and_runner_allowlist_outputs" {
  command = plan
  variables { allowed_client_cidrs = ["198.51.100.10/32", "198.51.100.10/32"] }
  assert {
    condition     = output.allowed_client_cidrs == tolist(["192.0.2.25/32", "198.51.100.10/32"])
    error_message = "The authoritative ALB output must sort and deduplicate operator CIDRs plus NAT /32."
  }
  assert {
    condition     = aws_vpc_security_group_ingress_rule.alb_nat_http.cidr_ipv4 == "192.0.2.25/32" && aws_vpc_security_group_ingress_rule.alb_nat_http.security_group_id == aws_security_group.alb.id && aws_vpc_security_group_ingress_rule.alb_nat_http.ip_protocol == "tcp" && aws_vpc_security_group_ingress_rule.alb_nat_http.from_port == 80 && aws_vpc_security_group_ingress_rule.alb_nat_http.to_port == 80
    error_message = "Runner smoke requires a separately addressed HTTP-only NAT /32 rule, never an unknown for_each key."
  }
  assert {
    condition     = output.subnet_ids == aws_subnet.public[*].id && output.private_subnet_ids == aws_subnet.database[*].id && toset(output.node_subnet_ids) == toset(flatten([for g in aws_eks_node_group.lab : tolist(g.subnet_ids)])) && output.nat_gateway_id == aws_nat_gateway.lab.id && output.nat_public_ip == aws_eip.nat.public_ip && output.nat_egress_cidr == "192.0.2.25/32"
    error_message = "Keep public ALB subnet_ids, publish real MNG subnet membership and exact NAT contracts."
  }
  assert {
    condition     = aws_nat_gateway.lab.subnet_id == aws_subnet.public[0].id && aws_nat_gateway.lab.allocation_id == aws_eip.nat.id && aws_nat_gateway.lab.connectivity_type == "public" && aws_eip.nat.domain == "vpc" && aws_route.private_nat.route_table_id == aws_route_table.database.id && aws_route.private_nat.nat_gateway_id == aws_nat_gateway.lab.id && aws_route.private_nat.destination_cidr_block == "0.0.0.0/0" && aws_route.private_nat.gateway_id == null && aws_route.internet.gateway_id == aws_internet_gateway.lab.id && aws_route.internet.destination_cidr_block == "0.0.0.0/0"
    error_message = "Private default egress must traverse the single EIP-backed NAT; public default egress must traverse the lab IGW."
  }
}
run "two_private_az_node_groups" {
  command = plan
  variables { node_count = 2 }
  assert {
    condition     = length(aws_eks_node_group.lab) == 2 && alltrue([for i, g in aws_eks_node_group.lab : g.subnet_ids == toset([aws_subnet.database[tonumber(i)].id]) && g.scaling_config[0].desired_size == 1 && g.scaling_config[0].min_size == 1 && g.scaling_config[0].max_size == 1])
    error_message = "Two nodes require one fixed managed group per private AZ; NAT remains a single-AZ dependency."
  }
}
run "reject_caller_supplied_nat_cidr" {
  command = plan
  variables { allowed_client_cidrs = ["192.0.2.25/32"] }
  expect_failures = [
    aws_vpc_security_group_ingress_rule.alb_http,
    aws_vpc_security_group_ingress_rule.alb_nat_http,
  ]
}
