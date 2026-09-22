<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\PlayerProfile;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PlayerStatsService;
use App\Services\TournamentRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlayerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_creates_persistent_profile_and_organizer_can_reuse_friend(): void
    {
        $user = User::factory()->create();
        $first = $this->tournament($user, 'Primeiro');

        $this->post(route('tournaments.invite.store', $first->invite_token), [
            'name' => 'Vinicius', 'avatar' => '🧙', 'deck_name' => 'Rakdos Vampires', 'deck_colors' => 'BR',
        ])->assertRedirect();

        $profile = PlayerProfile::query()->sole();
        $this->assertSame($user->id, $profile->user_id);
        $this->assertDatabaseHas('players', ['tournament_id' => $first->id, 'player_profile_id' => $profile->id]);

        $this->actingAs($user)->get(route('profiles.index'))->assertOk()->assertSee('Vinicius');
        $this->actingAs($user)->get(route('profiles.show', $profile))->assertOk()->assertSee('Rakdos Vampires');
        $this->actingAs($user)->patch(route('profiles.friend', $profile))->assertRedirect();

        $second = $this->tournament($user, 'Segundo');
        $this->actingAs($user)->post(route('tournaments.friends.store', [$second, $profile]))->assertRedirect();
        $this->assertDatabaseHas('players', [
            'tournament_id' => $second->id, 'player_profile_id' => $profile->id,
            'name' => 'Vinicius', 'deck_name' => 'Rakdos Vampires',
        ]);
    }

    public function test_profile_is_private_to_organizer(): void
    {
        $owner = User::factory()->create();
        $profile = $owner->playerProfiles()->create(['nickname' => 'Liliana']);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('profiles.show', $profile))->assertForbidden();
        $this->actingAs($intruder)->patch(route('profiles.friend', $profile))->assertForbidden();
    }

    public function test_stats_unlock_requested_achievements(): void
    {
        [$user, $tournament, $profile, $champion, $opponent] = $this->finalists();

        foreach ([1, 2, 3] as $round) {
            $tournament->matches()->create([
                'round' => $round, 'stage' => $round === 3 ? GameMatch::STAGE_FINAL : GameMatch::STAGE_LEAGUE,
                'player_one_id' => $champion->id, 'player_two_id' => $opponent->id,
                'player_one_games' => 2, 'player_two_games' => 0,
                'status' => GameMatch::STATUS_FINISHED, 'played_at' => now()->addMinutes($round),
            ]);
        }

        $stats = app(PlayerStatsService::class)->for($profile);
        $this->assertSame(3, $stats['wins']);
        $this->assertSame(100.0, (float) $stats['winrate']);
        $this->assertEqualsCanonicalizing(
            ['Clean Sweep', 'On Fire', 'Champion', 'Undefeated'],
            collect($stats['achievements'])->pluck('name')->all(),
        );
    }

    public function test_finished_tournament_generates_public_retrospective(): void
    {
        [$user, $tournament, , $champion, $opponent] = $this->finalists();
        $tournament->matches()->create([
            'round' => 4, 'stage' => GameMatch::STAGE_FINAL,
            'player_one_id' => $champion->id, 'player_two_id' => $opponent->id,
            'player_one_games' => 2, 'player_two_games' => 1,
            'status' => GameMatch::STATUS_FINISHED, 'played_at' => now(),
        ]);
        $tournament->update(['status' => Tournament::STATUS_FINISHED]);
        $tournament->load(['matches.playerOne', 'matches.playerTwo']);

        $recap = app(TournamentRecapService::class)->for($tournament);
        $this->assertSame('Champion', $recap['champion']->name);
        $this->assertSame(1, $recap['wins']);
        $this->assertSame(2, $recap['gamesFor']);
        $this->get(route('arena.public', $tournament->public_slug))
            ->assertOk()->assertSee('Champion é o campeão')->assertSee('Rakdos Vampires');
        $this->actingAs($user)->get(route('tournaments.show', $tournament))
            ->assertOk()->assertSee('Retrospectiva do campeonato');
    }

    private function finalists(): array
    {
        $user = User::factory()->create();
        $tournament = $this->tournament($user, 'Final');
        $profile = $user->playerProfiles()->create(['nickname' => 'Champion']);
        $champion = $tournament->players()->create([
            'name' => 'Champion', 'player_profile_id' => $profile->id,
            'deck_name' => 'Rakdos Vampires', 'deck_colors' => 'BR',
        ]);
        $opponent = $tournament->players()->create(['name' => 'Opponent']);

        return [$user, $tournament, $profile, $champion, $opponent];
    }

    private function tournament(User $user, string $name): Tournament
    {
        return $user->tournaments()->create([
            'name' => $name, 'format' => 'Pauper', 'tournament_date' => '2026-10-10',
            'public_token' => Str::random(40), 'public_slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'invite_token' => Str::random(40),
        ]);
    }
}
