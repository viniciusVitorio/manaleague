# Separate read-only database contract; publishing policy and app IRSA stay unchanged.
resource "aws_iam_role_policy" "github_database" {
  name = "${local.name}-database-read"
  role = aws_iam_role.github_deploy.id
  policy = jsonencode({ Version = "2012-10-17", Statement = [
    { Sid = "DescribeExactLabDatabase", Effect = "Allow", Action = "rds:DescribeDBInstances", Resource = aws_db_instance.lab.arn },
    { Sid = "ReadExactRdsManagedSecret", Effect = "Allow", Action = "secretsmanager:GetSecretValue", Resource = aws_db_instance.lab.master_user_secret[0].secret_arn }
  ] })
}
resource "aws_iam_openid_connect_provider" "cluster" {
  url            = aws_eks_cluster.lab.identity[0].oidc[0].issuer
  client_id_list = ["sts.amazonaws.com"]
  tags           = { Name = "${local.name}-irsa" }
}
locals {
  irsa_accounts = {
    app                      = "system:serviceaccount:${local.namespace}:lab-app"
    load_balancer_controller = "system:serviceaccount:kube-system:aws-load-balancer-controller"
    ebs_csi                  = "system:serviceaccount:kube-system:ebs-csi-controller-sa"
    vpc_cni                  = "system:serviceaccount:kube-system:aws-node"
  }
}
resource "aws_iam_role" "irsa" {
  for_each             = local.irsa_accounts
  name                 = "${local.name}-${replace(each.key, "_", "-")}"
  permissions_boundary = each.key == "load_balancer_controller" ? aws_iam_policy.controller_boundary.arn : each.key == "vpc_cni" ? aws_iam_policy.vpc_cni_boundary.arn : each.key == "ebs_csi" ? aws_iam_policy.ebs_csi_boundary.arn : null
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Federated = aws_iam_openid_connect_provider.cluster.arn }
      Action    = "sts:AssumeRoleWithWebIdentity"
      Condition = { StringEquals = { "${local.oidc_issuer}:aud" = "sts.amazonaws.com", "${local.oidc_issuer}:sub" = each.value } }
    }]
  })
}
# Keep the managed V2 policy; the boundary intersects it with cluster ownership.
# EBS volumes/snapshots and instance resources do NOT support ec2:Vpc here.
# Source and destination resources require separate conditions on create APIs.
# Extra driver tags cover both volumes and snapshots (controller.go ExtraTags).
# Snapshot ARNs have an empty account field; existing snapshots also require Owner.
# CopyVolumes follows the live AWS-managed V2 source/destination tag pattern:
# its machine-readable SAR currently omits the source ResourceTag keys.
# https://docs.aws.amazon.com/aws-managed-policy/latest/reference/AmazonEBSCSIDriverPolicyV2.html
# https://github.com/kubernetes-sigs/aws-ebs-csi-driver/blob/v1.68.0/pkg/driver/controller.go
resource "aws_iam_policy" "ebs_csi_boundary" {
  name = "${local.name}-ebs-csi-boundary"
  policy = jsonencode({ Version = "2012-10-17", Statement = [
    { Sid = "ReadOnlyDiscovery", Effect = "Allow", Action = "ec2:Describe*", Resource = "*" },
    { Sid = "CreateOwnedVolumes", Effect = "Allow", Action = ["ec2:CreateVolume", "ec2:CopyVolumes"], Resource = "arn:aws:ec2:${var.aws_region}:${var.expected_account_id}:volume/*", Condition = { StringEquals = { "aws:RequestTag/manaleague.io/cluster" = local.name, "aws:RequestTag/ebs.csi.aws.com/cluster" = "true" } } },
    { Sid = "OwnedVolumeLifecycleAndSources", Effect = "Allow", Action = ["ec2:AttachVolume", "ec2:DetachVolume", "ec2:ModifyVolume", "ec2:DeleteVolume", "ec2:CreateSnapshot", "ec2:CopyVolumes"], Resource = "arn:aws:ec2:${var.aws_region}:${var.expected_account_id}:volume/*", Condition = { StringEquals = { "ec2:ResourceTag/manaleague.io/cluster" = local.name, "ec2:ResourceTag/ebs.csi.aws.com/cluster" = "true" } } },
    { Sid = "LabNodeTargets", Effect = "Allow", Action = ["ec2:AttachVolume", "ec2:DetachVolume"], Resource = "arn:aws:ec2:${var.aws_region}:${var.expected_account_id}:instance/*", Condition = { StringEquals = { "ec2:ResourceTag/eks:eks-cluster-name" = local.name, "ec2:ResourceTag/manaleague.io/eks-lab-vpc" = aws_vpc.lab.id } } },
    { Sid = "CreateOwnedSnapshots", Effect = "Allow", Action = "ec2:CreateSnapshot", Resource = "arn:aws:ec2:${var.aws_region}::snapshot/*", Condition = { StringEquals = { "aws:RequestTag/manaleague.io/cluster" = local.name, "aws:RequestTag/ebs.csi.aws.com/cluster" = "true" } } },
    { Sid = "OwnedSnapshotLifecycleAndSources", Effect = "Allow", Action = ["ec2:CreateVolume", "ec2:DeleteSnapshot", "ec2:LockSnapshot", "ec2:EnableFastSnapshotRestores"], Resource = "arn:aws:ec2:${var.aws_region}::snapshot/*", Condition = { StringEquals = { "ec2:Owner" = var.expected_account_id, "ec2:ResourceTag/manaleague.io/cluster" = local.name, "ec2:ResourceTag/ebs.csi.aws.com/cluster" = "true" } } },
    { Sid = "VolumeTagsAtCreation", Effect = "Allow", Action = "ec2:CreateTags", Resource = "arn:aws:ec2:${var.aws_region}:${var.expected_account_id}:volume/*", Condition = { StringEquals = { "ec2:CreateAction" = ["CreateVolume", "CopyVolumes"], "aws:RequestTag/manaleague.io/cluster" = local.name, "aws:RequestTag/ebs.csi.aws.com/cluster" = "true" } } },
    { Sid = "SnapshotTagsAtCreation", Effect = "Allow", Action = "ec2:CreateTags", Resource = "arn:aws:ec2:${var.aws_region}::snapshot/*", Condition = { StringEquals = { "ec2:CreateAction" = "CreateSnapshot", "aws:RequestTag/manaleague.io/cluster" = local.name, "aws:RequestTag/ebs.csi.aws.com/cluster" = "true" } } },
    # Standalone tags cannot adopt a foreign/root volume, or alter/remove the
    # ownership markers. Keep V2's protected migration/cluster-name tags too.
    { Sid = "ReconcileOwnedVolumeTags", Effect = "Allow", Action = ["ec2:CreateTags", "ec2:DeleteTags"], Resource = "arn:aws:ec2:${var.aws_region}:${var.expected_account_id}:volume/*", Condition = {
      StringEquals                   = { "ec2:ResourceTag/manaleague.io/cluster" = local.name, "ec2:ResourceTag/ebs.csi.aws.com/cluster" = "true" },
      "ForAllValues:StringNotEquals" = { "aws:TagKeys" = ["manaleague.io/cluster", "ebs.csi.aws.com/cluster", "ebs.csi.aws.com/cluster-name", "kubernetes.io/created-for/pvc/name"] },
      Null                           = { "aws:TagKeys" = "false" }
    } }
  ] })
}
resource "aws_iam_role_policy_attachment" "ebs_csi" {
  role       = aws_iam_role.irsa["ebs_csi"].name
  policy_arn = "arn:aws:iam::aws:policy/AmazonEBSCSIDriverPolicyV2"
}
# EC2 SAR action/resource conditions are deliberately split: a new ENI has no
# ec2:Vpc, and instances have no supported ec2:Vpc on these APIs. The immutable
# VPC-ID tag comes only from this launch template or tag-on-create in lab subnets.
# https://docs.aws.amazon.com/service-authorization/latest/reference/list_amazonec2.html
# https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/supported-iam-actions-tagging.html
# https://github.com/aws/amazon-vpc-cni-k8s/blob/v1.22.4/pkg/awsutils/awsutils.go
resource "aws_iam_policy" "vpc_cni_boundary" {
  name = "${local.name}-vpc-cni-boundary"
  policy = jsonencode({ Version = "2012-10-17", Statement = [
    { Sid = "ReadOnlyDiscovery", Effect = "Allow", Action = "ec2:Describe*", Resource = "*" },
    { Sid = "CreateTaggedEni", Effect = "Allow", Action = "ec2:CreateNetworkInterface", Resource = "arn:aws:ec2:${var.aws_region}:${var.expected_account_id}:network-interface/*", Condition = { StringEquals = { "aws:RequestTag/manaleague.io/eks-lab-vpc" = aws_vpc.lab.id } } },
    { Sid = "LabNetworkInputs", Effect = "Allow", Action = ["ec2:CreateNetworkInterface", "ec2:ModifyNetworkInterfaceAttribute"], Resource = concat([for subnet in aws_subnet.database : subnet.arn], [aws_security_group.nodes.arn]) },
    { Sid = "MutateLabEni", Effect = "Allow", Action = ["ec2:AssignPrivateIpAddresses", "ec2:UnassignPrivateIpAddresses", "ec2:AttachNetworkInterface", "ec2:DetachNetworkInterface", "ec2:DeleteNetworkInterface", "ec2:ModifyNetworkInterfaceAttribute"], Resource = "arn:aws:ec2:${var.aws_region}:${var.expected_account_id}:network-interface/*", Condition = { StringEquals = { "ec2:Vpc" = aws_vpc.lab.arn, "ec2:Subnet" = [for subnet in aws_subnet.database : subnet.arn], "ec2:ResourceTag/manaleague.io/eks-lab-vpc" = aws_vpc.lab.id } } },
    { Sid = "LabNodeTargets", Effect = "Allow", Action = ["ec2:AttachNetworkInterface", "ec2:DetachNetworkInterface", "ec2:ModifyNetworkInterfaceAttribute"], Resource = "arn:aws:ec2:${var.aws_region}:${var.expected_account_id}:instance/*", Condition = { StringEquals = { "ec2:ResourceTag/eks:eks-cluster-name" = local.name, "ec2:ResourceTag/manaleague.io/eks-lab-vpc" = aws_vpc.lab.id } } },
    { Sid = "EniTagsAtCreation", Effect = "Allow", Action = "ec2:CreateTags", Resource = "arn:aws:ec2:${var.aws_region}:${var.expected_account_id}:network-interface/*", Condition = { StringEquals = { "ec2:CreateAction" = "CreateNetworkInterface", "aws:RequestTag/manaleague.io/eks-lab-vpc" = aws_vpc.lab.id } } },
    # TagENI sends only changes; its timestamp/instance/cluster tags must work on
    # both primary (launch-template tagged) and secondary (CNI-created) ENIs.
    # CreateTags cannot use ec2:Vpc; never let it introduce/change the guard tag.
    { Sid = "ReconcileOwnedEniTags", Effect = "Allow", Action = "ec2:CreateTags", Resource = "arn:aws:ec2:${var.aws_region}:${var.expected_account_id}:network-interface/*", Condition = {
      StringEquals                   = { "ec2:ResourceTag/manaleague.io/eks-lab-vpc" = aws_vpc.lab.id },
      "ForAllValues:StringNotEquals" = { "aws:TagKeys" = ["manaleague.io/eks-lab-vpc"] },
      Null                           = { "aws:TagKeys" = "false" }
    } }
  ] })
}
resource "aws_iam_role_policy_attachment" "vpc_cni" {
  role       = aws_iam_role.irsa["vpc_cni"].name
  policy_arn = "arn:aws:iam::aws:policy/AmazonEKS_CNI_Policy"
}
resource "aws_iam_policy" "load_balancer_controller" {
  name        = "${local.name}-load-balancer-controller"
  description = "Pinned upstream v3.6.3 policy with lab-only permissions boundary"
  policy      = file("${path.module}/../addons/iam-policy-v3.6.3.json")
}
resource "aws_iam_role_policy_attachment" "load_balancer_controller" {
  role       = aws_iam_role.irsa["load_balancer_controller"].name
  policy_arn = aws_iam_policy.load_balancer_controller.arn
}
# Bound upstream mutations using cluster tags and exact lab listener/rule ARNs.
# LB creation is bound to lab name/subnets/SG; TG creation has no VPC condition.
# TG reconciliation trusts its lab cluster tag; Describe remains global.
resource "aws_iam_policy" "controller_boundary" {
  name = "${local.name}-controller-boundary"
  policy = jsonencode({ Version = "2012-10-17", Statement = concat([
    { Effect = "Allow", Action = ["ec2:Describe*", "ec2:GetCoipPoolUsage", "ec2:GetSecurityGroupsForVpc", "elasticloadbalancing:Describe*"], Resource = "*" },
    { Effect = "Allow", Action = "iam:CreateServiceLinkedRole", Resource = "arn:aws:iam::${var.expected_account_id}:role/aws-service-role/elasticloadbalancing.amazonaws.com/*", Condition = { StringEquals = { "iam:AWSServiceName" = "elasticloadbalancing.amazonaws.com" } } },


    # No EC2 SG mutations: all frontend/backend groups and rules are Terraform-owned.
    # ArrayOfString keys are AWS-documented. All+Any+Null reject foreign,
    # missing or empty networks; no other Allow grants CreateLoadBalancer.
    { Effect = "Allow", Action = "elasticloadbalancing:CreateLoadBalancer", Resource = "arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:loadbalancer/app/${local.name}/*", Condition = {
      StringEquals = { "aws:RequestTag/elbv2.k8s.aws/cluster" = local.name },
      "ForAllValues:StringEquals" = {
        "elasticloadbalancing:Subnet"        = [for subnet in aws_subnet.public : subnet.id],
        "elasticloadbalancing:SecurityGroup" = [aws_security_group.alb.id]
      },
      "ForAnyValue:StringEquals" = {
        "elasticloadbalancing:Subnet"        = [for subnet in aws_subnet.public : subnet.id],
        "elasticloadbalancing:SecurityGroup" = [aws_security_group.alb.id]
      },
      Null = { "elasticloadbalancing:Subnet" = "false", "elasticloadbalancing:SecurityGroup" = "false" }
    } },
    # AWS exposes no VPC condition for TG creation; cluster-tag ownership remains.
    { Effect = "Allow", Action = "elasticloadbalancing:CreateTargetGroup", Resource = "arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:targetgroup/*/*", Condition = { StringEquals = { "aws:RequestTag/elbv2.k8s.aws/cluster" = local.name } } },
    # AWS supplies CreateAction only during tag-on-create, not standalone AddTags.
    # Pair it with its resource type; an Allow elsewhere cannot narrow a broad Allow.
    { Effect = "Allow", Action = "elasticloadbalancing:AddTags", Resource = "arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:loadbalancer/app/${local.name}/*", Condition = { StringEquals = { "elasticloadbalancing:CreateAction" = "CreateLoadBalancer", "aws:RequestTag/elbv2.k8s.aws/cluster" = local.name } } },
    { Effect = "Allow", Action = "elasticloadbalancing:AddTags", Resource = "arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:targetgroup/*/*", Condition = { StringEquals = { "elasticloadbalancing:CreateAction" = "CreateTargetGroup", "aws:RequestTag/elbv2.k8s.aws/cluster" = local.name } } },
    # Update keys are supported by AWS ELBv2 SAR (list_elbv2.html): IDs, not ARNs.
    # Separate grants require only the key supplied by each API; never tag-only.
    # All+Any+Null reject mixed/foreign IDs and missing/empty request context.
    { Effect = "Allow", Action = "elasticloadbalancing:SetSecurityGroups", Resource = "arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:loadbalancer/app/${local.name}/*", Condition = {
      StringEquals                = { "aws:ResourceTag/elbv2.k8s.aws/cluster" = local.name },
      "ForAllValues:StringEquals" = { "elasticloadbalancing:SecurityGroup" = [aws_security_group.alb.id] },
      "ForAnyValue:StringEquals"  = { "elasticloadbalancing:SecurityGroup" = [aws_security_group.alb.id] },
      Null                        = { "elasticloadbalancing:SecurityGroup" = "false" }
    } },
    { Effect = "Allow", Action = "elasticloadbalancing:SetSubnets", Resource = "arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:loadbalancer/app/${local.name}/*", Condition = {
      StringEquals                = { "aws:ResourceTag/elbv2.k8s.aws/cluster" = local.name },
      "ForAllValues:StringEquals" = { "elasticloadbalancing:Subnet" = [for subnet in aws_subnet.public : subnet.id] },
      "ForAnyValue:StringEquals"  = { "elasticloadbalancing:Subnet" = [for subnet in aws_subnet.public : subnet.id] },
      Null                        = { "elasticloadbalancing:Subnet" = "false" }
    } },
    # A forged cluster tag must never admit foreign listeners/rules through this grant.
    # Keep parent-authorized CreateListener out of this tag-based grant as well.
    { Effect = "Allow", Action = ["elasticloadbalancing:AddTags", "elasticloadbalancing:RemoveTags", "elasticloadbalancing:ModifyLoadBalancerAttributes", "elasticloadbalancing:SetIpAddressType", "elasticloadbalancing:DeleteLoadBalancer", "elasticloadbalancing:ModifyTargetGroup", "elasticloadbalancing:ModifyTargetGroupAttributes", "elasticloadbalancing:DeleteTargetGroup", "elasticloadbalancing:ModifyListenerAttributes", "elasticloadbalancing:ModifyCapacityReservation", "elasticloadbalancing:ModifyIpPools", "elasticloadbalancing:RegisterTargets", "elasticloadbalancing:DeregisterTargets", "elasticloadbalancing:AddListenerCertificates", "elasticloadbalancing:RemoveListenerCertificates", "elasticloadbalancing:SetWebAcl"], Resource = ["arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:loadbalancer/app/${local.name}/*", "arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:targetgroup/*/*", "arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:listener/app/${local.name}/*/*", "arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:listener-rule/app/${local.name}/*/*/*"], Condition = { StringEquals = { "aws:ResourceTag/elbv2.k8s.aws/cluster" = local.name } } },
    # Creation authorizes the parent; tagging authorizes the new child ARN.
    { Effect = "Allow", Action = "elasticloadbalancing:CreateListener", Resource = "arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:loadbalancer/app/${local.name}/*" },
    { Effect = "Allow", Action = ["elasticloadbalancing:CreateRule", "elasticloadbalancing:DeleteListener", "elasticloadbalancing:DeleteRule", "elasticloadbalancing:ModifyListener", "elasticloadbalancing:ModifyRule", "elasticloadbalancing:SetRulePriorities", "elasticloadbalancing:AddTags", "elasticloadbalancing:RemoveTags"], Resource = ["arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:listener/app/${local.name}/*/*", "arn:aws:elasticloadbalancing:${var.aws_region}:${var.expected_account_id}:listener-rule/app/${local.name}/*/*/*"] }
  ], local.lab_controller_certificate_read) })
}
# Role boundary is declared here rather than on a separate identity resource.
resource "aws_iam_role" "github_deploy" {
  name = "${local.name}-github-deploy"
  assume_role_policy = jsonencode({ Version = "2012-10-17", Statement = [{
    Effect    = "Allow", Action = "sts:AssumeRoleWithWebIdentity"
    Principal = { Federated = var.github_oidc_provider_arn }
    Condition = { StringEquals = { "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com", "token.actions.githubusercontent.com:sub" = var.github_oidc_subject } }
  }] })
}
resource "aws_iam_role_policy" "github_deploy" {
  name = "${local.name}-push-and-describe"
  role = aws_iam_role.github_deploy.id
  policy = jsonencode({ Version = "2012-10-17", Statement = [
    { Effect = "Allow", Action = "ecr:GetAuthorizationToken", Resource = "*" },
    { Effect = "Allow", Action = ["ecr:BatchCheckLayerAvailability", "ecr:InitiateLayerUpload", "ecr:UploadLayerPart", "ecr:CompleteLayerUpload", "ecr:PutImage", "ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer", "ecr:DescribeImages", "ecr:DescribeRepositories"], Resource = [for repo in aws_ecr_repository.images : repo.arn] },
    { Effect = "Allow", Action = "eks:DescribeCluster", Resource = aws_eks_cluster.lab.arn }
  ] })
}
