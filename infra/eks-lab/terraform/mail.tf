variable "enable_lab_mail" {
  description = "Opt in to real SES delivery from the lab; the default keeps mail in Laravel's log driver."
  type        = bool
  default     = false
}
locals {
  # Lab-only sender on the already verified parent domain. The lab never sends
  # as the production address and never verifies or mutates an SES identity.
  lab_mail_from     = "eks-lab@${local.lab_dns_zone}"
  lab_mail_identity = "arn:aws:ses:${var.aws_region}:${var.expected_account_id}:identity/${local.lab_dns_zone}"
}
resource "aws_iam_role_policy" "app_mail" {
  count = var.enable_lab_mail ? 1 : 0
  name  = "${local.name}-ses-send"
  role  = aws_iam_role.irsa["app"].id
  # Send only through the parent identity and its existing default configuration
  # set, and only as the lab address; production senders remain disallowed.
  policy = jsonencode({ Version = "2012-10-17", Statement = [{
    Sid    = "SendOnlyAsLabAddress"
    Effect = "Allow"
    Action = ["ses:SendEmail", "ses:SendRawEmail"]
    Resource = [
      local.lab_mail_identity,
      "arn:aws:ses:${var.aws_region}:${var.expected_account_id}:configuration-set/manaleague-transactional"
    ]
    Condition = { StringEquals = { "ses:FromAddress" = local.lab_mail_from } }
  }] })
}
output "lab_mail_enabled" {
  value = var.enable_lab_mail
}
output "lab_mail_from" {
  value = var.enable_lab_mail ? local.lab_mail_from : ""
}
