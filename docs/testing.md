# Testes do ManaLeague

Todo comportamento que o jogador percebe tem teste. Feature test (HTTP + banco) é o padrão; unit test só para cálculo puro.

## Rodar

```bash
php artisan test                          # suíte inteira (≈320 testes, ~1 min)
php artisan test --filter RivalTest       # um arquivo ou um método
node --test tests/js/deck-simulator.test.cjs
```

No Windows deste projeto, com o PHP do Herd, use o comando completo do [CLAUDE.md](../CLAUDE.md#comandos).
Banco de teste: SQLite em memória (`phpunit.xml`), `RANKED_AUTO_YEARLY_SEASON=false`.

## Onde e como

- `tests/Feature/<Assunto>Test.php`, um arquivo por funcionalidade (`RivalTest`, `ChallengesPageTest`, `AllMatchStatsTest`).
  `tests/Unit/` para cálculo isolado (`EloCalculatorTest`).
- Sempre `use RefreshDatabase;`. Docblock de uma linha na classe dizendo o que ela garante, em português.
- Nome do método em inglês descrevendo a regra: `test_tie_goes_to_the_most_recent_and_ranked_challenges_count_but_ranked_tournaments_do_not`.
- Comentários em português marcando cada etapa do cenário ("// Desafios: dois contra a Bia...").
- Helpers privados no fim do arquivo. Os mais usados (copie, não invente outro jeito):

```php
private function user(string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->ensurePlayerProfile()->update(['nickname' => $name]);

    return $user->fresh();
}

private function befriend(User $a, User $b): void
{
    $a->friendProfiles()->attach($b->playerProfile, ['status' => 'accepted']);
    $b->friendProfiles()->attach($a->playerProfile, ['status' => 'accepted']);
}

/** Desafio casual confirmado: $from reporta, $to confirma. */
private function casual(User $from, User $to, int $one, int $two): void
{
    $service = app(ChallengeService::class);
    $challenge = $service->create($from, $to->playerProfile, 'Pauper', 3, now()->addDay());
    $service->transition($challenge, $to, 'accept');
    $match = $service->report($challenge, $from, $one, $two);
    $service->review($challenge, $to, 'confirm', $match->revision);
}
```

Temporada ranqueada para teste:

```php
$season = RankedSeason::create(['name' => 'T1', 'slug' => 't1-'.Str::random(4), 'status' => 'active', 'scope' => 'global',
    'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'rules_version' => 'elo-v2']);
$season->formats()->create(['format' => 'Pauper', 'k_factor' => 32, 'starting_rating' => 600, 'allow_draws' => true]);
```

## O que testar

| Mudança | Testes mínimos |
|---|---|
| Regra de negócio | o caminho feliz, a borda (empate, zero, limite) e o que **não** conta (ex.: torneio não vira rival) |
| Dado de outra pessoa na tela | dono vê, amigo vê, estranho **não** vê (`assertDontSee`) |
| Tela nova ou reorganizada | a ordem (`assertSeeInOrder`), os textos principais, o link da ação (`route(...)`) e o estado vazio |
| Formulário | envio válido grava (`assertDatabaseHas`), inválido devolve erro (`assertSessionHasErrors('campo')`) |
| URL que mudou | o redirect antigo (`assertRedirect(route(...))`) |
| Remoção de funcionalidade | um teste garantindo que ela não aparece mais (`assertDontSee('Confronto direto')`) |
| Correção de bug | um teste que falhava antes da correção |

## Asserções

- HTML: `assertSee('<b class="is-wins">2</b>', false)` (o `false` desliga o escape). Prefira trechos estáveis
  (texto + classe principal) a blocos grandes de markup.
- Ordem: `assertSeeInOrder([...], false)`; serve para "o que precisa de você vem primeiro".
- Service: teste o array retornado (`assertSame([2, 1, 1, 0], [$r['played'], $r['wins'], ...])`) além da tela.
- Tempo: `$this->travel(1)->hours()` / `travelTo()` para ordenar eventos; nunca `sleep`.
- Ao mudar markup, rode os testes da tela antes da suíte (`--filter`): eles quebram por classe/texto e mostram o que ajustar.

## Antes de entregar

1. Teste novo cobre o que o jogador vai perceber?
2. `--filter` dos arquivos tocados passa; depois a suíte inteira.
3. Teste que você ajustou continua testando a regra (não só "passou a ver outro texto").
