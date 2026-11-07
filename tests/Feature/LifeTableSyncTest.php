<?php

namespace Tests\Feature;

use App\Events\RealtimeMessage;
use App\Models\Challenge;
use App\Models\LifeOp;
use App\Models\LifeTable;
use App\Models\User;
use App\Services\ChallengeService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Contador de vida compartilhado: somente mesas vinculadas às partidas e suas jogadas em ordem. */
class LifeTableSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_the_counter_creates_one_shared_table_for_both_players(): void
    {
        [$a, $b, $challenge] = $this->acceptedChallenge();

        $cfgA = $this->config($this->actingAs($a)->get(route('life.challenge', $challenge)));
        $cfgB = $this->config($this->actingAs($b)->get(route('life.challenge', $challenge)));

        // Os dois celulares apontam para a mesma mesa (e o mesmo canal), cada um com o seu estado salvo.
        $this->assertSame($cfgA['sync']['table'], $cfgB['sync']['table']);
        $this->assertSame('private-life.'.$cfgA['sync']['table'], $cfgA['sync']['channel']);
        $this->assertSame(route('life.tables.ops', $cfgA['sync']['table']), $cfgA['sync']['opsUrl']);
        $this->assertNotSame($cfgA['storageKey'], $cfgB['storageKey']);
        $this->assertSame([$a->id, $b->id], array_column($cfgA['players'], 'user'));
        $this->assertSame(1, LifeTable::count());
    }

    public function test_moves_from_both_phones_are_ordered_and_each_phone_gets_what_it_missed(): void
    {
        [$a, $b, $challenge, $table] = $this->tableFor();

        // A tira 3 de B; B (que ainda não sabia) ganha 2 de vida: as duas entram, em ordem.
        $this->ops($a, $table, 0, [['id' => 'a-0000001', 'type' => 'life', 'seat' => 1, 'd' => -3]])
            ->assertOk()->assertJsonPath('seq', 1)->assertJsonPath('ops.0.op', ['type' => 'life', 'seat' => 1, 'd' => -3]);
        $response = $this->ops($b, $table, 0, [['id' => 'b-0000001', 'type' => 'life', 'seat' => 1, 'd' => 2]])->assertOk();

        // B recebe a jogada de A que perdeu + a dele, já com a ordem do servidor.
        $this->assertSame(2, $response->json('seq'));
        $this->assertSame(['a-0000001', 'b-0000001'], array_column($response->json('ops'), 'id'));
        $this->assertSame([$a->id, $b->id], array_column($response->json('ops'), 'by'));

        // Abrir de novo: só o que veio depois do que o aparelho já tem.
        $this->actingAs($a)->getJson(route('life.tables.sync', [$table, 'after' => 1]))->assertOk()
            ->assertJsonPath('seq', 2)->assertJsonCount(1, 'ops')->assertJsonPath('ops.0.id', 'b-0000001');
    }

    public function test_resending_after_a_dropped_connection_does_not_repeat_moves(): void
    {
        [$a, , , $table] = $this->tableFor();
        $batch = [['id' => 'same-0001', 'type' => 'life', 'seat' => 0, 'd' => -1], ['id' => 'same-0002', 'type' => 'poison', 'seat' => 1, 'd' => 1]];

        $this->ops($a, $table, 0, $batch)->assertOk()->assertJsonPath('seq', 2);
        // A resposta se perdeu; o celular manda o mesmo lote de novo.
        $this->ops($a, $table, 0, $batch)->assertOk()->assertJsonPath('seq', 2)->assertJsonCount(2, 'ops');
        $this->assertSame(2, LifeOp::count());
    }

    public function test_server_rejects_moves_outside_the_table_rules(): void
    {
        [$a, , , $table] = $this->tableFor();

        foreach ([
            ['id' => 'bad-00001', 'type' => 'life', 'seat' => 2, 'd' => -1],          // lugar que não existe
            ['id' => 'bad-00002', 'type' => 'life', 'seat' => 0, 'd' => 0],           // sem variação
            ['id' => 'bad-00003', 'type' => 'cmd', 'seat' => 0, 'from' => 0, 'd' => 1], // comandante de si mesmo
            ['id' => 'bad-00004', 'type' => 'life', 'seat' => '0', 'd' => '-1'],      // texto no lugar de número
            ['id' => 'bad-00005', 'type' => 'drop_table'],                              // tipo inventado
            ['id' => 'x', 'type' => 'life', 'seat' => 0, 'd' => 1],                    // sem identificador válido
            ['id' => 'bad-00006', 'type' => 'roll', 'rolls' => ['0' => 21]],           // d20 não vai a 21
        ] as $op) {
            $this->ops($a, $table, 0, [$op])->assertUnprocessable()->assertJsonValidationErrors('ops');
        }
        $this->ops($a, $table, 0, array_map(fn ($i) => ['id' => 'many-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'type' => 'life', 'seat' => 0, 'd' => 1], range(1, 51)))
            ->assertUnprocessable();
        $this->assertSame(0, LifeOp::count());

        // Campos extras não são gravados (só os do tipo).
        $this->ops($a, $table, 0, [['id' => 'ok-000001', 'type' => 'loss', 'seat' => 1, 'game' => 1, 'html' => '<b>x</b>']])->assertOk();
        $this->assertSame(['type' => 'loss', 'seat' => 1, 'game' => 1], LifeOp::sole()->op);
    }

    public function test_only_the_players_of_the_match_can_read_or_write_the_table(): void
    {
        [, , , $table] = $this->tableFor();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson(route('life.tables.sync', $table))->assertNotFound();
        $this->ops($stranger, $table, 0, [['id' => 'evil-0001', 'type' => 'life', 'seat' => 0, 'd' => -20]])->assertNotFound();
        $this->assertSame(0, LifeOp::count());
        auth()->logout();
        $this->getJson(route('life.tables.sync', $table))->assertUnauthorized();
    }

    public function test_moves_are_pushed_to_the_table_channel_after_the_response(): void
    {
        Event::fake([RealtimeMessage::class]);
        config(['broadcasting.default' => 'reverb']);
        app(BroadcastManager::class)->forgetDrivers();
        [$a, , , $table] = $this->tableFor();

        $this->ops($a, $table, 0, [['id' => 'push-0001', 'type' => 'life', 'seat' => 1, 'd' => -5]])->assertOk();

        Event::assertDispatched(RealtimeMessage::class, fn (RealtimeMessage $m) => $m->name === 'ops'
            && $m->channels === ['private-life.'.$table->id]
            && $m->broadcastWith()['ops'][0] === ['seq' => 1, 'id' => 'push-0001', 'by' => $a->id, 'op' => ['type' => 'life', 'seat' => 1, 'd' => -5]]);
    }

    public function test_standalone_counter_routes_are_not_available(): void
    {
        $this->actingAs($this->user('Ana'));
        foreach ([['GET', '/life'], ['POST', '/life'], ['POST', '/life/join'], ['GET', '/life/q/ABCD2345'], ['POST', '/life/q/ABCD2345/join']] as [$method, $url]) {
            $this->json($method, $url)->assertNotFound();
        }
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Contador de vida');
        $this->assertSame(0, LifeTable::count());

        // Mesas avulsas antigas podem permanecer no banco, mas nem o dono acessa mais.
        $user = auth()->user();
        $legacy = LifeTable::create([
            'kind' => 'quick', 'seats' => 2, 'owner_id' => $user->id,
            'seat_users' => [$user->id, null], 'last_activity_at' => now(),
        ]);
        $this->getJson(route('life.tables.sync', $legacy))->assertNotFound();
        $this->ops($user, $legacy, 0, [['id' => 'legacy-01', 'type' => 'life', 'seat' => 0, 'd' => -1]])->assertNotFound();
        $this->assertSame(0, LifeOp::count());
    }

    // ---------- helpers ----------

    private function config(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertSame(1, preg_match('/data-config="([^"]+)"/', $response->getContent(), $m));

        return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
    }

    private function ops(User $user, LifeTable $table, int $after, array $ops): TestResponse
    {
        return $this->actingAs($user)->postJson(route('life.tables.ops', $table), ['after' => $after, 'ops' => $ops]);
    }

    private function user(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->ensurePlayerProfile()->update(['nickname' => $name]);

        return $user->fresh();
    }

    /** @return array{0: User, 1: User, 2: Challenge} */
    private function acceptedChallenge(): array
    {
        $a = $this->user('Ana');
        $b = $this->user('Bia');
        $a->friendProfiles()->attach($b->playerProfile, ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->playerProfile, ['status' => 'accepted']);
        $service = app(ChallengeService::class);
        $challenge = $service->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay());
        $service->transition($challenge, $b, 'accept');

        return [$a, $b, $challenge];
    }

    /** @return array{0: User, 1: User, 2: Challenge, 3: LifeTable} */
    private function tableFor(): array
    {
        [$a, $b, $challenge] = $this->acceptedChallenge();
        $cfg = $this->config($this->actingAs($a)->get(route('life.challenge', $challenge)));

        return [$a, $b, $challenge, LifeTable::findOrFail($cfg['sync']['table'])];
    }
}
