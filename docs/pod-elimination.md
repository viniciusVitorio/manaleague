# Eliminação por Pods (Commander)

## Fluxo e decisões conservadoras

`pod_elimination` é um sistema adicional. `commander_pods`, seus pontos e seu avanço manual não mudam.

- Commander, sem ELO/PDL. Padrão: 2 classificados por mesa, sorteio a cada fase, decisão de desempate documentada e sem consolação.
- 8 inscritos: duas semifinais de 4 → dois classificados de cada → grande final de 4. A consolação opcional usa os outros 4, na mesma rodada da final, e define 5º–8º.
- A consolação só é aceita para 8 inscritos e 2 classificados. Final e consolação podem terminar em qualquer ordem; o torneio só encerra após ambas. O campeão vem exclusivamente da final.
- 1 ou 2 classificados por mesa. Não há BYE, mesas de 1/2, corte arbitrário ou distribuição incompleta. Para cada total, o planejador procura uma partição exata em 3/4 lugares cujo número de classificados admita a mesma regra recursivamente até uma final de 3/4. Prefere menos mesas/mais mesas de 4, mas tenta outra partição se necessário.
- Exemplos: 8/2 e 16/2 são válidos; 9/1 leva a uma final de 3; 5/2 e 8/1 são rejeitados. O teste compara todos os totais de 5 a 128, para ambos os valores, com uma tabela independente de viabilidade.
- O limite configurado é validado ao criar/editar. O total **real** de inscritos é validado novamente sob lock no início. Ausentes não são removidos silenciosamente: todos precisam de check-in ou devem ser removidos pelo fluxo existente. Nenhum snapshot, mesa ou mudança de status sobrevive a uma falha no início.
- Sem sorteio, os assentos seguem a ordenação de nomes já usada na relação de inscritos. Isso não decide desempates nem colocações.

## Posições, empates e confirmação

A confirmação de duelos por dois jogadores **não é compatível com mesas multiplayer**. O endpoint canônico `matches.update`, autorizado pela policy `update` do torneio, continua sendo o único caminho do organizador. Para o novo sistema ele delega as regras ao `PodEliminationService`.

1. Informe a posição de cada inscrito da mesa. Use classificação competitiva: `1,2,2,4`, `1,2,3,3` e `1,1,3,4` são possíveis; `1,2,2,3` não é.
2. **Salvar provisório** guarda posições, inclusive empates não resolvidos, mas mantém a mesa pendente. Sem `confirm=1`, a gravação é provisória. Isso também impede que a ordem sugerida pelo contador de vida seja confirmada automaticamente.
3. **Confirmar posições** identifica o organizador e a data. Uma vaga empatada ou empate no título exige desempate explícito.
4. `tiebreak` contém uma ordenação completa apenas dentro dos grupos originalmente empatados; não pode promover alguém de outro grupo. `resolution_note` é obrigatória e limitada a 1000 caracteres. `manual` e `playoff` definem o critério anunciado; uma partida de desempate é realizada fora da progressão automática e sua decisão é documentada pelo organizador, não simulada pelo sistema.
5. As posições originais ficam em `pod_scores`; a decisão separada fica em `pod_tiebreak_scores`. Empates abaixo do corte podem permanecer compartilhados.
6. Só mesas confirmadas alimentam a progressão/classificação. Depois de uma fase dependente existir, a fase anterior fica imutável. Torneios encerrados também ficam imutáveis.

Prêmios de colocação precisam de destinatário único. Em posição compartilhada, nenhum dos empatados é escolhido arbitrariamente nem a quantidade física é multiplicada: essa premiação fica sem destinatário. A UI avisa para resolver o empate **antes de confirmar a final** quando houver premiação para essa posição. Prêmios de participação continuam para todos. A tabela preserva todas as posições compartilhadas; o pódio individual só marca colocações únicas.

Sem consolação, eliminados da mesma fase compartilham a faixa: no caso de 8, todos ficam em 5º. Em 16/2, eliminados na primeira fase compartilham 9º, e os eliminados na semifinal compartilham 5º. Não se usa nome, ID ou ordem de inscrição como desempate. Durante o torneio, a classificação é parcial e o status mostra quem ainda disputa.

## Reutilização e integridade

- Inscrição, convites, decks, check-in, regras da casa, banidas Commander e snapshots: mecanismos existentes. O início revalida decks salvos dos jogadores com conta, compatibilidade do formato e banidas antes do snapshot.
- Partidas: tabela `matches`, JSON dos assentos e posições existentes; novos campos de mesa, decisão e confirmação. `stage` distingue `league`, `final` e `consolation`; não se desenha bracket 1v1.
- `elimination_pod_seats`: associação normalizada com unicidade `(tournament_id, round, player_id)` e `(match_id, player_id)`; impede assento duplicado por fase no banco.
- Unicidade `(tournament_id, round, pod_number)` na tabela de partidas. Campos nulos deixam formatos antigos intactos.
- Início, registro canônico e progressão usam transações e lock da mesma linha do torneio. O escritor também trava a partida. A progressão verifica a existência da próxima fase antes de criar; reexecuções são no-op. Uma repetição de envio para uma fase já travada retorna conflito, sem duplicar nada.
- Avançar manualmente pelo endpoint genérico é recusado para este sistema. A geração automática só acontece quando **todas** as mesas necessárias têm resultado confirmado. O cronômetro é limpo na troca de fase e no encerramento.
- `StandingsService`, `TournamentChampionService`, retrospectiva e prêmios usam a mesma classificação específica. Não se escolhe o último match (que poderia ser a consolação) nem o vencedor de um placar 1v1 nulo.
- `MatchController` mantém `CommunityEvents::tournamentProgress`; notificações de rodada, conclusão e premiação mantêm suas chaves idempotentes. O escritor sincroniza conquistas de todos os inscritos após o resultado, inclusive eliminados quando o torneio encerra.
- Repetir torneio copia todas as configurações novas, não partidas, presenças, confirmações ou decisões.

