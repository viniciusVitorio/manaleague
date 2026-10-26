############################################
# Amazon SES — e-mail transacional
#
# - Identidade de dominio com DKIM (Easy DKIM, RSA 2048) publicada no Route 53;
# - MAIL FROM customizado (mail.<dominio>) com SPF, para alinhamento SPF/DMARC;
# - DMARC no dominio;
# - Configuration set com TLS obrigatorio e supressao automatica de bounces/reclamacoes;
# - IAM Role da EC2 com permissao minima: so envia, so pelo dominio e so com o remetente definido.
#   A aplicacao nao usa chave de acesso estatica.
############################################

locals {
  mail_from_address = "${var.mail_from_local_part}@${var.domain_name}"
  mail_from_domain  = "mail.${var.domain_name}"
}

resource "aws_sesv2_configuration_set" "transactional" {
  configuration_set_name = "${var.project_name}-transactional"

  delivery_options {
    tls_policy = "REQUIRE"
  }

  reputation_options {
    reputation_metrics_enabled = true
  }

  sending_options {
    sending_enabled = true
  }

  suppression_options {
    suppressed_reasons = ["BOUNCE", "COMPLAINT"]
  }

  tags = {
    Name        = "${var.project_name}-transactional"
    Environment = "academic"
    ManagedBy   = "Terraform"
  }
}

resource "aws_sesv2_email_identity" "domain" {
  email_identity         = var.domain_name
  configuration_set_name = aws_sesv2_configuration_set.transactional.configuration_set_name

  dkim_signing_attributes {
    next_signing_key_length = "RSA_2048_BIT"
  }

  tags = {
    Name        = var.domain_name
    Environment = "academic"
    ManagedBy   = "Terraform"
  }
}

# Easy DKIM: 3 registros CNAME.
resource "aws_route53_record" "ses_dkim" {
  count   = 3
  zone_id = aws_route53_zone.app.zone_id
  name    = "${aws_sesv2_email_identity.domain.dkim_signing_attributes[0].tokens[count.index]}._domainkey.${var.domain_name}"
  type    = "CNAME"
  ttl     = 600
  records = ["${aws_sesv2_email_identity.domain.dkim_signing_attributes[0].tokens[count.index]}.dkim.amazonses.com"]
}

resource "aws_sesv2_email_identity_mail_from_attributes" "domain" {
  email_identity         = aws_sesv2_email_identity.domain.email_identity
  mail_from_domain       = local.mail_from_domain
  behavior_on_mx_failure = "USE_DEFAULT_VALUE"
}

resource "aws_route53_record" "ses_mail_from_mx" {
  zone_id = aws_route53_zone.app.zone_id
  name    = local.mail_from_domain
  type    = "MX"
  ttl     = 600
  records = ["10 feedback-smtp.${var.aws_region}.amazonses.com"]
}

resource "aws_route53_record" "ses_mail_from_spf" {
  zone_id = aws_route53_zone.app.zone_id
  name    = local.mail_from_domain
  type    = "TXT"
  ttl     = 600
  records = ["v=spf1 include:amazonses.com -all"]
}

resource "aws_route53_record" "dmarc" {
  zone_id = aws_route53_zone.app.zone_id
  name    = "_dmarc.${var.domain_name}"
  type    = "TXT"
  ttl     = 600
  records = [join("; ", compact([
    "v=DMARC1",
    "p=${var.dmarc_policy}",
    var.dmarc_report_email != "" ? "rua=mailto:${var.dmarc_report_email}" : "",
    "adkim=r",
    "aspf=r",
  ]))]
}

############################################
# IAM: credenciais temporarias para a EC2 (via IMDSv2)
############################################

data "aws_iam_policy_document" "ec2_assume" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "app" {
  name               = "${var.project_name}-app"
  description        = "Role da EC2 do ManaLeague: envio de e-mail pelo SES e fotos de perfil no S3."
  assume_role_policy = data.aws_iam_policy_document.ec2_assume.json

  tags = {
    Environment = "academic"
    ManagedBy   = "Terraform"
  }
}

data "aws_iam_policy_document" "ses_send" {
  statement {
    sid     = "SendTransactionalMail"
    actions = ["ses:SendEmail", "ses:SendRawEmail"]
    resources = [
      aws_sesv2_email_identity.domain.arn,
      aws_sesv2_configuration_set.transactional.arn,
    ]

    # So permite o remetente oficial: um invasor com acesso a instancia nao consegue
    # enviar e-mails como outro endereco do dominio.
    condition {
      test     = "StringEquals"
      variable = "ses:FromAddress"
      values   = [local.mail_from_address]
    }
  }
}

resource "aws_iam_role_policy" "ses_send" {
  name   = "ses-send-transactional"
  role   = aws_iam_role.app.id
  policy = data.aws_iam_policy_document.ses_send.json
}

resource "aws_iam_instance_profile" "app" {
  name = "${var.project_name}-app"
  role = aws_iam_role.app.name
}
