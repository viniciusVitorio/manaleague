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
