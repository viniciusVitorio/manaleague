# Arquitetura

## Componentes

| Componente | Responsabilidade |
|---|---|
| Browser | interface e envio de formularios com CSRF |
| Cloudflare (Free) | DNS, proxy reverso na borda, protecao DDoS, Free Managed Ruleset e rate limit das rotas de autenticacao |
| Caddy | porta publica (80/443), TLS automatico (Let's Encrypt), IP real via `CF-Connecting-IP`, compressao, headers e proxy reverso para o WAF |
| WAF: Nginx + ModSecurity v3 + OWASP CRS v4 | imagem oficial `owasp/modsecurity-crs` (tag LTS fixa, sem porta publicada): inspeciona toda requisicao com o **OWASP Core Rule Set**, serve o `public/` e passa so o `index.php` para o PHP-FPM |
| Laravel/PHP-FPM | autenticacao, autorizacao, round-robin, ranking, finais e headers de seguranca (`SecurityHeaders`) |
| SQLite | usuarios, torneios, jogadores, partidas e sessoes |
| Amazon S3 (privado) | fotos de perfil (`avatars/<uuid>.webp`), lidas pelo navegador via URL assinada |
| GitHub Actions | CI, scanners, quality gate e CD |
| SonarQube Cloud | analise estatica e Quality Gate |
| Terraform | EC2, chave e firewall reproduziveis |

## Fluxo de requisicao

```text
Internet → Cloudflare (DDoS, Free Managed Ruleset, rate limit) → Security Group :80/:443 (so faixas da Cloudflare)
         → Caddy (TLS, IP real) → Nginx + ModSecurity/OWASP CRS (WAF) → PHP-FPM/Laravel → SQLite
                ↘ headers                  ↘ 403 "Requisicao bloqueada"      ↘ CSP/HSTS/XFO (middleware)
```

## Fluxo de entrega

```text
Commit → PHPUnit → Composer Audit/Trivy → Sonar → Quality Gate
                                                       │
                                               aprovado │
                                                       ▼
                                           SSH → EC2 → Docker Compose
```

## Decisoes

- **SQLite:** reduz custo e configuracao para um trabalho academico de baixa carga.
- **Docker Compose:** reproduz o mesmo runtime local e na EC2.
- **Caddy na borda:** emite e renova o certificado TLS sozinho (Full strict na Cloudflare) e resolve o IP real; o WAF fica atras dele, numa rede interna do Docker.
- **WAF em duas camadas:** a Cloudflare Free segura volume (DDoS, rate limit, regras gerenciadas basicas); o OWASP CRS completo roda na origem com a **ModSecurity v3** (servico `waf` do `docker-compose.yml`, imagem oficial `owasp/modsecurity-crs`), porque o CRS da Cloudflare so existe no plano Pro. Configuracao do CRS por variavel de ambiente da imagem: paranoia 1, limiar de anomalia 5, metodos `PUT/PATCH/DELETE` liberados, limites de corpo (8 MB com arquivo, 256 KB sem) e respostas fora da inspecao. Excecoes do projeto em `deploy/modsecurity/RESPONSE-999-EXCLUSION-RULES-AFTER-CRS.conf` (senhas, token CSRF e cookies do Laravel fora da inspecao; nunca uma regra inteira desligada). Nginx do WAF em `deploy/modsecurity/nginx/` e pagina de bloqueio em `deploy/modsecurity/blocked.html` (resposta 403 com `X-WAF-Block`). `WAF_MODE=DetectionOnly` (variavel do GitHub) vira `MODSEC_RULE_ENGINE` e so registra, para investigar falso positivo sem deploy de codigo. Regras disparadas aparecem em `docker compose logs waf` (error log com id/mensagem e audit log JSON com partes `AHZ`, sem corpo nem cookies). A CI (job `WAF`) sobe a stack inteira e roda `docker/waf/waf-smoke.sh`: trafego legitimo do app nao pode ser bloqueado e ataques classicos (SQLi, XSS, LFI, RCE, PHP injection, scanner, Log4Shell, TRACE) precisam ser.
- **IP real atras do proxy:** o Caddy so aceita `CF-Connecting-IP` vindo das faixas da Cloudflare (`deploy/caddy/cloudflare-trusted-proxies.caddy`, conferido pela CI) e repassa em `X-Real-IP`; o Nginx do WAF so confia nesse cabecalho vindo da rede interna e entrega o IP como `REMOTE_ADDR` ao PHP. A ModSecurity registra e o throttle do Laravel conta por visitante (a CI confere que `CF-Connecting-IP`/`X-Real-IP` forjados sao ignorados).
- **Formularios sem recarregar a pagina:** `public/js/async-forms.js` (no layout principal) envia todo `<form method="POST">` com `fetch()` e o cabecalho `X-ManaLeague-Async: 1`. Os controllers nao mudam: o middleware `AsyncFormRedirects` (primeiro do grupo `web`) troca o redirect por `200 {"redirect": url}`, entao a mensagem flash e os erros de validacao continuam na sessao. Se a URL final e a mesma pagina, o script busca a pagina e atualiza o `<body>` no lugar com Idiomorph (`public/js/vendor/idiomorph.min.js`, 0BSD): rolagem, foco, `<details>` abertos e ouvintes ficam. Outra URL (criar torneio, login, excluir) navega normalmente. Mensagens fora da tela viram aviso no canto; 419/403/429/erro de rede tambem. Fica de fora: GET, `target="_blank"` e `data-async="off"`; `data-async-keep` protege um elemento da atualizacao (ex.: resultados da busca de cartas). Scripts de tela usam eventos delegados ou o evento `manaleague:updated`, e `window.ManaLeague.refresh()` atualiza a tela depois de um `fetch` proprio.
- **Headers na aplicacao e no proxy:** defesa em profundidade; trocar o proxy nao remove as protecoes.
- **Volumes nomeados:** preservam banco e dados entre releases.
- **Releases imutaveis:** cada commit e extraido em uma pasta e um symlink `current` e trocado.
- **SSH efemero no CI:** o IP `/32` do runner e autorizado somente durante o deploy e revogado no final.
- **Servicos de dominio:** `PairingService` gera confrontos e `StandingsService` calcula a tabela sem misturar regra de negocio com HTML.
- **Maquina de estados do torneio:** `setup → league → finals → finished`; cada transicao valida o estado atual com lock, e placares so podem ser editados na fase da partida.
- **Pagina publica tokenizada:** amigos acompanham o placar sem conta, mas nao recebem endpoint de escrita. Ela traz meta tags Open Graph (formato, data, jogadores e lider/campeao) para a previa do link no WhatsApp.
- **Nome de usuario (endereco do perfil):** o `handle` e separado do nome de exibicao (`nickname`) e vira o link `/p/{handle}`; e gerado do nome no cadastro e depois escolhido pelo jogador (3-30 caracteres `a-z0-9-`, lista de reservados, uma troca a cada 30 dias, `throttle:10,1`). Trocar o nome de exibicao nao muda o usuario. O endereco anterior fica reservado por 90 dias em `profile_handle_holds` e redireciona (302) pelo `missing()` da rota; quem nao pode ver o perfil recebe 404. O endereco antigo `/players/{id}` tambem redireciona com 302 (o destino pode mudar), so para quem pode ver o perfil, com `throttle:30,1` contra varredura.
- **Data da lista do deck:** `decks.cards_updated_at` muda nos eventos `created/updated/deleted` de `DeckCard` (e na importacao com "substituir", que apaga em massa). Nome, capa e deck principal nao contam.
- **Exportar deck:** `DeckBuilderService::exportText` gera o formato do Arena (`Commander` / `Deck` / `Sideboard`), que o importador do proprio ManaLeague le de volta; `/decks/{deck}/export` usa a `DeckPolicy`.
- **Sem estatisticas de decks no torneio:** fica de fora por escolha (porcentagem de decks, cores e custos dos participantes). Seria redundante com os decks individuais e poderia expor o que os jogadores nao querem mostrar.
- **Cartas banidas:** `card_bans` guarda a lista de banidas por formato (Pauper, Commander, Standard, Modern, Pioneer, Legacy), copiada do Scryfall por `cards:sync-bans` (no deploy e agendado todo dia; se o Scryfall falhar, a lista anterior fica). A comparacao e pelo nome da carta. Draft e Outro nao tem lista. O deck mostra a tag "Banido" nas cartas banidas no formato dele.
- **Regras do torneio:** `rules` = `standard` (padrao do formato: banidas nao entram) ou `custom` (o organizador escolhe `allow_banned` e pode escrever `house_rules`). Torneio ranqueado nao aceita banidas. Quando o torneio barra banidas (`Tournament::blocksBannedCards`), o deck vinculado e conferido na inscricao pelo convite, na troca de deck, no amigo adicionado pelo organizador (deck principal), no check-in (jogador e organizador) e ao iniciar. Deck so com nome digitado (sem deck salvo) nao tem como ser conferido. O organizador ve so a marca "Deck invalido", sem a lista de cartas.
- **Repetir torneio:** copia formato, sistema, regras (incluindo as personalizadas), cronometro e, opcionalmente, os jogadores (sem check-in e sem resultados); links novos; o clube so continua se quem repete ainda organiza o clube.
- **Foto de perfil no S3 privado:** o upload passa pelo Laravel (`AvatarService`), que valida, reencoda em WebP 256x256 (descarta EXIF e payloads) e grava com nome UUID. As views usam o componente `<x-avatar>` (foto ou emoji/inicial). Com `AVATAR_DISK=s3` o navegador recebe *presigned GET* com inicio arredondado a janelas de 30 min (mesma URL = cache do navegador) e validade de janela + 90 min; a CSP libera so a origem do bucket. Com `AVATAR_DISK=local` (padrao em dev e no deploy ate o bucket existir) a rota `/avatars/{uuid}.webp` serve o arquivo do volume.

## Modulos

Cada modulo registra as proprias rotas (com middleware explicito) e e carregado por `routes/web.php` antes das rotas com binding dinamico.

| Modulo | Rotas | Servicos principais |
|---|---|---|
| Competicao (round-robin, suico, eliminacao simples/dupla, pods Commander) | `routes/competition.php` + rotas de torneio | `CompetitionService`, `PairingService`, `MatchResultService` (gravador canonico de placar), `StandingsService` |
| Ao vivo (placar pelo celular, confirmacao/contestacao, QR, Modo TV) | `routes/live.php` | `MatchResultSubmissionService`, `RegistrationQrService`, `LiveDisplayService` |
| Dia de evento (cronometro da rodada, check-in, remover ausentes) | `routes/competition.php` | `EventDayController`; o cronometro guarda so `round_ends_at` e o navegador conta (`js/round-timer.js`, corrigido pelo horario do servidor) |
| Regras do torneio e cartas banidas (padrao ou personalizadas, tag "Banido" no deck, repetir torneio) | `routes/web.php` (`tournaments.repeat`), `routes/console.php` (`cards:sync-bans`) | `Decks\BanListService`, `TournamentController::repeat` |
| Decks (versoes imutaveis, simulador de mao, colecao, comparador) | `routes/deck-tools.php` | `Decks\DeckVersionService`, `Decks\DeckAccessService` |
| Comunidade (painel, feed, notificacoes, clubes) | `routes/community.php` | `Community\DashboardService`, `ActivityService`, `NotificationService`, `ClubService`, `CommunityAccess` |
| Desafios e conquistas (1v1 casual, rivalidades, conquistas secretas, compartilhamento) | `routes/challenges.php` | `ChallengeService`, `AchievementService` |
| Ranked ELO/PDL (temporadas, ledger, desafios ranqueados, ranque no perfil, moderação) — ver [ranked.md](ranked.md) | `routes/ranked.php` | `Ranked\RatingLedgerService`, `RankedMatchService`, `SeasonService` |

### Integracao entre modulos

- `Community\CommunityEvents` e o unico ponto que transforma eventos de dominio em notificacoes e atividades do feed. E chamado depois do commit por `TournamentFlowController` (inicio, avanco, finais), `MatchController`, `MatchResultSubmissionService` (envio, contestacao, confirmacao/resolucao) e `ChallengeService` (convite, aceite/recusa/cancelamento, placar, confirmacao).
- Toda chave de evento e estavel (`tournament:{id}:round:{n}:...`, `submission:{id}:...`, `challenge:{id}:...`): repetir a chamada nunca duplica notificacao ou atividade.
- A visibilidade e recalculada a cada leitura (`CommunityAccess`): organizador e membros do clube veem o torneio; jogadores inscritos com conta veem eventos do proprio torneio (somente leitura, link para a arena publica). Quem nao participa nao ve nada.
- Silenciar uma categoria nas preferencias (`match`, `tournament`, `challenge`, `club`, `achievement`) silencia todos os tipos dela (`match.submitted`, `match.disputed`...).
- Resolucao de contestacao usa a policy de torneio: dono ou organizador de clube resolvem; membro comum nao.
- Placar enviado pelo celular segue as mesmas regras do placar do organizador (fase editavel, sem empate em eliminacao/finais); mesas Commander sao lancadas pelo organizador.
- Os decks sao congelados (`DeckVersionService::snapshotForTournament`) dentro da transacao de inicio do torneio.

### Deploy

- As migrations `2026_10_20_*` rodam sozinhas no `docker/entrypoint.sh` (`php artisan migrate --force`). Todas foram testadas em banco limpo, com rollback e reaplicacao.
- Nova dependencia: `bacon/bacon-qr-code` (PNG gerado localmente, sem GD/Imagick e sem servico externo; o token do convite nunca sai do servidor).
- Nao ha fila nem cron novos: notificacoes sao gravadas na hora (somente no app, sem e-mail) e desafios expiram de forma preguicosa ao abrir as paginas.
- Todos os CSS/JS usam `VersionedAsset::url`, entao cada deploy invalida o cache do navegador automaticamente.
- Fotos de perfil: a imagem Docker passa a ter `gd` (JPEG/PNG/WebP) e `exif`; a migration `2026_10_26_*` cria `player_profiles.avatar_path`. Ativar o S3 em producao:
  1. `terraform apply` (cria o bucket privado e a policy `s3-profile-photos` na role da EC2; revise o plano: nao pode haver `-/+` na instancia);
  2. no GitHub (Settings > Secrets and variables > Actions > Variables): `AVATAR_DISK=s3` e `AVATAR_BUCKET=<output avatar_bucket>`;
  3. novo deploy. Sem essas variaveis as fotos ficam no volume local (`storage/app/private/avatars`); fotos enviadas antes da troca nao sao copiadas sozinhas (`aws s3 sync` do volume para o bucket, se houver).
- Testes: `php artisan test` (PHPUnit) e `node --test tests/js/deck-simulator.test.cjs` (simulador de mao).
