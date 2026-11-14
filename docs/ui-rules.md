# Regras de UI do ManaLeague

Regras verificáveis para qualquer tela nova ou ajuste, feita por pessoa ou por IA.
Se uma regra atrapalhar um caso real, **mude a regra aqui primeiro**; não abra exceção escondida na tela.

Referência rápida do que já foi aprovado: estatísticas do perfil (`players/show.blade.php`),
lista e formulário de **Desafios** (`challenges/index`, `challenges/create`), **contador de vida** (`life/*`, `js/life-counter.js`).

---

## 1. Princípios

1. **Bonito com propósito.** A tela é sóbria (paleta zinc, poucos tons), e o brilho aparece onde há algo a celebrar
   ou a decidir: quem lidera, uma vitória, uma conquista, a ação que falta. Enfeite sem significado é ruído.
2. **A informação vem primeiro.** Número grande com legenda pequena; a ação principal visível sem rolar; detalhes em dica (`title`) ou em tela própria.
3. **Celular primeiro.** A maioria joga com o celular na mesa: tudo tem que funcionar com o polegar em 360–390px.
4. **Discreto por padrão, intenso por exceção.** Indicadores do dia a dia (bolinhas de forma, selos) são suaves;
   animação forte (confete, fogos) só em momento de vitória real.
5. **Consistência acima de criatividade.** Antes de inventar um componente, procure um igual (seção 4). Duas telas
   que fazem a mesma coisa usam o mesmo componente.

### Aprovado e rejeitado (aprendizado real)

| Aprovado | Rejeitado |
|---|---|
| Card do Rival: os dois avatares lado a lado, placar grande no meio, selo de quem lidera, barra fina de vitórias e botão "Desafiar" | Versão "arena" com painel de fundo, brilho dourado, coroa 👑 e anel no avatar: "enfeitado demais" |
| Bolinhas da forma recente pequenas (12px), cor suave (`opacity .55`), anel na mais recente e legenda "← mais recente" | Bolinhas grandes com letra e cor forte numa área separada |
| Editar perfil como ícone de lápis no canto | Botão com texto cobrindo o título no celular |
| Lista de desafios como página de entrada e formulário em tela própria ("Desafiar amigo") | Formulário de criar no topo da lista |
| Escolha por cartões (amigos com foto) e por chips (melhor de 1/3/5) | `<select>` para poucas opções |
| Contador de vida: confete, canhões, balões e fogos na vitória, com duração longa (≈7s) | — |
| Uma seção por história: o Rival já mostra o retrospecto, então o "Confronto direto" saiu do perfil | Seção de números soltos (vitórias/derrotas em texto) sem visual, ou duas seções contando a mesma coisa |

---

## 2. Base visual

### Tokens (sempre use, nunca cor solta)

Definidos em `public/css/ui.css` para o tema **escuro (padrão)** e o **claro** (`html[data-theme="light"]`).
Toda cor nova tem que funcionar nos dois.

| Uso | Token |
|---|---|
| Fundo da página / card / área neutra / hover | `--ui-bg` / `--ui-card` / `--ui-muted` / `--ui-accent` |
| Bordas | `--ui-border`, `--ui-border-strong`; foco: `--ui-ring` |
| Texto | `--ui-fg` (principal), `--ui-fg-soft`, `--ui-fg-muted` (secundário), `--ui-fg-subtle` (meta), `--ui-fg-faint` (desligado) |
| Botão primário | `--ui-primary` + `--ui-primary-fg` (branco no escuro, quase preto no claro) |
| Estado | `--ui-success` (vitória, ok), `--ui-danger` (derrota, erro), `--ui-warning` (precisa de você), `--ui-info` (em andamento) |
| Espaço | `--s1` 4px · `--s2` 8 · `--s3` 12 · `--s4` 16 · `--s5` 20 · `--s6` 24 · `--s8` 32 |
| Raio | `--ui-radius` 10px (cards), `--ui-radius-sm` 6px (botões, inputs, linhas), `999px` (pills, selos, avatar redondo) |

- Fundo translúcido de estado: `rgba(74,222,128,.07)` sucesso, `rgba(248,113,113,.08)` perigo, `rgba(251,191,36,.07)` aviso,
  com borda na mesma cor a `.22–.3`. É o padrão do `.ui-badge-*`; copie dali.
- Cor de status é **só para status**. Vitória sempre verde, derrota sempre vermelha, empate cinza (`--ui-fg-faint`).
- Exceções que já existem: cores dos jogadores no contador de vida (`App\Support\LifeColor`), roxo do foil (`✦`), cores de mana.

### Profundidade

