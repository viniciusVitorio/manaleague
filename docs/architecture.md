# Arquitetura

## Componentes

| Componente | Responsabilidade |
|---|---|
| Browser | interface e envio de formularios com CSRF |
| Caddy | porta publica (80/443), TLS automatico (Let's Encrypt), compressao, headers e proxy FastCGI |
| Laravel/PHP-FPM | autenticacao, autorizacao, round-robin, ranking, finais e headers de seguranca (`SecurityHeaders`) |
| SQLite | usuarios, torneios, jogadores, partidas e sessoes |
| GitHub Actions | CI, scanners, quality gate e CD |
| SonarQube Cloud | analise estatica e Quality Gate |
| Terraform | EC2, chave e firewall reproduziveis |

> `deploy/nginx/default.conf` fica como alternativa ao Caddy para quem preferir Nginx; o `docker-compose.yml` usa Caddy.

## Fluxo de requisicao

```text
Internet → Security Group :80/:443 → Caddy (TLS) → PHP-FPM/Laravel → SQLite
                                        ↘ headers         ↘ CSP/HSTS/XFO (middleware)
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
- **Caddy separado:** divide servidor web e PHP-FPM e emite certificado TLS sem configuracao manual.
- **Headers na aplicacao e no proxy:** defesa em profundidade; trocar o proxy nao remove as protecoes.
- **Volumes nomeados:** preservam banco e dados entre releases.
- **Releases imutaveis:** cada commit e extraido em uma pasta e um symlink `current` e trocado.
- **SSH efemero no CI:** o IP `/32` do runner e autorizado somente durante o deploy e revogado no final.
- **Servicos de dominio:** `PairingService` gera confrontos e `StandingsService` calcula a tabela sem misturar regra de negocio com HTML.
- **Maquina de estados do torneio:** `setup → league → finals → finished`; cada transicao valida o estado atual com lock, e placares so podem ser editados na fase da partida.
- **Pagina publica tokenizada:** amigos acompanham o placar sem conta, mas nao recebem endpoint de escrita.

## Modulos

Cada modulo registra as proprias rotas (com middleware explicito) e e carregado por `routes/web.php` antes das rotas com binding dinamico.

| Modulo | Rotas | Servicos principais |
|---|---|---|
| Competicao (round-robin, suico, eliminacao simples/dupla, pods Commander) | `routes/competition.php` + rotas de torneio | `CompetitionService`, `PairingService`, `MatchResultService` (gravador canonico de placar), `StandingsService` |
| Ao vivo (placar pelo celular, confirmacao/contestacao, QR, Modo TV) | `routes/live.php` | `MatchResultSubmissionService`, `RegistrationQrService`, `LiveDisplayService` |
| Decks (versoes imutaveis, simulador de mao, colecao, comparador) | `routes/deck-tools.php` | `Decks\DeckVersionService`, `Decks\DeckAccessService` |
| Comunidade (painel, feed, notificacoes, clubes) | `routes/community.php` | `Community\DashboardService`, `ActivityService`, `NotificationService`, `ClubService`, `CommunityAccess` |
| Desafios e conquistas (1v1 casual, rivalidades, conquistas secretas, compartilhamento) | `routes/challenges.php` | `ChallengeService`, `AchievementService` |

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
- Testes: `php artisan test` (PHPUnit) e `node --test tests/js/deck-simulator.test.cjs` (simulador de mao).
