<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FriendshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_friendship_is_reciprocal(): void
    {
        [$organizer, $player, $playerProfile] = $this->organizerAndPlayer();

        $this->actingAs($organizer)->patch(route('profiles.friend', $playerProfile))->assertRedirect();
        $this->actingAs($player)->patch(route('friends.accept', $organizer))->assertRedirect();

        $organizerProfile = $organizer->playerProfile()->sole();
        $this->assertDatabaseHas('player_profile_user', ['user_id' => $organizer->id, 'player_profile_id' => $playerProfile->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('player_profile_user', ['user_id' => $player->id, 'player_profile_id' => $organizerProfile->id, 'status' => 'accepted']);

        // O jogador agora consegue abrir o perfil do organizador e convida-lo para o proprio torneio.
        $this->actingAs($player)->get(route('profiles.show', $organizerProfile))->assertOk()->assertSee('Remover amizade');
        $ownTournament = $this->tournament($player, 'Liga do Jogador');
        $this->actingAs($player)->post(route('tournaments.friends.store', [$ownTournament, $organizerProfile]))->assertRedirect();
        $this->assertDatabaseHas('players', ['tournament_id' => $ownTournament->id, 'player_profile_id' => $organizerProfile->id]);
    }

    public function test_removing_friendship_removes_both_directions(): void
    {
        [$organizer, $player, $playerProfile] = $this->organizerAndPlayer();
        $this->actingAs($organizer)->patch(route('profiles.friend', $playerProfile));
        $this->actingAs($player)->patch(route('friends.accept', $organizer));

        $organizerProfile = $organizer->playerProfile()->sole();
        $this->actingAs($player)->patch(route('profiles.friend', $organizerProfile))->assertSessionHas('status', 'Amizade removida.');
        $this->assertDatabaseCount('player_profile_user', 0);
    }

    public function test_rejecting_request_removes_it(): void
    {
        [$organizer, $player, $playerProfile] = $this->organizerAndPlayer();
        $this->actingAs($organizer)->patch(route('profiles.friend', $playerProfile));
        $this->actingAs($player)->delete(route('friends.reject', $organizer))->assertRedirect();
        $this->assertDatabaseCount('player_profile_user', 0);
    }

    public function test_requesting_back_a_pending_request_accepts_it(): void
    {
        [$organizer, $player, $playerProfile] = $this->organizerAndPlayer();
        $this->actingAs($organizer)->patch(route('profiles.friend', $playerProfile));

        $organizerProfile = $organizer->playerProfile()->sole();
        $this->actingAs($player)->get(route('profiles.show', $organizerProfile))->assertOk();
        $this->actingAs($player)->patch(route('profiles.friend', $organizerProfile))->assertSessionHas('status', 'Vocês agora são amigos.');

        $this->assertDatabaseHas('player_profile_user', ['user_id' => $organizer->id, 'player_profile_id' => $playerProfile->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('player_profile_user', ['user_id' => $player->id, 'player_profile_id' => $organizerProfile->id, 'status' => 'accepted']);
    }

    public function test_friend_request_list_does_not_expose_requester_email(): void
    {
        [$organizer, $player, $playerProfile] = $this->organizerAndPlayer();
        $this->actingAs($organizer)->patch(route('profiles.friend', $playerProfile));

        $this->actingAs($player)->get(route('profiles.index'))
            ->assertOk()->assertSee('Solicitações de amizade')->assertDontSee($organizer->email);
    }

    /** @return array{0: User, 1: User, 2: \App\Models\PlayerProfile} */
    private function organizerAndPlayer(): array
    {
        $organizer = User::factory()->create(['name' => 'Organizador']);
        $organizer->ensurePlayerProfile();
        $player = User::factory()->create(['name' => 'Jogador']);
        $tournament = $this->tournament($organizer, 'Liga');
        $this->actingAs($player)->post(route('tournaments.invite.store', $tournament->invite_token));

        return [$organizer, $player, $player->playerProfile()->sole()];
    }

    private function tournament(User $user, string $name): Tournament
    {
        return $user->tournaments()->create([
            'name' => $name, 'format' => 'Pauper', 'tournament_date' => '2026-10-10',
            'public_token' => Str::random(40), 'public_slug' => Str::slug($name).'-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40),
        ]);
    }
}
