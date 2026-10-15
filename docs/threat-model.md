# Modelo de ameacas — STRIDE

## Ativos

- credenciais e sessoes;
- torneios, jogadores, placares e tokens publicos;
- dados pessoais dos jogadores (e-mail, historico);
- chave SSH, `APP_KEY` e tokens do pipeline;
- integridade do codigo implantado;
- disponibilidade da aplicacao.

## Ameacas e controles

| STRIDE | Ameaca | Risco | Controle |
|---|---|---:|---|
| Spoofing | adivinhar senha | Alto | hash forte, senha minima, rate limit, sessao regenerada e log de falhas de login |
| Tampering | alterar torneio/placar de outro organizador | Alto | TournamentPolicy, escopo por torneio e teste 403 |
| Tampering | reescrever resultado da liga depois das finais para mudar finalistas | Medio | liga travada apos gerar as finais; cada partida so e editavel na sua fase |
| Repudiation | negar alteracao de placar | Medio | log `match.result_saved` (usuario, antes/depois) no stderr e historico do pipeline |
| Information Disclosure | `.env`/chaves no Git | Critico | `.gitignore`, `APP_KEY` obrigatoria via ambiente (sem valor padrao), GitHub Secrets e Trivy |
| Information Disclosure | e-mail de um usuario exposto a outro | Medio | pedidos de amizade exibem apenas apelido; perfis privados |
| Information Disclosure | adivinhar pagina publica | Medio | slug com sufixo aleatorio de 16 chars (~82 bits) + `throttle:60,1`; sem IDs sequenciais |
| Information Disclosure / XSS | carregar script ou frame externo | Medio | escape do Blade, CSP `default-src 'self'`, `frame-ancestors 'self'`, `object-src 'none'` |
| Denial of Service | muitas tentativas de login/convite/arena | Medio | throttle por rota; evolucao futura: WAF |
| Elevation of Privilege | trocar `user_id` no formulario | Alto | `user_id` nunca e aceito como entrada; vem da sessao |
| Supply Chain | dependencia vulneravel | Alto | Composer Audit, Trivy e dependencias versionadas |
| Infrastructure | SSH aberto ao mundo | Alto | CIDR `/32`, chave ed25519, regra efemera para CI e IMDSv2 |
| Business logic | gerar confrontos duplicados | Medio | transacao, lock pessimista e validacao de estado |
| Business logic | inscricoes simultaneas estourarem o limite / apelido duplicado gerar erro 500 | Medio | checagem de vagas e de nome dentro de transacao com lock; erro amigavel |
| Spoofing | tomar conta via "esqueci a senha" | Alto | token aleatorio com hash, expira em 60 min, uso unico, throttle; e-mail de aviso a cada troca; sessoes antigas revogadas |
| Information Disclosure | descobrir quais e-mails tem conta pelo formulario de recuperacao | Medio | mensagem identica para e-mail existente ou nao |
| Spoofing | e-mail falso em nome do ManaLeague (phishing) | Medio | DKIM + SPF (MAIL FROM proprio) + DMARC |
| Elevation of Privilege | chave AWS vazada da aplicacao | Alto | sem chave estatica: IAM Role com credenciais temporarias, so envio de e-mail e so pelo remetente oficial |
| Tampering | cliente forjar dados de carta (nome, tipo, imagem) | Medio | apenas o ID trafega; o servidor consulta o Scryfall; URLs de imagem validadas e restritas pela CSP |
| Information Disclosure | enumerar jogadores e enviar pedidos em massa | Baixo | perfis privados (so com codigo), busca minima de 3 letras, rate limit e teto de pedidos pendentes |
| Denial of Service | busca de cartas sobrecarregar a API externa | Baixo | cache de 12h/7d, debounce no cliente, throttle 40/min, falhas nao cacheadas e erro amigavel |

## Abusos testados

1. visitante acessa `/tournaments`: redirecionado para login;
2. organizador B tenta abrir ou alterar torneio de A: HTTP 403;
3. partida de outro torneio e enviada na URL: HTTP 404;
4. placar decisivo empatado: validacao rejeita;
5. placar da liga alterado depois das finais: HTTP 409 e valor preservado;
6. inscricao alem do limite de vagas: HTTP 409;
7. requisicao POST sem CSRF: middleware rejeita;
8. token publico inexistente: HTTP 404; 61a requisicao por minuto: HTTP 429;
9. resposta sem headers de seguranca: teste falha;
10. scanner encontra vulnerabilidade HIGH/CRITICAL: job falha e deploy nao inicia.

## Riscos residuais

- CSP ainda permite `'unsafe-inline'` para os pequenos handlers inline (copiar link, confirmacoes); migrar para JS externo com nonce;
- a `APP_KEY` de exemplo usada nas primeiras versoes continua no historico do Git — nunca reutiliza-la (producao usa a chave do GitHub Secrets);
- SQLite nao e indicado para alta concorrencia; migrar para RDS PostgreSQL em crescimento;
- backups e monitoramento gerenciado ficam como evolucao futura.
