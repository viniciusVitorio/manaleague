<?php

namespace Tests\Feature;

use App\Events\RealtimeMessage;
use App\Models\GameMatch;
use App\Models\LifeTable;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ChallengeService;
use App\Services\Community\NotificationService;
use App\Services\Realtime\RealtimeService;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Tempo real (Reverb): o que cada mudança avisa, quem pode ouvir cada canal e que o site segue sem o Reverb. */
class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_challenge_score_warns_the_challenge_and_both_players_once_per_request(): void
    {
        $this->enableRealtime();
        Event::fake([RealtimeMessage::class]);
        [$a, $b] = $this->friends();
        $service = app(ChallengeService::class);

        // Desafio aceito e placar enviado pela tela (uma requisição, vários saves).
        $challenge = $service->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay());
        $service->transition($challenge, $b, 'accept');
        app(RealtimeService::class)->flush();
        Event::fake([RealtimeMessage::class]);
        $this->actingAs($a)->post(route('challenges.report', $challenge), ['challenger_games' => 2, 'opponent_games' => 1, 'game_order' => '1,2,1'])
            ->assertRedirect(route('challenges.show', $challenge));

        $changed = $this->changedChannels();
        $this->assertContains('private-challenge.'.$challenge->id, $changed);
        $this->assertContains('private-user.'.$a->id, $changed);
        $this->assertContains('private-user.'.$b->id, $changed);
        // Um aviso "changed" por requisição, mesmo com vários registros gravados.
        $this->assertSame(1, Event::dispatched(RealtimeMessage::class, fn (RealtimeMessage $m) => $m->name === 'changed')->count());
        // O aviso não leva dado nenhum: a tela pergunta pelo endpoint que autoriza.
        Event::assertDispatched(RealtimeMessage::class, fn (RealtimeMessage $m) => $m->name !== 'changed' || $m->broadcastWith() === []);
    }

    public function test_a_tournament_score_warns_the_organizer_panel_the_public_arena_and_the_players(): void
    {
        $this->enableRealtime();
        [$t, $m, , $one, $two] = $this->duel();
        Event::fake([RealtimeMessage::class]);

        $this->actingAs($one)->postJson(route('matches.submissions.store', [$t, $m]), ['player_one_games' => 2, 'player_two_games' => 0, 'idempotency_key' => (string) Str::uuid()])->assertCreated();

        $changed = $this->changedChannels();
        $this->assertContains('private-tournament.'.$t->id, $changed);
        $this->assertContains('arena.'.$t->public_slug, $changed);
        $this->assertContains('private-user.'.$one->id, $changed);
        $this->assertContains('private-user.'.$two->id, $changed);
    }

    public function test_round_timer_warns_the_public_arena(): void
    {
        $this->enableRealtime();
        [$t] = $this->duel();
        Event::fake([RealtimeMessage::class]);

        $t->forceFill(['round_ends_at' => now()->addMinutes(50)])->save();
        app(RealtimeService::class)->flush();

        $this->assertContains('arena.'.$t->public_slug, $this->changedChannels());
    }

    public function test_new_notification_reaches_only_its_owner_with_title_and_internal_link(): void
    {
        $this->enableRealtime();
        Event::fake([RealtimeMessage::class]);
        $user = User::factory()->create();

        app(NotificationService::class)->send($user, 'challenge.received', 'Ajani desafiou você', '/challenges', 'test:1');
        app(RealtimeService::class)->flush();

        Event::assertDispatched(RealtimeMessage::class, fn (RealtimeMessage $m) => $m->name === 'notification'
            && $m->channels === ['private-user.'.$user->id]
            && $m->broadcastWith() === ['title' => 'Ajani desafiou você', 'url' => '/challenges']);
    }

    public function test_nothing_is_sent_when_realtime_is_off(): void
    {
        Event::fake([RealtimeMessage::class]);
        [$a, $b] = $this->friends();
        app(ChallengeService::class)->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay());
        app(RealtimeService::class)->flush();

        Event::assertNotDispatched(RealtimeMessage::class);
        // Sem Reverb, as páginas não carregam o cliente nem os canais (seguem no polling).
        $html = $this->actingAs($a)->get(route('challenges.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="realtime"', $html);
        $this->assertStringNotContainsString('js/realtime.js', $html);
    }

    public function test_site_keeps_working_when_reverb_is_down(): void
    {
        // Reverb ligado na configuração, mas ninguém escutando na porta: o aviso falha e é só registrado.
        $this->enableRealtime(fake: false);
        config(['broadcasting.connections.reverb.options.host' => '127.0.0.1', 'broadcasting.connections.reverb.options.port' => 9]);
        app(BroadcastManager::class)->forgetDrivers();
        Exceptions::fake();
        [$a, $b] = $this->friends();
        $challenge = app(ChallengeService::class)->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay());

        $this->actingAs($b)->post(route('challenges.transition', $challenge), ['action' => 'accept'])->assertRedirect();
        $this->assertSame('accepted', $challenge->fresh()->status);
        // A falha foi tentada de verdade e só registrada no log.
        Exceptions::assertReported(BroadcastException::class);
    }

    public function test_live_pages_declare_their_channels_and_the_csp_allows_the_websocket(): void
    {
        $this->enableRealtime();
        [$a] = $this->friends();

        $response = $this->actingAs($a)->get(route('challenges.index'))->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('name="realtime"', $html);
        $this->assertMatchesRegularExpression('#js/realtime\.js\?v=[a-f0-9]{16}#', $html);
        $this->assertStringContainsString('data-channels="private-user.'.$a->id.'"', $html);
        // Canal pessoal (sino) em qualquer página logada.
        $this->assertStringContainsString('id="live-user" hidden data-channel="private-user.'.$a->id.'"', $html);
        $this->assertStringContainsString('wss://localhost', (string) $response->headers->get('Content-Security-Policy'));
        // A chave do app é pública; o segredo nunca vai para a página.
        $this->assertStringNotContainsString((string) config('reverb.apps.apps.0.secret'), $html);
    }

    public function test_private_channels_are_authorized_like_the_pages(): void
    {
        $this->enableRealtime();
        [$t, $m, $owner, $one, $two] = $this->duel();
        $stranger = User::factory()->create();
        $table = LifeTable::create(['kind' => 'tournament', 'subject_id' => $m->id, 'seats' => 2]);

        // Canal pessoal: só o dono.
        $this->auth($one, 'private-user.'.$one->id)->assertOk()->assertJsonStructure(['auth']);
        $this->auth($two, 'private-user.'.$one->id)->assertForbidden();
        // Painel do torneio: organizador sim, jogador e estranho não (a tela também é só do organizador).
        $this->auth($owner, 'private-tournament.'.$t->id)->assertOk();
        $this->auth($stranger, 'private-tournament.'.$t->id)->assertForbidden();
        // Mesa do contador de vida: os dois jogadores e o organizador; estranho não.
        $this->auth($one, 'private-life.'.$table->id)->assertOk();
        $this->auth($two, 'private-life.'.$table->id)->assertOk();
        $this->auth($owner, 'private-life.'.$table->id)->assertOk();
        $this->auth($stranger, 'private-life.'.$table->id)->assertForbidden();
        // Sem login: nada.
        auth()->logout();
        $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-user.'.$one->id])->assertUnauthorized();
    }

    public function test_reverb_keys_are_derived_from_app_key_and_origins_are_locked_to_the_site(): void
    {
        $app = config('reverb.apps.apps.0');
        $this->assertNotEmpty($app['key']);
        $this->assertGreaterThanOrEqual(40, strlen($app['secret']));
        $this->assertNotSame($app['key'], $app['secret']);
        $this->assertNotContains('*', $app['allowed_origins']);
        $this->assertSame('none', $app['accept_client_events_from']);
        $this->assertSame($app['secret'], config('broadcasting.connections.reverb.secret'));
    }

    // ---------- helpers ----------

    /** Liga o Reverb na configuracao. $fake: as mensagens ficam no Event::fake (sem tentar conectar de verdade). */
    private function enableRealtime(bool $fake = true): void
    {
        if ($fake) {
            Event::fake([RealtimeMessage::class]);
        }
        config(['broadcasting.default' => 'reverb', 'reverb.client.enabled' => true]);
        // routes/channels.php registra as regras no driver atual: refaz com o Reverb ligado.
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');
    }

    private function auth(User $user, string $channel)
    {
        return $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    /** @return list<string> */
    private function changedChannels(): array
    {
        return Event::dispatched(RealtimeMessage::class, fn (RealtimeMessage $m) => $m->name === 'changed')
            ->flatMap(fn (array $call) => $call[0]->channels)->values()->all();
    }

    /** @return array{0: User, 1: User} */
    private function friends(): array
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $a->friendProfiles()->attach($b->ensurePlayerProfile(), ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->ensurePlayerProfile(), ['status' => 'accepted']);

        return [$a->fresh(), $b->fresh()];
    }

    /** @return array{0: Tournament, 1: GameMatch, 2: User, 3: User, 4: User} */
    private function duel(): array
    {
        $owner = User::factory()->create();
        $one = User::factory()->create();
        $two = User::factory()->create();
        $t = $owner->tournaments()->create(['name' => 'Live Cup', 'format' => 'Pauper', 'tournament_date' => '2026-11-04', 'status' => 'league',
            'public_token' => Str::random(40), 'public_slug' => 'live-cup-'.Str::lower(Str::random(5)), 'invite_token' => Str::random(40)]);
        $p1 = $t->players()->create(['name' => 'Alice', 'player_profile_id' => $one->ensurePlayerProfile()->id]);
        $p2 = $t->players()->create(['name' => 'Bruno', 'player_profile_id' => $two->ensurePlayerProfile()->id]);
        $m = $t->matches()->create(['round' => 1, 'stage' => 'league', 'status' => 'pending', 'player_one_id' => $p1->id, 'player_two_id' => $p2->id]);

        return [$t, $m, $owner, $one, $two];
    }
}