## Estatísticas, histórico e privacidade

A política existente de estatísticas de perfil é **1v1**. Mesas não viram draws `0–0`, wins de duelista ou partidas ranqueadas. O filtro de `PlayerStatsService` agora também executa essa política, inclusive para os primeiros dois assentos dos pods antigos. Participações, torneios encerrados, títulos e posições de pódio do novo sistema são fatos reais e continuam alimentando conquistas. Não se inventam clean sweeps, comebacks ou sequências 1v1 para multiplayer.

A classificação do próprio torneio conta cada mesa confirmada uma vez por jogador, inclusive a final; o histórico individual mostra todos os 3/4 assentos e a posição efetiva. `DeckUsageService` já exige placares 1v1 não nulos, portanto não cria resultados de duelo para essas mesas. O contador e a mesa de vida compartilhada existentes já autorizam todos os assentos: jogadores usam os contadores; só organizadores salvam posições provisórias e as confirmam na tela do torneio.

As páginas do organizador, públicas e TV mostram fases/mesas/assentos/posição/status. O snapshot público tem allowlist explícita de nomes, posições confirmadas e classificados: não expõe decks privados, decisões textuais, IDs de contas, autor da confirmação ou payload de submissões. A confirmação usa os formulários assíncronos existentes; as telas continuam com `live-scope`, Reverb quando configurado e polling como fallback. Assets usam `VersionedAsset`.

## Testes e evidência TDD

Arquivo: `tests/Feature/PodEliminationTest.php` (18 testes iniciais, incluindo varredura matemática; 29 após os regressivos da revisão independente).

| Área obrigatória | Cobertura |
|---|---|
| 1–2. Criação com 8 e duas mesas de 4 | criação HTTP + início + unicidade dos oito assentos |
| 3–5. Semifinais, classificados e final automática | resultados HTTP, somente top 2, conjunto exato da final |
| 6. Resultados pendentes | provisório, ausência de confirmação, avanço manual recusado |
| 7. Fases duplicadas | reexecução da progressão, constraints de mesas e assentos |
| 8. Eliminados | não entram na fase seguinte; faixas compartilhadas; progressão de 16 |
| 9. Campeão | final autoritativa, retrospectiva e encerramento |
| 10. Consolação | opcional, jogadores disjuntos, ambas as ordens de conclusão |
| 11. Empates | empates simultâneos preservados, corte/título bloqueados, decisão explícita |
| 12. Segurança e consistência | policies, posições/IDs inválidos, início atômico, bloqueio retroativo, cronômetro |
| 13. Sem ELO/PDL | ausência de ranked matches e rating transactions; sem draws de duelo fictícios |
| 14. Compatibilidade | suite existente completa + views novas e antigas, snapshots, histórico, repetição, conquistas/prêmios |

REDs observados antes das respectivas implementações: sistema inválido na criação; final inexistente após semifinais; rejeição de posições empatadas; retrospectiva escolhendo o segundo assento; configurações perdidas na repetição; draw fictício em estatísticas de perfil; UI sem Semifinais/configurações; prêmio escolhendo um empatado; histórico sem fase; contador confirmando sem opt-in; início aceitando conta sem deck; cronômetro da final não limpo; metadado público anunciando líder arbitrário. Cada slice foi executado novamente até GREEN. Os testes de validação/constraints e a varredura ampliam a cobertura das regras já introduzidas; não se afirma que testes de cobertura adicionais passaram primeiro por RED.

Baseline, sem `.env`: `php artisan test --compact` → 436 testes, 4985 assertions, exit 0 (424 warnings do wrapper Artisan, nenhum teste falhou). A execução direta de PHPUnit evita esses avisos e é usada na verificação final. Logs locais estão em `storage/logs/pod-*.log` (ignorados).

Verificação inicial executada neste worktree (antes dos ajustes da revisão independente):

- SQLite em memória: `php vendor/bin/phpunit` → **454 tests, 13573 assertions, 3 skipped**, exit 0, 43.639s. Os três skips são os testes existentes de importação que exigem PostgreSQL.
- PostgreSQL **17.6**, cluster novo descartável em scratch, porta exclusiva 55487 e banco `manaleague_pod_test`: mesma suite → **OK (454 tests, 13593 assertions)**, exit 0, 81.645s. Não foi usado o cluster/banco de desenvolvimento.
- Novo arquivo focado: **OK (18 tests, 8587 assertions)**.
- `node --test 'tests/js/*.test.cjs'`: **25 passed, 0 failed**.
- Pint em check mode nos quatro arquivos PHP novos: passou. `git diff --check`: passou.

