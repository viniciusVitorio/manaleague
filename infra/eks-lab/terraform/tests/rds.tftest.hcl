mock_provider "aws" {
  override_during = plan
  mock_resource "aws_vpc" { override_during = plan }
  mock_resource "aws_subnet" { override_during = plan }
  mock_resource "aws_route_table" { override_during = plan }
  mock_resource "aws_security_group" { override_during = plan }
  mock_resource "aws_iam_role" {
    override_during = plan
    defaults        = { arn = "arn:aws:iam::123456789012:role/mock-lab-role" }
  }
  mock_resource "aws_s3_bucket" {
    override_during = plan
    defaults        = { arn = "arn:aws:s3:::manaleague-eks-lab-123456789012-us-east-1-avatars" }
  }
  mock_resource "aws_db_instance" {
    defaults = {
      arn     = "arn:aws:rds:us-east-1:123456789012:db:manaleague-eks-lab"
      address = "manaleague-eks-lab.fixture.us-east-1.rds.amazonaws.com"
      master_user_secret = [{
        secret_arn    = "arn:aws:secretsmanager:us-east-1:123456789012:secret:rds!db-fixture-AbCd12"
        secret_status = "active"
        kms_key_id    = "arn:aws:kms:us-east-1:123456789012:key/fixture"
      }]
    }
  }
  mock_data "aws_availability_zones" { defaults = { names = ["us-east-1a", "us-east-1b"] } }
  mock_data "aws_eks_addon_version" { defaults = { version = "v1.0.0-eksbuild.1" } }
  mock_data "aws_caller_identity" { defaults = { account_id = "123456789012" } }
  mock_resource "aws_eks_cluster" { defaults = { identity = [{ oidc = [{ issuer = "https://oidc.eks.us-east-1.amazonaws.com/id/LAB" }] }] } }
}
variables {
  expected_account_id       = "123456789012"
  provisioner_principal_arn = "arn:aws:iam::123456789012:role/lab-operator"
  github_oidc_provider_arn  = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
  api_public_access_cidrs   = ["203.0.113.10/32"]
  allowed_client_cidrs      = ["198.51.100.10/32"]
}
run "deploy_database_policy_and_output_contract" {
  command = plan
  assert {
    condition     = can(regex("resource \"aws_iam_role_policy\" \"github_database\"", join("\n", [for filename in fileset(path.module, "*.tf") : file("${path.module}/${filename}")]))) && can(regex("output \"rds_endpoint\"", file("${path.module}/outputs.tf")))
    error_message = "Deploy requires an exact database/managed-secret read policy and RDS output contracts."
  }
}
run "private_database_public_nodes_topology" {
  command = plan
  assert {
    condition     = length(aws_subnet.database) == 2 && toset(aws_subnet.database[*].availability_zone) == toset(["us-east-1a", "us-east-1b"]) && alltrue([for subnet in aws_subnet.database : !subnet.map_public_ip_on_launch && subnet.vpc_id == aws_vpc.lab.id]) && length(setintersection(toset(aws_subnet.database[*].cidr_block), toset(aws_subnet.public[*].cidr_block))) == 0
    error_message = "RDS requires two new nonpublic, disjoint database subnets in the exclusive lab VPC."
  }
  assert {
    condition     = aws_db_subnet_group.lab.subnet_ids == toset(aws_subnet.database[*].id) && !aws_db_instance.lab.publicly_accessible && !aws_db_instance.lab.multi_az && aws_db_instance.lab.db_subnet_group_name == aws_db_subnet_group.lab.name && aws_db_instance.lab.network_type == "IPV4"
    error_message = "RDS must be private Single-AZ using only the dedicated subnet group."
  }
  assert {
    condition     = length(aws_route_table.database.route) == 0 && aws_route_table.database.vpc_id == aws_vpc.lab.id && alltrue([for i, association in aws_route_table_association.database : association.subnet_id == aws_subnet.database[i].id && association.route_table_id == aws_route_table.database.id])
    error_message = "Private route table must contain no configured NAT/IGW/default routes and must own both database subnet associations."
  }
  assert {
    condition     = length(aws_subnet.public) == 2 && alltrue([for subnet in aws_subnet.public : subnet.map_public_ip_on_launch]) && aws_eks_node_group.lab["0"].subnet_ids == toset(aws_subnet.public[*].id) && aws_eks_cluster.lab.vpc_config[0].subnet_ids == toset(aws_subnet.public[*].id)
    error_message = "Existing EKS two-AZ public topology must not move to the database subnets."
  }
}
run "database_only_accepts_lab_nodes" {
  command = plan
  assert {
    condition     = aws_db_instance.lab.vpc_security_group_ids == toset([aws_security_group.database.id]) && aws_security_group.database.vpc_id == aws_vpc.lab.id && length(aws_security_group.database.egress) == 0 && aws_vpc_security_group_ingress_rule.database_nodes.security_group_id == aws_security_group.database.id && aws_vpc_security_group_ingress_rule.database_nodes.referenced_security_group_id == aws_security_group.nodes.id && aws_vpc_security_group_ingress_rule.database_nodes.ip_protocol == "tcp" && aws_vpc_security_group_ingress_rule.database_nodes.from_port == 5432 && aws_vpc_security_group_ingress_rule.database_nodes.to_port == 5432 && aws_vpc_security_group_ingress_rule.database_nodes.cidr_ipv4 == null && aws_vpc_security_group_ingress_rule.database_nodes.cidr_ipv6 == null
    error_message = "TCP/5432 may originate only from the existing dedicated EKS node SG, never any CIDR."
  }
}
run "postgres17_minimal_managed_storage_and_tls" {
  command = plan
  assert {
    condition     = aws_db_instance.lab.identifier == "manaleague-eks-lab" && aws_db_instance.lab.engine == "postgres" && aws_db_instance.lab.engine_version == "17" && aws_db_instance.lab.auto_minor_version_upgrade && !aws_db_instance.lab.allow_major_version_upgrade && aws_db_instance.lab.engine_lifecycle_support == "open-source-rds-extended-support-disabled" && aws_db_instance.lab.instance_class == "db.t4g.micro"
    error_message = "Use PostgreSQL major 17, automatic minor maintenance, t4g.micro and no paid Extended Support."
  }
  assert {
    condition     = aws_db_instance.lab.allocated_storage == 20 && aws_db_instance.lab.max_allocated_storage == 0 && aws_db_instance.lab.storage_type == "gp3" && aws_db_instance.lab.storage_encrypted && aws_db_instance.lab.monitoring_interval == 0 && aws_db_instance.lab.database_insights_mode == "standard" && !aws_db_instance.lab.performance_insights_enabled && length(aws_db_instance.lab.enabled_cloudwatch_logs_exports) == 0
    error_message = "Keep encrypted 20GB gp3 with no storage autoscaling or paid observability extras."
  }
  assert {
    condition     = aws_db_parameter_group.lab.family == "postgres17" && anytrue([for p in aws_db_parameter_group.lab.parameter : p.name == "rds.force_ssl" && p.value == "1" && p.apply_method == "pending-reboot"]) && aws_db_instance.lab.parameter_group_name == aws_db_parameter_group.lab.name
    error_message = "Explicit postgres17 parameter group must enforce TLS."
  }
  assert {
    condition     = aws_db_instance.lab.tags.Project == "manaleague-eks-lab" && aws_db_instance.lab.tags.Environment == "eks-lab"
    error_message = "RDS must retain the exclusive lab identity tags."
  }
}
run "disposable_database_has_real_backups" {
  command = plan
  assert {
    condition     = aws_db_instance.lab.backup_retention_period == 1 && !aws_db_instance.lab.deletion_protection && aws_db_instance.lab.skip_final_snapshot && aws_db_instance.lab.delete_automated_backups && aws_db_instance.lab.apply_immediately
    error_message = "Keep one-day managed backups but intentionally delete all DB data/backups on authorized lab teardown."
  }
}
run "credentials_never_generated_or_read_by_terraform" {
  command = plan
  assert {
    condition     = aws_db_instance.lab.manage_master_user_password && aws_db_instance.lab.password == null && aws_db_instance.lab.password_wo == null && !can(regex("(?m)^\\s*master_user_secret_kms_key_id\\s*=", file("${path.module}/rds.tf"))) && aws_db_instance.lab.db_name == "manaleague_eks_lab" && aws_db_instance.lab.username == "manaleague_lab"
    error_message = "RDS must manage the master password outside Terraform using the AWS-managed secret key and exact runtime DB identity."
  }
  assert {
    condition     = !can(regex("data\\s+\"aws_secretsmanager_secret_version\"|resource\\s+\"random_password\"|variable\\s+\"[^\"]*password[^\"]*\"", join("\n", [for filename in fileset(path.module, "*.tf") : file("${path.module}/${filename}")])))
    error_message = "Never materialize a database password in Terraform input/state."
  }
}
run "deploy_read_only_exact_database_and_managed_secret" {
  command = plan
  assert {
    condition     = length(jsondecode(aws_iam_role_policy.github_database.policy).Statement) == 2 && jsondecode(aws_iam_role_policy.github_database.policy).Statement[0].Action == "rds:DescribeDBInstances" && jsondecode(aws_iam_role_policy.github_database.policy).Statement[0].Resource == "arn:aws:rds:us-east-1:123456789012:db:manaleague-eks-lab" && jsondecode(aws_iam_role_policy.github_database.policy).Statement[1].Action == "secretsmanager:GetSecretValue" && jsondecode(aws_iam_role_policy.github_database.policy).Statement[1].Resource == "arn:aws:secretsmanager:us-east-1:123456789012:secret:rds!db-fixture-AbCd12" && aws_iam_role_policy.github_database.role == aws_iam_role.github_deploy.id
    error_message = "Deploy must only describe this exact RDS instance and read its actual computed managed secret, without wildcard or mutation permissions."
  }
  assert {
    condition     = alltrue([for s in jsondecode(aws_iam_role_policy.app_avatars.policy).Statement : alltrue([for action in flatten([s.Action]) : startswith(action, "s3:")])])
    error_message = "App IRSA remains S3-only; pods must not read Secrets Manager."
  }
}
run "rds_outputs_match_pipeline_contract" {
  command = plan
  assert {
    condition     = output.rds_identifier == "manaleague-eks-lab" && output.rds_endpoint == "manaleague-eks-lab.fixture.us-east-1.rds.amazonaws.com" && output.rds_port == 5432 && output.rds_database == "manaleague_eks_lab" && output.rds_username == "manaleague_lab" && output.rds_master_secret_arn == "arn:aws:secretsmanager:us-east-1:123456789012:secret:rds!db-fixture-AbCd12" && output.rds_security_group_id == aws_security_group.database.id && output.private_subnet_ids == aws_subnet.database[*].id && output.subnet_ids == aws_subnet.public[*].id
    error_message = "Expose RDS hostname (not hostname:port), port, identity, actual secret ARN and private subnets; preserve public subnet_ids."
  }
}
run "two_node_option_preserves_private_single_az_rds" {
  command = plan
  variables { node_count = 2 }
  assert {
    condition     = length(aws_eks_node_group.lab) == 2 && alltrue([for i, group in aws_eks_node_group.lab : group.subnet_ids == toset([aws_subnet.public[tonumber(i)].id])]) && !aws_db_instance.lab.multi_az && !aws_db_instance.lab.publicly_accessible && aws_db_subnet_group.lab.subnet_ids == toset(aws_subnet.database[*].id)
    error_message = "Two EKS nodes must stay in separate public AZs without changing private Single-AZ RDS."
  }
}
run "required_rds_not_an_in_cluster_database" {
  command = plan
  assert {
    condition     = can(regex("resource \"aws_db_instance\" \"lab\"", join("\n", [for filename in fileset(path.module, "*.tf") : file("${path.module}/${filename}")])))
    error_message = "The academic lab MUST provision its own Amazon RDS instance."
  }
}