- Sem glassmorphism, sem sombra forte, sem gradiente de fundo em card.
- Permitido: **linha de destaque de 3px** no topo de um card de confronto (`.rival-card::before`, gradiente verde → cinza → vermelho)
  e brilho (`box-shadow` com cor) **só** em animação de vitória.

### Tipografia

- Um `h1` por página. Seções com `h2` (ou `ui-card-title`). Rótulo acima do título: `.eyebrow` (caixa alta, pequeno).
- Número de destaque: peso 700–800, `font-variant-numeric: tabular-nums`, legenda pequena embaixo em `--ui-fg-subtle`.
- Placar sempre com travessão: `2–1`, `3–1–0` (V–D–E). "×" só entre dois nomes ou dois números grandes num confronto.

### Ícones

- Só `<x-icon name="...">` (Lucide, 14–16px). Disponíveis: search, sun, moon, upload, download, repeat, sparkles, dollar,
  heart, megaphone, link, pin, trash, medal, user-plus, globe, lock, info, check-check, pencil, settings, layout-dashboard,
  trophy, swords, bell, plus, user, users, log-out, menu, x, chevron-right, chevron-down, arrow-right, calendar, activity,
  circle-check, clock, inbox, layers, library, chart, shield, flag, target, ellipsis, crown, alert, home.
- Precisa de outro? Adicione o path do Lucide em `components/icon.blade.php` (licença ISC), não use emoji no lugar.
- Emoji é conteúdo (avatar, conquista, mensagem de festa), não ícone de interface.

---

## 3. Estrutura de página

- **Contador de vida só dentro da partida:** desafios, torneios e Ranked usam seus contadores vinculados. Não criar contador avulso no menu, mesa rápida ou entrada por código/QR.

```
← Voltar                         (back-link, fora de card, só em páginas de detalhe/formulário)
[eyebrow]
Título h1                         [ação secundária] [AÇÃO PRIMÁRIA]   ← .page-head + .page-head-actions
Frase curta do que é a tela
(aviso/alerta contextual: confirm-all, ui-hint)
Card · Card · Card                (mesma largura, 12–16px entre eles)
```

- Todo conteúdo dentro do `.container` (máx. 1120px). Nada encosta fora da coluna.
- **Lista é a página de entrada; criar é tela própria.** A lista tem no canto o botão primário ("Desafiar amigo",
  "Novo torneio"); o formulário abre em `/<recurso>/new` com `← <Lista>`, título "Novo ...", campos em cards e,
  no fim, `Cancelar` (ghost) + ação primária. Links que pré-preenchem usam query (`?opponent=ID`).
- Lista com o que **precisa de você** primeiro (selo amarelo), depois o resto, depois o histórico (paginado).
  Em Desafios o histórico não aparece: ele fica em Minhas partidas, junto com torneios e Ranked.
- Uma página tem **no máximo 1 botão primário**.

---

## 4. Componentes (use estes antes de criar outro)

| Componente | Quando | Como |
|---|---|---|
| `<x-ui.card title icon meta href>` | bloco com cabeçalho | `meta` = contagem ("7 desafios"); `href` = "Ver tudo" |
| `<x-ui.empty icon title text href action>` | lista vazia | texto verdadeiro + 1 CTA que cria o dado |
| `<x-ui.badge tone>` / `.ui-badge ui-badge-{tone}` | estado curto | `success` vitória/ok, `danger` derrota/erro, `warning` precisa de você, `info` em andamento, sem tom = neutro |
| `<x-ui.stat label value icon hint href>` | número do painel | |
| `<x-ui.status :status>` | estado de torneio/partida | não traduza estado na mão |
| `<x-avatar :of>` | foto/emoji/inicial | nunca imprima `mark()` ou emoji direto |
| `<x-profile-link :of>` | nome clicável | nunca dentro de outro link, `<label>`, `<option>` |
| `.ui-row` (+ `ui-row-main`, `ui-row-title`, `ui-row-sub`) | linha de lista | linha inteira clicável quando leva a um detalhe (`<a class="ui-row ...">`); ver `matches/partials/link-row.blade.php` |
| `.ui-option.ui-option-box` + `input.ui-switch` | liga/desliga com explicação | título + descrição à esquerda, chave à direita |
| `.choice-chips` / `.choice-chip` | 2–5 opções curtas (melhor de, validade) | radio escondido + `span` em pill; selecionado = primário |
| `.friend-choices` / `.friend-choice` | escolher pessoa | cartão com avatar + nome, radio escondido, borda primária quando marcado |
| `.stat-grid` + `.stat-card` | números do perfil | ordem: partidas, V–D–E, aproveitamento, maior sequência |
| `.form-dots` / `.form-dot.is-win|is-loss|is-draw` | últimos resultados | 12px, `opacity .55`, `:hover` 1, anel na primeira, `title` com o detalhe + `sr-only` |
| `.rival-card` | "um contra o outro" | avatares nas pontas, placar no meio, selo de quem lidera, barra `.rival-meter`, rodapé com resumo + ação |
| `partials/confirm-all` | 2+ placares para confirmar | já incluso nas listas de partidas e desafios |
| `.mm-open` (+ `mm-body`, `mm-score`, `mm-actions`, `mm-dispute`) | partida em andamento: linha que abre ao clicar (`<details id>`, fechada por padrão) com a ação dentro | placar com as fotos dos jogadores e mini contador − número + (`matches/partials/score-board` + `js/score-step.js`, o mesmo visual do resumo da partida; só leitura ao confirmar), contador de vida, confirmar/contestar; o `id` mantém aberta nas atualizações e um link `#id` já chega aberto; ver `matches/partials/open-match` (torneio) e `challenges/partials/open-challenge` (desafio) |
| `.ui-hint` | explicação de regra | no topo da página, com ícone `info` |
| `<dialog class="ui-dialog">` (+ `ui-dialog-head`, `ui-dialog-body`, `ui-dialog-foot`) | janela por cima da página (compartilhar link e QR) | abre com `[data-dialog-open="id"]`, inclusive de dentro de um menu; fecha no X `[data-dialog-close]`, no Esc ou no fundo (`nav.js`); ver `tournaments/show.blade.php` |

