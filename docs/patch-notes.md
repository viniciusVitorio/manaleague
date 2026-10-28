# Patch notes

A aba **Patch notes** (`/patch-notes`, menu **Mais**) mostra as GitHub Releases publicadas.
O GitHub é a fonte: a versão é a **tag** da release e o texto é o **corpo** da release.
A tabela `patch_notes` é só uma cópia; se o banco for perdido, a próxima sincronização refaz tudo.

## Publicar um patch note

1. Faça o merge das mudanças na `main` normalmente (o deploy de código continua igual).
2. No GitHub: **Releases → Draft a new release**.
3. Crie a tag da versão a partir da `main` (semver: `v1.4.0`).
4. Título e texto seguindo o [padrão abaixo](#padrão-do-texto).
5. **Não** marque "Set as a pre-release" (pre-releases não aparecem na aba).
6. Clique em **Publish release**.

## Padrão do texto

> Com o Claude Code, peça "escreva o patch note da próxima versão": a skill `.claude/skills/patch-notes/SKILL.md` segue este padrão.

**Título:** `Nome da versão · palavras-chave` — a tag já aparece acima do título, não repita.
Ex.: `Versão inicial · Torneios, decks e comunidade`, `Temporada de inverno · Ranked e clubes`.

**Texto:** 1 ou 2 frases de resumo e depois as seções, nesta ordem. Seção sem item fica de fora.

```
Resumo em uma ou duas frases, falando com o jogador.

## Novidades
- Área: o que dá para fazer agora

## Melhorias
- Área: o que ficou melhor ou mais rápido

## Correções
- Área: o que estava errado e agora funciona

## Problemas conhecidos
- Área: o que ainda não funciona e como contornar
```

- Cada item começa pela área (`Torneios:`, `Decks:`, `Ranked:`, `Perfil:`, `Comunidade:`...) e descreve o efeito para o jogador, não o código.
- A página entende só isto: `## ` vira subtítulo, `- ` vira item de lista, o resto vira parágrafo (linha em branco separa).
  Negrito, links e HTML aparecem como texto puro.

Publicar, editar ou apagar uma release dispara a pipeline, que **só sincroniza os patch notes** na produção
(não roda testes nem reimplanta código). Rascunhos e pre-releases não aparecem.

## Aviso no sino

Uma versão nova avisa todas as contas no sino de notificações ("Nova versão v1.4.0: título", com link para a versão na aba).

- Cada conta recebe o aviso de uma versão só uma vez; editar a release não avisa de novo.
- Se várias releases novas chegarem de uma vez (ex.: primeira sincronização), só a mais recente avisa.
- Release antiga cadastrada depois (mais velha que a versão atual) não avisa.
- Release apagada leva o aviso junto.
- Quem desligar a categoria **Patch notes** em Notificações → Preferências não recebe.

## Versão

- Use `vMAJOR.MINOR.PATCH`: PATCH para correções, MINOR para funções novas, MAJOR para mudanças grandes.
- A "versão atual" da aba é a release publicada mais recente.

## Como funciona

1. No job `deploy` (`.github/workflows/pipeline.yml`), o runner lê as releases com `gh api` (token do próprio
   workflow, só leitura) e monta um JSON `[{tag, name, body, published_at}]`.
2. O JSON vai pela entrada padrão do SSH para `php artisan patch-notes:sync` dentro do container.
3. O comando valida tudo antes de gravar e espelha a lista: cria, atualiza e apaga o que sumiu do GitHub.
   Entrada inválida não altera nada.

A EC2 não fala com o GitHub e não guarda nenhum token.

## Testar localmente

```bash
# com o GitHub CLI autenticado
gh api "repos/<dono>/<repo>/releases?per_page=100" --paginate \
  --jq '.[] | select((.draft | not) and (.prerelease | not)) | {tag: .tag_name, name, body, published_at}' \
  | jq -s '.' > patch-notes.json
php artisan patch-notes:sync patch-notes.json
```
