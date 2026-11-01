<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\MatchResultSubmission;
use App\Models\RankedMatch;
use App\Models\RankedSeason;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ChallengeService;
use App\Services\MatchResultSubmissionService;
use App\Services\Ranked\RankedMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** "Confirmar todos": quem não confirmou nada no dia confirma tudo de uma vez. */
class ConfirmAllResultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_felipe_confirms_every_score_his_opponents_sent_at_once(): void
    {
        $felipe = $this->user('Felipe');
        $ana = $this->user('Ana');
        $bia = $this->user('Bia');
        $this->befriend($felipe, $ana);
        $this->befriend($felipe, $bia);

        // Torneio: dois placares enviados pelos adversários.
        $tournament = $this->tournament($ana);
        $f = $tournament->players()->create(['name' => 'Felipe', 'player_profile_id' => $felipe->playerProfile->id]);
        $a = $tournament->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id]);
        $b = $tournament->players()->create(['name' => 'Bia', 'player_profile_id' => $bia->playerProfile->id]);
        $m1 = $tournament->matches()->create(['round' => 1, 'stage' => 'league', 'player_one_id' => $f->id, 'player_two_id' => $a->id, 'status' => 'pending']);
        $m2 = $tournament->matches()->create(['round' => 1, 'stage' => 'league', 'player_one_id' => $b->id, 'player_two_id' => $f->id, 'status' => 'pending']);
        $this->submit($tournament, $m1, $ana, 0, 2);
        $this->submit($tournament, $m2, $bia, 2, 1);

        // Desafios casuais: dois reportados pelos adversários e um reportado pelo próprio Felipe.
        $casual = app(ChallengeService::class);
        foreach ([$ana, $bia] as $rival) {
            $challenge = $casual->create($rival, $felipe->playerProfile, 'Pauper', 3, now()->addDay());
            $casual->transition($challenge, $felipe, 'accept');
            $casual->report($challenge, $rival, 2, 0);
        }
        $own = $casual->create($felipe, $ana->playerProfile, 'Pauper', 3, now()->addDay());
        $casual->transition($own, $ana, 'accept');
        $casual->report($own, $felipe, 2, 0);

        // Ranked: um placar enviado pela Ana.
        $season = RankedSeason::create(['name' => 'Temporada', 'slug' => 'temporada-'.Str::random(5), 'status' => 'active', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
            'scope' => 'global', 'rules_version' => 'elo-v2']);
        $season->formats()->create(['format' => 'Pauper', 'k_factor' => 32, 'starting_rating' => 600, 'allow_draws' => true]);
        $ranked = app(RankedMatchService::class)->create($season->fresh('formats'), 'Pauper', $ana->playerProfile, $felipe->playerProfile, 'challenge', 501, 3);
        app(RankedMatchService::class)->submit($ranked, $ana, 2, 1, (string) Str::uuid());

        $this->actingAs($felipe)->get(route('dashboard'))->assertOk()
            ->assertSee('5 placares esperando a sua confirmação')->assertSee('Confirmar todos (5)');
        $this->actingAs($felipe)->get(route('challenges.index'))->assertOk()->assertSee('Confirmar todos (5)');
        $this->actingAs($felipe)->get(route('matches.mine'))->assertOk()->assertSee('Confirmar todos (5)');

        $this->actingAs($felipe)->post(route('results.confirm-all'))->assertSessionHas('status', '5 placares confirmados.');

        $this->assertSame(2, MatchResultSubmission::where('status', 'confirmed')->count());
        $this->assertSame(['finished', 'finished'], [$m1->fresh()->status, $m2->fresh()->status]);
        $this->assertSame(2, \App\Models\CasualMatch::where('status', 'confirmed')->count());
        $this->assertSame('reported', $own->casualMatch()->sole()->status, 'O placar que o próprio Felipe enviou continua esperando a Ana');
        $this->assertSame('confirmed', $ranked->fresh()->status);
        $this->assertSame(1, \App\Models\RatingTransaction::where('player_profile_id', $felipe->playerProfile->id)->count());

        // Nada mais para confirmar: o botão some e a Ana ainda vê o placar do Felipe para revisar.
        $this->actingAs($felipe)->get(route('dashboard'))->assertOk()->assertDontSee('Confirmar todos');
        $this->actingAs($felipe)->post(route('results.confirm-all'))->assertSessionHas('status', 'Nenhum placar esperando a sua confirmação.');
    }

    public function test_scores_that_cannot_be_confirmed_are_skipped_without_blocking_the_rest(): void
    {
        $felipe = $this->user('Felipe');
        $ana = $this->user('Ana');
        $this->befriend($felipe, $ana);
        $open = $this->tournament($ana);
        $closed = $this->tournament($ana);
        foreach ([$open, $closed] as $tournament) {
            $f = $tournament->players()->create(['name' => 'Felipe', 'player_profile_id' => $felipe->playerProfile->id]);
            $a = $tournament->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id]);
            $match = $tournament->matches()->create(['round' => 1, 'stage' => 'league', 'player_one_id' => $f->id, 'player_two_id' => $a->id, 'status' => 'pending']);
            $this->submit($tournament, $match, $ana, 1, 2);
        }
        $closed->update(['status' => Tournament::STATUS_SETUP]); // a fase fechou antes da confirmação

        $this->actingAs($felipe)->post(route('results.confirm-all'))
            ->assertSessionHas('status', '1 placar confirmado. 1 placar não pôde ser confirmado porque mudou, foi contestado ou a fase do torneio fechou. Confira um por um.');
        $this->assertSame(['confirmed', 'pending'], MatchResultSubmission::orderBy('id')->pluck('status')->all());
    }

    public function test_a_single_pending_score_keeps_only_the_regular_button(): void
    {
        $felipe = $this->user('Felipe');
        $ana = $this->user('Ana');
        $this->befriend($felipe, $ana);
        $challenge = app(ChallengeService::class)->create($ana, $felipe->playerProfile, 'Pauper', 3, now()->addDay());
        app(ChallengeService::class)->transition($challenge, $felipe, 'accept');
        app(ChallengeService::class)->report($challenge, $ana, 2, 1);

        $this->actingAs($felipe)->get(route('dashboard'))->assertOk()->assertSee('Confirmar placar do desafio')->assertDontSee('Confirmar todos');
    }

    private function submit(Tournament $tournament, GameMatch $match, User $by, int $one, int $two): void
    {
        app(MatchResultSubmissionService::class)->submit($tournament, $match, $by,
            ['player_one_games' => $one, 'player_two_games' => $two, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function user(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->ensurePlayerProfile();

        return $user->fresh();
    }

    private function befriend(User $a, User $b): void
    {
        $a->friendProfiles()->attach($b->playerProfile, ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->playerProfile, ['status' => 'accepted']);
    }

    private function tournament(User $owner): Tournament
    {
        return $owner->tournaments()->create(['name' => 'Liga '.Str::random(4), 'format' => 'Pauper', 'tournament_date' => now()->toDateString(), 'status' => Tournament::STATUS_LEAGUE,
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
    }
}
