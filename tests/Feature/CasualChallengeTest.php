<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\User;
use App\Services\ChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CasualChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scores_require_opponent_confirmation_and_disputes_can_be_revised(): void
    {
        [$a, $b] = $this->friends(); $s = app(ChallengeService::class);
        $c = $s->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay());
        $s->transition($c, $b, 'accept');
        $s->report($c, $a, 2, 1, [2, 1, 1]);
        $this->assertSame('reported', $c->casualMatch()->sole()->status);
        try { $s->review($c, $a, 'confirm', 1); $this->fail('Self confirmation'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $s->review($c, $b, 'dispute', 1, 'Ordem incorreta');
        $this->assertSame('disputed', $c->casualMatch()->sole()->status);
        $s->report($c, $b, 2, 1, [1, 2, 1]);
        try { $s->review($c, $a, 'confirm', 1); $this->fail('Stale confirmation'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertSame('reported', $c->casualMatch()->sole()->status); }
        $s->review($c, $a, 'confirm', 2);
        $this->assertSame('confirmed', $c->casualMatch()->sole()->status);
        $this->assertNotNull($c->casualMatch()->sole()->confirmed_at);
        $this->assertSame('completed', $c->fresh()->status);
    }

    public function test_invalid_scores_and_impossible_game_order_are_rejected(): void
    {
        [$a, $b] = $this->friends(); $s = app(ChallengeService::class);
        $c = $s->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay()); $s->transition($c, $b, 'accept');
        foreach ([[2, 2, []], [1, 0, []], [-1, 2, []], [2, 1, [1, 1, 2]], [2, 1, [1, 2]], [2, 1, [1, 3, 1]]] as [$x, $y, $order]) {
            try { $s->report($c, $a, $x, $y, $order); $this->fail('Invalid result accepted'); }
            catch (\Illuminate\Validation\ValidationException $e) { $this->assertSame('pending', $c->casualMatch()->sole()->status); }
        }
    }

    public function test_expiry_decline_cancel_and_unauthorized_transitions(): void
    {
        [$a, $b] = $this->friends(); $s = app(ChallengeService::class);
        $expired = $s->create($a, $b->playerProfile, 'Pauper', 1, now()->addMinute());
        $this->travel(2)->minutes();
        $s->expire();
        $this->assertSame('expired', $expired->fresh()->status);
        foreach (['decline' => $b, 'cancel' => $a] as $action => $actor) {
            $c = $s->create($a, $b->playerProfile, 'Pauper', 1, now()->addDay());
            $s->transition($c, $actor, $action);
            $this->assertContains($c->fresh()->status, ['declined', 'cancelled']);
        }
        $c = $s->create($a, $b->playerProfile, 'Pauper', 1, now()->addDay());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $s->transition($c, $a, 'accept');
    }

    public function test_http_flow_rivalry_and_private_boundaries(): void
    {
        [$a, $b] = $this->friends();
        $this->actingAs($a)->get('/challenges')->assertOk()->assertSee('Desafios 1v1');
        $this->post('/challenges', ['opponent_id' => $b->playerProfile->id, 'format' => 'Pauper', 'best_of' => 3, 'expires_in' => 24])->assertRedirect();
        $c = Challenge::sole();
        $this->actingAs(User::factory()->create())->get('/challenges/'.$c->id)->assertForbidden();
        $this->actingAs($b)->post('/challenges/'.$c->id.'/transition', ['action' => 'accept'])->assertRedirect();
        $this->actingAs($a)->post('/challenges/'.$c->id.'/report', ['challenger_games' => 2, 'opponent_games' => 1, 'game_order' => '2,1,1'])->assertRedirect();
        $this->get('/challenges/'.$c->id)->assertOk()->assertSee('Aguardando confirmação');
        $this->actingAs($b)->post('/challenges/'.$c->id.'/review', ['action' => 'confirm', 'revision' => 1])->assertRedirect();
        $this->get('/challenges/rivalries')->assertOk()->assertSee('1 série')->assertSee('0 vitórias');
        $this->assertDatabaseCount('matches', 0);
    }

    public function test_share_is_opt_in_no_pii_and_revocable(): void
    {
        [$a, $b] = $this->friends();
        $p = $a->playerProfile;
        $p->achievements()->create(['achievement' => 'first_friend', 'unlocked_at' => now()]);
        $this->actingAs($a)->get('/achievements/first_friend/share')->assertOk()->assertSee('Baixar PNG');
        $this->post('/achievements/secret_comeback/share')->assertNotFound();
        $response = $this->post('/achievements/first_friend/share');
        $response->assertRedirect();
        $url = session('achievement_share_url');
        $this->assertNotEmpty($url);
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Parceiro de Mesa')->assertDontSee($a->email)->assertDontSee($p->nickname)->assertDontSee($p->friend_code);
        $this->actingAs($b)->delete('/achievements/first_friend/share')->assertRedirect();
        auth()->logout(); $this->get($url)->assertOk();
        $this->actingAs($a)->delete('/achievements/first_friend/share')->assertRedirect();
        auth()->logout(); $this->get($url)->assertNotFound();
    }

    private function friends(): array
    {
        $a = User::factory()->create(); $b = User::factory()->create();
        $a->friendProfiles()->attach($b->ensurePlayerProfile(), ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->ensurePlayerProfile(), ['status' => 'accepted']);
        return [$a, $b];
    }

    public function test_only_friends_can_create_and_recipient_accepts_once(): void
    {
        [$a, $b] = $this->friends();
        $service = app(ChallengeService::class);
        $challenge = $service->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay());
        $this->assertSame('pending', $challenge->status);
        $service->transition($challenge, $b, 'accept');
        $this->assertSame('accepted', $challenge->fresh()->status);
        $this->assertDatabaseCount('casual_matches', 1);
        try { $service->transition($challenge, $b, 'accept'); $this->fail('Repeated transition accepted'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertDatabaseCount('casual_matches', 1); }
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->create($a, User::factory()->create()->ensurePlayerProfile(), 'Pauper', 3, now()->addDay());
    }
}
