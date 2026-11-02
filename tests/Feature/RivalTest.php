<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\RankedSeason;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ChallengeService;
use App\Services\Ranked\RankedMatchService;
use App\Services\RivalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Rival: o amigo mais enfrentado em desafios (casuais e ranqueados). Torneio não conta. */
class RivalTest extends TestCase
{
    use RefreshDatabase;

    public function test_rival_is_the_friend_you_challenged_most_and_tournaments_do_not_count(): void
    {
        $ana = $this->user('Ana');
        $bia = $this->user('Bia');
        $caio = $this->user('Caio');
        $this->befriend($ana, $bia);
        $this->befriend($ana, $caio);

        // Três partidas de torneio contra o Caio: não fazem dele um rival.
        $tournament = $ana->tournaments()->create(['name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => now()->toDateString(), 'status' => Tournament::STATUS_LEAGUE,
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(12)), 'invite_token' => Str::random(40)]);
        $a = $tournament->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id]);
        $c = $tournament->players()->create(['name' => 'Caio', 'player_profile_id' => $caio->playerProfile->id]);
        foreach (range(1, 3) as $round) {
            $tournament->matches()->create(['round' => $round, 'stage' => GameMatch::STAGE_LEAGUE, 'player_one_id' => $a->id, 'player_two_id' => $c->id,
                'player_one_games' => 2, 'player_two_games' => 0, 'status' => GameMatch::STATUS_FINISHED, 'played_at' => now()]);
        }
        $this->assertNull(app(RivalService::class)->for($ana->playerProfile), 'Só torneio: sem rival');
        $this->actingAs($ana)->get(route('profiles.index'))->assertOk()->assertDontSee('id="rival-title"', false);

        // Desafios: dois contra a Bia (1 vitória, 1 derrota) e um contra o Caio.
        $this->casual($ana, $bia, 2, 0);
        $this->casual($bia, $ana, 2, 1);
        $this->casual($ana, $caio, 2, 0);

        $rival = app(RivalService::class)->for($ana->playerProfile);
        $this->assertSame('Bia', $rival['profile']->nickname);
        $this->assertSame([2, 1, 1, 0], [$rival['played'], $rival['wins'], $rival['losses'], $rival['draws']]);

        $this->actingAs($ana)->get(route('profiles.index'))->assertOk()
            ->assertSee('id="rival-title"', false)->assertSee('Seu placar nos desafios')->assertSee('2 desafios entre vocês')
            ->assertSee('Rivalidade empatada')->assertSee('Desafiar Bia')->assertSee('opponent='.$bia->playerProfile->id, false);
    }

    public function test_tie_goes_to_the_most_recent_and_ranked_challenges_count_but_ranked_tournaments_do_not(): void
    {
        $ana = $this->user('Ana');
        $bia = $this->user('Bia');
        $caio = $this->user('Caio');
        $this->befriend($ana, $bia);
        $this->befriend($ana, $caio);

        $this->casual($ana, $bia, 2, 0);
        $this->travel(1)->hours();
        $season = RankedSeason::create(['name' => 'Temporada', 'slug' => 'temporada-'.Str::random(5), 'status' => 'active', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
            'scope' => 'global', 'rules_version' => 'elo-v2']);
        $season->formats()->create(['format' => 'Pauper', 'k_factor' => 32, 'starting_rating' => 600, 'allow_draws' => true]);
        $service = app(RankedMatchService::class);

        // Ranked vindo de torneio ranqueado contra a Bia: não conta para rival.
        \App\Models\RankedMatch::create(['season_id' => $season->id, 'format' => 'Pauper', 'player_one_profile_id' => $ana->playerProfile->id, 'player_two_profile_id' => $bia->playerProfile->id,
            'source_type' => 'tournament_match', 'source_id' => 1, 'status' => 'confirmed', 'best_of' => 3, 'is_ranked' => true, 'k_factor' => 32, 'rules_version' => 'elo-v2',
            'score_one' => 2, 'score_two' => 0, 'confirmed_at' => now()]);
        // Desafio ranqueado contra o Caio: conta, e empata em 1 desafio com a Bia, mas é mais recente.
        $this->travel(1)->hours();
        $ranked = $service->create($season->fresh('formats'), 'Pauper', $ana->playerProfile, $caio->playerProfile, 'challenge', 2, 3);
        $service->submit($ranked, $caio, 1, 1, (string) Str::uuid());
        $service->confirm($ranked, $ana);

        $rival = app(RivalService::class)->for($ana->playerProfile);
        $this->assertSame('Caio', $rival['profile']->nickname);
        $this->assertSame([1, 0, 0, 1], [$rival['played'], $rival['wins'], $rival['losses'], $rival['draws']]);
    }

    public function test_only_the_owner_and_friends_see_the_rival(): void
    {
        $ana = $this->user('Ana');
        $bia = $this->user('Bia');
        $stranger = $this->user('Zé');
        $this->befriend($ana, $bia);
        $this->casual($ana, $bia, 2, 1);
        $bia->playerProfile->forceFill(['avatar_path' => 'avatars/'.Str::uuid().'.webp'])->save();

        // O rival aparece com a foto, quando tiver uma; quem lidera fica em destaque.
        $this->actingAs($ana)->get(route('profiles.index'))->assertOk()
            ->assertSee('<span class="has-photo profile-avatar rival-avatar"><img src="', false)->assertSee('Você lidera');
        $this->actingAs($bia)->get(route('profiles.show', $ana->playerProfile))->assertOk()
            ->assertSee('id="rival-title"', false)->assertSee('Placar de Ana nos desafios')->assertSee('1 desafio entre Ana e Bia')
            ->assertSee('Ana lidera')->assertDontSee('Desafiar Bia');
        $this->actingAs($stranger)->get(route('profiles.show', $ana->playerProfile))->assertDontSee('id="rival-title"', false);
    }

    private function casual(User $from, User $to, int $one, int $two): void
    {
        $service = app(ChallengeService::class);
        $challenge = $service->create($from, $to->playerProfile, 'Pauper', 3, now()->addDay());
        $service->transition($challenge, $to, 'accept');
        $match = $service->report($challenge, $from, $one, $two);
        $service->review($challenge, $to, 'confirm', $match->revision);
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
