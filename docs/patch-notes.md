# Patch notes

A aba **Patch notes** (`/patch-notes`, menu **Mais**) mostra as GitHub Releases publicadas.
O GitHub é a fonte: a versão é a **tag** da release e o texto é o **corpo** da release.
A tabela `patch_notes` é só uma cópia; se o banco for perdido, a próxima sincronização refaz tudo.

## Publicar um patch note

1. Faça o merge das mudanças na `main` normalmente (o deploy de código continua igual).
2. No GitHub: **Releases → Draft a new release**.
3. Crie a tag da versão a partir da `main` (semver: `v1.4.0`).
4. Título: uma frase curta (ex.: `Decks na navegação`). Sem título, a aba mostra a tag.
5. Texto: escreva para o jogador, em texto plano:
   - linhas começando com `- ` viram lista;
   - linhas começando com `## ` viram subtítulo;
   - o resto vira parágrafo (linha em branco separa parágrafos).
   Nada é interpretado como HTML ou link; negrito (`**`) aparece como texto.
6. Clique em **Publish release**.

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
