<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrudManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_can_edit_choose_and_delete_owned_decks(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensurePlayerProfile();
        $first = $profile->decks()->create(['name' => 'Mono Red', 'colors' => 'R', 'is_primary' => true]);
        $second = $profile->decks()->create(['name' => 'Dimir', 'colors' => 'UB']);

        $this->actingAs($user)->get(route('decks.edit', $second))->assertOk()->assertSee('Dimir');
        $this->actingAs($user)->patch(route('decks.update', $second), [
            'name' => 'Dimir Faeries', 'colors' => 'UB',
        ])->assertRedirect();
        $this->assertDatabaseHas('decks', ['id' => $second->id, 'name' => 'Dimir Faeries']);

        $this->actingAs($user)->delete(route('decks.destroy', $first))->assertSessionHasErrors('deck');
        $this->actingAs($user)->patch(route('decks.primary', $second))->assertRedirect();
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame('Dimir Faeries', $profile->fresh()->preferred_deck_name);

        $this->actingAs($user)->delete(route('decks.destroy', $first))->assertRedirect();
        $this->assertDatabaseMissing('decks', ['id' => $first->id]);
    }

    public function test_user_cannot_change_another_players_deck(): void
    {
        $owner = User::factory()->create();
        $deck = $owner->ensurePlayerProfile()->decks()->create(['name' => 'Secret']);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->patch(route('decks.update', $deck), ['name' => 'Stolen'])->assertForbidden();
        $this->actingAs($intruder)->delete(route('decks.destroy', $deck))->assertForbidden();
    }

    public function test_organizer_can_edit_setup_tournament_and_regenerate_invite(): void
    {
        $user = User::factory()->create();
        $tournament = $this->tournament($user);
        $oldToken = $tournament->invite_token;

        $this->actingAs($user)->get(route('tournaments.edit', $tournament))->assertOk();
        $this->actingAs($user)->put(route('tournaments.update', $tournament), [
            'name' => 'Liga atualizada', 'format' => 'Modern',
            'tournament_date' => '2026-11-15', 'max_players' => 16,
        ])->assertRedirect(route('tournaments.show', $tournament));

        $this->assertDatabaseHas('tournaments', ['id' => $tournament->id, 'name' => 'Liga atualizada', 'max_players' => 16]);
        $this->actingAs($user)->post(route('tournaments.invite.regenerate', $tournament))->assertRedirect();
        $this->assertNotSame($oldToken, $tournament->fresh()->invite_token);
    }

    public function test_tournament_cannot_be_edited_after_start(): void
    {
        $user = User::factory()->create();
        $tournament = $this->tournament($user);
        $tournament->update(['status' => Tournament::STATUS_LEAGUE]);

        $this->actingAs($user)->get(route('tournaments.edit', $tournament))->assertStatus(409);
        $this->actingAs($user)->post(route('tournaments.invite.regenerate', $tournament))->assertStatus(409);
    }

    private function tournament(User $user): Tournament
    {
        return $user->tournaments()->create([
            'name' => 'Arena', 'format' => 'Pauper', 'tournament_date' => '2026-10-10',
            'public_token' => Str::random(40), 'public_slug' => 'arena-'.Str::lower(Str::random(8)),
            'invite_token' => Str::random(40),
        ]);
    }
}
