variable "enable_lab_https" {
  description = "Opt in to TLS for the fixed lab hostname; HTTP remains available for redirect."
  type        = bool
  default     = false
}
variable "lab_dns_zone_id" {
  description = "Existing public parent zone ID, never a /hostedzone/ path. Required only for HTTPS. This state never owns the parent zone."
  type        = string
  default     = ""
  validation {
    condition     = (var.lab_dns_zone_id == "" && !var.enable_lab_https) || can(regex("^Z[A-Z0-9]{7,31}$", var.lab_dns_zone_id))
    error_message = "lab_dns_zone_id must be a literal uppercase Z-prefixed 8-32 character zone ID (no /hostedzone/ prefix); HTTPS requires it."
  }
}
locals {
  lab_hostname = "eks.manaleague.com.br"
  lab_dns_zone = "manaleague.com.br"
  # Upstream LBC policy already grants DescribeCertificate; intersect it with
  # this one ARN only. No discovery, export, issuance, tags or DNS permissions.
  lab_controller_certificate_read = [for certificate in aws_acm_certificate.lab : {
    Sid = "DescribeExactLabCertificate", Effect = "Allow", Action = "acm:DescribeCertificate", Resource = certificate.arn
  }]
  lab_authoritative_name_servers = toset([
    "ns-605.awsdns-11.net", "ns-16.awsdns-02.com",
    "ns-1514.awsdns-61.org", "ns-1580.awsdns-05.co.uk"
  ])
}
output "lab_https_enabled" {
  value = var.enable_lab_https
}
output "lab_hostname" {
  value = local.lab_hostname
}
output "lab_url" {
  value = var.enable_lab_https ? "https://${local.lab_hostname}" : ""
}
data "aws_route53_zone" "lab" {
  count        = var.enable_lab_https ? 1 : 0
  zone_id      = var.lab_dns_zone_id
  private_zone = false
  # Postconditions inspect returned data, not user assertions. Terraform blocks
  # dependent certificate creation and DNS writes if this existing zone fails.
  lifecycle {
    postcondition {
      condition     = trimsuffix(self.name, ".") == local.lab_dns_zone && !self.private_zone
      error_message = "HTTPS requires the existing PUBLIC manaleague.com.br parent zone."
    }
    postcondition {
      condition     = length(self.name_servers) == 4 && toset([for ns in self.name_servers : trimsuffix(ns, ".")]) == local.lab_authoritative_name_servers
      error_message = "The existing zone must match all four reviewed authoritative name servers."
    }
  }
}
# No parent zone, alias or production record is owned by this root.
resource "aws_acm_certificate" "lab" {
  count                     = var.enable_lab_https ? 1 : 0
  domain_name               = local.lab_hostname
  subject_alternative_names = [local.lab_hostname]
  validation_method         = "DNS"
  options {
    export = "DISABLED"
  }
  tags       = local.tags
  depends_on = [data.aws_route53_zone.lab]
  lifecycle {
    create_before_destroy = true
  }
}
resource "aws_route53_record" "lab_certificate_validation" {
  count           = var.enable_lab_https ? 1 : 0
  zone_id         = data.aws_route53_zone.lab[0].zone_id
  name            = one(aws_acm_certificate.lab[0].domain_validation_options).resource_record_name
  type            = one(aws_acm_certificate.lab[0].domain_validation_options).resource_record_type
  records         = [one(aws_acm_certificate.lab[0].domain_validation_options).resource_record_value]
  ttl             = 60
  allow_overwrite = false
  lifecycle {
    precondition {
      condition = try(
        length(aws_acm_certificate.lab[0].domain_validation_options) == 1 &&
        one(aws_acm_certificate.lab[0].domain_validation_options).domain_name == local.lab_hostname &&
        one(aws_acm_certificate.lab[0].domain_validation_options).resource_record_type == "CNAME" &&
        endswith(one(aws_acm_certificate.lab[0].domain_validation_options).resource_record_name, ".${local.lab_hostname}."),
        false
      )
      error_message = "ACM validation must be the single CNAME strictly below eks.manaleague.com.br., never the parent, wildcard or another domain."
    }
  }
}
resource "aws_acm_certificate_validation" "lab" {
  count                   = var.enable_lab_https ? 1 : 0
  certificate_arn         = aws_acm_certificate.lab[0].arn
  validation_record_fqdns = [aws_route53_record.lab_certificate_validation[0].fqdn]
}
resource "aws_iam_role_policy" "github_https" {
  count = var.enable_lab_https ? 1 : 0
  name  = "${local.name}-certificate-read"
  role  = aws_iam_role.github_deploy.id
  policy = jsonencode({ Version = "2012-10-17", Statement = [{
    Sid      = "ReadExactLabCertificate"
    Effect   = "Allow"
    Action   = ["acm:DescribeCertificate", "acm:ListTagsForCertificate"]
    Resource = aws_acm_certificate.lab[0].arn
  }] })
}
output "lab_certificate_arn" {
  value = var.enable_lab_https ? aws_acm_certificate_validation.lab[0].certificate_arn : ""
}
output "lab_dns_zone_id" {
  value = var.enable_lab_https ? data.aws_route53_zone.lab[0].zone_id : ""
}
output "lab_dns_name_servers" {
  value = var.enable_lab_https ? data.aws_route53_zone.lab[0].name_servers : []
}
