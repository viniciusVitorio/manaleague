<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TournamentSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_organizer_dashboard(): void
    {
        $this->get('/tournaments')->assertRedirect('/login');
    }

    public function test_user_cannot_view_or_modify_another_organizers_tournament(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $tournament = $owner->tournaments()->create([
            'name' => 'Liga privada',
            'format' => 'Pauper',
            'public_token' => Str::random(40),
            'public_slug' => Str::lower(Str::random(12)),
            'invite_token' => Str::random(40),
        ]);

        $this->actingAs($intruder)->get(route('tournaments.show', $tournament))->assertForbidden();
        $this->actingAs($intruder)->post(route('tournaments.start', $tournament))->assertForbidden();
        $this->actingAs($intruder)->delete(route('tournaments.destroy', $tournament))->assertForbidden();
        $this->assertDatabaseHas('tournaments', ['id' => $tournament->id]);
    }

    public function test_public_arena_uses_an_unguessable_token_and_is_read_only(): void
    {
        $owner = User::factory()->create();
        $tournament = $owner->tournaments()->create([
            'name' => 'Eagle TKS 2026',
            'format' => 'Commander',
            'public_token' => Str::random(40),
            'public_slug' => Str::lower(Str::random(12)),
            'invite_token' => Str::random(40),
        ]);

        $this->get(route('arena.public', $tournament->public_slug))
            ->assertOk()
            ->assertSee('Eagle TKS 2026');
        $this->get(route('arena.public', Str::random(40)))->assertNotFound();
    }

    public function test_match_from_another_tournament_cannot_be_injected_into_route(): void
    {
        $owner = User::factory()->create();
        $first = $this->createTournament($owner, 'Primeira arena');
        $second = $this->createTournament($owner, 'Segunda arena');
        $one = $second->players()->create(['name' => 'Jogador 1']);
        $two = $second->players()->create(['name' => 'Jogador 2']);
        $match = $second->matches()->create([
            'round' => 1,
            'stage' => GameMatch::STAGE_LEAGUE,
            'player_one_id' => $one->id,
            'player_two_id' => $two->id,
        ]);

        $this->actingAs($owner)->put(route('matches.update', [$first, $match]), [
            'player_one_games' => 2,
            'player_two_games' => 0,
        ])->assertNotFound();
    }

    private function createTournament(User $user, string $name): Tournament
    {
        return $user->tournaments()->create([
            'name' => $name,
            'format' => 'Pauper',
            'public_token' => Str::random(40),
            'public_slug' => Str::lower(Str::random(12)),
            'invite_token' => Str::random(40),
        ]);
    }
}
