variable "aws_region" {
  description = "AWS region for this isolated lab."
  type        = string
  default     = "us-east-1"
}
variable "expected_account_id" {
  description = "REQUIRED: dedicated authorized AWS account, checked by the AWS provider before writes."
  type        = string
  validation {
    condition     = can(regex("^[0-9]{12}$", var.expected_account_id))
    error_message = "expected_account_id must contain exactly 12 digits."
  }
}
variable "provisioner_principal_arn" {
  description = "REQUIRED: permanent IAM role/user ARN of trusted lab operator (not an STS assumed-role/session ARN); gets explicit cluster admin. Must be in expected_account_id."
  type        = string
  validation {
    condition     = can(regex("^arn:aws:iam::[0-9]{12}:(role|user)/.+$", var.provisioner_principal_arn))
    error_message = "Supply an IAM user or role ARN, never an STS session ARN."
  }
}
variable "github_oidc_provider_arn" {
  description = "REQUIRED: EXISTING account-wide GitHub OIDC provider ARN, owned outside this state. No shared identity provider is created/deleted here."
  type        = string
  validation {
    condition     = can(regex("^arn:aws:iam::[0-9]{12}:oidc-provider/token.actions.githubusercontent.com$", var.github_oidc_provider_arn))
    error_message = "Supply the existing GitHub Actions OIDC provider ARN."
  }
}
variable "github_oidc_subject" {
  description = "Exact GitHub OIDC sub claim: name-only or immutable numeric IDs on both owner/repository. Verify the real claim and environment before use; no wildcards. Restrict environment eks-lab to branch eks-lab in GitHub settings."
  type        = string
  default     = "repo:viniciusVitorio/manaleague:environment:eks-lab"
  validation {
    condition     = can(regex("^repo:([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+|[A-Za-z0-9_.-]+@[0-9]+/[A-Za-z0-9_.-]+@[0-9]+):environment:eks-lab$", var.github_oidc_subject))
    error_message = "Subject must be exact repo:OWNER/REPO:environment:eks-lab or repo:OWNER@OWNER_ID/REPO@REPO_ID:environment:eks-lab with numeric IDs on both names; no wildcards."
  }
}
variable "allowed_client_cidrs" {
  description = "REQUIRED: trusted demo-client IPv4 CIDRs for the Terraform-owned ALB frontend security group. This is authoritative, not the Ingress inbound-cidrs annotation."
  type        = list(string)
  validation {
    condition     = length(var.allowed_client_cidrs) > 0 && alltrue([for cidr in var.allowed_client_cidrs : can(cidrnetmask(cidr)) && try(tonumber(split("/", cidr)[1]) >= 24, false)])
    error_message = "Provide explicit trusted client IPv4 CIDRs /24 or narrower; world access, IPv6 and empty lists are forbidden. Prefer /32s."
  }
}
variable "api_public_access_cidrs" {
  description = "REQUIRED: trusted operator and stable runner egress IPv4 CIDRs; endpoint is never opened to the world. Hosted ephemeral runner ranges are not an implicit authorization."
  type        = list(string)
  validation {
    condition     = length(var.api_public_access_cidrs) > 0 && alltrue([for cidr in var.api_public_access_cidrs : can(cidrnetmask(cidr)) && try(tonumber(split("/", cidr)[1]) >= 24, false)])
    error_message = "Provide explicit trusted IPv4 CIDRs /24 or narrower; world access and IPv6 are forbidden. Prefer individual /32s."
  }
}
variable "kubernetes_version" {
  description = "Explicit EKS standard-support version. Reviewed 2026-11-16: 1.37 latest (standard support ends 2027-12-01). STANDARD upgrade policy prevents extended-support charges. Recheck AWS calendar before use."
  type        = string
  default     = "1.37"
  validation {
    condition     = contains(["1.35", "1.36", "1.37"], var.kubernetes_version)
    error_message = "Use reviewed standard-support version 1.35, 1.36 or 1.37; re-review calendar before changing this allowlist."
  }
}
variable "vpc_cidr" {
  description = "Dedicated lab IPv4 range. Choose a non-overlapping private /16."
  type        = string
  default     = "10.73.0.0/16"
  validation {
    condition     = can(cidrnetmask(var.vpc_cidr)) && try(split("/", var.vpc_cidr)[1] == "16", false) && (startswith(var.vpc_cidr, "10.") || startswith(var.vpc_cidr, "192.168.") || can(regex("^172\\.(1[6-9]|2[0-9]|3[01])\\.", var.vpc_cidr)))
    error_message = "Use an RFC1918 IPv4 /16."
  }
}
variable "node_count" {
  description = "1 cheapest; 2 creates separate single-node managed groups in the two AZs. No HA claim for the database."
  type        = number
  default     = 1
  validation {
    condition     = contains([1, 2], var.node_count)
    error_message = "Only one or two t3.medium nodes are supported."
  }
}
variable "addon_versions" {
  description = "Optional explicit managed addon pins keyed by vpc-cni, coredns, kube-proxy, aws-ebs-csi-driver. Omitted pins use AWS's compatible default (not most_recent); actual resolved versions are outputs and state. Review any subsequent plan."
  type        = map(string)
  default     = {}
  validation {
    condition     = alltrue([for k, v in var.addon_versions : contains(["vpc-cni", "coredns", "kube-proxy", "aws-ebs-csi-driver"], k) && can(regex("^v[0-9]+\\.[0-9]+\\.[0-9]+-eksbuild\\.[0-9]+$", v))])
    error_message = "Only supported addon names and explicit vX.Y.Z-eksbuild.N versions are accepted."
  }
}
