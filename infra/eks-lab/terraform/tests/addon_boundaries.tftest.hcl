# Render-only offline fixture; never contacts AWS.
mock_provider "aws" {
  mock_data "aws_availability_zones" { defaults = { names = ["us-east-1a", "us-east-1b"] } }
  mock_data "aws_caller_identity" { defaults = { account_id = "123456789012" } }
  mock_data "aws_eks_addon_version" { defaults = { version = "v1.55.0-eksbuild.2" } }
  mock_resource "aws_eks_cluster" { defaults = { identity = [{ oidc = [{ issuer = "https://oidc.eks.us-east-1.amazonaws.com/id/LAB" }] }] } }
  mock_resource "aws_iam_policy" {
    override_during = plan
    defaults        = { arn = "arn:aws:iam::123456789012:policy/mock-boundary" }
  }
}
variables {
  expected_account_id       = "123456789012"
  provisioner_principal_arn = "arn:aws:iam::123456789012:role/lab-operator"
  github_oidc_provider_arn  = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
  api_public_access_cidrs   = ["203.0.113.10/32"]
  allowed_client_cidrs      = ["198.51.100.10/32"]
}
override_resource {
  target          = aws_vpc.lab
  override_during = plan
  values          = { id = "vpc-0123456789abcdef0", arn = "arn:aws:ec2:us-east-1:123456789012:vpc/vpc-0123456789abcdef0" }
}
override_resource {
  target          = aws_subnet.public[0]
  override_during = plan
  values          = { id = "subnet-0123456789abcdef0", arn = "arn:aws:ec2:us-east-1:123456789012:subnet/subnet-0123456789abcdef0" }
}
override_resource {
  target          = aws_subnet.public[1]
  override_during = plan
  values          = { id = "subnet-0123456789abcdef1", arn = "arn:aws:ec2:us-east-1:123456789012:subnet/subnet-0123456789abcdef1" }
}
override_resource {
  target          = aws_security_group.nodes
  override_during = plan
  values          = { id = "sg-0123456789abcdef0", arn = "arn:aws:ec2:us-east-1:123456789012:security-group/sg-0123456789abcdef0" }
}
override_resource {
  target          = aws_iam_policy.vpc_cni_boundary
  override_during = plan
  values          = { arn = "arn:aws:iam::123456789012:policy/mock-vpc-cni-boundary" }
}
override_resource {
  target          = aws_iam_policy.ebs_csi_boundary
  override_during = plan
  values          = { arn = "arn:aws:iam::123456789012:policy/mock-ebs-csi-boundary" }
}
run "render_addon_boundaries" {
  command = plan
}
