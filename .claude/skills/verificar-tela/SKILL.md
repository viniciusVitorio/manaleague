---
name: verificar-tela
description: Confere visualmente uma tela do ManaLeague depois de mudar view, CSS ou JS — atualiza o Docker local, tira prints no computador (1280px) e no celular (390px), nos temas escuro e claro, e olha os prints antes de entregar. Use sempre que mexer em interface, quando pedirem para "ver como ficou", "conferir no celular", "tirar print" ou antes/depois de uma mudança visual.
---

# Verificar tela

Teste automatizado garante a regra; o print garante que ficou bonito e não quebrou no celular.
Nenhuma mudança de interface é entregue sem esta verificação (ver "Definição de pronto" no CLAUDE.md).

## Passos

1. **Atualize o app local** (o código é copiado para a imagem):
   ```bash
   docker compose up -d --build --wait --wait-timeout 300
   ```
2. **Tire os prints** com o script desta pasta. Ele faz login com a conta demo e salva um PNG por URL × largura × tema:
   ```bash
   node .claude/skills/verificar-tela/screenshot.mjs --urls /me/profile,/challenges --widths 1280,390 --theme both --out <scratchpad>/prints
   ```
   - Só um componente: `--selector .rival-card` (recorta o elemento).
   - Perfil de outra pessoa: `/p/ajani`. Formulário: `/challenges/new`.
   - Outra conta: `--email ... --password ...`. Chrome em outro lugar: `--chrome <caminho>` ou variável `CHROME`.
   - A última linha mostra erros de JavaScript da página; tem que ser `SEM ERROS`.
3. **Olhe cada print** (ferramenta Read no PNG) e confira:
   - alinhamento na mesma coluna, nada cortado ou sobreposto, botão de canto sem cobrir texto;
   - no 390px: selo não espreme o nome, botões de ação com largura total, grid em uma coluna;
   - tema claro: texto legível, nenhuma cor fixa que some no fundo branco;
   - o checklist de [docs/ui-rules.md](../../../docs/ui-rules.md#checklist-antes-de-entregar).
4. **Antes e depois:** para mudança visual pedida pelo dono, tire os prints antes de editar (`--out .../antes`) e depois
   (`--out .../depois`) e mostre os dois na resposta.
5. Na resposta, mostre os prints mais importantes com o caminho **absoluto** do arquivo e diga o que foi conferido.

## Dados de teste

- Conta `demo@manaleague.test` / `manaleague123`, amigos Ajani, Chandra, Jace, Liliana e Bruno Banido.
- Precisa de um estado específico (desafio confirmado, placar pendente)? Crie com um script no tinker
  (`docker compose cp x.php app:/tmp/x.php` e `MSYS_NO_PATHCONV=1 docker compose exec -T app php artisan tinker /tmp/x.php`)
  usando os services (`ChallengeService`, `RankedMatchService`), nunca SQL solto. Diga na resposta o que foi criado.

## Não faça

- Não entregue mudança visual só com teste passando.
- Não deixe o Chrome headless aberto nem arquivos de print dentro do repositório.
