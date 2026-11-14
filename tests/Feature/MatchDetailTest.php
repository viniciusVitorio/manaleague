<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\RankedMatch;
use App\Models\RankedSeason;
use App\Models\RatingTransaction;
use App\Models\User;
use App\Services\ChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Resumo da partida em Minhas partidas: clicar na linha do histórico mostra jogadores, decks, placar, vencedor, ELO e o gráfico. */
class MatchDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_tournament_summary_shows_decks_score_winner_and_the_chart_when_there_is_one(): void
    {
        [$t, $owner, $ana, $bia, $match] = $this->cup();
        $deck = $ana->playerProfile->decks()->create(['name' => 'Elfos', 'colors' => 'G', 'format' => 'Pauper']);
        $t->players()->where('player_profile_id', $ana->playerProfile->id)->update(['deck_id' => $deck->id]);
        $match->update(['status' => GameMatch::STATUS_FINISHED, 'player_one_games' => 2, 'player_two_games' => 1]);
        $url = route('matches.detail', ['tournament', $match->id]);

        // Partida antiga (sem o contador): o resumo aparece, só sem gráfico. Bia fica à esquerda (o ponto de vista é dela)
        // e vê o nome do deck da Ana, sem link (deck privado).
        $this->actingAs($bia)->get($url)->assertOk()
            ->assertSeeInOrder(['Torneio', 'Copa', 'Rodada 1', 'Bia', 'Você', 'Deck não informado', '1', '2', 'Ana', 'Elfos', 'Vencedor', 'Ana venceu por 2–1'])
            ->assertSee('Sem gráfico de vida')->assertDontSee('class="mdetail-chart"', false)
            ->assertDontSee('href="'.route('decks.show', $deck).'"', false);

        // Com o histórico do contador, o gráfico entra embaixo, sempre aberto (dá para recolher). Na janela vem só o pedaço.
        $this->actingAs($ana)->postJson(route('life.log.store', ['tournament', $match->id]), ['games' => [
            ['winner' => 0, 'points' => [[40, 1, 17, 0], [240, 1, 0, 0]], 'out' => []],
        ]])->assertCreated();
        $fragment = $this->get($url, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('<details class="mdetail-chart" open>', false)->assertSee('Game 1')->assertDontSee('Sem gráfico de vida')
            ->assertSee('href="'.route('decks.show', $deck).'"', false)->getContent();
        $this->assertStringStartsWith('<div class="mdetail">', ltrim(preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', $fragment)));
        $this->assertStringNotContainsString('<html', $fragment);

        $this->actingAs($owner)->get($url)->assertOk()->assertSee('Ver torneio');
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($ana)->get(route('matches.detail', ['tournament', 999999]))->assertNotFound();
    }

    public function test_history_rows_open_the_summary_and_the_chart_button_is_gone(): void
    {
        [$t, $owner, $ana, $bia, $match] = $this->cup();
        $match->update(['status' => GameMatch::STATUS_FINISHED, 'player_one_games' => 2, 'player_two_games' => 0]);
        $next = $t->matches()->create(['round' => 2, 'stage' => 'league', 'status' => 'pending', 'player_one_id' => $match->player_one_id, 'player_two_id' => $match->player_two_id]);

        $page = $this->actingAs($ana)->get(route('matches.mine'))->assertOk()
            ->assertSee('href="'.route('matches.detail', ['tournament', $match->id]).'" data-dialog-open="match-dialog" data-dialog-src', false)
            ->assertSee('id="match-dialog"', false)->assertDontSee('lchart-link', false);
        // Partida que ainda vai ser jogada não abre resumo.
        $page->assertDontSee(route('matches.detail', ['tournament', $next->id]));
    }

    public function test_challenge_and_ranked_rows_open_the_same_summary(): void
    {
        $ana = $this->user('Ana');
        $bia = $this->user('Bia');
        $ana->friendProfiles()->attach($bia->playerProfile, ['status' => 'accepted']);
        $bia->friendProfiles()->attach($ana->playerProfile, ['status' => 'accepted']);
        $service = app(ChallengeService::class);

        // Desafio casual confirmado: Bia venceu 2–1. Sem deck (desafio não guarda deck) e sem link: não há página do desafio.
        $challenge = $service->create($ana, $bia->playerProfile, 'Pauper', 3, now()->addDay());
        $service->transition($challenge, $bia, 'accept');
        $casual = $service->report($challenge, $ana, 1, 2);
        $service->review($challenge, $bia, 'confirm', $casual->revision);
        $url = route('matches.detail', ['challenge', $challenge->id]);

        $this->actingAs($ana)->get(route('matches.mine'))->assertOk()->assertSee('href="'.$url.'" data-dialog-open="match-dialog"', false);
        $this->get($url)->assertOk()->assertSeeInOrder(['Desafio casual', 'Pauper', 'MD3', 'Ana', 'Você', '1', '2', 'Bia', 'Vencedor', 'Bia venceu por 2–1'])
            ->assertDontSee('Deck não informado')->assertSee('Sem gráfico de vida')->assertDontSee('Abrir desafio')
            ->assertDontSee('href="'.url('/challenges/'.$challenge->id).'"', false);
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();

        // Ranked do matchmaking confirmado: mesmo resumo, com o ELO de cada um (quanto mudou, de quanto para quanto e a divisão).
        [$season, $ranked] = $this->rankedMatch($ana, $bia, ['source_type' => 'matchmaking', 'source_id' => 1]);
        $this->ledger($ranked, $ana, 615, 631);
        $this->ledger($ranked, $bia, 630, 614);
        $this->actingAs($bia)->get(route('matches.detail', ['ranked', $ranked->id]))->assertOk()
            ->assertSeeInOrder(['Ranked', 'Bia', 'Você', '−16 ELO', '630 → 614', 'Ferro II', 'caiu', '0', '2',
                'Ana', '+16 ELO', '615 → 631', 'Ferro I', 'subiu', 'Vencedor', 'Ana venceu por 2–0'])
            ->assertSee('Abrir partida ranqueada')->assertDontSee('mdetail-elo-note', false);

        // Contestada: sem pontos ainda, só o aviso.
        [, $disputed] = $this->rankedMatch($ana, $bia, ['source_type' => 'matchmaking', 'source_id' => 2, 'status' => 'disputed', 'confirmed_at' => null], $season);
        $this->get(route('matches.detail', ['ranked', $disputed->id]))->assertOk()
            ->assertSee('Pontos de ELO congelados até a moderação resolver.')->assertDontSee('ELO</b>', false);
    }

    public function test_ranked_tournament_summary_shows_the_elo_only_to_who_can_open_the_ranked_match(): void
    {
        [$t, $owner, $ana, $bia, $match] = $this->cup();
        $t->update(['ranked' => true]);
        $match->update(['status' => GameMatch::STATUS_FINISHED, 'player_one_games' => 2, 'player_two_games' => 1]);
        [, $ranked] = $this->rankedMatch($ana, $bia, ['source_type' => 'tournament_match', 'source_id' => $match->id, 'score_one' => 2, 'score_two' => 1]);
        $this->ledger($ranked, $ana, 600, 616);
        $this->ledger($ranked, $bia, 600, 584);
        $url = route('matches.detail', ['tournament', $match->id]);

        $this->actingAs($ana)->get($url)->assertOk()
            ->assertSeeInOrder(['Torneio ranqueado', 'Ana', 'Você', '+16 ELO', '600 → 616', 'Bia', '−16 ELO', '600 → 584']);
        // O organizador vê o resumo, mas o ELO é dos jogadores (como na página da partida ranqueada).
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('Ana venceu por 2–1')->assertDontSee('ELO</b>', false);
    }

    public function test_ranked_summary_uses_the_corrected_value_after_the_score_was_flipped(): void
    {
        $ana = $this->user('Ana');
        $bia = $this->user('Bia');
        // Placar lançado ao contrário (Bia +16) e depois invertido pela moderação: hoje Ana venceu 2–0.
        [, $ranked] = $this->rankedMatch($ana, $bia, ['source_type' => 'matchmaking', 'source_id' => 1]);
        $this->ledger($ranked, $ana, 600, 584);
        $this->ledger($ranked, $bia, 600, 616);
        $this->ledger($ranked, $ana, 584, 616, 'correction');
        $this->ledger($ranked, $bia, 616, 584, 'correction');

        $this->actingAs($ana)->get(route('matches.detail', ['ranked', $ranked->id]))->assertOk()
            ->assertSeeInOrder(['Ana', '+16 ELO', '600 → 616', '2', '0', 'Bia', '−16 ELO', '600 → 584', 'Ana venceu por 2–0']);
    }

    /** Partida ranqueada confirmada entre Ana (um) e Bia (dois), 2–0 por padrão. */
    private function rankedMatch(User $one, User $two, array $data, ?RankedSeason $season = null): array
    {
        $season ??= RankedSeason::create(['name' => 'T1', 'slug' => 't1-'.Str::random(4), 'status' => 'active', 'scope' => 'global',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'rules_version' => 'elo-v2']);
        $match = RankedMatch::create($data + ['season_id' => $season->id, 'format' => 'Pauper', 'player_one_profile_id' => $one->playerProfile->id,
            'player_two_profile_id' => $two->playerProfile->id, 'status' => 'confirmed', 'best_of' => 3,
            'is_ranked' => true, 'k_factor' => 32, 'rules_version' => 'elo-v2', 'score_one' => 2, 'score_two' => 0, 'played_at' => now(), 'confirmed_at' => now()]);

        return [$season, $match];
    }

    private function ledger(RankedMatch $match, User $user, int $before, int $after, string $event = 'match'): void
    {
        RatingTransaction::create(['ranked_match_id' => $match->id, 'season_id' => $match->season_id, 'format' => $match->format,
            'player_profile_id' => $user->playerProfile->id, 'rating_before' => $before, 'rating_after' => $after, 'delta' => $after - $before,
            'k_factor' => 32, 'event_type' => $event, 'correction_seq' => $event === 'match' ? 0 : 1, 'rules_version' => 'elo-v2', 'effective_at' => now()]);
    }

    /** Torneio do organizador com Ana × Bia na rodada 1. */
    private function cup(): array
    {
        $owner = $this->user('Org');
        $ana = $this->user('Ana');
        $bia = $this->user('Bia');
        $t = $owner->tournaments()->create(['name' => 'Copa', 'format' => 'Pauper', 'tournament_date' => '2026-11-06', 'status' => 'league',
            'public_token' => Str::random(40), 'public_slug' => 'copa-'.Str::lower(Str::random(8)), 'invite_token' => Str::random(40)]);
        $pAna = $t->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id]);
        $pBia = $t->players()->create(['name' => 'Bia', 'player_profile_id' => $bia->playerProfile->id]);
        $match = $t->matches()->create(['round' => 1, 'stage' => 'league', 'status' => 'pending', 'player_one_id' => $pAna->id, 'player_two_id' => $pBia->id]);

        return [$t, $owner, $ana, $bia, $match];
    }

    private function user(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->ensurePlayerProfile()->update(['nickname' => $name]);

        return $user->fresh();
    }
}
