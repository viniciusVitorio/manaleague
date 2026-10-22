<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\GameMatch;
use App\Models\RankedMatch;
use App\Models\RankedSeason;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ChallengeService;
use App\Services\LifeCounterService;
use App\Services\Ranked\RankedMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LifeCounterTest extends TestCase
{
    use RefreshDatabase;

    private function config(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(1, preg_match('/data-config="([^"]+)"/', $response->getContent(), $m));

        return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{0:Tournament,1:GameMatch,2:User,3:User,4:User} */
    private function duel(array $tournament = [], string $stage = 'league'): array
    {
        $owner = User::factory()->create();
        $one = User::factory()->create();
        $two = User::factory()->create();
        $t = $owner->tournaments()->create(array_merge(['name' => 'Life Cup', 'format' => 'Pauper', 'tournament_date' => '2026-10-22', 'status' => 'league',
            'public_token' => Str::random(40), 'public_slug' => 'life-cup-'.Str::random(5), 'invite_token' => Str::random(40)], $tournament));
        $p1 = $t->players()->create(['name' => 'Alice', 'player_profile_id' => $one->ensurePlayerProfile()->id]);
        $p2 = $t->players()->create(['name' => 'Bruno', 'player_profile_id' => $two->ensurePlayerProfile()->id]);
        $m = $t->matches()->create(['round' => 1, 'stage' => $stage, 'status' => 'pending', 'player_one_id' => $p1->id, 'player_two_id' => $p2->id]);

        return [$t, $m, $owner, $one, $two];
    }

    public function test_starting_life_follows_the_format(): void
    {
        $s = app(LifeCounterService::class);
        $this->assertSame(20, $s->startingLife('Pauper'));
        $this->assertSame(20, $s->startingLife('Modern'));
        $this->assertSame(40, $s->startingLife('Commander'));
        $this->assertSame(40, $s->startingLife('EDH casual'));
        $this->assertSame(25, $s->startingLife('Brawl'));
        $this->assertSame(40, $s->startingLife('Modern', true));
    }

    public function test_tournament_players_get_a_counter_linked_to_their_match_and_submit_the_final_score(): void
    {
        [$t, $m, $owner, $one, $two] = $this->duel();
        $outsider = User::factory()->create();

        $cfg = $this->config($this->actingAs($two)->get(route('life.tournament', [$t, $m])));
        $this->assertSame('duel', $cfg['mode']);
        $this->assertSame(20, $cfg['startingLife']);
        $this->assertSame(2, $cfg['winsNeeded']);
        $this->assertSame(1, $cfg['meIndex']);
        $this->assertSame(['Alice', 'Bruno'], array_column($cfg['players'], 'name'));
        $this->assertSame('tournament', $cfg['submit']['kind']);
        $this->assertSame(route('matches.submissions.store', [$t, $m]), $cfg['submit']['url']);
        $this->assertSame(route('matches.mine'), $cfg['resultUrl']);
        $this->assertStringContainsString(':'.$two->id, $cfg['storageKey']);

        $this->actingAs($outsider)->get(route('life.tournament', [$t, $m]))->assertForbidden();

        // Exatamente o corpo que o contador envia ao fim da serie.
        $this->actingAs($two)->postJson($cfg['submit']['url'], ['player_one_games' => 1, 'player_two_games' => 2, 'idempotency_key' => $cfg['idempotencyKey']])->assertCreated();
        $this->assertSame('pending', $m->fresh()->status, 'O adversario ainda precisa confirmar.');

        // Ja existe placar enviado: o contador fecha e manda para o resultado.
        $this->actingAs($one)->get(route('life.tournament', [$t, $m]))->assertRedirect(route('matches.mine'));
        $this->actingAs($one)->get(route('matches.mine'))->assertOk()->assertDontSee(route('life.tournament', [$t, $m]), false);
    }

    public function test_my_matches_page_links_to_the_counter_while_the_match_is_open(): void
    {
        [$t, $m, , $one] = $this->duel();
        $this->actingAs($one)->get(route('matches.mine'))->assertOk()->assertSee(route('life.tournament', [$t, $m]), false)->assertSee('Abrir contador de vida');
    }

    public function test_organizer_counter_writes_the_result_directly_and_decisive_matches_reject_draws(): void
    {
        [$t, $m, $owner] = $this->duel(['system' => 'single_elimination'], 'league');
        $cfg = $this->config($this->actingAs($owner)->get(route('life.tournament', [$t, $m])));
        $this->assertSame('tournament_admin', $cfg['submit']['kind']);
        $this->assertSame('PUT', $cfg['submit']['method']);
        $this->assertSame(route('tournaments.show', $t), $cfg['resultUrl']);

        $this->actingAs($owner)->putJson($cfg['submit']['url'], ['player_one_games' => 1, 'player_two_games' => 1])->assertUnprocessable();
        $this->assertSame('pending', $m->fresh()->status);
        $this->actingAs($owner)->putJson($cfg['submit']['url'], ['player_one_games' => 2, 'player_two_games' => 0])->assertRedirect();
        $this->assertSame('finished', $m->fresh()->status);
        $this->actingAs($owner)->get(route('life.tournament', [$t, $m]))->assertRedirect(route('tournaments.show', $t));
    }

    public function test_commander_pod_counter_uses_40_life_and_only_the_organizer_posts_placements(): void
    {
        $owner = User::factory()->create();
        $users = User::factory()->count(4)->create();
        $t = $owner->tournaments()->create(['name' => 'Pod Night', 'format' => 'Commander', 'system' => 'commander_pods', 'tournament_date' => '2026-10-22', 'status' => 'league',
            'public_token' => Str::random(40), 'public_slug' => 'pod-night', 'invite_token' => Str::random(40)]);
        $ids = $users->map(fn ($u, $k) => $t->players()->create(['name' => 'Seat '.($k + 1), 'player_profile_id' => $u->ensurePlayerProfile()->id])->id)->all();
        $m = $t->matches()->create(['round' => 1, 'stage' => 'league', 'bracket' => 'pod', 'status' => 'pending', 'player_one_id' => $ids[0], 'pod_player_ids' => $ids]);

        $cfg = $this->config($this->actingAs($users[2])->get(route('life.tournament', [$t, $m])));
        $this->assertSame('pod', $cfg['mode']);
        $this->assertSame(40, $cfg['startingLife']);
        $this->assertTrue($cfg['commanderDamage']);
        $this->assertSame(2, $cfg['meIndex']);
        $this->assertNull($cfg['submit']);
        $this->assertCount(4, $cfg['players']);

        $admin = $this->config($this->actingAs($owner)->get(route('life.tournament', [$t, $m])));
        $this->assertSame('pod', $admin['submit']['kind']);
        $placements = array_combine($ids, [3, 1, 4, 2]);
        $this->actingAs($owner)->putJson($admin['submit']['url'], ['placements' => $placements])->assertRedirect();
        $this->assertSame('finished', $m->fresh()->status);
        $this->assertEquals($placements, $m->fresh()->pod_scores);
    }

    private function rankedMatch(bool $allowDraws = true, int $bestOf = 3): array
    {
        $season = RankedSeason::create(['name' => 'Temporada Teste', 'slug' => 'temporada-'.Str::random(5), 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'scope' => 'global', 'rules_version' => 'elo-v2']);
        $season->formats()->create(['format' => 'Pauper', 'k_factor' => 32, 'starting_rating' => 600, 'allow_draws' => $allowDraws]);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $match = app(RankedMatchService::class)->create($season->fresh('formats'), 'Pauper', $a->ensurePlayerProfile(), $b->ensurePlayerProfile(), 'challenge', random_int(1000, 9999), $bestOf);

        return [$match, $a, $b];
    }

    public function test_ranked_counter_submits_through_the_ranked_endpoint(): void
    {
        [$match, $a, $b] = $this->rankedMatch(false);
        $cfg = $this->config($this->actingAs($a)->get(route('life.ranked', $match)));
        $this->assertSame('ranked', $cfg['submit']['kind']);
        $this->assertSame(0, $cfg['meIndex']);
        $this->assertFalse($cfg['earlyEnd'], 'Sem empate no formato: nada de encerrar por tempo.');
        $this->actingAs(User::factory()->create())->get(route('life.ranked', $match))->assertForbidden();
        $this->actingAs($a)->get(route('ranked.matches.show', $match))->assertSee(route('life.ranked', $match), false);

        $this->actingAs($a)->postJson($cfg['submit']['url'], ['score_one' => 2, 'score_two' => 1, 'idempotency_key' => $cfg['idempotencyKey']])->assertCreated();
        $this->assertSame('reported', $match->fresh()->status);
        $this->actingAs($b)->get(route('life.ranked', $match))->assertRedirect(route('ranked.matches.show', $match));
    }

    private function friends(): array
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $a->friendProfiles()->attach($b->ensurePlayerProfile(), ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->ensurePlayerProfile(), ['status' => 'accepted']);

        return [$a, $b];
    }

    public function test_casual_challenge_counter_reports_score_and_game_order(): void
    {
        [$a, $b] = $this->friends();
        $s = app(ChallengeService::class);
        $c = $s->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay());
        $this->actingAs($a)->get(route('life.challenge', $c))->assertRedirect(route('challenges.show', $c));
        $s->transition($c, $b, 'accept');

        $cfg = $this->config($this->actingAs($b)->get(route('life.challenge', $c)));
        $this->assertSame('challenge', $cfg['submit']['kind']);
        $this->assertSame(1, $cfg['meIndex']);
        $this->assertSame(2, $cfg['winsNeeded']);
        $this->actingAs($b)->get(route('challenges.show', $c))->assertSee(route('life.challenge', $c), false);

        $this->actingAs($b)->postJson($cfg['submit']['url'], ['challenger_games' => 2, 'opponent_games' => 2, 'game_order' => '1,2,1,2'])->assertUnprocessable();
        $this->actingAs($b)->postJson($cfg['submit']['url'], ['challenger_games' => 1, 'opponent_games' => 2, 'game_order' => '2,1,2'])->assertRedirect(route('challenges.show', $c));
        $match = $c->casualMatch()->sole();
        $this->assertSame('reported', $match->status);
        $this->assertSame([2, 1, 2], $match->game_order);
        $this->actingAs($a)->get(route('life.challenge', $c))->assertRedirect(route('challenges.show', $c));
    }

    public function test_ranked_challenge_redirects_to_the_ranked_counter(): void
    {
        [$match, $a, $b] = $this->rankedMatch();
        $c = Challenge::create(['challenger_id' => $a->playerProfile->id, 'opponent_id' => $b->playerProfile->id, 'format' => 'Pauper', 'best_of' => 3, 'expires_at' => now()->addDay(), 'status' => 'accepted', 'is_ranked' => true]);
        $match->update(['source_id' => $c->id]);
        $this->actingAs($a)->get(route('life.challenge', $c))->assertRedirect(route('life.ranked', $match));
        $this->actingAs(User::factory()->create())->get(route('life.challenge', $c))->assertForbidden();
    }

    public function test_counter_page_ships_versioned_assets_and_escapes_player_names(): void
    {
        [$t, $m, , $one] = $this->duel();
        $m->playerOne->update(['name' => '"><script>alert(1)</script>']);
        $html = $this->actingAs($one)->get(route('life.tournament', [$t, $m]))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertMatchesRegularExpression('#js/life-counter\.js\?v=[a-f0-9]{16}#', $html);
        $this->assertMatchesRegularExpression('#css/life\.css\?v=[a-f0-9]{16}#', $html);
        $this->assertSame('"><script>alert(1)</script>', $this->config($this->actingAs($one)->get(route('life.tournament', [$t, $m])))['players'][0]['name']);
    }
}
