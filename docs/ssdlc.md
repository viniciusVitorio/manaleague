# Secure Software Development Life Cycle

| Fase | Atividade realizada | Evidencia no repositorio |
|---|---|---|
| Planejamento | escopo pequeno e ativos identificados | `README.md`, `requirements.md` |
| Requisitos | requisitos funcionais e de seguranca antes do codigo | `docs/requirements.md` |
| Design | arquitetura, fronteiras e decisoes | `docs/architecture.md` |
| Modelagem | STRIDE e riscos priorizados | `docs/threat-model.md` |
| Implementacao | validacao, auth, CSRF, ownership e secrets externos | `app/`, `routes/`, `.gitignore` |
| Verificacao | PHPUnit, Composer Audit, Trivy e Sonar | `tests/`, workflow, Sonar |
| Entrega | Quality Gate bloqueia deploy inseguro | job `deploy` depende de `security` e `sonar` |
| Operacao | health check, logs e infra reproduzivel | `/up`, Docker, Terraform |
| Manutencao | novo push repete todos os controles | GitHub Actions |

## Politica de gate

Uma release somente chega a producao quando:

1. testes funcionais e de seguranca passam;
2. Composer Audit nao encontra advisories bloqueantes;
3. Trivy nao encontra vulnerabilidade corrigivel HIGH/CRITICAL;
4. SonarQube aprova o Quality Gate;
5. o evento e um push na branch `main`.

Se qualquer etapa falha, as dependencias `needs` impedem o job de deploy. Assim, seguranca participa do fluxo normal, e nao de uma revisao manual apenas no final.
