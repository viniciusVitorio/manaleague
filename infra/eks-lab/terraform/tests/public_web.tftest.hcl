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
  deploy_runtime_image      = ""
  deploy_app_secret_arn     = ""
  enable_deploy_broker      = false
}
run "public_http" {
  command = plan
  variables {
    enable_public_lab_web = true
    enable_lab_https      = false
    lab_dns_zone_id       = ""
  }
  assert {
    condition     = length(aws_vpc_security_group_ingress_rule.alb_public_http) == 1 && aws_vpc_security_group_ingress_rule.alb_public_http[0].cidr_ipv4 == "0.0.0.0/0" && aws_vpc_security_group_ingress_rule.alb_public_http[0].from_port == 80 && aws_vpc_security_group_ingress_rule.alb_public_http[0].to_port == 80 && aws_vpc_security_group_ingress_rule.alb_public_http[0].ip_protocol == "tcp" && aws_vpc_security_group_ingress_rule.alb_public_http[0].security_group_id == aws_security_group.alb.id
    error_message = "Public opt-in must add TCP 80 to ALB only."
  }
  assert {
    condition     = length(aws_vpc_security_group_ingress_rule.alb_public_https) == 0 && var.api_public_access_cidrs == tolist(["203.0.113.10/32"]) && toset([for rule in aws_vpc_security_group_ingress_rule.alb_http : rule.cidr_ipv4]) == toset(var.allowed_client_cidrs)
    error_message = "HTTPS stays off and restricted client/API CIDRs remain intact."
  }
}

run "public_https" {
  command = plan
  variables {
    enable_public_lab_web = true
    enable_lab_https      = true
    lab_dns_zone_id       = "Z0900230TGWPQ92QCQJ8"
  }
  assert {
    condition     = length(aws_vpc_security_group_ingress_rule.alb_public_https) == 1 && aws_vpc_security_group_ingress_rule.alb_public_https[0].cidr_ipv4 == "0.0.0.0/0" && aws_vpc_security_group_ingress_rule.alb_public_https[0].from_port == 443 && aws_vpc_security_group_ingress_rule.alb_public_https[0].to_port == 443 && aws_vpc_security_group_ingress_rule.alb_public_https[0].ip_protocol == "tcp" && aws_vpc_security_group_ingress_rule.alb_public_https[0].security_group_id == aws_security_group.alb.id
    error_message = "HTTPS opt-in must add TCP 443 to ALB only."
  }
}

run "public_disabled_with_https" {
  command = plan
  variables {
    enable_public_lab_web = false
    enable_lab_https      = true
    lab_dns_zone_id       = "Z0900230TGWPQ92QCQJ8"
  }
  assert {
    condition     = length(aws_vpc_security_group_ingress_rule.alb_public_http) == 0 && length(aws_vpc_security_group_ingress_rule.alb_public_https) == 0
    error_message = "Public web remains disabled even with HTTPS enabled."
  }
}
run "public_disabled_http" {
  command = plan
  variables {
    enable_public_lab_web = false
    enable_lab_https      = false
    lab_dns_zone_id       = ""
  }
  assert {
    condition     = length(aws_vpc_security_group_ingress_rule.alb_public_http) == 0 && length(aws_vpc_security_group_ingress_rule.alb_public_https) == 0
    error_message = "Disabled web must create no public rules."
  }
}
run "reject_public_client_cidr" {
  command = plan
  variables {
    enable_public_lab_web = true
    enable_lab_https      = false
    lab_dns_zone_id       = ""
    allowed_client_cidrs  = ["0.0.0.0/0"]
  }
  expect_failures = [var.allowed_client_cidrs]
}
