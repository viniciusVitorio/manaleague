# All resources and data are mocked. This file never reaches AWS.
mock_provider "aws" {
  mock_data "aws_route53_zone" {
    defaults = {
      zone_id      = "Z0900230TGWPQ92QCQJ8"
      name         = "manaleague.com.br."
      private_zone = false
      name_servers = ["ns-605.awsdns-11.net", "ns-16.awsdns-02.com", "ns-1514.awsdns-61.org", "ns-1580.awsdns-05.co.uk"]
    }
  }
  mock_resource "aws_acm_certificate" {
    override_during = plan
    defaults = {
      arn                       = "arn:aws:acm:us-east-1:123456789012:certificate/00000000-0000-0000-0000-000000000001"
      type                      = "AMAZON_ISSUED"
      domain_validation_options = [{ domain_name = "eks.manaleague.com.br", resource_record_name = "_token.eks.manaleague.com.br.", resource_record_type = "CNAME", resource_record_value = "_value.acm-validations.aws." }]
    }
  }
  mock_resource "aws_acm_certificate_validation" {
    override_during = plan
    defaults        = { certificate_arn = "arn:aws:acm:us-east-1:123456789012:certificate/00000000-0000-0000-0000-000000000001" }
  }
  mock_resource "aws_eip" {
    override_during = plan
    defaults        = { public_ip = "192.0.2.1" }
  }
  mock_data "aws_availability_zones" { defaults = { names = ["us-east-1a", "us-east-1b"] } }
  mock_data "aws_caller_identity" { defaults = { account_id = "123456789012" } }
  mock_data "aws_eks_addon_version" { defaults = { version = "v1.0.0-eksbuild.1" } }
  mock_resource "aws_eks_cluster" { defaults = { identity = [{ oidc = [{ issuer = "https://oidc.eks.us-east-1.amazonaws.com/id/LAB" }] }] } }
}
override_data {
  target = data.aws_route53_zone.lab[0]
  values = {
    zone_id      = "Z0900230TGWPQ92QCQJ8"
    name         = "manaleague.com.br."
    private_zone = false
    name_servers = ["ns-605.awsdns-11.net", "ns-16.awsdns-02.com", "ns-1514.awsdns-61.org", "ns-1580.awsdns-05.co.uk"]
  }
}
override_resource {
  target          = aws_subnet.public[0]
  override_during = plan
  values          = { id = "subnet-0123456789abcdef0" }
}
override_resource {
  target          = aws_subnet.public[1]
  override_during = plan
  values          = { id = "subnet-0123456789abcdef1" }
}
override_resource {
  target          = aws_security_group.alb
  override_during = plan
  values          = { id = "sg-0123456789abcdef0" }
}
variables {
  expected_account_id       = "123456789012"
  provisioner_principal_arn = "arn:aws:iam::123456789012:role/lab-operator"
  github_oidc_provider_arn  = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
  api_public_access_cidrs   = ["203.0.113.10/32"]
  allowed_client_cidrs      = ["198.51.100.10/32"]
}
run "http_compatible_defaults" {
  command = plan
  assert {
    condition     = output.lab_https_enabled == false && output.lab_hostname == "eks.manaleague.com.br" && output.lab_url == "" && output.lab_certificate_arn == "" && output.lab_dns_zone_id == "" && length(output.lab_dns_name_servers) == 0
    error_message = "HTTPS must be opt-in and its disabled outputs must remain empty."
  }
}
run "https_certificate_and_validation" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z0900230TGWPQ92QCQJ8"
  }
  assert {
    condition     = length(aws_acm_certificate.lab) == 1 && aws_acm_certificate.lab[0].domain_name == "eks.manaleague.com.br" && aws_acm_certificate.lab[0].subject_alternative_names == toset(["eks.manaleague.com.br"]) && aws_acm_certificate.lab[0].validation_method == "DNS" && aws_acm_certificate.lab[0].options[0].export == "DISABLED" && aws_acm_certificate.lab[0].type == "AMAZON_ISSUED"
    error_message = "Only a non-exportable Amazon public certificate for the exact lab hostname is allowed."
  }
  assert {
    condition     = aws_acm_certificate.lab[0].tags.Project == "manaleague-eks-lab" && aws_acm_certificate.lab[0].tags.Environment == "eks-lab"
    error_message = "Certificate ownership tags are mandatory."
  }
  assert {
    condition     = length(data.aws_route53_zone.lab) == 1 && data.aws_route53_zone.lab[0].zone_id == var.lab_dns_zone_id && length(aws_route53_record.lab_certificate_validation) == 1 && aws_route53_record.lab_certificate_validation[0].name == "_token.eks.manaleague.com.br." && aws_route53_record.lab_certificate_validation[0].type == "CNAME" && aws_route53_record.lab_certificate_validation[0].records == toset(["_value.acm-validations.aws."]) && aws_route53_record.lab_certificate_validation[0].allow_overwrite == false && aws_route53_record.lab_certificate_validation[0].zone_id == var.lab_dns_zone_id
    error_message = "Manage exactly the lab validation CNAME, never adopt an existing foreign record."
  }
  assert {
    condition     = length(aws_acm_certificate_validation.lab) == 1 && aws_acm_certificate_validation.lab[0].certificate_arn == aws_acm_certificate.lab[0].arn && output.lab_certificate_arn == aws_acm_certificate_validation.lab[0].certificate_arn && output.lab_https_enabled && output.lab_url == "https://eks.manaleague.com.br" && output.lab_dns_zone_id == var.lab_dns_zone_id && toset(output.lab_dns_name_servers) == toset(["ns-605.awsdns-11.net", "ns-16.awsdns-02.com", "ns-1514.awsdns-61.org", "ns-1580.awsdns-05.co.uk"])
    error_message = "Export the issued certificate and verified zone metadata only."
  }
}
run "reject_zone_id_empty" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = ""
  }
  expect_failures = [var.lab_dns_zone_id]
}
run "reject_zone_id_prefix" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "/hostedzone/Z0900230TGWPQ92QCQJ8"
  }
  expect_failures = [var.lab_dns_zone_id]
}
run "reject_zone_id_lower" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "z0900230tgw"
  }
  expect_failures = [var.lab_dns_zone_id]
}
run "reject_zone_id_short" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z123456"
  }
  expect_failures = [var.lab_dns_zone_id]
}
run "reject_zone_id_long" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z11111111111111111111111111111111"
  }
  expect_failures = [var.lab_dns_zone_id]
}
run "reject_zone_id_space" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = " Z0900230TGWPQ92QCQJ8"
  }
  expect_failures = [var.lab_dns_zone_id]
}
run "reject_zone_id_punctuation" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z0900230-TGWP"
  }
  expect_failures = [var.lab_dns_zone_id]
}
run "reject_zone_foreign_name" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z0900230TGWPQ92QCQJ8"
  }
  override_data {
    target = data.aws_route53_zone.lab[0]
    values = {
      zone_id      = "Z0900230TGWPQ92QCQJ8"
      name         = "production.example."
      private_zone = false
      name_servers = ["ns-605.awsdns-11.net", "ns-16.awsdns-02.com", "ns-1514.awsdns-61.org", "ns-1580.awsdns-05.co.uk"]
    }
  }
  expect_failures = [data.aws_route53_zone.lab]
}
run "public_zone_guard_is_returned_data_not_only_filter" {
  command = plan
  assert {
    # private_zone is Optional (not Computed), so mock override ignores it.
    # Real provider GetHostedZone by ID sets the returned PrivateZone value.
    condition     = strcontains(file("${path.module}/https.tf"), "&& !self.private_zone")
    error_message = "Keep the public-zone postcondition: ID lookup bypasses the provider private_zone filter."
  }
}
run "reject_zone_wrong_ns" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z0900230TGWPQ92QCQJ8"
  }
  override_data {
    target = data.aws_route53_zone.lab[0]
    values = {
      zone_id      = "Z0900230TGWPQ92QCQJ8"
      name         = "manaleague.com.br."
      private_zone = false
      name_servers = ["ns-foreign.example", "ns-16.awsdns-02.com", "ns-1514.awsdns-61.org", "ns-1580.awsdns-05.co.uk"]
    }
  }
  expect_failures = [data.aws_route53_zone.lab]
}
run "reject_validation_parent" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z0900230TGWPQ92QCQJ8"
  }
  override_resource {
    target          = aws_acm_certificate.lab[0]
    override_during = plan
    values          = { domain_validation_options = [{ domain_name = "eks.manaleague.com.br", resource_record_name = "_token.manaleague.com.br.", resource_record_type = "CNAME", resource_record_value = "_value.acm-validations.aws." }] }
  }
  expect_failures = [aws_route53_record.lab_certificate_validation]
}
run "reject_validation_wildcard" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z0900230TGWPQ92QCQJ8"
  }
  override_resource {
    target          = aws_acm_certificate.lab[0]
    override_during = plan
    values          = { domain_validation_options = [{ domain_name = "*.eks.manaleague.com.br", resource_record_name = "_token.eks.manaleague.com.br.", resource_record_type = "CNAME", resource_record_value = "_value.acm-validations.aws." }] }
  }
  expect_failures = [aws_route53_record.lab_certificate_validation]
}
run "reject_validation_suffix_spoof" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z0900230TGWPQ92QCQJ8"
  }
  override_resource {
    target          = aws_acm_certificate.lab[0]
    override_during = plan
    values          = { domain_validation_options = [{ domain_name = "eks.manaleague.com.br", resource_record_name = "_token.eks.manaleague.com.br.attacker.", resource_record_type = "CNAME", resource_record_value = "_value.acm-validations.aws." }] }
  }
  expect_failures = [aws_route53_record.lab_certificate_validation]
}
run "reject_validation_not_cname" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z0900230TGWPQ92QCQJ8"
  }
  override_resource {
    target          = aws_acm_certificate.lab[0]
    override_during = plan
    values          = { domain_validation_options = [{ domain_name = "eks.manaleague.com.br", resource_record_name = "_token.eks.manaleague.com.br.", resource_record_type = "TXT", resource_record_value = "_value.acm-validations.aws." }] }
  }
  expect_failures = [aws_route53_record.lab_certificate_validation]
}
run "https_frontend_rules" {
  command = plan
  variables {
    enable_lab_https     = true
    lab_dns_zone_id      = "Z0900230TGWPQ92QCQJ8"
    allowed_client_cidrs = ["198.51.100.10/32", "198.51.100.10/32", "198.51.100.0/24"]
  }
  assert {
    condition     = length(aws_vpc_security_group_ingress_rule.alb_https) == 2 && toset([for rule in aws_vpc_security_group_ingress_rule.alb_https : rule.cidr_ipv4]) == toset(var.allowed_client_cidrs) && alltrue([for rule in aws_vpc_security_group_ingress_rule.alb_https : rule.from_port == 443 && rule.to_port == 443 && rule.ip_protocol == "tcp"])
    error_message = "TLS client rules must use the same deduplicated explicit allowlist."
  }
  assert {
    condition     = length(aws_vpc_security_group_ingress_rule.alb_nat_https) == 1 && aws_vpc_security_group_ingress_rule.alb_nat_https[0].cidr_ipv4 == "192.0.2.1/32" && aws_vpc_security_group_ingress_rule.alb_nat_https[0].from_port == 443 && aws_vpc_security_group_ingress_rule.alb_nat_https[0].to_port == 443 && length(aws_vpc_security_group_ingress_rule.alb_http) == 2 && aws_vpc_security_group_ingress_rule.alb_nat_http.from_port == 80
    error_message = "TLS adds one NAT /32 singleton and preserves HTTP for redirect."
  }
}
run "disabled_has_no_tls_frontend" {
  command = plan
  assert {
    condition     = length(aws_vpc_security_group_ingress_rule.alb_https) == 0 && length(aws_vpc_security_group_ingress_rule.alb_nat_https) == 0
    error_message = "HTTP default must not expose port 443 on the ALB frontend."
  }
}
run "reject_duplicate_nat_tls_cidr" {
  command = plan
  variables {
    enable_lab_https     = true
    lab_dns_zone_id      = "Z0900230TGWPQ92QCQJ8"
    allowed_client_cidrs = ["192.0.2.1/32"]
  }
  expect_failures = [aws_vpc_security_group_ingress_rule.alb_http, aws_vpc_security_group_ingress_rule.alb_nat_http, aws_vpc_security_group_ingress_rule.alb_https, aws_vpc_security_group_ingress_rule.alb_nat_https]
}
run "https_exact_certificate_read_permissions" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z0900230TGWPQ92QCQJ8"
  }
  assert {
    condition     = length(aws_iam_role_policy.github_https) == 1 && length(jsondecode(aws_iam_role_policy.github_https[0].policy).Statement) == 1 && toset(jsondecode(aws_iam_role_policy.github_https[0].policy).Statement[0].Action) == toset(["acm:DescribeCertificate", "acm:ListTagsForCertificate"]) && jsondecode(aws_iam_role_policy.github_https[0].policy).Statement[0].Resource == aws_acm_certificate.lab[0].arn && jsondecode(aws_iam_role_policy.github_https[0].policy).Statement[0].Effect == "Allow"
    error_message = "GitHub gets only two reads of the exact lab certificate; never DNS mutation."
  }
  assert {
    condition     = length([for statement in jsondecode(aws_iam_policy.controller_boundary.policy).Statement : statement if statement.Action == "acm:DescribeCertificate" && statement.Resource == aws_acm_certificate.lab[0].arn && statement.Effect == "Allow"]) == 1 && length([for action in flatten([for statement in jsondecode(aws_iam_policy.controller_boundary.policy).Statement : statement.Action]) : action if startswith(lower(action), "acm:")]) == 1 && !strcontains(lower(aws_iam_policy.controller_boundary.policy), "route53:") && !strcontains(lower(file("${path.module}/identity.tf")), "route53:")
    error_message = "LBC boundary grants only exact-certificate DescribeCertificate; DNS is operator-only."
  }
}
run "http_has_no_certificate_iam_grants" {
  command = plan
  assert {
    condition     = length(aws_iam_role_policy.github_https) == 0 && alltrue([for action in flatten([for statement in jsondecode(aws_iam_policy.controller_boundary.policy).Statement : statement.Action]) : !startswith(lower(action), "acm:") && !startswith(lower(action), "route53:")])
    error_message = "Default HTTP mode must not add ACM or Route53 grants."
  }
}
run "enabled_graph_does_not_key_on_allocated_values" {
  command = plan
  variables {
    enable_lab_https = true
    lab_dns_zone_id  = "Z0900230TGWPQ92QCQJ8"
  }
  override_resource {
    target          = aws_acm_certificate.lab[0]
    override_during = apply
    values          = {}
  }
  override_resource {
    target          = aws_eip.nat
    override_during = apply
    values          = {}
  }
  assert {
    condition     = length(aws_route53_record.lab_certificate_validation) == 1 && length(aws_vpc_security_group_ingress_rule.alb_nat_https) == 1 && length(aws_vpc_security_group_ingress_rule.alb_https) == 1
    error_message = "Unknown ACM validation tokens and NAT EIP must not become resource keys."
  }
}
run "no_parent_zone_or_alias_ownership" {
  command = plan
  assert {
    condition     = length(regexall("resource\\s+\"aws_route53_", join("\n", [for f in fileset(path.module, "*.tf") : file("${path.module}/${f}")]))) == 1 && !strcontains(join("\n", [for f in fileset(path.module, "*.tf") : file("${path.module}/${f}")]), "import {")
    error_message = "This root owns only the validation record: no parent zone, alias, import or other Route53 resource."
  }
}
run "disabled_owns_no_certificate_or_dns" {
  command = plan
  assert {
    condition     = length(data.aws_route53_zone.lab) == 0 && length(aws_acm_certificate.lab) == 0 && length(aws_route53_record.lab_certificate_validation) == 0 && length(aws_acm_certificate_validation.lab) == 0
    error_message = "Default HTTP mode must not read or manage DNS/certificates."
  }
}
