resource "aws_iam_role" "cluster" {
  name               = "${local.name}-cluster"
  assume_role_policy = jsonencode({ Version = "2012-10-17", Statement = [{ Effect = "Allow", Principal = { Service = "eks.amazonaws.com" }, Action = "sts:AssumeRole" }] })
}
resource "aws_iam_role_policy_attachment" "cluster" {
  role       = aws_iam_role.cluster.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonEKSClusterPolicy"
}
resource "aws_eks_cluster" "lab" {
  name                          = local.name
  role_arn                      = aws_iam_role.cluster.arn
  version                       = var.kubernetes_version
  bootstrap_self_managed_addons = false
  access_config {
    authentication_mode                         = "API"
    bootstrap_cluster_creator_admin_permissions = false
  }
  upgrade_policy { support_type = "STANDARD" }
  vpc_config {
    subnet_ids              = aws_subnet.database[*].id
    security_group_ids      = [aws_security_group.cluster.id]
    endpoint_private_access = true
    endpoint_public_access  = true
    public_access_cidrs     = var.api_public_access_cidrs
  }
  depends_on = [aws_iam_role_policy_attachment.cluster, aws_route_table_association.database]
  lifecycle {
    precondition {
      condition     = split(":", var.provisioner_principal_arn)[4] == var.expected_account_id && split(":", var.github_oidc_provider_arn)[4] == var.expected_account_id
      error_message = "Provisioner and existing GitHub OIDC provider must belong to expected_account_id."
    }
    precondition {
      condition     = length(data.aws_availability_zones.available.names) >= 2
      error_message = "At least two standard availability zones are required."
    }
  }
}
resource "aws_eks_access_entry" "operator" {
  cluster_name  = aws_eks_cluster.lab.name
  principal_arn = var.provisioner_principal_arn
  type          = "STANDARD"
}
resource "aws_eks_access_policy_association" "operator" {
  cluster_name  = aws_eks_cluster.lab.name
  principal_arn = aws_eks_access_entry.operator.principal_arn
  policy_arn    = "arn:aws:eks::aws:cluster-access-policy/AmazonEKSClusterAdminPolicy"
  access_scope { type = "cluster" }
}
resource "aws_eks_access_entry" "deploy" {
  cluster_name      = aws_eks_cluster.lab.name
  principal_arn     = aws_iam_role.github_deploy.arn
  type              = "STANDARD"
  kubernetes_groups = ["manaleague-eks-lab-deploy"]
}
resource "aws_iam_role" "nodes" {
  name               = "${local.name}-nodes"
  assume_role_policy = jsonencode({ Version = "2012-10-17", Statement = [{ Effect = "Allow", Principal = { Service = "ec2.amazonaws.com" }, Action = "sts:AssumeRole" }] })
}
resource "aws_iam_role_policy_attachment" "nodes" {
  for_each   = toset(["AmazonEKSWorkerNodePolicy", "AmazonEC2ContainerRegistryPullOnly"])
  role       = aws_iam_role.nodes.name
  policy_arn = "arn:aws:iam::aws:policy/${each.value}"
}
resource "aws_launch_template" "nodes" {
  name                   = "${local.name}-nodes"
  description            = "AL2023 private managed nodes, no SSH/key pair or public IPv4, encrypted gp3, standard CPU credits"
  update_default_version = true
  network_interfaces {
    associate_public_ip_address = false
    security_groups             = [aws_security_group.nodes.id]
  }
  credit_specification { cpu_credits = "standard" }
  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 1
    instance_metadata_tags      = "disabled"
  }
  block_device_mappings {
    device_name = "/dev/xvda"
    ebs {
      volume_size           = 20
      volume_type           = "gp3"
      encrypted             = true
      delete_on_termination = true
    }
  }
  tag_specifications {
    resource_type = "instance"
    tags          = merge(local.tags, { Name = "${local.name}-node", "eks:eks-cluster-name" = local.name, "manaleague.io/eks-lab-vpc" = aws_vpc.lab.id })
  }
  # The CNI may reconcile primary ENI tags before creating secondary ENIs. Only
  # Terraform/EC2 launch tags may introduce this immutable network guard.
  tag_specifications {
    resource_type = "network-interface"
    tags          = merge(local.tags, { "manaleague.io/eks-lab-vpc" = aws_vpc.lab.id })
  }
  tag_specifications {
    resource_type = "volume"
    tags          = merge(local.tags, { Name = "${local.name}-node-root" })
  }
}
resource "aws_eks_node_group" "lab" {
  for_each        = local.node_groups
  cluster_name    = aws_eks_cluster.lab.name
  node_group_name = "${local.name}-az-${each.value + 1}"
  node_role_arn   = aws_iam_role.nodes.arn
  subnet_ids      = [aws_subnet.database[each.value].id]
  ami_type        = "AL2023_x86_64_STANDARD"
  instance_types  = ["t3.medium"]
  capacity_type   = "ON_DEMAND"
  version         = var.kubernetes_version
  scaling_config {
    desired_size = 1
    min_size     = 1
    max_size     = 1
  }
  launch_template {
    id      = aws_launch_template.nodes.id
    version = aws_launch_template.nodes.latest_version
  }
  update_config { max_unavailable = 1 }
  labels     = { "manaleague.io/environment" = "eks-lab" }
  depends_on = [aws_iam_role_policy_attachment.nodes, aws_eks_addon.cni, aws_route.private_nat, aws_route_table_association.database, aws_vpc_security_group_ingress_rule.nodes_control_plane, aws_vpc_security_group_ingress_rule.nodes_internal, aws_vpc_security_group_ingress_rule.cluster_nodes, aws_vpc_security_group_egress_rule.nodes, aws_vpc_security_group_egress_rule.cluster_nodes]
}
