output "rds_identifier" {
  value = aws_db_instance.lab.identifier
}
output "rds_endpoint" {
  description = "Hostname only (not hostname:port); use as the exact expected DB_HOST."
  value       = aws_db_instance.lab.address
}
output "rds_port" {
  value = aws_db_instance.lab.port
}
output "rds_database" {
  value = aws_db_instance.lab.db_name
}
output "rds_username" {
  value = aws_db_instance.lab.username
}
output "rds_master_secret_arn" {
  description = "Metadata only; the authorized deployer retrieves the value at runtime."
  value       = aws_db_instance.lab.master_user_secret[0].secret_arn
}
output "rds_security_group_id" {
  value = aws_security_group.database.id
}
output "private_subnet_ids" {
  value = aws_subnet.database[*].id
}
output "aws_region" {
  value = var.aws_region
}
output "account_id" {
  value = data.aws_caller_identity.current.account_id
}
output "cluster_name" {
  value = aws_eks_cluster.lab.name
}
output "namespace" {
  value = local.namespace
}
output "vpc_id" {
  value = aws_vpc.lab.id
}
output "vpc_cidr" {
  value = aws_vpc.lab.cidr_block
}
output "subnet_ids" {
  value = aws_subnet.public[*].id
}
output "ecr_app_repository_url" {
  value = aws_ecr_repository.images["app"].repository_url
}
output "ecr_web_repository_url" {
  value = aws_ecr_repository.images["web"].repository_url
}
output "avatar_bucket_name" {
  value = aws_s3_bucket.avatars.id
}
output "app_role_arn" {
  value = aws_iam_role.irsa["app"].arn
}
output "github_deploy_role_arn" {
  value = aws_iam_role.github_deploy.arn
}
output "load_balancer_controller_role_arn" {
  value = aws_iam_role.irsa["load_balancer_controller"].arn
}
output "ebs_csi_role_arn" {
  value = aws_iam_role.irsa["ebs_csi"].arn
}
output "api_public_access_cidrs" {
  value = var.api_public_access_cidrs
}
output "allowed_client_cidrs" {
  description = "Authoritative ALB frontend security-group IPv4 allowlist."
  value       = var.allowed_client_cidrs
}
output "alb_security_group_id" {
  value = aws_security_group.alb.id
}
output "node_security_group_id" {
  value = aws_security_group.nodes.id
}
output "kubernetes_version" {
  value = aws_eks_cluster.lab.version
}
output "managed_addon_versions" {
  value = merge({ "vpc-cni" = aws_eks_addon.cni.addon_version }, { for k, v in aws_eks_addon.runtime : k => v.addon_version })
}
output "load_balancer_controller_helm_values" {
  value = { clusterName = local.name, region = var.aws_region, vpcId = aws_vpc.lab.id, serviceAccount = { create = true, name = "aws-load-balancer-controller", annotations = { "eks.amazonaws.com/role-arn" = aws_iam_role.irsa["load_balancer_controller"].arn } } }
}
output "lab_identity" {
  value = { name = local.name, namespace = local.namespace, region = var.aws_region, account_id = var.expected_account_id }
}
