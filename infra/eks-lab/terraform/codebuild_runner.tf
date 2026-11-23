# Disabled pending a security redesign: webhook filters are not authorization,
# and untrusted jobs can use the service role and its connection token.
variable "enable_codebuild_runner" {
  type    = bool
  default = false
  validation {
    condition     = !var.enable_codebuild_runner
    error_message = "CodeBuild runner is disabled until untrusted job and connection-token access are secured and independently reviewed."
  }
}

variable "codebuild_connection_arn" {
  type    = string
  default = ""
  validation {
    condition     = var.codebuild_connection_arn == "" || can(regex("^arn:aws:(codestar-connections|codeconnections):[a-z0-9-]+:[0-9]{12}:connection/[a-f0-9-]+$", var.codebuild_connection_arn))
    error_message = "Provide a CodeConnections connection ARN."
  }
}

variable "codebuild_repository_url" {
  type    = string
  default = ""
  validation {
    condition     = var.codebuild_repository_url == "" || can(regex("^https://github[.]com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$", var.codebuild_repository_url))
    error_message = "Provide an exact GitHub repository HTTPS URL."
  }
}

locals {
  runner_name = "${local.name}-runner"
}

resource "aws_security_group" "codebuild_runner" {
  count       = var.enable_codebuild_runner ? 1 : 0
  name        = local.runner_name
  description = "Ephemeral runner with outbound HTTP and HTTPS"
  vpc_id      = aws_vpc.lab.id
}
resource "aws_vpc_security_group_egress_rule" "runner_https" {
  count             = var.enable_codebuild_runner ? 1 : 0
  security_group_id = aws_security_group.codebuild_runner[0].id
  cidr_ipv4         = "0.0.0.0/0"
  from_port         = 443
  to_port           = 443
  ip_protocol       = "tcp"
}
resource "aws_vpc_security_group_egress_rule" "runner_http" {
  count             = var.enable_codebuild_runner ? 1 : 0
  security_group_id = aws_security_group.codebuild_runner[0].id
  cidr_ipv4         = "0.0.0.0/0"
  from_port         = 80
  to_port           = 80
  ip_protocol       = "tcp"
}
resource "aws_vpc_security_group_ingress_rule" "cluster_runner" {
  count                        = var.enable_codebuild_runner ? 1 : 0
  security_group_id            = aws_security_group.cluster.id
  referenced_security_group_id = aws_security_group.codebuild_runner[0].id
  from_port                    = 443
  to_port                      = 443
  ip_protocol                  = "tcp"
}
resource "aws_cloudwatch_log_group" "codebuild_runner" {
  count             = var.enable_codebuild_runner ? 1 : 0
  name              = "/aws/codebuild/${local.runner_name}"
  retention_in_days = 7
}
resource "aws_iam_role" "codebuild_runner" {
  count = var.enable_codebuild_runner ? 1 : 0
  name  = local.runner_name
  assume_role_policy = jsonencode({ Version = "2012-10-17", Statement = [{
    Effect    = "Allow", Principal = { Service = "codebuild.amazonaws.com" }, Action = "sts:AssumeRole"
    Condition = { StringEquals = { "aws:SourceAccount" = var.expected_account_id }, ArnEquals = { "aws:SourceArn" = "arn:aws:codebuild:${var.aws_region}:${var.expected_account_id}:project/${local.runner_name}" } }
  }] })
}
resource "aws_iam_role_policy" "codebuild_runner" {
  count = var.enable_codebuild_runner ? 1 : 0
  role  = aws_iam_role.codebuild_runner[0].id
  policy = jsonencode({ Version = "2012-10-17", Statement = [
    { Effect = "Allow", Action = ["logs:CreateLogStream", "logs:PutLogEvents"], Resource = "${aws_cloudwatch_log_group.codebuild_runner[0].arn}:*" },
    { Effect = "Allow", Action = ["codeconnections:UseConnection", "codeconnections:GetConnection", "codeconnections:GetConnectionToken", "codestar-connections:UseConnection", "codestar-connections:GetConnection", "codestar-connections:GetConnectionToken"], Resource = var.codebuild_connection_arn },
    { Effect = "Allow", Action = ["ec2:CreateNetworkInterface", "ec2:DeleteNetworkInterface", "ec2:DescribeDhcpOptions", "ec2:DescribeNetworkInterfaces", "ec2:DescribeSubnets", "ec2:DescribeSecurityGroups", "ec2:DescribeVpcs"], Resource = "*" },
    { Effect = "Allow", Action = "ec2:CreateNetworkInterfacePermission", Resource = "arn:aws:ec2:${var.aws_region}:${var.expected_account_id}:network-interface/*", Condition = { StringEquals = { "ec2:AuthorizedService" = "codebuild.amazonaws.com", "ec2:Subnet" = [for subnet in aws_subnet.database : subnet.arn] } } }
  ] })
}
resource "aws_codebuild_project" "runner" {
  count                  = var.enable_codebuild_runner ? 1 : 0
  name                   = local.runner_name
  service_role           = aws_iam_role.codebuild_runner[0].arn
  build_timeout          = 60
  queued_timeout         = 60
  concurrent_build_limit = 2
  artifacts { type = "NO_ARTIFACTS" }
  environment {
    compute_type    = "BUILD_GENERAL1_SMALL"
    image           = "aws/codebuild/standard:7.0"
    type            = "LINUX_CONTAINER"
    privileged_mode = false
  }
  source {
    type     = "GITHUB"
    location = var.codebuild_repository_url
    auth {
      type     = "CODECONNECTIONS"
      resource = var.codebuild_connection_arn
    }
  }
  vpc_config {
    vpc_id             = aws_vpc.lab.id
    subnets            = [for subnet in aws_subnet.database : subnet.id]
    security_group_ids = [aws_security_group.codebuild_runner[0].id]
  }
  logs_config {
    cloudwatch_logs {
      group_name = aws_cloudwatch_log_group.codebuild_runner[0].name
    }
  }
  lifecycle {
    precondition {
      condition     = var.codebuild_connection_arn != "" && var.codebuild_repository_url != "" && split(":", var.codebuild_connection_arn)[3] == var.aws_region && split(":", var.codebuild_connection_arn)[4] == var.expected_account_id && trimsuffix(var.codebuild_repository_url, ".git") == "https://github.com/${split(":", var.github_oidc_subject)[1]}"
      error_message = "Runner requires an in-account, in-region connection and the same repository as the GitHub OIDC subject."
    }
  }
  depends_on = [aws_route.private_nat, aws_route_table_association.database, aws_iam_role_policy.codebuild_runner]
}
resource "aws_codebuild_webhook" "runner" {
  count        = var.enable_codebuild_runner ? 1 : 0
  project_name = aws_codebuild_project.runner[0].name
  build_type   = "BUILD"
  filter_group {
    filter {
      type    = "EVENT"
      pattern = "WORKFLOW_JOB_QUEUED"
    }
    filter {
      type    = "WORKFLOW_NAME"
      pattern = "^(SSDLC Pipeline|EKS lab checks and deploy)$"
    }
  }
}
output "codebuild_runner_project_name" {
  value = var.enable_codebuild_runner ? aws_codebuild_project.runner[0].name : null
}
