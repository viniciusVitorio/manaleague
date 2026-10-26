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
| Tampering / XSS | foto de perfil maliciosa (SVG com script, polyglot JPEG+HTML/PHP, extensao falsa) | Alto | so JPG/PNG/WebP conferidos pelo conteudo; a imagem e decodificada pelo GD e **reencodada** em WebP 256x256, entao nada do arquivo original e gravado; `nosniff` e `Content-Type: image/webp` |
| Information Disclosure | EXIF da foto vazar localizacao (GPS) e aparelho | Medio | reencodar descarta todos os metadados; a rotacao do EXIF e aplicada antes |
| Information Disclosure | bucket S3 publico / enumerar fotos | Medio | Block Public Access + `BucketOwnerEnforced`, leitura so por URL assinada (ate ~2h), nome do objeto e UUID aleatorio, policy nega acesso sem TLS |
| Elevation of Privilege | credencial da EC2 usada para mexer no bucket inteiro | Medio | IAM Role so com `Put/Get/DeleteObject` em `avatars/*` (sem listar, sem ACL/policy) |
| Denial of Service | imagem gigante ("bomba de descompressao") ou upload em massa | Medio | limite de 5 MB e 16 MP checado antes de decodificar, `throttle:10,1` nas rotas de foto |

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
10. scanner encontra vulnerabilidade HIGH/CRITICAL: job falha e deploy nao inicia;
11. foto SVG, texto disfarcado de PNG, GIF, imagem pequena ou grande demais: rejeitada sem gravar nada;
12. JPEG com EXIF "GPS" e payload `<?php`/`<script>` no fim: o arquivo salvo nao contem nenhum dos dois;
13. caminho adulterado no banco (`../../.env`): nunca vira URL; rota `/avatars/` so aceita `<uuid>.webp`;
14. S3 fora do ar no upload: mensagem amigavel, sem HTTP 500 e sem perder a foto atual.

## Riscos residuais

- CSP ainda permite `'unsafe-inline'` para os pequenos handlers inline (copiar link, confirmacoes); migrar para JS externo com nonce;
- a `APP_KEY` de exemplo usada nas primeiras versoes continua no historico do Git — nunca reutiliza-la (producao usa a chave do GitHub Secrets);
- SQLite nao e indicado para alta concorrencia; migrar para RDS PostgreSQL em crescimento;
- backups e monitoramento gerenciado ficam como evolucao futura;
- fotos de perfil nao passam por moderacao de conteudo (evolucao: Amazon Rekognition ou denuncia/remocao por admin);
- uma URL assinada vazada continua valida ate expirar (no maximo ~2h); objetos orfaos (falha ao apagar a foto antiga) ficam no bucket ate limpeza manual.
