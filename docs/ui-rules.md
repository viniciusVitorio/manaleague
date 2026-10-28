# Regras de UI do ManaLeague

Regras curtas e verificaveis para qualquer tela nova ou ajuste (humano ou IA).
Ao pedir algo para uma IA, inclua: **"siga docs/ui-rules.md"**. Se uma regra atrapalhar um caso real,
mude a regra aqui primeiro — nao abra excecao escondida na tela.

## 1. Base visual

- Tema escuro unico, paleta zinc. Use **sempre os tokens** de `public/css/ui.css` (`--ui-bg`, `--ui-card`,
  `--ui-muted`, `--ui-border`, `--ui-fg`, `--ui-fg-muted`, `--ui-fg-subtle`, `--ui-primary`) — nunca cor solta no HTML.
- Cores de status so para status: `--ui-success`, `--ui-warning`, `--ui-danger`, `--ui-info` (via `<x-ui.badge tone>`).
- Espacamento em multiplos de 4px (`--s1`…`--s8`). Distancia entre cards irmaos: **12px**. Padding de card: **20px** (16px no celular).
- Raio: `--ui-radius` (10px) em cards, `--ui-radius-sm` (6px) em botoes/inputs, `999px` so em pills, badges e avatar redondo do cabecalho.
- Sem gradiente decorativo, sem glass, sem sombra forte. Hierarquia vem de tamanho, peso e cor do texto.
- Icones: so o set Lucide de `<x-icon>`, 14–16px, mesmo traco. Nao misture emoji e icone na mesma funcao
  (emoji e conteudo do usuario — avatar, conquistas — nao e icone de interface).

## 2. Grid e alinhamento

- Todo conteudo fica dentro do `.container` (max 1120px). **Nada encosta fora da coluna**: foto, titulo e cards
  comecam na mesma linha vertical da esquerda.
- Uma pagina e uma pilha de blocos com a mesma largura. Blocos lado a lado so em grid (`stat-grid`, `profile-columns`),
  com o mesmo gap.
- Link de voltar (`← Torneios`) fica **acima** do primeiro card, nunca dentro dele.

## 3. Cards

| Tipo | Para que serve | Acoes permitidas |
|---|---|---|
| **Identidade** (cabecalho de perfil, de clube, de torneio) | quem/o que e | **no maximo 1** acao, secundaria (`ui-btn-outline ui-btn-sm`), no **canto superior direito** |
| **Dados** (stats, ranque, historico) | numeros e listas | 1 acao de secao no cabecalho do card (`Ver tudo`, `Desafio ranqueado`) |
| **Formulario** | editar algo | 1 botao primario no fim; "remover/cancelar" como `ghost` |
| **Vazio** (`<x-ui.empty>`) | estado sem dados | 1 CTA que leva para criar o dado |

- Card de identidade **nao** leva botoes de navegacao (ex.: "Desafios", "Rivalidades"): isso ja existe no menu.
- Excecao: o icone "Copiar link do perfil" ao lado do nome nao conta como a acao do card (e parte do titulo, como num perfil de rede social).
- Uma pagina tem **no maximo 1 botao primario** (branco). Todo o resto e `outline` ou `ghost`.
- Acao destrutiva (remover, excluir) nunca e primaria; pede confirmacao quando apaga dados.

## 4. Botoes e links

- Classes: `ui-btn` + `ui-btn-primary | ui-btn-outline | ui-btn-ghost` (+ `ui-btn-sm` dentro de cards).
- Texto do botao = verbo + objeto curto ("Editar perfil", "Enviar foto"). Nada de "Clique aqui".
- Botao com icone: icone a esquerda, 14px. Botao so com icone precisa de `aria-label`.
- Navegar = link (`<a>`); mudar dados = `<form>` com `POST/PATCH/DELETE` + `@csrf`.

## 5. Tipografia

- Um `h1` por pagina (nome do perfil, titulo da tela). Seções: `h2`. Rotulo acima do titulo: `.eyebrow` (caixa alta, pequeno, `--ui-fg-subtle`).
- Texto secundario em `--ui-fg-muted`; meta (datas, contagens) em `--ui-fg-subtle` e tamanho menor.
- Numeros de estatistica em destaque (peso 700) com legenda pequena embaixo.

## 6. Avatar

- Sempre `<x-avatar :of="$perfilOuUsuario" class="...">` — nunca imprimir `mark()`/emoji direto.
- Tamanhos: 28px (listas/cabecalho do site), 42px (linhas de amigos), 62px (grande sem foto), **104px** (grande com foto; 80px no celular).
- Foto preenche o quadrado (`object-fit: cover`); sem foto mostra o emoji ou a inicial centralizada.
- Nome de jogador e clicavel, como numa rede social: envolva com `<x-profile-link :of="$playerOuPerfilOuConta">nome</x-profile-link>`.
  O texto nao muda (sublinha so no hover) e o link so aparece se quem ve pode abrir o perfil.
  **Nunca** dentro de outro link (card ou linha clicavel), nem em `<label>`, `<option>` ou atributo.
- Avatar ao lado do nome tambem leva ao perfil: `<x-profile-link :of="..." class="avatar-link" tabindex="-1" aria-hidden="true"><x-avatar .../></x-profile-link>`
  (fora do Tab e dos leitores de tela, porque o nome ao lado ja e o link acessivel).

## 7. Celular (≤780px)

- Grids viram uma coluna; o padding do card cai para 16px.
- A acao do card de identidade continua no canto superior direito; nunca empurra o conteudo para baixo.
- Toque minimo de 32px de altura; nada de hover como unico jeito de achar uma acao.

## 8. Estados e mensagens

- Sucesso: `session('status')` → alerta verde no topo. Erro de campo: mensagem logo acima do campo, em portugues, dizendo o que fazer.
- Sem dados: `<x-ui.empty>` com texto verdadeiro (nada de placeholder de funcao que nao existe).
- Carregamento externo (Scryfall, S3) falha "macio": a tela abre e mostra o fallback.

## 9. Checklist antes de entregar uma tela

1. Todos os blocos alinhados na mesma coluna? Link de voltar fora do card?
2. No maximo 1 botao primario na pagina? Card de identidade com no maximo 1 acao, no canto?
3. So tokens e classes `ui-*` (nenhuma cor/raio novo)?
4. Testado em 1280px e em 390px de largura?
5. Textos em portugues, verbos claros, sem ingles de framework?
