# Regras de backend do ManaLeague

Como organizar controller, service, model, migration e regra de negócio. Complementa [architecture.md](architecture.md)
(infra e decisões) e [testing.md](testing.md).

---

## 1. Onde cada coisa mora

| Camada | Faz | Não faz |
|---|---|---|
| **Route** (`routes/<modulo>.php`) | URL, nome (`challenges.create`), middleware (`auth`, `throttle:N,1`) | lógica |
| **Controller** (`app/Http/Controllers`) | valida a entrada, chama o service, escolhe a view/redirect, monta dados **de exibição** | regra de negócio, query complexa repetida |
| **Service** (`app/Services`, subpastas por domínio) | regra de negócio, transações, cálculos, eventos | HTML, `request()` |
| **Policy** (`app/Policies`) | quem pode ver/editar (`view`, `befriend`, `resolve`...) | regra de negócio |
| **Model** (`app/Models`) | relações, casts, atalhos pequenos (`displayName()`, `statusLabel()`, `emoji()`) | consulta a outros domínios |
| **View** (`resources/views`) | apresentação; pode decidir texto/plural/classe | consultas ao banco (exceto componente que já faz isso) |
| **Support** (`app/Support`) | utilitários sem estado (`VersionedAsset`, `Theme`, `LifeColor`) | |

- Rota estática antes da rota com binding: `/challenges/new` e `/challenges/rivalries` antes de `/challenges/{challenge}`.
- Toda rota POST/PATCH/DELETE tem `throttle` adequado e `@csrf` no formulário.
- Link antigo que muda de lugar ganha **redirect**, não 404 (ex.: `/challenges?opponent=` → `/challenges/new?opponent=`).

## 2. Services

- Um service por assunto, com nome do assunto: `ChallengeService`, `RivalService`, `PlayerStatsService`, `Ranked\RankedMatchService`.
- Fonte única: um cálculo mora num lugar só e os outros chamam. Estatísticas do perfil e do painel vêm de
  `PlayerStatsService::for()`; conquistas, de `AchievementService`; rival, de `RivalService`.
- Retorno para tela: array com chaves claras e tipadas no docblock (`@return array{played: int, wins: int, ...}`).
- Erro de regra para o usuário: `ValidationException::withMessages(['campo' => 'Mensagem em português'])`
  (o padrão `require(bool $ok, string $msg)` de `ChallengeService`). O layout já mostra.
- Escrita com mais de uma tabela: `DB::transaction(...)` e `lockForUpdate()` em estados que podem disputar
  (placar, ELO). Efeitos colaterais (notificação, feed, conquistas) **depois** do commit.
- Resolva dependência com injeção no construtor; `app(X::class)` só onde a injeção não chega (views, closures).

## 3. Visibilidade e privacidade

- Perfil: `@can('view', $profile)` / `PlayerProfilePolicy`. Quem não pode ver recebe 404, não 403, em URLs adivinháveis.
- Lista de amigos, rival, fichário de trocas: **só o dono e amigos aceitos** (`FriendshipService::ACCEPTED`).
- Decks: `DeckPolicy`; deck privado nunca aparece para terceiros, nem em contagem.
- Dado de outra pessoa numa tela de terceiro: sempre pergunte "um estranho deveria ver isso?" e teste os três papéis
  (dono, amigo, estranho).

## 4. Eventos, notificações e feed

- `Community\CommunityEvents` é o único ponto que transforma evento de domínio em notificação e atividade.
- `NotificationService::send($user, $type, $title, $url, $key, $data)`: `$key` estável (`challenge:{id}:...`), então repetir não duplica.
- Conquistas: depois de um resultado confirmado, `AchievementService::sync($profile)` para os dois jogadores
  (avisa no sino; `notify: false` quando for só leitura de página).
- Telas ao vivo: `LiveVersionService` (escopo `me`, `challenge:{id}`...) e `@section('live-scope', ...)` na view.

## 5. Domínios com regra especial

- **Partidas:** torneio (`GameMatch`), desafio casual (`Challenge` + `CasualMatch`), Ranked (`RankedMatch`).
  Placar só conta **confirmado pelos dois**. Commander (pods) fica fora de estatística 1v1.
- **Ranked:** `source_type` é `challenge` ou `tournament_match`. Ranked de torneio já conta pela partida do torneio
  (não some duas vezes). Correção de ELO só por replay (`RatingReplayService`) dentro de transação; temporada encerrada é imutável.
  Feature flag: `config('ranked.enabled')`. Detalhes em [ranked.md](ranked.md).
- **Desafios:** casual não tem empate na UI de estatística (V–D). Status: `pending → accepted → completed`
  (ou `declined`, `cancelled`, `expired`, que expira de forma preguiçosa ao abrir as páginas). Placar sem resposta por `Challenge::CONFIRM_DAYS` dias é confirmado sozinho (`ChallengeService::autoConfirmOverdue`, também ao abrir as páginas e todo dia pelo workflow `daily-tasks.yml`).
- **Avatar:** emoji único (servidor valida 1 grafema pictográfico; `PlayerProfile::emoji()` corta valores antigos).

## 6. Banco e migrations

- Uma migration por mudança: `database/migrations/AAAA_MM_DD_00000N_descricao.php`, com `down()` funcional.
- Coluna nova em tabela com dados: `nullable()` ou `default`. Nada de migration que dependa de dado de produção.
- Migrations rodam sozinhas ao subir o container (local e produção). Teste em banco limpo (`RefreshDatabase` já faz).
- Evite N+1: `with([...])`, e em listas monte os dados em lote (ver `ChallengeController::rows()`, que busca os Ranked
  de todos os desafios de uma vez).
- SQLite: `LIKE` é case-insensitive só para ASCII; ordene datas pelo campo, não por string formatada.

## 7. Estilo de código

- PSR-12 + Laravel Pint (`composer lint`). Arquivos antigos têm métodos em uma linha; em código novo, prefira legível
  (um método por bloco, linhas até ~160 colunas).
- Nomes em inglês; comentários e mensagens em português. Comentário explica o **porquê**, não o que a linha faz.
- Sem código morto: removeu a funcionalidade, remova service, rota, CSS e teste junto (ex.: `HeadToHeadService` removido com a seção).
- Datas: `Carbon`, exibidas com `->timezone(config('app.timezone'))`. Preço de carta: `decimal(10, 2)` em dólar (`price_usd`), como vem do Scryfall.

## 8. Segurança

- CSP só `'self'` (`SecurityHeaders`): nada de script/estilo externo. Fotos só do bucket configurado.
- Validação no servidor sempre, mesmo com ajuda no cliente.
- Segredos só em variável de ambiente/GitHub Secrets. Nunca logue e-mail, token ou código de amigo.
- Ajuste de dado em produção: skill `corrigir-producao` (backup, simulação, aplicar, limpar).

## Checklist antes de entregar

1. Regra no service, controller fino, autorização por policy?
2. Visibilidade testada para dono, amigo e estranho?
3. Escrita em transação; notificações/conquistas depois do commit, com chave estável?
4. Sem N+1 nas listas? Migration com `down()` e coluna nova nullable?
5. Removeu algo? Removeu também rota, service, CSS e testes que só serviam a isso?
6. Testes cobrindo o comportamento (ver [testing.md](testing.md)) e suíte inteira passando?
