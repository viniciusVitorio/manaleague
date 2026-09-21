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
