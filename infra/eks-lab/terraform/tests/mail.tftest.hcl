mock_provider "aws" {
  mock_data "aws_availability_zones" { defaults = { names = ["us-east-1a", "us-east-1b"] } }
  mock_data "aws_caller_identity" { defaults = { account_id = "123456789012" } }
  mock_data "aws_eks_addon_version" { defaults = { version = "v1.0.0-eksbuild.1" } }
  mock_resource "aws_eks_cluster" { defaults = { identity = [{ oidc = [{ issuer = "https://oidc.eks.us-east-1.amazonaws.com/id/LAB" }] }] } }
}
variables {
  expected_account_id       = "123456789012"
  provisioner_principal_arn = "arn:aws:iam::123456789012:role/lab-operator"
  github_oidc_provider_arn  = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
  api_public_access_cidrs   = ["203.0.113.10/32"]
  allowed_client_cidrs      = ["198.51.100.10/32"]
  enable_lab_https          = false
  lab_dns_zone_id           = ""
  aws_region                = "us-east-1"
}
run "mail_disabled" {
  command = plan
  variables { enable_lab_mail = false }
  assert {
    condition     = length(aws_iam_role_policy.app_mail) == 0 && output.lab_mail_enabled == false && output.lab_mail_from == ""
    error_message = "Disabled mail must not grant SES access or expose a sender."
  }
}
run "mail_enabled_exact_resources_and_sender" {
  command = plan
  variables { enable_lab_mail = true }
  assert {
    condition = try(
      length(jsondecode(aws_iam_role_policy.app_mail[0].policy).Statement) == 1 &&
      toset(jsondecode(aws_iam_role_policy.app_mail[0].policy).Statement[0].Resource) == toset([
        "arn:aws:ses:us-east-1:123456789012:identity/manaleague.com.br",
        "arn:aws:ses:us-east-1:123456789012:configuration-set/manaleague-transactional"
      ]) &&
      toset(jsondecode(aws_iam_role_policy.app_mail[0].policy).Statement[0].Action) == toset(["ses:SendEmail", "ses:SendRawEmail"]) &&
      jsondecode(aws_iam_role_policy.app_mail[0].policy).Statement[0].Effect == "Allow" &&
      jsondecode(aws_iam_role_policy.app_mail[0].policy).Statement[0].Condition.StringEquals["ses:FromAddress"] == "eks-lab@manaleague.com.br" &&
      output.lab_mail_from == "eks-lab@manaleague.com.br",
      false
    )
    error_message = "Only lab sender may send via the exact domain identity and transactional configuration set."
  }
}
