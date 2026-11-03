---
name: atualizar-readme
description: Mantém o README.md do ManaLeague (em inglês) em dia com o projeto — confere se a mudança atual afeta alguma seção (funcionalidades, stack, como rodar, testes, CI/CD, estrutura, docs, equipe) e atualiza só o que mudou, sem inventar nada. Use ao terminar uma funcionalidade nova ou removida, ao mudar infra/comandos/pipeline, ao adicionar um doc, quando pedirem "atualize o README" e na checagem antes dos commits.
---

# Atualizar o README

O [README.md](../../../README.md) é a porta de entrada do repositório no GitHub: diz o que o ManaLeague faz, como rodar e como
contribuir. Ele é **em inglês** (o resto do projeto, docs e interface, segue em português).
O README não é changelog: novidades por versão ficam nas patch notes (skill `patch-notes`).

## 1. A mudança afeta o README?

Olhe o que mudou (`git diff --stat`, `git diff main...HEAD --stat` numa branch) e procure na tabela:

| Se mudou... | Seção do README | Onde conferir |
|---|---|---|
| Funcionalidade nova, removida ou que mudou de cara (ex.: Confronto direto saiu, Rival entrou) | **Features** | `routes/*.php`, views, `docs/architecture.md` |
| Módulo novo (`routes/<modulo>.php`, subpasta em `app/Services/`) | **Features** e **Project structure** | `routes/`, `app/Services/` |
| Versão do Laravel/PHP, banco, serviço AWS, Cloudflare, WAF | **Stack** (e o diagrama) | `composer.json`, `Dockerfile`, `docker-compose.yml`, `terraform/` |
| Variável obrigatória no `.env`, passo para subir, seeder, conta demo | **Running locally** | `.env.example`, `docker-compose.yml`, `docker/entrypoint.sh`, `database/seeders/` |
| Comando de teste ou lint | **Tests and quality** | `composer.json` (`scripts`), `tests/` |
| Job, gatilho ou etapa de deploy do pipeline | **CI/CD** | `.github/workflows/pipeline.yml` (`on:` e `if:` de cada job) |
| Pasta nova de primeiro nível ou papel de uma pasta | **Project structure** | `ls` na raiz |
| Doc novo ou renomeado em `docs/` | **Documentation** | `ls docs` |
| Regra de branch, commit ou PR | **Contributing** | `CLAUDE.md`, skill `preparar-commits` |
| Pessoa entrou ou saiu do time, papel mudou | **Team** (só quando o dono pedir) | perfil no GitHub |
| Licença, aviso da Wizards ou do Scryfall | **License** (só quando o dono pedir) | `LICENSE` |

Nada na tabela? Não mexa no README e diga isso em uma linha ("README: sem mudança").

## 2. Escrever

- **Inglês** claro e simples, frases curtas. Nome de tela ou botão em português vai entre aspas, como aparece no app ("Desafiar amigo").
- **Só o que existe no código** da branch. Não invente funcionalidade nem anuncie o que ainda não foi feito.
  Funcionalidade atrás de config (ex.: `config('ranked.enabled')`, `AVATAR_DISK=s3`) entra dizendo que depende dela.
- **Mude só a linha ou o item afetado.** Não reescreva seções inteiras nem mude o tom do resto.
- Mantenha o formato:
  - **Features:** tabela `Area | What you can do`, com o efeito para o jogador, sem nome de classe;
  - **Stack:** lista;
  - **Running locally** e **Tests:** blocos `bash` que funcionam se forem copiados;
  - **CI/CD:** lista numerada, na ordem dos jobs.
- Links relativos para arquivos do repositório (`docs/ranked.md`). Antes de entregar, confira que cada link e cada comando citado existe.
- Fim de linha: o README é CRLF. Depois de editar, `file README.md` tem que mostrar `CRLF`; se não, `unix2dos -q README.md`.

## 3. Não faça

- Lista de versões, datas de lançamento ou "novidades da v0.5.0": isso é patch note.
- Segredo, IP, host do servidor, nome de bucket, conta AWS ou caminho de produção.
- Contagem que envelhece rápido (número exato de testes, de rotas, de cartas).
- Mudar **Team** ou **License** por conta própria.
- Traduzir os docs de `docs/` para inglês: o README só aponta para eles (e avisa que estão em português).

## 4. Entregar

- Diga em uma ou duas linhas o que mudou no README e por quê ("Features: Rival no perfil substitui Confronto direto").
- O README entra no **mesmo commit** da funcionalidade que ele descreve. Se a atualização for avulsa, use `docs: update README ...`.
  Siga a skill `preparar-commits`: peça aprovação antes.
