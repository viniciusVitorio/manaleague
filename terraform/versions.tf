terraform {
  required_version = ">= 1.7.0"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 5.28"
    }
  }
}

provider "aws" {
  region = var.aws_region
}

# Token lido de CLOUDFLARE_API_TOKEN (permissoes: Zone:Edit, DNS:Edit, Zone Settings:Edit, Zone WAF:Edit).
provider "cloudflare" {}
