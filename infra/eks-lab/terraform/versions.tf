terraform {
  required_version = ">= 1.11.0, < 2.0.0"
  required_providers {
    aws = { source = "hashicorp/aws", version = "= 6.73.0" }
  }
  backend "local" { path = ".state/terraform.tfstate" }
}
provider "aws" {
  region              = var.aws_region
  allowed_account_ids = [var.expected_account_id]
  default_tags { tags = local.tags }
}
locals {
  name        = "manaleague-eks-lab"
  namespace   = "manaleague-eks-lab"
  tags        = { Project = "manaleague-eks-lab", Environment = "eks-lab", ManagedBy = "Terraform" }
  oidc_issuer = replace(aws_eks_cluster.lab.identity[0].oidc[0].issuer, "https://", "")
  node_groups = { for i in range(var.node_count) : tostring(i) => i }
}
data "aws_caller_identity" "current" {}
data "aws_availability_zones" "available" {
  state = "available"
  filter {
    name   = "opt-in-status"
    values = ["opt-in-not-required"]
  }
}
