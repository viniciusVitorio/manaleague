output "instance_id" {
  value = aws_instance.app.id
}

output "public_ip" {
  value = aws_eip.app.public_ip
}

output "application_url" {
  value = "https://${var.domain_name}"
}

output "security_group_id" {
  value = aws_security_group.web.id
}

output "ssh_command" {
  value = "ssh -i <sua-chave-privada> ubuntu@${aws_eip.app.public_ip}"
}


output "route53_name_servers" {
  description = "Nameservers que devem ser configurados no registrador do dominio."
  value       = aws_route53_zone.app.name_servers
}

output "ses_mail_from_address" {
  description = "Remetente autorizado pela IAM policy (use em MAIL_FROM_ADDRESS)."
  value       = local.mail_from_address
}

output "ses_configuration_set" {
  description = "Configuration set do SES (use em AWS_SES_CONFIGURATION_SET)."
  value       = aws_sesv2_configuration_set.transactional.configuration_set_name
}

output "ses_next_steps" {
  description = "Passos manuais depois do apply."
  value       = <<-EOT
    1. Aguarde a verificacao do dominio (DKIM): aws sesv2 get-email-identity --email-identity ${var.domain_name} --query 'DkimAttributes.Status'
    2. Contas novas comecam no sandbox (so enviam para enderecos verificados). Peca acesso de producao:
       aws sesv2 put-account-details --production-access-enabled --mail-type TRANSACTIONAL \
         --website-url https://${var.domain_name} --contact-language EN \
         --use-case-description "Transactional password reset and security notification emails for ManaLeague users. Low volume, opt-in accounts only, bounces and complaints auto-suppressed."
    3. Teste na EC2: sudo docker compose -p manaleague exec app php artisan mail:test seu@email.com
  EOT
}

output "avatar_bucket" {
  description = "Bucket privado das fotos de perfil (use na variavel AVATAR_BUCKET do GitHub, com AVATAR_DISK=s3)."
  value       = aws_s3_bucket.avatars.bucket
}