### Botões

| Papel | Classe | Exemplo |
|---|---|---|
| Ação principal (1 por página) | `ui-btn ui-btn-primary` | "Desafiar amigo", "Enviar desafio" |
| Secundária | `ui-btn ui-btn-outline` | "Recusar", "Revanche" |
| Terciária / cancelar | `ui-btn ui-btn-ghost` | "Cancelar" |
| Dentro de card / linha | `+ ui-btn-sm` | |
| Só ícone | `ui-icon-btn` + `aria-label` + `title` | lápis de editar perfil, copiar link |

- Ícone à esquerda do texto (`<x-icon name="swords" />Desafiar amigo`). Texto = verbo + objeto ("Enviar foto").
- **Legado:** `btn btn-primary|btn-secondary|btn-ghost btn-small` ainda existe em telas antigas. Em tela nova use só `ui-btn`.
  Ao tocar numa linha antiga pode migrar **aquela linha** (`btn-secondary` → `ui-btn-outline`, `btn-small` → `ui-btn-sm`); nunca em massa.
- Destrutivo nunca é primário e pede confirmação (`data-confirm="..."`).

---

## 5. Padrões de tela já resolvidos

### Confronto (Rival)

> O card do Rival **saiu do perfil** (pedido do dono). Padrão e classes `.rival-card` foram removidos; o retrospecto entre amigos fica só na seção Rivalidades da tela de Desafios. Mantido aqui só como referência visual caso volte.

```
⚔ RIVAL                                   [Você lidera]
   (avatar)          3 × 1          (avatar)
     Você      Seu placar nos desafios   Ajani
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  (verde/cinza/vermelho, proporcional)
3 desafios entre vocês · último em 01/11/2026      [⚔ Desafiar Ajani]
```

- Placar do ponto de vista de quem está à esquerda. Número de quem lidera colorido (verde se é você, vermelho se é o outro).
- Resumo para leitor de tela em `.sr-only` ("Seu placar... 3 vitórias, 1 derrota").
- Seção que não tem dado **não aparece** (sem "nenhum rival ainda").

### Linha de lista com estado

`avatar · título ("Ajani desafiou você") · sub (Ranqueada · Pauper · MD3 · data) · selo de estado · chevron`.
No celular (≤480px) o selo desce para baixo do texto e o chevron some (grid de 2 colunas).

### Cabeçalho de perfil no celular

Foto (64px) ao lado do eyebrow, nome e @usuário; o resto (deck, conquistas, código) ocupa a largura toda embaixo
(grid com o bloco de texto em `display: contents`). Ação do dono = ícone no canto (o título reserva `padding-right`);
ação do visitante = botão de largura total no rodapé do card.

### Formulário

Campos agrupados em cards com título ("Adversário", "Série"). Escolhas curtas em chips, pessoas em cartões, texto livre com
`<datalist>` de sugestões. Erros: mensagem em português dizendo o que fazer (vem do servidor; o layout já mostra).
Campo com regra rígida também ajuda no cliente (ex.: `emoji-input.js` mantém um emoji só), mas o servidor sempre valida.

---

## 6. Movimento e animação

| Tipo | Duração | Uso |
|---|---|---|
| Hover / foco | `.15s` (`transition: background-color, border-color, color, opacity`) | botões, linhas, chips, cartões |
| Entrada de elemento | `.2–.3s`, `ui-pop` (sobe 4px e aparece) | toasts, menus, avisos |
| Revelar detalhe | `opacity` de `.55` → `1` no hover | bolinhas de forma |
| Celebração | 3–7s, canvas (`.life-party`) | vitória no contador de vida; conquista desbloqueada |

