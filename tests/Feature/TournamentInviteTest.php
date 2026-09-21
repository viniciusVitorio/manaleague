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

    public function test_guest_can_join_setup_tournament_using_invite_token(): void
    {
        $tournament = $this->tournament();
        $this->get(route("tournaments.invite.show", $tournament->invite_token))->assertOk()->assertSee($tournament->name);
        $this->post(route("tournaments.invite.store", $tournament->invite_token), [
            "name" => "Convidado", "deck_name" => "Mono Red", "deck_colors" => "R",
        ])->assertRedirect();
        $this->assertDatabaseHas("players", ["tournament_id" => $tournament->id, "name" => "Convidado"]);
    }

    public function test_invalid_invite_token_is_rejected(): void
    {
        $this->get(route("tournaments.invite.show", Str::random(40)))->assertNotFound();
    }

    public function test_invite_cannot_add_players_after_tournament_starts(): void
    {
        $tournament = $this->tournament();
        $tournament->update(["status" => Tournament::STATUS_LEAGUE]);
        $this->post(route("tournaments.invite.store", $tournament->invite_token), ["name" => "Atrasado"])->assertStatus(409);
        $this->assertDatabaseMissing("players", ["name" => "Atrasado"]);
    }

    private function tournament(): Tournament
    {
        return User::factory()->create()->tournaments()->create([
            "name" => "Liga de sexta", "format" => "Pauper", "tournament_date" => "2026-10-10",
            "public_token" => Str::random(40), "public_slug" => "liga-de-sexta-abc12345", "invite_token" => Str::random(40),
        ]);
    }
}
