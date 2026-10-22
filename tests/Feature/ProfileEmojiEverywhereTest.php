<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Community\ClubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** O emoji escolhido no perfil acompanha a pessoa pelo site (avatares e nomes). */
class ProfileEmojiEverywhereTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_emoji_replaces_initials_and_prefixes_names(): void
    {
        $owner = User::factory()->create(['name' => 'Vinicius']);
        $owner->ensurePlayerProfile()->update(['avatar' => '🐉']);
        $pablo = User::factory()->create(['name' => 'Pablo']);
        $pablo->ensurePlayerProfile()->update(['avatar' => '🧙']);
        $noEmoji = User::factory()->create(['name' => 'Zeca']);

        $club = app(ClubService::class)->create($owner, ['name' => 'Eagle TJS', 'privacy' => 'private']);
        $club->members()->attach($pablo->id, ['role' => 'member']);
        $club->members()->attach($noEmoji->id, ['role' => 'member']);

        $page = $this->actingAs($owner)->get(route('clubs.show', $club))->assertOk();
        $page->assertSee('<span class="ui-avatar" aria-hidden="true">🧙</span>', false)
            ->assertSee('<span class="ui-avatar" aria-hidden="true">Z</span>', false) // sem emoji: inicial
            ->assertSee('<span class="ui-avatar">🐉</span>', false); // cabecalho do site

        $t = $owner->tournaments()->create(['name' => 'Copa', 'format' => 'Pauper', 'tournament_date' => '2026-10-22', 'status' => 'league', 'club_id' => null,
            'public_token' => Str::random(40), 'public_slug' => 'copa-emoji', 'invite_token' => Str::random(40)]);
        $p1 = $t->players()->create(['name' => 'Pablo', 'player_profile_id' => $pablo->playerProfile->id]);
        $p2 = $t->players()->create(['name' => 'Zeca', 'player_profile_id' => $noEmoji->ensurePlayerProfile()->id]);
        $t->matches()->create(['round' => 1, 'stage' => 'league', 'status' => 'pending', 'player_one_id' => $p1->id, 'player_two_id' => $p2->id]);

        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertOk()->assertSee('🧙 Pablo');
        $this->actingAs($pablo)->get(route('matches.mine'))->assertOk()->assertSee('🧙 Pablo × Zeca');
        $this->getJson('/t/copa-emoji/live')->assertOk()->assertJsonPath('matches.0.one', '🧙 Pablo')->assertJsonPath('matches.0.two', 'Zeca');
    }
}
