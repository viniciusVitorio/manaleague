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
  permissions_boundary = each.key == "load_balancer_controller" ? aws_iam_policy.controller_boundary.arn : null
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
resource "aws_iam_role_policy_attachment" "ebs_csi" {
  role       = aws_iam_role.irsa["ebs_csi"].name
  policy_arn = "arn:aws:iam::aws:policy/AmazonEBSCSIDriverPolicyV2"
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
  policy = jsonencode({ Version = "2012-10-17", Statement = [
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
  ] })
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
