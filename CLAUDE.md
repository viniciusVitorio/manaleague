# ManaLeague

Plataforma de Magic: The Gathering para jogadores e grupos: torneios (ligas, suíço, eliminação, Commander),
desafios 1v1 entre amigos, Ranked com ELO, decks, coleção, perfis, conquistas e comunidade.
Público: jogadores brasileiros, a maioria no celular. Produção em https://manaleague.com.br.

## Antes de começar uma tarefa, leia

| Vai mexer em... | Leia antes |
|---|---|
| Qualquer tela, CSS, JS ou texto de interface | [docs/ui-rules.md](docs/ui-rules.md) |
| Controller, Service, Model, regra de negócio, migration | [docs/backend-rules.md](docs/backend-rules.md) |
| Testes (sempre) | [docs/testing.md](docs/testing.md) |
| Infra, deploy, módulos, decisões já tomadas | [docs/architecture.md](docs/architecture.md) |
| Ranked / ELO | [docs/ranked.md](docs/ranked.md) |
| Segurança | [docs/threat-model.md](docs/threat-model.md), [docs/ssdlc.md](docs/ssdlc.md) |

Skills do projeto (em `.claude/skills/`): `verificar-tela` (prints no computador e no celular), `preparar-commits`,
`corrigir-producao` (ajustar dado em produção com segurança), `atualizar-readme` (README em inglês em dia com o projeto),
`patch-notes` e `anuncio-comunidade`.

## Stack e mapa

- Laravel 12, PHP 8.4, Blade, SQLite (WAL), sem build de front: CSS e JS puros em `public/`.
- Docker Compose local em http://localhost:8080 (o mesmo runtime da produção). Migrations rodam sozinhas ao subir.
- `app/Services/` tem a regra de negócio (subpastas `Community/`, `Decks/`, `Ranked/`); controllers ficam finos.
- `routes/<modulo>.php` por módulo: `competition`, `live`, `deck-tools`, `community`, `challenges`, `ranked`, `life`.
- `resources/views/components/` (`<x-avatar>`, `<x-icon>`, `<x-profile-link>`, `<x-ui.*>`) e `partials/`.
- `public/css/ui.css` é o design system (tokens e componentes `ui-*`); `app.css` é legado; CSS de módulo
  (`ranked.css`, `life.css`...) entra com `@push('head')`.
- Contas de teste locais: `demo@manaleague.test` / `manaleague123` (amigos: Ajani, Chandra, Jace, Liliana).

## Comandos

```bash
php artisan test                         # suíte inteira (≈320 testes); --filter NomeDoTeste para um só
composer lint                            # Pint em modo verificação
node --test "tests/js/*.test.cjs"        # testes de JS (simulador de mão, histórico de vida)
docker compose up -d --build --wait --wait-timeout 300   # sobe/atualiza o app local depois de mudar código
```

No Windows deste projeto o PHP vem do Herd sem as extensões no php.ini; se `php artisan test` falhar por extensão:

```bash
P="C:/Users/Vinicius/.config/herd/bin/php84"; "$P/php.exe" -n -d extension_dir="$P/ext" -d extension=pdo_sqlite -d extension=sqlite3 -d extension=mbstring -d extension=intl -d extension=gd -d extension=fileinfo -d extension=openssl -d memory_limit=1G vendor/bin/phpunit
```

Rodar código no app local: copie o arquivo para o container e use o tinker:
`docker compose cp x.php app:/tmp/x.php` e `MSYS_NO_PATHCONV=1 docker compose exec -T app php artisan tinker /tmp/x.php`.

## Regras de trabalho (combinadas com o dono do projeto)

- **Git:** quem roda git é o dono. Leitura (`status`, `diff`, `log`, `show`) pode. `add`, `commit` e criar branch
  **só quando ele pedir**. Nunca `push`, PR, `reset`, `checkout --`/`restore` em arquivo dele, nem reescrever histórico.
- **Antes de commitar, peça aprovação** mostrando a lista de commits propostos. Um commit por assunto.
- Mensagens de commit e descrição de PR **em inglês**, no padrão Conventional Commits (`feat(profile): ...`),
  no nome do usuário do git. **Sem** `Co-Authored-By` e sem "Generated with Claude Code".
- Trabalho novo vai numa branch própria (`feat/...`, `fix/...`), criada quando ele pedir.
- Não comece tarefas da lista de adiadas sem ele pedir. Não invente funcionalidade em patch notes e anúncios.
- Responda em português. Texto de interface em português do Brasil; nomes no código em inglês; comentários em português.
  O `README.md` é em inglês; os docs de `docs/` seguem em português.

## Definição de pronto

1. Testes novos ou ajustados para o comportamento visível, e a suíte inteira passando.
2. Se mexeu em tela: Docker atualizado e conferido em **1280px e 390px** (skill `verificar-tela`), tema escuro e claro.
3. Checklist do [docs/ui-rules.md](docs/ui-rules.md#checklist-antes-de-entregar) ou do [docs/backend-rules.md](docs/backend-rules.md) cumprido.
4. README conferido (skill `atualizar-readme`): funcionalidade, stack, comando ou pipeline que mudou está refletido nele.
5. Relato curto: o que mudou, o que foi testado, o que ficou de fora. Depois, proposta de commits.

## Armadilhas conhecidas (já custaram retrabalho)

- **Fim de linha:** os arquivos do projeto são CRLF. `sed -i` e escrita em Python sem `newline=''` trocam para LF.
  Arquivo novo: `unix2dos -q arquivo`. Confira com `file arquivo`.
- **Blade:** não misture `@php(...)` (inline) com bloco `@php ... @endphp` no mesmo arquivo: o compilador junta os dois
  e quebra. Em views que já usam `@php(...)`, use só a forma inline.
- **Dois sistemas de botão:** `ui-btn ui-btn-*` é o padrão; `btn btn-*` é legado. Nunca troque classes com
  substituição em massa (`btn-sm` → `btn-small` pegou `ui-btn-sm` e quebrou botões). Edite linha a linha.
- **Nome de classe CSS:** antes de criar, procure (`grep -rn "\.nome-" public/css`). `versus-*` já existia e colidiu.
  Use prefixo da funcionalidade (`rival-`, `challenge-`, `duel-`...).
- **Cascata do legado:** `.stat-card span` e `.stat-card b` estilizam qualquer span/b dentro do card;
  `label:has(> input[type=checkbox|radio])` vira `display:flex !important`.
- **Ranked:** `source_type` só aceita `challenge` ou `tournament_match`. Ranked de torneio já conta pela partida do torneio.
- **Datas:** mostre sempre com `->timezone(config('app.timezone'))->format('d/m/Y')`.
- **Produção:** nunca altere dado direto sem backup; siga a skill `corrigir-producao`.
