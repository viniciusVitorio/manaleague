<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\RankedSeason;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ChallengeService;
use App\Services\PlayerStatsService;
use App\Services\Ranked\RankedMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Estatísticas do perfil somam torneio, desafio casual e Ranked. */
class AllMatchStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_stats_add_up_tournaments_casual_challenges_and_ranked(): void
    {
        [$ana, $bia] = $this->friends();

        // Torneio: Ana vence por 2–1.
        $this->travelTo(now()->subDays(3));
        $tournament = $this->tournament($ana);
        $one = $tournament->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id]);
        $two = $tournament->players()->create(['name' => 'Bia', 'player_profile_id' => $bia->playerProfile->id]);
        $tournament->matches()->create(['round' => 1, 'stage' => GameMatch::STAGE_LEAGUE, 'player_one_id' => $one->id, 'player_two_id' => $two->id,
            'player_one_games' => 2, 'player_two_games' => 1, 'status' => GameMatch::STATUS_FINISHED, 'played_at' => now()]);
        $this->travelBack();

        // Desafios casuais: 2–0 para Ana, depois 0–2 (confirmados) e um só reportado, que não conta.
        $this->travelTo(now()->subDays(2));
        $this->casual($ana, $bia, 2, 0);
        $this->travelBack();
        $this->travelTo(now()->subDay());
        $this->casual($ana, $bia, 0, 2);
        $this->travelBack();
        $pending = app(ChallengeService::class)->create($ana, $bia->playerProfile, 'Pauper', 3, now()->addDay());
        app(ChallengeService::class)->transition($pending, $bia, 'accept');
        app(ChallengeService::class)->report($pending, $ana, 2, 0);

        // Ranked: Ana vence 2–1.
        $season = $this->season();
        $match = app(RankedMatchService::class)->create($season, 'Pauper', $ana->playerProfile, $bia->playerProfile, 'challenge', 9001, 3);
        app(RankedMatchService::class)->submit($match, $ana, 2, 1, (string) Str::uuid());
        app(RankedMatchService::class)->confirm($match, $bia);

        $stats = app(PlayerStatsService::class)->for($ana->playerProfile->fresh());
        $this->assertSame([3, 1, 0, 4], [$stats['wins'], $stats['losses'], $stats['draws'], $stats['played']]);
        $this->assertSame([6, 4, 75], [$stats['games_for'], $stats['games_against'], $stats['winrate']]);
        // Ordem: torneio V, casual V, casual D, ranked V → a maior sequência é 2.
        $this->assertSame(2, $stats['best_streak']);
        // Forma recente: da mais nova para a mais antiga.
        $this->assertSame(['win', 'loss', 'win', 'win'], array_column($stats['recent'], 'result'));
        $this->assertSame(['ranked', 'casual', 'casual', 'tournament'], array_column($stats['recent'], 'kind'));
        $this->assertSame(1, $stats['by_kind']['tournament']['wins']);
        $this->assertSame([1, 1, 2], [$stats['by_kind']['casual']['wins'], $stats['by_kind']['casual']['losses'], $stats['by_kind']['casual']['played']]);
        $this->assertSame([1, 0], [$stats['by_kind']['ranked']['wins'], $stats['by_kind']['ranked']['losses']]);

        $this->actingAs($ana)->get(route('profiles.index'))->assertOk()
            ->assertSee('id="stat-casual"', false)->assertSee('Desafios')->assertSee('Ranked')
            // Ordem dos cards: partidas jogadas, V–D–E, aproveitamento, maior sequência.
            ->assertSeeInOrder(['data-kind="all"', '<b>4</b><span>Partidas jogadas', '<b>3–1–0</b><span>Vitórias / derrotas / empates', '<b>75%</b><span>Aproveitamento', '<b>2</b><span>Maior sequência de vitórias'], false)
            // Desafio casual não tem empate: só V–D.
            ->assertSeeInOrder(['data-kind="casual"', '<b>2</b><span>Partidas jogadas', '<b>1–1</b><span>Vitórias / derrotas</span>', 'data-kind="ranked"'], false)
            ->assertDontSee('<span>Games</span>', false)
            // Últimos resultados: bolinhas dentro do card de V–D–E, sem área separada.
            ->assertSeeInOrder(['data-kind="all"', 'Vitórias / derrotas / empates</span>', 'class="form-dots"', 'form-dot is-win" title="Vitória 2–1 · Ranked', 'form-dot is-loss', '<b>75%</b>'], false)
            ->assertSee('← mais recente</small>', false)->assertDontSee('Forma recente')->assertDontSee('Sequência atual');
        $this->actingAs($ana)->get(route('dashboard'))->assertOk()->assertSee('3V · 0E · 1D no geral'); // torneios, desafios e ranked somados
    }

    public function test_ranked_coming_from_a_tournament_match_is_not_counted_twice(): void
    {
        [$ana, $bia] = $this->friends();
        $season = $this->season();
        \App\Models\RankedMatch::create(['season_id' => $season->id, 'format' => 'Pauper', 'player_one_profile_id' => $ana->playerProfile->id, 'player_two_profile_id' => $bia->playerProfile->id,
            'source_type' => 'tournament_match', 'source_id' => 1, 'status' => 'confirmed', 'best_of' => 3, 'is_ranked' => true, 'k_factor' => 32, 'rules_version' => 'elo-v2',
            'score_one' => 2, 'score_two' => 0, 'confirmed_at' => now()]);

        $stats = app(PlayerStatsService::class)->for($ana->playerProfile->fresh());
        $this->assertSame(0, $stats['played']);
        $this->actingAs($ana)->get(route('profiles.index'))->assertOk()->assertSee('Nenhum desafio casual confirmado ainda.');
    }

    private function casual(User $a, User $b, int $one, int $two): void
    {
        $service = app(ChallengeService::class);
        $challenge = $service->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay());
        $service->transition($challenge, $b, 'accept');
        $match = $service->report($challenge, $a, $one, $two);
        $service->review($challenge, $b, 'confirm', $match->revision);
    }

    /** @return array{0: User, 1: User} */
    private function friends(): array
    {
        $a = User::factory()->create(['name' => 'Ana']);
        $b = User::factory()->create(['name' => 'Bia']);
        $a->friendProfiles()->attach($b->ensurePlayerProfile(), ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->ensurePlayerProfile(), ['status' => 'accepted']);

        return [$a->fresh(), $b->fresh()];
    }

    private function season(): RankedSeason
    {
        $season = RankedSeason::create(['name' => 'Temporada', 'slug' => 'temporada-'.Str::random(5), 'status' => 'active', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
            'scope' => 'global', 'rules_version' => 'elo-v2']);
        $season->formats()->create(['format' => 'Pauper', 'k_factor' => 32, 'starting_rating' => 600, 'allow_draws' => true]);

        return $season->fresh('formats');
    }

    private function tournament(User $user): Tournament
    {
        return $user->tournaments()->create(['name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => now()->toDateString(), 'status' => Tournament::STATUS_LEAGUE,
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
    }
}
