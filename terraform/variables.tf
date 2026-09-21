variable "aws_region" {
  description = "Regiao AWS usada pelo trabalho."
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
