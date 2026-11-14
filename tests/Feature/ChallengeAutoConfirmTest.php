<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\CommunityActivity;
use App\Models\CommunityNotification;
use App\Models\RankedMatch;
use App\Models\RankedResultSubmission;
use App\Models\RankedSeason;
use App\Models\RatingTransaction;
use App\Models\User;
use App\Services\ChallengeService;
use App\Services\Ranked\RankedMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Placar de desafio sem resposta do adversário por 7 dias é confirmado sozinho (casual e ranqueado). */
class ChallengeAutoConfirmTest extends TestCase
{
    use RefreshDatabase;

    public function test_casual_score_left_unanswered_for_seven_days_is_confirmed_when_a_page_opens(): void
    {
        [$vini, $felipe] = $this->friends();
        $challenge = $this->acceptedCasual($vini, $felipe);
        app(ChallengeService::class)->report($challenge, $vini, 2, 1);

        $this->travel(7)->days();
        $this->travel(-1)->minutes();
        $this->actingAs($felipe)->get('/challenges')->assertOk();
        $this->assertSame('reported', $challenge->casualMatch()->sole()->status, 'ainda dentro do prazo');

        $this->travel(2)->minutes();
        $this->actingAs($vini)->get('/challenges')->assertOk();

        $match = $challenge->casualMatch()->sole();
        $this->assertSame(['confirmed', true], [$match->status, $match->auto_confirmed]);
        $this->assertNotNull($match->confirmed_at);
        $this->assertSame('completed', $challenge->fresh()->status);
        foreach ([$vini, $felipe] as $user) {
            $this->assertTrue(CommunityNotification::where('user_id', $user->id)->where('title', 'Placar do desafio confirmado automaticamente')->exists());
        }
        $this->assertTrue(CommunityActivity::where('event_key', "challenge:{$challenge->id}:completed")->where('actor_id', $vini->id)->exists());
        // O aviso fica no resumo da partida (o endereço antigo do desafio leva até ele).
        $this->actingAs($felipe)->get("/challenges/{$challenge->id}")->assertRedirect(route('matches.detail', ['challenge', $challenge->id]));
        $this->get(route('matches.detail', ['challenge', $challenge->id]))
            ->assertSee('Confirmado automaticamente em')
            ->assertSee('não confirmou nem contestou em 7 dias');

        // Rodar de novo não duplica nada.
        $this->assertSame(['casual' => 0, 'ranked' => 0], app(ChallengeService::class)->autoConfirmOverdue());
    }

    public function test_both_players_see_the_deadline_while_the_score_waits(): void
    {
        [$vini, $felipe] = $this->friends();
        $challenge = $this->acceptedCasual($vini, $felipe);
        app(ChallengeService::class)->report($challenge, $vini, 2, 1);
        $deadline = now()->addDays(Challenge::CONFIRM_DAYS)->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i');

        // No cartão do desafio em andamento (Desafios e Minhas partidas).
        $this->actingAs($felipe)->get('/challenges')
            ->assertSee("Você tem até {$deadline} para confirmar ou contestar. Depois disso, o placar é confirmado automaticamente.");
        $this->actingAs($vini)->get('/me/matches')
            ->assertSee("Se não houver resposta até {$deadline}, o placar é confirmado automaticamente.");
        $this->assertTrue(CommunityNotification::where('user_id', $felipe->id)->where('title', 'Confirme o placar do desafio em até 7 dias')->exists());
    }

    public function test_disputed_scores_wait_and_a_new_report_restarts_the_clock(): void
    {
        [$vini, $felipe] = $this->friends();
        $service = app(ChallengeService::class);
        $challenge = $this->acceptedCasual($vini, $felipe);
        $service->report($challenge, $vini, 2, 1);

        $this->travel(1)->days();
        $service->review($challenge, $felipe, 'dispute', 1, 'Foi 2 a 0');
        $this->travel(10)->days();
        $this->artisan('challenges:auto-confirm')->assertExitCode(0);
        $this->assertSame('disputed', $challenge->casualMatch()->sole()->status, 'contestado nunca confirma sozinho');

        $service->report($challenge, $vini, 2, 0);
        $this->travel(6)->days();
        $this->artisan('challenges:auto-confirm');
        $this->assertSame('reported', $challenge->casualMatch()->sole()->status, 'o prazo conta do novo envio');

        $this->travel(1)->days();
        $this->artisan('challenges:auto-confirm')->expectsOutputToContain('1 casual(is), 0 ranqueado(s)')->assertExitCode(0);
        $match = $challenge->casualMatch()->sole();
        $this->assertSame(['confirmed', 2, 0], [$match->status, $match->challenger_games, $match->opponent_games]);
    }

