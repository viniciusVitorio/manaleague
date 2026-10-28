---
name: patch-notes
description: Escreve o patch note de uma nova versão do ManaLeague (título e texto da GitHub Release) no padrão do projeto — seções Novidades, Melhorias, Correções e Problemas conhecidos, escritas para o jogador. Use quando pedirem patch note, release, notas da versão, changelog ou "o que mudou" de uma versão.
---

# Patch notes do ManaLeague

Cada GitHub Release publicada vira um card na aba **Patch notes** (`/patch-notes`) e um aviso no sino de todas as contas.
A tag é a versão, o título e o texto da release são o patch note. Detalhes técnicos: `docs/patch-notes.md`.

Seu trabalho é **escrever o texto**. Quem cria a tag e publica a release é a pessoa usuária.

## Regras do projeto

- **Nunca rode comandos git** (nem `git log`, `git tag`) — a pessoa usuária executa o git. Peça a saída quando precisar.
- **Nunca publique nem crie a release** (nem via `gh`). Entregue o texto pronto para colar.
- **Não invente funcionalidade.** Todo item precisa vir das mudanças informadas ou do código.

## 1. Juntar as mudanças

Peça uma destas fontes (a primeira que a pessoa tiver):

1. Saída de `git log --oneline <tag-anterior>..main` (ou `git log --oneline` se for a primeira versão).
2. Lista de PRs mergeadas desde a última release.
3. Descrição livre do que mudou.

Os commits seguem Conventional Commits (`feat(decks): ...`, `fix(auth): ...`). Para entender um item, leia o código, as rotas e as views relacionadas.

Antes de listar uma funcionalidade, confira se ela está **ligada em produção** (ex.: Ranked depende de `config('ranked.enabled')`; fotos no S3 de `AVATAR_DISK`). Na dúvida, pergunte.

## 2. Classificar

| Origem | Seção |
|---|---|
| `feat` — algo novo que o jogador pode fazer | **Novidades** |
| `perf`, melhoria de algo que já existia, UX, textos | **Melhorias** |
| `fix` — algo que estava errado e agora funciona | **Correções** |
| limitação conhecida ainda não resolvida | **Problemas conhecidos** |
| `refactor`, `test`, `ci`, `chore`, `docs`, dependências, segurança interna, deploy | **fica de fora**, a menos que o jogador perceba a diferença |

Junte commits que falam da mesma coisa num item só. Correção de algo que nunca chegou a ser lançado não é correção — fica de fora.

## 3. Escolher a versão

Semver com prefixo `v`: `vMAJOR.MINOR.PATCH`.

- **Fase atual: pré-1.0** (versão inicial `v0.0.1`). Até a `v1.0.0`:
  - só correções → sobe o PATCH (`v0.0.1` → `v0.0.2`);
  - funcionalidade nova → sobe o MINOR (`v0.0.2` → `v0.1.0`);
  - a `v1.0.0` é o lançamento oficial — só quando a pessoa usuária decidir.
- Depois da 1.0: PATCH = correções, MINOR = novidades, MAJOR = mudança grande/incompatível.

Proponha o número e explique em uma linha; a pessoa confirma.

## 4. Escrever

### Título

`Nome da versão · palavras-chave` — no máximo ~80 caracteres.

- A tag já aparece acima do título na página: **não repita a versão** no título.
- Nome curto e com personalidade; palavras-chave dizem do que a versão trata.
- Exemplos: `Versão inicial · Torneios, decks e comunidade`, `Temporada de inverno · Ranked e clubes`, `Ajustes finos · Correções em torneios suíços`.
- No sino aparece: `Nova versão <tag>: <título>`.

### Texto

```
Resumo em uma ou duas frases, falando com o jogador.

## Novidades
- Área: o que dá para fazer agora

## Melhorias
- Área: o que ficou melhor

## Correções
- Área: o que estava errado e agora funciona

## Problemas conhecidos
- Área: o que ainda não funciona e como contornar

Fechamento curto (opcional).
```

- Seções **sempre nesta ordem**; seção sem item fica de fora.
- Cada item: `Área: efeito para o jogador` — uma linha, sem ponto final.
- Áreas usadas: `Torneios`, `Partidas`, `Desafios`, `Ranked`, `Mesa`, `Decks`, `Coleção`, `Perfil`, `Amigos`, `Clubes`, `Comunidade`, `Notificações`, `Interface`, `Conta`, `Patch notes`. Itens da mesma área ficam juntos.
- Correção descreve o sintoma que o jogador via: `Torneios: o BYE não contava na classificação em ligas com número ímpar` — não `fix no StandingsService`.

### Tom

- Português do Brasil, segunda pessoa ("você"), frases curtas e verbos claros.
- Profissional e caloroso; um toque de Magic é bem-vindo no resumo ("a mesa está posta"), nunca nos itens.
- Sem jargão técnico (controller, migration, endpoint, cache, deploy, PR) e sem nome de arquivo ou classe.
- Sem emoji no título nem nos subtítulos.

### O que a página entende (limites do renderizador)

O texto é **texto plano** (`PatchNote::blocks()`):

- `## ` (ou qualquer `#`) no começo da linha → subtítulo;
- `- `, `* ` ou `+ ` no começo da linha → item de lista (sem listas aninhadas);
- o resto → parágrafo; linha em branco separa parágrafos.

**Não use** negrito, itálico, links, imagens, tabelas, HTML nem código — aparecem como texto cru.
Limites: título até 117 caracteres, texto até 20.000.

## 5. Entregar

Responda com:

1. **Tag** (`vX.Y.Z`) e **Target** (`main`).
2. **Título** e **Texto**, cada um num bloco de código para copiar.
3. Como o aviso aparece no sino.
4. Checklist de publicação:
   - publicar só **depois** do merge e do deploy do que está sendo anunciado;
   - GitHub → Releases → Draft a new release → **Choose a tag** → digitar a tag nova → "Create new tag on publish", target `main`;
   - **não** marcar "Set as a pre-release" (pre-release não aparece na aba nem avisa);
   - errou o texto? editar a release atualiza a aba sem avisar de novo; publicou por engano? apagar a release remove o card e o aviso.

## Exemplo

**Tag:** `v0.1.0` · **Título:** `Temporada de inverno · Ranked e clubes`

```
O Ranked ganhou temporadas de verdade e os clubes ficaram mais fáceis de organizar. Bora subir de ranque!

## Novidades
- Ranked: temporadas com início e fim, e o ranque final fica guardado no seu perfil
- Clubes: convide jogadores por link e publique avisos no mural do clube

## Melhorias
- Torneios: a página pública carrega mais rápido em eventos grandes

## Correções
- Decks: a curva de mana ignorava cartas com custo X

## Problemas conhecidos
- Decks: importar listas muito grandes pode levar alguns segundos

Boas partidas!
```
