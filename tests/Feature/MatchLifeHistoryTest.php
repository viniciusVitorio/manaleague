<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\MatchLifeLog;
use App\Models\RankedMatch;
use App\Models\RankedSeason;
use App\Models\User;
use App\Services\ChallengeService;
use App\Services\Ranked\RankedMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Histórico da partida: o contador grava a vida de cada game depois do placar, e só quem pode ver a partida vê o gráfico. */
class MatchLifeHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** Dois games: Ana vence o 1º (Bia zera), Bia vence o 2º. Lugar 0 = jogador 1 da partida. */
    private function games(): array
    {
        return [
            ['winner' => 0, 'points' => [[40, 1, 17, 0], [95, 0, 18, 0], [180, 1, 9, 0], [240, 1, 0, 0]], 'out' => []],
            ['winner' => 1, 'points' => [[60, 0, 14, 2], [200, 0, -1, 2]], 'out' => []],
        ];
    }

    public function test_history_goes_up_during_the_game_and_the_chart_shows_up_whatever_way_the_score_is_sent(): void
    {
        [$t, $owner, $ana, $bia, $pAna, $pBia, $match] = $this->cup();
        $store = route('life.log.store', ['tournament', $match->id]);
        $chart = route('life.log.show', ['tournament', $match->id]);

        // A página do contador já sabe para onde mandar o histórico.
        $this->actingAs($ana)->get(route('life.tournament', [$t, $match]))->assertOk()->assertSee(e(json_encode($store)), false);

        // Durante o jogo o contador manda o que já tem (e atualiza depois); o gráfico ainda não aparece.
        $this->postJson($store, ['games' => [['winner' => null, 'points' => [[40, 1, 17, 0]]]]])->assertCreated()->assertJson(['status' => 'created']);
        $this->postJson($store, ['games' => $this->games()])->assertOk()->assertJson(['status' => 'updated']);
        $this->get($chart)->assertNotFound();

        $log = MatchLifeLog::of('tournament', $match->id);
        $this->assertSame([$pAna->id, $pBia->id], $log->seats);
        $this->assertSame(20, $log->starting_life);
        $this->assertSame($ana->id, $log->recorded_by);
        $this->assertSame([[40, 1, 17, 0], [95, 0, 18, 0], [180, 1, 9, 0], [240, 1, 0, 0]], $log->games[0]['points']);

        // O segundo aparelho na mesa não sobrescreve.
        $this->actingAs($bia)->postJson($store, ['games' => [['winner' => 1, 'points' => [[5, 0, 1, 0]]]]])->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame([[40, 1, 17, 0], [95, 0, 18, 0], [180, 1, 9, 0], [240, 1, 0, 0]], $log->fresh()->games[0]['points']);

        // O placar saiu pelo formulário de Minhas partidas (não pelo contador): o gráfico aparece igual.
        $this->actingAs($bia)->post(route('matches.submissions.store', [$t, $match]), ['player_one_games' => 2, 'player_two_games' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertDatabaseHas('match_result_submissions', ['match_id' => $match->id]);
        $this->actingAs($ana)->get($chart)->assertOk()->assertSee('vitória de Ana');
    }

    public function test_reset_all_on_the_counter_deletes_the_history_that_was_sent(): void
    {
        [$t, $owner, $ana, $bia, $pAna, $pBia, $match] = $this->cup();
        $store = route('life.log.store', ['tournament', $match->id]);
        $this->actingAs($ana)->postJson($store, ['games' => $this->games()])->assertCreated();

        // Outro jogador mandando vazio não apaga nada; o dono, sim ("Zerar tudo" no contador).
        $this->actingAs($bia)->postJson($store, ['games' => []])->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertDatabaseCount('match_life_logs', 1);
        $this->actingAs($ana)->postJson($store, ['games' => []])->assertOk()->assertJson(['status' => 'deleted']);
        $this->assertDatabaseCount('match_life_logs', 0);
    }

    public function test_server_drops_impossible_points_and_rejects_strangers(): void
    {
        [$t, $owner, $ana, $bia, $pAna, $pBia, $match] = $this->cup();
        $match->update(['status' => GameMatch::STATUS_FINISHED, 'player_one_games' => 2, 'player_two_games' => 0]);

        // Quem não está na partida (nem organiza) não grava.
        $this->actingAs(User::factory()->create())->postJson(route('life.log.store', ['tournament', $match->id]), ['games' => $this->games()])->assertForbidden();

        // Lugar que não existe, vida absurda e tempo voltando: o ponto ruim sai e o tempo nunca anda para trás.
        $this->actingAs($ana)->postJson(route('life.log.store', ['tournament', $match->id]), ['games' => [
            ['winner' => 7, 'points' => [[30, 0, 15, 0], [20, 1, 12, 0], [40, 5, 10, 0], [50, 1, 5000, 0], [60, 1, 3, 200]]],
        ]])->assertCreated();
        $game = MatchLifeLog::of('tournament', $match->id)->games[0];
        $this->assertSame([[30, 0, 15, 0], [30, 1, 12, 0]], $game['points']);
        $this->assertNull($game['winner']);

        // Histórico sem nenhuma mudança de vida não cria nada.
        $other = $t->matches()->create(['round' => 2, 'stage' => 'league', 'status' => 'finished', 'player_one_id' => $pBia->id, 'player_two_id' => $pAna->id, 'player_one_games' => 2, 'player_two_games' => 0]);
        $this->postJson(route('life.log.store', ['tournament', $other->id]), ['games' => [['winner' => 0, 'points' => []]]])->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertNull(MatchLifeLog::of('tournament', $other->id));
    }

    public function test_chart_is_seen_by_the_players_and_the_organizer_but_not_by_strangers(): void
    {
        [$t, $owner, $ana, $bia, $pAna, $pBia, $match] = $this->cup();
        $match->update(['status' => GameMatch::STATUS_FINISHED, 'player_one_games' => 1, 'player_two_games' => 1]);
        $this->actingAs($ana)->postJson(route('life.log.store', ['tournament', $match->id]), ['games' => $this->games()])->assertCreated();
        $url = route('life.log.show', ['tournament', $match->id]);

        // Página do gráfico: um quadro por game, com vencedor, duração e o lance a lance.
        $this->actingAs($bia)->get($url)->assertOk()
            ->assertSeeInOrder(['Ana × Bia', 'Game 1', 'vitória de Ana', '4 min', 'Game 2', 'vitória de Bia', 'Ver lance a lance', '0:40', 'Bia', '17', '−3'])
            ->assertSee('lchart-line s1', false)->assertSee('☠ 2', false);
        // Na janela da lista vem só o gráfico, sem o layout.
        $fragment = $this->actingAs($ana)->get($url, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertStringStartsWith('<div class="lchart">', ltrim(preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', $fragment)));
        $this->assertStringNotContainsString('<html', $fragment);
        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();

        // Onde aparece: o link do gráfico fica na página do organizador e na página pública só para quem jogou.
        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertOk()->assertSee('href="'.$url.'"', false)->assertSee('Gráfico da partida');
        $this->actingAs($bia)->get(route('arena.public', $t->public_slug))->assertOk()->assertSee('href="'.$url.'"', false);
        $this->actingAs(User::factory()->create())->get(route('arena.public', $t->public_slug))->assertOk()->assertDontSee('href="'.$url.'"', false);
    }

    public function test_challenge_and_ranked_pages_show_the_chart_when_it_exists(): void
    {
        $ana = $this->user('Ana');
        $bia = $this->user('Bia');
        $this->befriend($ana, $bia);
        $service = app(ChallengeService::class);

        // Desafio casual: sem contador, sem gráfico; com histórico, o gráfico aparece na página do desafio.
        $challenge = $service->create($ana, $bia->playerProfile, 'Pauper', 3, now()->addDay());
        $service->transition($challenge, $bia, 'accept');
        $service->report($challenge, $ana, 2, 1);
        $this->actingAs($ana)->get(route('challenges.show', $challenge))->assertOk()->assertDontSee('Gráfico da partida');
        $this->postJson(route('life.log.store', ['challenge', $challenge->id]), ['games' => $this->games()])->assertCreated();
        $this->actingAs($bia)->get(route('challenges.show', $challenge))->assertOk()->assertSeeInOrder(['Gráfico da partida', 'Game 1', 'vitória de Ana']);
        $this->actingAs(User::factory()->create())->get(route('life.log.show', ['challenge', $challenge->id]))->assertNotFound();

        // Ranqueada: o gráfico fica na partida ranqueada (e no desafio que a criou).
        $season = RankedSeason::create(['name' => 'T1', 'slug' => 't1-'.Str::random(4), 'status' => 'active', 'scope' => 'global',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'rules_version' => 'elo-v2']);
        $season->formats()->create(['format' => 'Pauper', 'k_factor' => 32, 'starting_rating' => 600, 'allow_draws' => true]);
        $rankedChallenge = $service->create($ana, $bia->playerProfile, 'Pauper', 3, now()->addDay(), true);
        $service->transition($rankedChallenge, $bia, 'accept');
        $ranked = RankedMatch::where(['source_type' => 'challenge', 'source_id' => $rankedChallenge->id])->firstOrFail();
        app(RankedMatchService::class)->submit($ranked, $ana, 2, 0, (string) Str::uuid());
        $this->actingAs($ana)->postJson(route('life.log.store', ['ranked', $ranked->id]), ['games' => $this->games()])->assertCreated();
        $this->get(route('ranked.matches.show', $ranked))->assertOk()->assertSeeInOrder(['Gráfico da partida', 'Game 2', 'vitória de Bia']);
        $this->get(route('challenges.show', $rankedChallenge))->assertOk()->assertSee('Gráfico da partida');
    }

    /** Torneio do organizador com Ana × Bia na rodada 1, ainda sem placar. */
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

        return [$t, $owner, $ana, $bia, $pAna, $pBia, $match];
    }

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
}
