<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TournamentInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_is_required_to_open_invite(): void
    {
        $tournament = $this->tournament();
        $this->get(route('tournaments.invite.show', $tournament->invite_token))->assertRedirect(route('login'));
        $this->post(route('tournaments.invite.store', $tournament->invite_token))->assertRedirect(route('login'));
    }

    public function test_authenticated_player_can_join_with_personal_profile(): void
    {
        $tournament = $this->tournament();
        $player = User::factory()->create(['name' => 'Convidado']);
        $this->actingAs($player)->get(route('tournaments.invite.show', $tournament->invite_token))
            ->assertOk()->assertSee($tournament->name)->assertSee('Convidado');
        $this->actingAs($player)->post(route('tournaments.invite.store', $tournament->invite_token), [
            'deck_name' => 'Mono Red', 'deck_colors' => 'R',
        ])->assertRedirect(route('arena.public', $tournament->public_slug));
        $profile = $player->playerProfile()->sole();
        $this->assertDatabaseHas('players', [
            'tournament_id' => $tournament->id, 'name' => 'Convidado', 'player_profile_id' => $profile->id,
        ]);
    }

    public function test_invalid_invite_token_is_rejected_for_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('tournaments.invite.show', Str::random(40)))->assertNotFound();
    }

    public function test_invite_cannot_add_players_after_tournament_starts(): void
    {
        $tournament = $this->tournament();
        $tournament->update(['status' => Tournament::STATUS_LEAGUE]);
        $this->actingAs(User::factory()->create())
            ->post(route('tournaments.invite.store', $tournament->invite_token))->assertStatus(409);
        $this->assertDatabaseCount('players', 0);
    }

    private function tournament(): Tournament
    {
        return User::factory()->create()->tournaments()->create([
            'name' => 'Liga de sexta', 'format' => 'Pauper', 'tournament_date' => '2026-10-10',
            'public_token' => Str::random(40), 'public_slug' => 'liga-de-sexta-abc12345', 'invite_token' => Str::random(40),
        ]);
    }
}
