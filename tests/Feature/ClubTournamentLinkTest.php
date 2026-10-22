<?php

namespace Tests\Feature;

use App\Models\CommunityNotification;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Community\ClubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClubTournamentLinkTest extends TestCase
{
    use RefreshDatabase;

    private function tournament(User $owner, array $attrs = []): Tournament
    {
        return $owner->tournaments()->create($attrs + ['name' => 'Pauper de Sexta', 'format' => 'Pauper', 'tournament_date' => '2026-10-10', 'status' => 'setup',
            'public_token' => Str::random(40), 'public_slug' => 'pauper-'.Str::lower(Str::random(8)), 'invite_token' => Str::random(40)]);
    }

    public function test_manager_links_own_personal_tournament_and_it_counts_for_the_club(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $club = app(ClubService::class)->create($owner, ['name' => 'Eagle TJS', 'privacy' => 'private']);
        $club->members()->attach($member->id, ['role' => 'member']);
        $finished = $this->tournament($owner, ['name' => 'Copa Antiga', 'status' => 'finished']);

        $this->actingAs($owner)->get(route('clubs.show', $club))->assertOk()->assertSee('Vincular torneio existente')->assertSee('Copa Antiga');
        $this->actingAs($member)->get(route('tournaments.show', $finished))->assertForbidden();

        $this->actingAs($owner)->post(route('clubs.tournaments.link', $club), ['tournament_id' => $finished->id])
            ->assertRedirect(route('clubs.show', $club))->assertSessionHas('status');
        $this->assertSame($club->id, $finished->fresh()->club_id);
        $this->assertSame($owner->id, $finished->fresh()->user_id, 'O criador continua o mesmo.');

        // Membros passam a ver, sao avisados e o torneio aparece no clube.
        $this->actingAs($member)->get(route('tournaments.show', $finished))->assertOk()->assertSee('Eagle TJS');
        $this->assertTrue(CommunityNotification::where('user_id', $member->id)->where('type', 'club.tournament')->exists());
        $this->actingAs($member)->get(route('clubs.show', $club))->assertOk()->assertSee('Copa Antiga')->assertDontSee('Vincular torneio existente');
        $this->actingAs($owner)->get(route('clubs.show', $club))->assertDontSee('Vincular torneio existente');

        // Segunda tentativa: ja e do clube.
        $this->actingAs($owner)->post(route('clubs.tournaments.link', $club), ['tournament_id' => $finished->id])->assertStatus(409);
    }

    public function test_linked_finished_tournament_enters_the_club_ranking(): void
    {
        $owner = User::factory()->create();
        $club = app(ClubService::class)->create($owner, ['name' => 'Eagle TJS', 'privacy' => 'private']);
        $t = $this->tournament($owner, ['status' => 'finished']);
        $a = $t->players()->create(['name' => 'Alice']);
        $b = $t->players()->create(['name' => 'Bruno']);
        $t->matches()->create(['round' => 1, 'stage' => 'league', 'status' => 'finished', 'player_one_id' => $a->id, 'player_two_id' => $b->id, 'player_one_games' => 2, 'player_two_games' => 0]);
        $this->assertTrue(app(ClubService::class)->ranking($club)->isEmpty());

        $this->actingAs($owner)->post(route('clubs.tournaments.link', $club), ['tournament_id' => $t->id]);

        $top = app(ClubService::class)->ranking($club->fresh())->first();
        $this->assertSame(['Alice', 3], [$top['name'], $top['points']]);
        $this->actingAs($owner)->get(route('clubs.show', $club))->assertSee('Alice');
    }

    public function test_cannot_link_someone_elses_tournament_or_one_from_another_club(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $member = User::factory()->create();
        $club = app(ClubService::class)->create($owner, ['name' => 'Clube A', 'privacy' => 'private']);
        $other = app(ClubService::class)->create($owner, ['name' => 'Clube B', 'privacy' => 'private']);
        $club->members()->attach($member->id, ['role' => 'member']);

        $foreign = $this->tournament($stranger);
        $this->actingAs($owner)->post(route('clubs.tournaments.link', $club), ['tournament_id' => $foreign->id])->assertNotFound();
        $this->assertNull($foreign->fresh()->club_id);

        $mine = $this->tournament($member);
        $this->actingAs($member)->post(route('clubs.tournaments.link', $club), ['tournament_id' => $mine->id])->assertForbidden();

        $inB = $this->tournament($owner);
        $this->actingAs($owner)->post(route('clubs.tournaments.link', $other), ['tournament_id' => $inB->id])->assertRedirect();
        $this->actingAs($owner)->post(route('clubs.tournaments.link', $club), ['tournament_id' => $inB->id])->assertStatus(409);
        $this->assertSame($other->id, $inB->fresh()->club_id);
    }

    public function test_manager_unlinks_and_tournament_returns_to_creator(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $club = app(ClubService::class)->create($owner, ['name' => 'Eagle TJS', 'privacy' => 'private']);
        $club->members()->attach($member->id, ['role' => 'member']);
        $t = $this->tournament($owner);
        $this->actingAs($owner)->post(route('clubs.tournaments.link', $club), ['tournament_id' => $t->id]);

        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertSee('Desvincular do clube');
        $this->actingAs($member)->delete(route('clubs.tournaments.unlink', [$club, $t]))->assertForbidden();
        $this->actingAs($owner)->delete(route('clubs.tournaments.unlink', [$club, $t]))->assertRedirect(route('clubs.show', $club));

        $this->assertNull($t->fresh()->club_id);
        $this->actingAs($member)->get(route('tournaments.show', $t))->assertForbidden();
        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertOk()->assertDontSee('Desvincular do clube');
        $this->actingAs($owner)->get(route('clubs.show', $club))->assertSee('Vincular torneio existente');
    }
}
