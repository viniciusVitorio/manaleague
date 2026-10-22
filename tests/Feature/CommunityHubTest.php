<?php

namespace Tests\Feature;

use App\Models\PlayerRating;
use App\Models\RankedSeason;
use App\Models\User;
use App\Services\Community\ActivityService;
use App\Services\Community\ClubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityHubTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        $u = User::factory()->create(['name' => $name]);
        $u->ensurePlayerProfile()->update(['nickname' => $name]);

        return $u->fresh();
    }

    public function test_feed_filters_friends_ranking_and_upcoming(): void
    {
        [$ana, $bia, $zeca] = [$this->user('Ana'), $this->user('Bia'), $this->user('Zeca')];
        $ana->friendProfiles()->attach($bia->playerProfile, ['status' => 'accepted']);
        $bia->friendProfiles()->attach($ana->playerProfile, ['status' => 'accepted']);
        $club = app(ClubService::class)->create($ana, ['name' => 'Liga', 'privacy' => 'public']);
        app(ActivityService::class)->record($ana, 'club.joined', 'Bia entrou no clube Liga', $club, 'k1');
        $this->actingAs($ana)->post('/clubs/'.$club->id.'/tournaments', ['name' => 'Copa da Liga', 'format' => 'Pauper', 'tournament_date' => now()->addDays(3)->toDateString()]);

        $season = RankedSeason::create(['name' => 'T', 'slug' => 't', 'status' => 'active', 'scope' => 'global', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'rules_version' => 'elo-v2']);
        foreach ([[$ana, 640], [$bia, 1210], [$zeca, 1500]] as [$u, $r]) {
            PlayerRating::create(['season_id' => $season->id, 'format' => 'Pauper', 'player_profile_id' => $u->playerProfile->id, 'rating' => $r, 'peak_rating' => $r, 'wins' => 1, 'losses' => 0, 'draws' => 0, 'played' => 1, 'provisional_games_remaining' => 4, 'version' => 1]);
        }

        $page = $this->actingAs($ana)->get('/feed')->assertOk()->assertSee('Comunidade')->assertSee('Bia entrou no clube Liga')
            ->assertSee('Ranking entre amigos')->assertSee('Mestre')->assertSee('1210')->assertDontSee('Zeca')->assertSee('Copa da Liga');
        $html = $page->getContent();
        $this->assertLessThan(strpos($html, '640'), strpos($html, '1210')); // ordenado por ELO
        $this->get('/feed?tipo=tournament')->assertOk()->assertDontSee('Bia entrou no clube Liga');
        $this->get('/feed?tipo=club')->assertSee('Bia entrou no clube Liga');
        $this->get('/feed?tipo=xss')->assertOk()->assertSee('Bia entrou no clube Liga'); // filtro inválido = tudo
        $this->actingAs($zeca)->get('/feed')->assertOk()->assertDontSee('Copa da Liga');
    }
}
