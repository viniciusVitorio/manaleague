<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\User;
use App\Services\ChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Telas ao vivo: a página guarda a versão com que foi montada e muda quando o outro lado age. */
class LiveRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_challenges_page_notices_when_the_friend_accepts_and_reports(): void
    {
        [$ana, $felipe] = $this->friends();
        $service = app(ChallengeService::class);
        $challenge = $service->create($ana, $felipe->playerProfile, 'Pauper', 3, now()->addDay());
        $scope = 'me';

        $page = $this->actingAs($ana)->get(route('challenges.index'))->assertOk()
            ->assertSee('id="live-refresh"', false)->assertSee('js/live-refresh.js', false);
        $rendered = $this->renderedVersion($page);
        $this->assertSame($rendered, $this->version($ana, $scope), 'Nada mudou: a versão é a mesma da página');

        $this->travel(1)->seconds();
        $service->transition($challenge, $felipe, 'accept');
        $accepted = $this->version($ana, $scope);
        $this->assertNotSame($rendered, $accepted, 'Felipe aceitou: a tela da Ana precisa se atualizar');

        $this->travel(1)->seconds();
        $service->report($challenge->fresh(), $felipe, 0, 2);
        $this->assertNotSame($accepted, $this->version($ana, $scope), 'Felipe mandou o placar');
    }

    public function test_dashboard_version_changes_when_an_opponent_sends_a_score(): void
    {
        [$ana, $felipe] = $this->friends();
        $service = app(ChallengeService::class);
        $challenge = $service->create($ana, $felipe->playerProfile, 'Pauper', 3, now()->addDay());
        $service->transition($challenge, $felipe, 'accept');

        $page = $this->actingAs($ana)->get(route('dashboard'))->assertOk()->assertSee('id="live-refresh"', false);
        $before = $this->renderedVersion($page);
        $this->travel(1)->seconds();
        $service->report($challenge->fresh(), $felipe, 2, 1);

        $this->assertNotSame($before, $this->version($ana, 'me'));
    }

    public function test_scopes_check_who_is_asking(): void
    {
        [$ana, $felipe] = $this->friends();
        $stranger = User::factory()->create();
        $challenge = app(ChallengeService::class)->create($ana, $felipe->playerProfile, 'Pauper', 3, now()->addDay());
        $tournament = $ana->tournaments()->create(['name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => now()->toDateString(), 'status' => Tournament::STATUS_LEAGUE,
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);

        $this->actingAs($stranger)->getJson(route('live.version', ['scope' => 'challenge:'.$challenge->id]))->assertForbidden();
        $this->actingAs($stranger)->getJson(route('live.version', ['scope' => 'tournament:'.$tournament->id]))->assertForbidden();
        $this->actingAs($ana)->getJson(route('live.version', ['scope' => 'tournament:'.$tournament->id]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($ana)->getJson(route('live.version', ['scope' => 'nada:1']))->assertNotFound();
        $this->actingAs($ana)->getJson(route('live.version', ['scope' => 'me,me,me,me,arena:x,challenge:1']))->assertNotFound();
        $this->actingAs($ana)->getJson(route('live.version', ['scope' => '']))->assertNotFound();

        auth()->logout();
        $this->getJson(route('live.version', ['scope' => 'me']))->assertUnauthorized();
        // A página pública do torneio é aberta: quem tem o link acompanha sem conta.
        $this->getJson(route('live.version', ['scope' => 'arena:'.$tournament->public_slug]))->assertOk()->assertJsonStructure(['v']);
        $this->get(route('arena.public', $tournament->public_slug))->assertOk()->assertSee('id="live-refresh"', false);
    }

    public function test_pages_that_are_not_live_do_not_poll(): void
    {
        [$ana] = $this->friends();
        $this->actingAs($ana)->get(route('profiles.index'))->assertOk()->assertDontSee('id="live-refresh"', false);
    }

    private function version(User $user, string $scope): string
    {
        return $this->actingAs($user)->getJson(route('live.version', ['scope' => $scope]))->assertOk()->json('v');
    }

    private function renderedVersion(TestResponse $page): string
    {
        $this->assertMatchesRegularExpression('/id="live-refresh"[^>]*data-version="([0-9a-f]{16})"/', $page->getContent());
        preg_match('/id="live-refresh"[^>]*data-version="([0-9a-f]{16})"/', $page->getContent(), $match);

        return $match[1];
    }

    /** @return array{0: User, 1: User} */
    private function friends(): array
    {
        $a = User::factory()->create(['name' => 'Ana']);
        $b = User::factory()->create(['name' => 'Felipe']);
        $a->friendProfiles()->attach($b->ensurePlayerProfile(), ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->ensurePlayerProfile(), ['status' => 'accepted']);

        return [$a->fresh(), $b->fresh()];
    }
}
