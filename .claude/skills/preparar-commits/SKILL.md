---
name: preparar-commits
description: Prepara os commits do ManaLeague no padrão combinado — separa as mudanças por assunto, propõe as mensagens em inglês (Conventional Commits), pede aprovação e só então faz add/commit no nome do usuário, sem atribuição ao Claude. Use quando o trabalho estiver pronto para commit, quando pedirem "pode commitar", "faça os commits", "crie a branch" ou a descrição de PR.
---

# Preparar commits

O dono do projeto roda o git. Você só faz `add`, `commit` e cria branch **quando ele pedir**, e nunca `push`.

## 1. Levantar o que mudou (só leitura)

```bash
git status --short
git diff --stat
git log --oneline -5
```

- Separe o que é do trabalho atual do que já estava pendente antes (ex.: arquivos que o dono deixou de fora de propósito,
  como `docs/patch-notes.md` ou uma skill nova). Na dúvida, pergunte; nunca inclua arquivo que você não mexeu.
- Confira que a suíte passou depois da última mudança.

## 2. Propor (sem executar nada)

Mostre uma tabela com um commit por assunto, na ordem em que fazem sentido:

| # | Mensagem | Arquivos |
|---|---|---|
| 1 | `feat(challenges): list your challenges first and move the form to its own page` | `ChallengeController.php`, `challenges/*.blade.php`, `ui.css` (bloco Desafios), `ChallengesPageTest.php` |

Regras da mensagem:
- Inglês, Conventional Commits: `feat`, `fix`, `style`, `refactor`, `test`, `docs`, `chore`, com escopo (`profile`, `challenges`,
  `decks`, `ranked`, `life`, `live`, `achievements`...). Assunto no imperativo, minúsculo, sem ponto, até ~72 caracteres.
- Corpo opcional (2–3 frases) quando a regra não é óbvia ("Only confirmed challenges count; tournaments never do.").
- **Sem** `Co-Authored-By`, sem "Generated with Claude Code". O autor é o usuário do `git config`.

Diga também em qual branch vai (atual ou nova `feat/...`) e espere o **ok** explícito.

## 3. Executar (depois do ok)

- Branch nova, se pedida: `git switch -c feat/<nome>` (leva junto as mudanças não commitadas).
- Arquivo inteiro de um assunto: `git add <arquivos>` e `git commit -m "<assunto>" -m "<corpo>"`.
- **Arquivo com dois assuntos** (ex.: `ui.css` com blocos de duas funcionalidades): monte a versão intermediária do arquivo
  só com o bloco do primeiro commit e coloque no índice sem tocar a cópia de trabalho:
  ```bash
  python - <<'EOF'
  import subprocess
  path = 'public/css/ui.css'
  text = open(path, encoding='utf-8', newline='').read()
  partial = text.replace(BLOCO_DO_SEGUNDO_COMMIT, '')   # o texto exato do bloco que fica para depois
  sha = subprocess.run(['git', 'hash-object', '-w', '--path=' + path, '--stdin'], input=partial.encode(), capture_output=True, check=True).stdout.decode().strip()
  subprocess.run(['git', 'update-index', '--cacheinfo', '100644,' + sha + ',' + path], check=True)
  EOF
  ```
  Commite, e no commit seguinte `git add` o arquivo inteiro. Para evitar isso, **commite cada assunto assim que for aprovado**,
  antes de começar o próximo.
- No fim mostre `git log --format='%h %an | %s' -N` e `git status --short` (deve sobrar só o que ficou de fora de propósito).

## 4. Depois

- Lembre o comando de push para o dono rodar: `git push -u origin <branch>`.
- Se a branch depende de outra ainda não mergeada, avise para onde a PR deve apontar.
- Descrição de PR (se pedida): em inglês, seções `Summary`, `Changes` (por área), `How to test`, `Tests`; sem linha de atribuição.

## Não faça

- `push`, abrir PR, `reset`, `rebase -i`, `checkout --`/`restore` em arquivo do usuário, `--amend` em commit já mostrado.
- Commit sem aprovação, mesmo que o pedido anterior tenha sido "faça a mudança".
- `git add -A` / `git add .` (pega arquivos que não são seus).