- Celebração tem que **orientar para o vencedor**, ficar abaixo de modais (`z-index` menor) e não cobrir o resultado.
- `@media (prefers-reduced-motion: reduce)`: desligue animações longas e troque por um estado final estático.
- Nada pisca em loop sem motivo; pulso (`life-pulse`) só para "sua vez" ou alerta ativo.
- `transform: translateY(.5px)` no `:active` dos botões já existe; não adicione `scale` exagerado.

---

## 7. Acessibilidade

- Informação visual também em texto: bolinha tem `title` + `<span class="sr-only">`; barra tem resumo em `.sr-only`.
- Cor nunca é o único sinal (selo diz "Vitória 2–1", não só fica verde).
- Botão só com ícone: `aria-label` e `title`. Grupo de radios customizados: `role="radiogroup"` + `aria-label`; foco visível
  com `:has(input:focus-visible)` e `outline: 2px solid var(--ui-ring)`.
- Toque mínimo de 32px (botões 36px; chips 38px). Nada de ação que só aparece no hover.
- Elementos decorativos com `aria-hidden="true"`.

---

## 8. Celular (≤780px e ≤480px)

- Teste em **360px e 390px**. Grids viram uma coluna; padding de card cai para 16px.
- Texto longo não espreme selo: selo desce de linha. Nome longo usa `overflow-wrap: anywhere` ou reticências.
- Botão de ação no fim de formulário/card ocupa a largura toda (≤480px); `Cancelar` fica embaixo dele.
- Ação no canto (ícone) é `position: absolute` e o conteúdo ao lado reserva o espaço com `padding-right`.

---

## 9. CSS e JS

- **Onde:** componente compartilhado → `public/css/ui.css` (bloco com comentário `/* ---------- Nome ---------- */`);
  só de um módulo → CSS do módulo com `@push('head')<link ... VersionedAsset::url('css/x.css')>@endpush`.
- **Nome:** prefixo da funcionalidade (`rival-`, `challenge-`, `form-`, `friend-choice`). Antes de criar,
  `grep -rn "\.prefixo-" public/css` para não colidir.
- `style=""` só para valor dinâmico (ex.: `flex-grow` proporcional). Nada de cor inline.
- Escreva as regras do celular junto do componente, no mesmo bloco.
- Cuidado com a cascata do legado (`.stat-card span`, `.stat-card b`, `label:has(> input)`); se um elemento novo dentro
  desses herdar estilo, sobrescreva com seletor específico.
- **JS:** arquivo pequeno por comportamento em `public/js/`, IIFE com `'use strict'`, carregado com `defer` via
  `VersionedAsset::url`, ligado por `data-*` (ex.: `data-single-emoji`, `data-copy-link`). Eventos delegados no `document`;
  para reagir à atualização da página use o evento `manaleague:updated`.
- Formulários POST já são enviados sem recarregar (`async-forms.js`); telas "ao vivo" usam `@section('live-scope', ...)`
  (com o Reverb, o aviso chega na hora; sem ele, o polling segue igual). Script que precisa de tempo real usa
  `window.ManaLeagueRealtime` (pode ser `null`: sempre tenha o plano B do polling).
- Sem CDN e sem bibliotecas novas no front (a CSP só libera `'self'`).

---

## 10. Texto

- Português do Brasil, frases curtas, "você". Botão: verbo + objeto. Sem inglês de framework ("submit", "loading").
- Plural certo: `1 desafio` / `2 desafios`, `1 vitória` / `2 vitórias`.
- Data `dd/mm/aaaa` no fuso do app. Estados com o mesmo nome em todo lugar ("Aguardando confirmação", "Confirmar placar").
- Vazio diz o que fazer: "Nenhum desafio ainda. Chame um amigo para a primeira série."

---

## Checklist antes de entregar

1. Usei componentes e classes existentes? Nome de classe novo tem prefixo e não colide?
2. Só tokens (nenhuma cor/raio novo)? Funciona no tema claro também?
3. No máximo 1 botão primário? Ação principal visível sem rolar?
4. Conferido em 1280px e 390px (skill `verificar-tela`)? Selo e nome não se espremem?
5. Informação visual tem texto (`title`, `sr-only`)? Botão de ícone tem `aria-label`?
6. Animação respeita `prefers-reduced-motion`? É discreta, ou é um momento de celebração?
7. Textos em português, plural certo, datas `dd/mm/aaaa`?
8. Seção sem dado some (ou mostra `x-ui.empty` com ação útil)?
