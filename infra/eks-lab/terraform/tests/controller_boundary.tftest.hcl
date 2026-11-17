# Offline render fixture for tests/test_controller_boundary.py; no real provider.
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
run "render_controller_boundary" {
  command = plan
  assert {
    condition     = jsondecode(aws_iam_policy.controller_boundary.policy).Version == "2012-10-17"
    error_message = "The evaluator requires the actual rendered IAM boundary."
  }
}
