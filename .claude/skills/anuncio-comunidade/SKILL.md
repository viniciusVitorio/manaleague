---
name: anuncio-comunidade
description: Escreve o anúncio de uma atualização do ManaLeague para grupos de WhatsApp ou Telegram — texto leve, alegre e com emojis, feito a partir do patch note. Use quando pedirem mensagem, anúncio, aviso ou divulgação de uma versão para WhatsApp, Telegram, grupo, comunidade ou galera.
---

# Anúncio de atualização para WhatsApp e Telegram

O **patch note** (GitHub Release, skill `patch-notes`) é a fonte oficial do que mudou.
Este anúncio é a versão para o grupo: menos técnica, mais alegre, com emojis, para a galera ler no celular.

Seu trabalho é **escrever o texto**. Quem manda a mensagem no grupo é a pessoa usuária.

## Regras

- **Nunca rode comandos git** e **nunca envie a mensagem** (nem por e-mail, nem por nenhuma ferramenta). Entregue o texto pronto para colar.
- **Não invente funcionalidade.** Todo item precisa vir do patch note, das mudanças informadas ou do código.
- O anúncio **não substitui** o patch note: ele resume e aponta para a aba Patch notes.

## 1. Juntar a fonte

Use a primeira fonte disponível:

1. O patch note da versão (título e texto da release). Se ele foi escrito nesta conversa, use esse.
2. Se ainda não existe patch note, escreva primeiro com a skill `patch-notes` e depois faça o anúncio a partir dele.
3. Descrição livre do que mudou, e então confira no código o que cada item faz.

Só anuncie o que já está **em produção** (depois do merge e do deploy). Na dúvida, pergunte.

## 2. Escolher o que entra

- Escolha de **3 a 8 tópicos**. Junte itens do patch note que falam da mesma coisa num tópico só.
- Comece pelo que o jogador mais vai gostar ou perceber; correções e detalhes vão por último.
- Deixe de fora o que o jogador não percebe. Ajustes pequenos podem ficar só no patch note.
- **Mudança de regra** que afeta o jogador (algo que agora é obrigatório, bloqueado ou diferente) **sempre entra**, com um ⚠️ e dizendo o que a pessoa precisa fazer.

## 3. Formato

```
🧙 Novidades no ManaLeague!

<emoji> <Nome do tópico>: <o que mudou, em 1 a 3 frases curtas>. <Onde encontrar ou como usar.>

<emoji> <Nome do tópico>: ...

🐛 Correção: <o que estava errado e agora funciona>

📜 Tudo isso também está na aba Patch notes (menu Mais), na versão vX.Y.Z.

Quem testar e achar algum bug, me chama! 🃏
```

- **Abertura fixa:** `🧙 Novidades no ManaLeague!`
- **Cada tópico** é um parágrafo separado por linha em branco: um emoji, um nome curto com personalidade, dois-pontos e o texto.
  - Nome do tópico como apelido da novidade: "Em chamas", "Tudo clicável", "Vitrine no perfil", "Perfil de cara nova".
  - Diga **onde fica**: "vai em Editar perfil → Preferências", "é só clicar no ☀️ no topo".
  - Use os nomes que aparecem na tela, entre aspas quando for um botão: "Já comprei", "+ Adicionar deck".
- **Correções** usam 🐛 e `Correção:`. Se forem várias, junte numa linha só ou use um 🐛 por correção.
- **Linha do patch note** (📜) com a versão, quando a release já estiver publicada.
- **Fechamento fixo:** `Quem testar e achar algum bug, me chama! 🃏`

### Emojis

Use **um emoji no começo de cada tópico** e, no máximo, um ou dois no meio do texto quando fizer graça (🔥, 😅, ☀️). Não encha a mensagem.

Sugestões por área (não é obrigatório, escolha o que combina com a novidade):

| Área | Emojis |
|---|---|
| Torneios | 🏆 ⚔️ 📋 |
| Partidas, placar, Modo TV | 🎯 📺 |
| Desafios, confronto direto | ⚔️ 🥊 |
| Ranked | 📈 🏅 |
| Decks | 🃏 ✏️ |
| Coleção | 📚 ✨ (foil) |
| Perfil | 👤 📸 🎨 |
| Amigos, comunidade, clubes | 🤝 👥 |
| Interface, tema | 🌗 👆 👁️ |
| Privacidade, conta | 🔒 |
| Mudança de regra | ⚠️ |
| Correção | 🐛 |
| Patch notes | 📜 |

### Tom

- Português do Brasil, bem informal: "dá pra", "pra galera", "é só", "tipo".
- Alegre e direto, como quem conta a novidade para os amigos da mesa. Um toque de Magic cai bem.
- Sem jargão técnico (controller, migration, endpoint, cache, deploy, PR, API) e sem nome de arquivo ou classe.
- Se um detalhe técnico tranquiliza o jogador (privacidade, segurança), explique em palavras simples: "a gente apaga os dados escondidos da foto, tipo a localização".

### Limites do WhatsApp e do Telegram

- **Texto puro.** Não use negrito, itálico, links em markdown nem tabelas: WhatsApp (`*negrito*`) e Telegram (`**negrito**`) marcam diferente, e o que um entende o outro mostra cru.
- Sem listas com `-`: cada tópico é um parágrafo começando pelo emoji.
- Até **3.500 caracteres** para caber numa mensagem só do Telegram (o limite é 4.096). Ideal: 800 a 2.000.

## 4. Entregar

Responda com:

1. O **anúncio** num bloco de código, pronto para colar.
2. Quantos caracteres ele tem, mais ou menos.
3. Lembrete: mandar no grupo só **depois** do deploy, de preferência depois de publicar a release (para a linha 📜 apontar para algo que já existe).

Se a pessoa pedir, ofereça também uma **versão curta** (abertura, 2 ou 3 tópicos e fechamento) para status ou canais com pouco espaço.

## Exemplos

Mensagens reais já mandadas nos grupos estão em [exemplos.md](exemplos.md). Siga o jeito delas: tamanho dos tópicos, tom, onde entram os emojis e como apontam onde fica cada coisa.
