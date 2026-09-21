# Modelo de ameacas — STRIDE

## Ativos

- credenciais e sessoes;
- torneios, jogadores, placares e tokens publicos;
- chave SSH, `APP_KEY` e tokens do pipeline;
- integridade do codigo implantado;
- disponibilidade da aplicacao.

## Ameacas e controles

| STRIDE | Ameaca | Risco | Controle |
|---|---|---:|---|
| Spoofing | adivinhar senha | Alto | hash forte, senha minima, rate limit e sessao regenerada |
| Tampering | alterar torneio/placar de outro organizador | Alto | TournamentPolicy, escopo por torneio e teste 403 |
| Repudiation | negar operacao realizada | Medio | logs centralizados no stderr e historico do pipeline |
| Information Disclosure | `.env`/chaves no Git | Critico | `.gitignore`, GitHub Secrets e Trivy |
| Denial of Service | muitas tentativas de login | Medio | throttle; evolucao futura: WAF e rate limit no Nginx |
| Elevation of Privilege | trocar `user_id` no formulario | Alto | `user_id` nunca e aceito como entrada; vem da sessao |
| Supply Chain | dependencia vulneravel | Alto | Composer Audit, Trivy e dependencias versionadas |
| Infrastructure | SSH aberto ao mundo | Alto | CIDR `/32`, chave ed25519, regra efemera para CI e IMDSv2 |
| Business logic | gerar confrontos duplicados | Medio | transacao, lock pessimista e validacao de estado |
| Information Disclosure | adivinhar pagina publica | Medio | token aleatorio de 40 caracteres, sem IDs sequenciais na URL publica |

## Abusos testados

1. visitante acessa `/tournaments`: redirecionado para login;
2. organizador B tenta abrir ou alterar torneio de A: HTTP 403;
3. partida de outro torneio e enviada na URL: HTTP 404;
4. placar decisivo empatado: validacao rejeita;
5. requisicao POST sem CSRF: middleware rejeita;
6. token publico inexistente: HTTP 404;
7. scanner encontra vulnerabilidade HIGH/CRITICAL: job falha e deploy nao inicia.

## Riscos residuais

- a configuracao academica publica apenas HTTP; para uso real, adicionar dominio e TLS com certificado ACM/Let's Encrypt;
- SQLite nao e indicado para alta concorrencia; migrar para RDS PostgreSQL em crescimento;
- backups e monitoramento gerenciado ficam como evolucao futura.