Após a revisão independente, chaves não canônicas nos mapas `placements` e `tiebreak` são recusadas **antes de coerção**, sem reparar IDs. Os regressivos HTTP cobrem IDs com zero à esquerda e sufixo, em resultados provisórios e confirmados de convidados. Criação/edição e UI permitem limite/início com 3 jogadores somente em `pod_elimination`; os demais sistemas mantêm mínimo 4. Snapshot e cards ao vivo identificam `Mesa N · fase`, preservando a allowlist pública. REDs observados: oito respostas HTTP 500 para os mapas inválidos, criação com limite 3 rejeitada, mínimo JS preso em 4 e snapshot/cards sem número de mesa. Verificação SQLite posterior: **OK (29 tests, 8723 assertions)** no arquivo focado; suite completa **465 tests, 13709 assertions, 3 skipped**, exit 0. JavaScript: **27 passed, 0 failed**. Logs: `storage/logs/pod-review-{focused,full,js}.log`. Esses resultados não substituem a repetição do fresh-clone gate pelo integrador.

A suite inclui reexecução/idempotência e constraints. Uma verificação independente e limitada de contenção multiprocesso também foi executada em PostgreSQL: **20 tentativas** (10 com e 10 sem consolação), **343 assertions** e **40 commits de processos PHP independentes**. Em cada tentativa, dois backends PostgreSQL ficaram efetivamente bloqueados no lock do torneio; após a liberação, houve exatamente uma final com os top 2 corretos de cada mesa e, quando configurada, uma consolação, sem assentos duplicados. Reenvios foram recusados e a repetição da progressão foi no-op. Isso é um teste delimitado de contenção, **não um benchmark de carga/stress**; não exigiu correções de concorrência no código da aplicação. Evidência local: `C:/Users/vinic/AppData/Local/hermes/cache/scratch/pod-concurrency/{summary.json,verification.log,evidence-readback.json}`; cluster descartável removido e porta 55489 fechada. Fresh-clone gate, revisão de integração, commit e PR permanecem com o integrador; não houve commit/push nesta implementação.

## Smoke real descartável (sem produção)

`scripts/pod-elimination-smoke.php` cria **apenas** `storage/app/private/pod-elimination-smoke.sqlite`, ignorado pelo Git, e recusa sobrescrever uma fixture existente. Ignora `.env`, recusa config cache e fixa SQLite/arquivo antes de migrar. Cria estados de semifinal, final+consolação e encerrado; nenhuma seed permanente é adicionada. O JSON adjacente contém URLs e uma sessão local de teste para automação autenticada; **não publicar nem versionar o JSON/cookie**.

PowerShell, em **D:\Projetos\manaleague-pod-elimination**:

```powershell
& C:\Users\vinic\tools\php84\php.exe scripts/pod-elimination-smoke.php
$env:APP_ENV = 'testing'
$env:APP_KEY = 'base64:Zwg8Vi58yyja0i2uMfYZRw0klTp/PKUmMTUpo/EppbY='
$env:APP_URL = 'http://127.0.0.1:8768'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = 'D:/Projetos/manaleague-pod-elimination/storage/app/private/pod-elimination-smoke.sqlite'
$env:DB_URL = ''
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'file'
$env:SESSION_SECURE_COOKIE = 'false'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'array'
$env:BROADCAST_CONNECTION = 'null'
$env:RANKED_AUTO_YEARLY_SEASON = 'false'
& C:\Users\vinic\tools\php84\php.exe artisan serve --host=127.0.0.1 --port=8768 --no-reload
```

URLs públicas: `/t/pod-smoke-semifinals`, `/t/pod-smoke-finals`, `/t/pod-smoke-finished`; acrescente `/tv` para TV. Para automação do organizador, leia o JSON local e instale seu cookie no contexto isolado do navegador (host `127.0.0.1`, HttpOnly, SameSite=Lax), então abra `urls.semifinals.organizer`. Não é necessário usar credenciais reais.

Smoke executado em navegador real: duas semifinais confirmadas via formulários → final/consolação criadas sem reload → ambas confirmadas → campeão e classificação final. Formulário Commander-only e bloqueio de Ranked verificados. Views verificadas em 1280px, 390px e 360px (inclusive tema claro); sem overflow horizontal nos checks. TV mostrou todos os assentos por fase. Essa execução usou polling; não afirma validar um servidor Reverb.

Ao terminar, pare o servidor (`Ctrl+C`), remova as variáveis de banco da sessão PowerShell antes de executar testes e rode:

```powershell
& C:\Users\vinic\tools\php84\php.exe scripts/pod-elimination-smoke.php --cleanup
```

O cleanup remove exclusivamente a fixture, seus arquivos auxiliares e a sessão de teste identificada no JSON. Não usar essa fixture em produção. Não houve deploy nem alteração de bancos reais.
