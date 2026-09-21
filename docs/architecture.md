# Arquitetura

## Componentes

| Componente | Responsabilidade |
|---|---|
| Browser | interface e envio de formularios com CSRF |
| Nginx | porta publica, headers e proxy FastCGI |
| Laravel/PHP-FPM | autenticacao, autorizacao, round-robin, ranking e finais |
| SQLite | usuarios, torneios, jogadores, partidas e sessoes |
| GitHub Actions | CI, scanners, quality gate e CD |
| SonarQube Cloud | analise estatica e Quality Gate |
| Terraform | EC2, chave e firewall reproduziveis |

## Fluxo de requisicao

```text
Internet → Security Group :80 → Nginx → PHP-FPM/Laravel → SQLite
                                    ↘ headers de seguranca
```

## Fluxo de entrega

```text
Commit → PHPUnit/Pint → Composer Audit/Trivy → Sonar → Quality Gate
                                                            │
                                                    aprovado │
                                                            ▼
                                                SSH → EC2 → Docker Compose
```

## Decisoes

- **SQLite:** reduz custo e configuracao para um trabalho academico de baixa carga.
- **Docker Compose:** reproduz o mesmo runtime local e na EC2.
- **Nginx separado:** divide servidor web e PHP-FPM.
- **Volumes nomeados:** preservam banco e dados entre releases.
- **Releases imutaveis:** cada commit e extraido em uma pasta e um symlink `current` e trocado.
- **SSH efemero no CI:** o IP `/32` do runner e autorizado somente durante o deploy e revogado no final.
- **Servicos de dominio:** `PairingService` gera confrontos e `StandingsService` calcula a tabela sem misturar regra de negocio com HTML.
- **Pagina publica tokenizada:** amigos acompanham o placar sem conta, mas nao recebem endpoint de escrita.