    public function test_ranked_challenge_is_confirmed_once_with_elo_after_seven_days(): void
    {
        $season = $this->season();
        [$vini, $felipe] = $this->friends();
        $this->actingAs($vini)->post('/challenges', ['opponent_id' => $felipe->playerProfile->id, 'format' => 'Pauper', 'best_of' => 3, 'expires_in' => 24, 'ranked' => 1])->assertRedirect();
        $challenge = Challenge::sole();
        $this->actingAs($felipe)->post("/challenges/{$challenge->id}/transition", ['action' => 'accept'])->assertRedirect();
        $match = RankedMatch::sole();
        app(RankedMatchService::class)->submit($match, $vini, 2, 1, (string) Str::uuid());

        $this->actingAs($felipe)->get("/ranked/matches/{$match->id}")->assertSee('Você tem até')->assertSee('o placar é confirmado automaticamente e o ranking é atualizado');
        $this->travel(6)->days();
        $this->artisan('challenges:auto-confirm')->expectsOutputToContain('0 casual(is), 0 ranqueado(s)');
        $this->assertSame('reported', $match->fresh()->status);

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->artisan('challenges:auto-confirm')->expectsOutputToContain('0 casual(is), 1 ranqueado(s)');
        $this->artisan('challenges:auto-confirm')->expectsOutputToContain('0 casual(is), 0 ranqueado(s)');

        $match->refresh();
        $this->assertSame(['confirmed', true], [$match->status, $match->auto_confirmed]);
        $this->assertSame(2, RatingTransaction::where('ranked_match_id', $match->id)->count(), 'Elo aplicado uma vez só');
        $this->assertNull(RankedResultSubmission::where('ranked_match_id', $match->id)->sole()->reviewed_by);
        $this->assertSame('completed', $challenge->fresh()->status);
        $this->assertSame($season->id, $match->season_id);
        $this->assertTrue(CommunityNotification::where('user_id', $felipe->id)->where('title', 'like', 'Resultado confirmado automaticamente:%')->exists());
        $this->actingAs($felipe)->get("/ranked/matches/{$match->id}")->assertOk()
            ->assertSee('Confirmado automaticamente em')
            ->assertSee('confirmado automaticamente');
    }

    public function test_ranked_tournament_matches_and_finished_seasons_are_left_alone(): void
    {
        $season = $this->season();
        [$vini, $felipe] = $this->friends();
        $service = app(RankedMatchService::class);
        // Ranked de torneio nasce confirmado pelo torneio; aqui fica "aguardando" só para provar que a regra não o toca.
        $tournament = RankedMatch::create(['season_id' => $season->id, 'format' => 'Pauper', 'player_one_profile_id' => $vini->playerProfile->id,
            'player_two_profile_id' => $felipe->playerProfile->id, 'source_type' => 'tournament_match', 'source_id' => 1, 'status' => 'reported',
            'best_of' => 3, 'is_ranked' => true, 'k_factor' => 32, 'rules_version' => 'elo-v1', 'score_one' => 2, 'score_two' => 0]);
        RankedResultSubmission::create(['ranked_match_id' => $tournament->id, 'submitted_by' => $vini->id, 'score_one' => 2, 'score_two' => 0,
            'status' => 'pending', 'idempotency_key' => (string) Str::uuid()]);
        $challenge = $service->create($season, 'Pauper', $vini->playerProfile, $felipe->playerProfile, 'challenge', 99);
        $service->submit($challenge, $vini, 2, 0, (string) Str::uuid());
        $season->update(['status' => 'finished']);

        $this->travel(30)->days();
        $this->artisan('challenges:auto-confirm')->expectsOutputToContain('0 casual(is), 0 ranqueado(s)');

        $this->assertSame(['reported', 'reported'], [$tournament->fresh()->status, $challenge->fresh()->status]);
        $this->assertSame(0, RatingTransaction::count());
    }

    /** @return array{0: User, 1: User} */
    private function friends(): array
    {
        $vini = User::factory()->create(['name' => 'Vini']);
        $felipe = User::factory()->create(['name' => 'Felipe']);
        $vini->friendProfiles()->attach($felipe->ensurePlayerProfile(), ['status' => 'accepted']);
        $felipe->friendProfiles()->attach($vini->ensurePlayerProfile(), ['status' => 'accepted']);

        return [$vini->fresh(), $felipe->fresh()];
    }

    private function acceptedCasual(User $challenger, User $opponent): Challenge
    {
        $service = app(ChallengeService::class);
        $challenge = $service->create($challenger, $opponent->playerProfile, 'Pauper', 3, now()->addDay());
        $service->transition($challenge, $opponent, 'accept');

        return $challenge->fresh();
    }

    private function season(): RankedSeason
    {
        $season = RankedSeason::create(['name' => 'Temporada 1', 'slug' => 'temporada-1-'.Str::random(5), 'status' => 'active', 'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonths(3), 'scope' => 'global', 'rules_version' => 'elo-v1']);
        $season->formats()->create(['format' => 'Pauper', 'k_factor' => 32, 'starting_rating' => 1000, 'allow_draws' => true]);

        return $season->fresh('formats');
    }
}
