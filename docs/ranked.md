# Ranked ELO/PDL (ManaLeague 3.0 — ML3-01 e ML3-02)

## Regulamento para jogadores

A mesma versão aparece no app em `/ranked/regulamento` (regras `elo-v1`).

- **ELO é o único saldo.** PDL é só a exibição do progresso dentro de uma divisão de 100 pontos (1128 = Prata I, 28/100 PDL).
- Todos começam com **1000 (Prata II)** em **cada formato** e em **cada temporada**. Os ratings de Pauper, Modern etc. são separados.
- **Cálculo:** `E = 1 / (1 + 10^((R_adv − R_seu)/400))`; `Δ = round(K × (S − E))`, com S = 1, 0,5 ou 0. O adversário recebe `−Δ`. O K padrão é 32 e fica gravado em cada partida. Placar em games não altera o valor.
- **Patentes:** Ferro (até 799; II até 699), Bronze, Prata, Ouro, Platina e Diamante (II e I de 100 pontos cada), Mestre (1800+). As transições são instantâneas.
- **Provisório:** nas primeiras 5 partidas. O ranking público exige 3 partidas confirmadas.
- **O que pontua:** só partida marcada como ranqueada **antes** de jogar e aceita pelos dois (desafio ranqueado ou proposta da fila), com placar enviado por um jogador e confirmado pelo outro.
- **O que não pontua:** casuais, BYE, W.O., torneios, resultados antigos e Commander multiplayer.
- **Anti-farming:** no máximo 3 ranqueadas por dupla a cada 24h por formato (configurável por temporada). O aviso aparece antes de marcar. Padrões atípicos geram alerta para revisão humana, sem punição automática.
- **Disputas:** nada muda até a resolução. Os jogadores podem enviar um placar corrigido (e o outro confirma) ou a moderação decide.
- **Correções:** invalidar um resultado recalcula o formato em ordem cronológica e lança uma *correção* com motivo. Nenhum lançamento antigo é editado.
- **Temporadas:** rascunho → agendada → em andamento → encerrando → encerrada. Ao encerrar, nada novo pontua e as disputas abertas têm 72h. Depois o ranking final é congelado (snapshot) e a próxima temporada recomeça em 1000.
- **Privacidade:** o jogador pode aparecer como "Jogador oculto". Nunca mostramos e-mail ou localização. O IP do envio de placar fica no máximo 90 dias, para auditoria.

## Matchmaking presencial

O app não roda partidas online. A fila só propõe confrontos entre quem está disponível:

| Espera | Diferença máxima |
|---|---|
| 0 min | 100 |
| 2 min | 200 |
| 5 min | 300 |
| 8 min | 400 (com aviso de diferença alta) |

- A entrada mais antiga é atendida primeiro, pela menor diferença de rating.
- A fila é separada por temporada, formato e clube (ou comunidade geral).
- Ficam de fora jogadores suspensos e duplas que já atingiram o limite de partidas.
- A proposta vale 3 minutos. Quem recusa sai da fila; quem aceitou volta a buscar sem perder a posição.
- A página aberta envia um sinal de presença. Sem sinal por 10 minutos (ou após 2h na fila), a entrada expira.
- Antes da proposta, nada sobre outros jogadores é exibido além do tamanho da fila.

## Arquitetura

| Peça | Responsabilidade |
|---|---|
| `Ranked\EloCalculator` | Fórmula pura, um único arredondamento, soma zero |
| `Ranked\RankTierResolver` | Patente e PDL derivados do rating (nada duplicado no banco) |
| `Ranked\RatingLedgerService` | Única porta que altera rating: transação, lock dos dois ratings em ordem de id, piso 0 auditado |
| `Ranked\RatingReplayService` | Recalcula o pool e grava lançamentos `correction` |
| `Ranked\RankedMatchService` | Elegibilidade, criação, envio/confirmação/disputa/resolução/invalidação/cancelamento |
| `Ranked\MatchmakingService` | Fila, janela progressiva, propostas, presença |
| `Ranked\SeasonService` | Ciclo de vida das temporadas, conflito de sobreposição, snapshot final |
| `Ranked\LeaderboardService` | Ranking com mínimo de partidas, privacidade e desempate (rating, vitórias, partidas, id) |
| `Ranked\RankedFraudReviewService` | Alertas: volume da dupla, vitórias alternadas, transferência unilateral |
| `Ranked\RankedNotifier` | Notificações e feed com chaves idempotentes |

**Integridade:**
- `rating_transactions` tem índice único `(ranked_match_id, player_profile_id, event_type, correction_seq)`, e o model bloqueia `update`/`delete`.
- O envio de placar é idempotente por `(submitted_by, idempotency_key)`.
- `ranked_matches` é único por `(source_type, source_id)`, então uma proposta ou desafio gera no máximo uma partida.
- Confirmação repetida ou concorrente não aplica Elo duas vezes: o status é relido dentro da transação, há o índice único e o `applyMatch` vira no-op.

**Autorização:**
- Só participantes enviam, confirmam ou contestam (quem enviou não confirma).
- Disputas são resolvidas por admin, ou por dono/organizador do clube nas temporadas de clube. Participantes nunca resolvem.
- Só admin invalida resultado e gerencia temporadas e suspensões.
- `is_admin` não é *fillable*: só é alterado via comando.

**Integração com a 2.0:**
- O desafio 1v1 ganhou a opção "ranqueada".
- O ranked usa as notificações e o feed (categoria `ranked` silenciável).
- O painel pessoal mostra patente e pendências, os clubes servem de escopo da fila e das temporadas, e o perfil tem link para o histórico ranqueado.

## Operação

```bash
php artisan ml:admin voce@exemplo.com      # dá acesso à moderação (--revoke remove)
php artisan ranked:tick                    # avança temporadas por data e roda a fila
php artisan ranked:prune                   # apaga IPs de envios com mais de 90 dias
```

- Os dois comandos estão no scheduler (`ranked:tick` a cada minuto, `ranked:prune` diário). Mesmo sem cron, as páginas fazem a transição de temporadas sob demanda e a fila roda com o heartbeat das páginas abertas.
- Para aplicar a retenção de IP em produção, adicione `* * * * * php artisan schedule:run` no container ou no host.
- Feature flag: `RANKED_ENABLED=false` esconde rotas e menu sem apagar dados.

**Primeiro uso:**
1. `php artisan ml:admin <seu e-mail>`
2. Abrir `/ranked/admin` e criar a temporada (fica como rascunho).
3. Clicar em **Publicar**. Ela vira "em andamento" quando chegar a data de início.

**Deploy e rollback:**
- A migration `2026_10_21_010001_create_ranked_tables` só cria tabelas e colunas novas e roda sozinha no entrypoint.
- Rollback: `php artisan migrate:rollback --step=1` remove tudo do ranked sem tocar no legado. Faça backup do SQLite antes, porque o histórico ranqueado será perdido.

## Limitações conhecidas

- A resolução de disputas recalcula apenas a partida em questão (ela ainda não tinha Elo aplicado). Correções de partidas já confirmadas passam por invalidação e replay.
- Concorrência foi testada por repetição de chamadas e pelo índice único. O SQLite serializa as escritas, então não há teste com dois processos reais em paralelo.
- Não há bloqueio entre jogadores: a fila usa suspensão de moderação e o limite por dupla.
