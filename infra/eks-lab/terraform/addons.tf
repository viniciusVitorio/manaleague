locals { managed_addon_names = toset(["vpc-cni", "coredns", "kube-proxy", "aws-ebs-csi-driver"]) }
data "aws_eks_addon_version" "compatible" {
  for_each           = local.managed_addon_names
  addon_name         = each.key
  kubernetes_version = var.kubernetes_version
  most_recent        = false
}
resource "aws_eks_addon" "cni" {
  cluster_name                = aws_eks_cluster.lab.name
  addon_name                  = "vpc-cni"
  addon_version               = lookup(var.addon_versions, "vpc-cni", data.aws_eks_addon_version.compatible["vpc-cni"].version)
  service_account_role_arn    = aws_iam_role.irsa["vpc_cni"].arn
  configuration_values        = jsonencode({ enableNetworkPolicy = "true" })
  resolve_conflicts_on_create = "OVERWRITE"
  resolve_conflicts_on_update = "PRESERVE"
  depends_on                  = [aws_iam_role_policy_attachment.vpc_cni]
}
resource "aws_eks_addon" "runtime" {
  for_each                    = setsubtract(local.managed_addon_names, toset(["vpc-cni"]))
  cluster_name                = aws_eks_cluster.lab.name
  addon_name                  = each.key
  addon_version               = lookup(var.addon_versions, each.key, data.aws_eks_addon_version.compatible[each.key].version)
  service_account_role_arn    = each.key == "aws-ebs-csi-driver" ? aws_iam_role.irsa["ebs_csi"].arn : null
  resolve_conflicts_on_create = "OVERWRITE"
  resolve_conflicts_on_update = "PRESERVE"
  configuration_values        = each.key == "aws-ebs-csi-driver" ? jsonencode({ controller = { replicaCount = 1 } }) : each.key == "coredns" ? jsonencode({ replicaCount = 1 }) : null
  depends_on                  = [aws_eks_node_group.lab, aws_iam_role_policy_attachment.ebs_csi]
}
