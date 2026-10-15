variable "aws_region" {
  description = "Regiao AWS usada pelo trabalho (EC2 e SES)."
  type        = string
  default     = "us-east-1"
}

variable "instance_type" {
  description = "Tipo da EC2."
  type        = string
  default     = "t3.micro"
}

variable "public_key_path" {
  description = "Caminho da chave SSH publica local, por exemplo ~/.ssh/manaleague.pub."
  type        = string
}

variable "ssh_allowed_cidr" {
  description = "CIDR autorizado a acessar SSH. Use seu IP/32, nunca 0.0.0.0/0 em producao."
  type        = string
}

variable "project_name" {
  description = "Prefixo dos recursos."
  type        = string
  default     = "manaleague"
}

variable "domain_name" {
  description = "Dominio publico da aplicacao."
  type        = string
  default     = "manaleague.com.br"
}

variable "mail_from_local_part" {
  description = "Parte local do remetente dos e-mails transacionais (antes do @)."
  type        = string
  default     = "nao-responda"
}

variable "dmarc_report_email" {
  description = "E-mail que recebe relatorios agregados de DMARC (opcional; vazio desativa rua=)."
  type        = string
  default     = ""
}

variable "dmarc_policy" {
  description = "Politica DMARC: comece com none, observe os relatorios e depois suba para quarantine/reject."
  type        = string
  default     = "none"

  validation {
    condition     = contains(["none", "quarantine", "reject"], var.dmarc_policy)
    error_message = "dmarc_policy deve ser none, quarantine ou reject."
  }
}
