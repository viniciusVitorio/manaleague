############################################
# Cloudflare na frente da EC2 (plano Free)
#
# - DNS do dominio passa da Route 53 para a Cloudflare (trocar os nameservers no registro.br);
# - site e www "proxied": DDoS, cache de estaticos, Free Managed Ruleset (WAF basico, ligado por padrao);
# - registros de e-mail (SES) ficam "DNS only" — DKIM/SPF/MX nao passam pelo proxy;
# - TLS de ponta a ponta: Full (strict) + certificado Let's Encrypt do Caddy na origem;
# - 1 regra de rate limit (limite do Free) nas rotas de autenticacao;
# - o WAF com OWASP Core Rule Set completo roda na origem (ModSecurity v3 + OWASP CRS, servico waf do docker-compose).
#
# Autenticacao: variavel de ambiente CLOUDFLARE_API_TOKEN (nunca no repositorio nem no tfvars).
############################################

locals {
  dmarc_record = join("; ", compact([
    "v=DMARC1",
    "p=${var.dmarc_policy}",
    var.dmarc_report_email != "" ? "rua=mailto:${var.dmarc_report_email}" : "",
    "adkim=r",
    "aspf=r",
  ]))
  ses_dkim_tokens = aws_sesv2_email_identity.domain.dkim_signing_attributes[0].tokens
}

resource "cloudflare_zone" "app" {
  account = {
    id = var.cloudflare_account_id
  }
  name = var.domain_name
  type = "full"
}

# ---------- Site (passa pelo proxy da Cloudflare) ----------

resource "cloudflare_dns_record" "apex" {
  zone_id = cloudflare_zone.app.id
  name    = var.domain_name
  type    = "A"
  content = aws_eip.app.public_ip
  proxied = true
  ttl     = 1 # automatico (obrigatorio para registros proxied)
  comment = "ManaLeague (EC2) via proxy da Cloudflare"
}

resource "cloudflare_dns_record" "www" {
  zone_id = cloudflare_zone.app.id
  name    = "www.${var.domain_name}"
  type    = "A"
  content = aws_eip.app.public_ip
  proxied = true
  ttl     = 1
  comment = "ManaLeague (EC2) via proxy da Cloudflare"
}

# ---------- E-mail (SES): sempre "DNS only" ----------

resource "cloudflare_dns_record" "ses_dkim" {
  count   = 3
  zone_id = cloudflare_zone.app.id
  name    = "${local.ses_dkim_tokens[count.index]}._domainkey.${var.domain_name}"
  type    = "CNAME"
  content = "${local.ses_dkim_tokens[count.index]}.dkim.amazonses.com"
  proxied = false
  ttl     = 600
}

resource "cloudflare_dns_record" "ses_mail_from_mx" {
  zone_id  = cloudflare_zone.app.id
  name     = local.mail_from_domain
  type     = "MX"
  content  = "feedback-smtp.${var.aws_region}.amazonses.com"
  priority = 10
  ttl      = 600
}

resource "cloudflare_dns_record" "ses_mail_from_spf" {
  zone_id = cloudflare_zone.app.id
  name    = local.mail_from_domain
  type    = "TXT"
  content = "\"v=spf1 include:amazonses.com -all\""
  ttl     = 600
}

resource "cloudflare_dns_record" "dmarc" {
  zone_id = cloudflare_zone.app.id
  name    = "_dmarc.${var.domain_name}"
  type    = "TXT"
  content = "\"${local.dmarc_record}\""
  ttl     = 600
}

# ---------- TLS ----------

# Full (strict): a Cloudflare so fala com a origem por HTTPS e exige certificado valido
# (o Caddy renova o Let's Encrypt sozinho; o desafio HTTP-01 funciona atras do proxy).
resource "cloudflare_zone_setting" "ssl" {
  zone_id    = cloudflare_zone.app.id
  setting_id = "ssl"
  value      = "strict"
}

resource "cloudflare_zone_setting" "always_use_https" {
  zone_id    = cloudflare_zone.app.id
  setting_id = "always_use_https"
  value      = "on"
}

resource "cloudflare_zone_setting" "min_tls_version" {
  zone_id    = cloudflare_zone.app.id
  setting_id = "min_tls_version"
  value      = "1.2"
}

# ---------- Rate limit na borda (Free: 1 regra, janela de 10 s, filtro so por caminho) ----------

resource "cloudflare_ruleset" "auth_rate_limit" {
  zone_id     = cloudflare_zone.app.id
  name        = "ManaLeague - rate limit de autenticacao"
  description = "Barra rajadas contra login/cadastro/recuperacao antes de chegarem na EC2"
  kind        = "zone"
  phase       = "http_ratelimit"

  rules = [
    {
      ref         = "auth_endpoints_burst"
      description = "Mais de 10 requisicoes em 10 s por IP nas rotas de autenticacao"
      expression  = "(http.request.uri.path in {\"/login\" \"/register\" \"/forgot-password\" \"/reset-password\"})"
      action      = "block"
      ratelimit = {
        characteristics     = ["ip.src", "cf.colo.id"]
        period              = 10
        requests_per_period = 10
        mitigation_timeout  = 10
      }
    }
  ]
}

# Faixas oficiais da Cloudflare: usadas para fechar a origem (var.origin_ingress = "cloudflare").
data "cloudflare_ip_ranges" "cloudflare" {}
