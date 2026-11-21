<?php

namespace Tests\Feature;

use App\Models\MatchLifeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PodLifeHistoryTest extends TestCase
{
    use RefreshDatabase;

    public static function podSystems(): array
    {
        return [
            'elimination-four' => ['pod_elimination', 4],
            'elimination-three' => ['pod_elimination', 3],
            'legacy-four' => ['commander_pods', 4],
            'legacy-three' => ['commander_pods', 3],
        ];
    }

    #[DataProvider('podSystems')]
    public function test_counter_history_is_accessible_from_pod_cards_for_every_seat_but_not_strangers(string $system, int $count): void
    {
        $owner = User::factory()->create();
        $t = $owner->tournaments()->create(['name' => 'Commander history', 'format' => 'Commander', 'system' => $system,
            'tournament_date' => today(), 'status' => 'finals', 'round_limit' => 1,
            'public_token' => Str::random(40), 'public_slug' => 'history-'.Str::random(12), 'invite_token' => Str::random(40)]);
        $users = [];
        $players = [];
        foreach (range(1, $count) as $i) {
            $u = User::factory()->create(['name' => 'Seat '.$i]);
            $u->ensurePlayerProfile()->update(['nickname' => 'Seat '.$i]);
            $users[] = $u;
            $players[] = $t->players()->create(['name' => 'Seat '.$i, 'player_profile_id' => $u->playerProfile->id]);
        }
        $ids = array_map(fn ($p) => $p->id, $players);
        $m = $t->matches()->create(['round' => 1, 'stage' => 'final', 'pod_number' => 1,
            'player_one_id' => $ids[0], 'player_two_id' => $ids[1], 'pod_player_ids' => $ids, 'status' => 'pending']);
        $store = route('life.log.store', ['tournament', $m->id]);
        $chart = route('life.log.show', ['tournament', $m->id]);
        $points = array_map(fn ($i) => [30 + $i, $i, 35 - $i, $i === $count - 1 ? 2 : 0], range(0, $count - 1));
        $games = [['winner' => 0, 'points' => $points, 'out' => [[120, 1], [120, 2]]]];

        // The last seat (third/fourth) can record, including poison and simultaneous eliminations.
        $this->actingAs($users[$count - 1])->get(route('life.tournament', [$t, $m]))->assertOk()->assertSee(e(json_encode($store)), false);
        $this->postJson($store, ['games' => $games])->assertCreated();
        $log = MatchLifeLog::of('tournament', $m->id);
        $this->assertSame($ids, $log->seats);
        $this->assertSame(40, $log->starting_life);
        $this->assertSame($points, $log->games[0]['points']);
        $this->assertSame([[120, 1], [120, 2]], $log->games[0]['out']);
        $this->get($chart)->assertNotFound();
        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertOk()->assertDontSee('href="'.$chart.'"', false);

        $this->put(route('matches.update', [$t, $m]), ['placements' => array_combine($ids, range(1, $count)), 'confirm' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('finished', $m->fresh()->status);
        $this->get(route('tournaments.show', $t))->assertOk()->assertSee('href="'.$chart.'"', false)->assertSee('Gráfico da partida');
        foreach ($users as $user) {
            $this->actingAs($user)->get(route('arena.public', $t->public_slug))->assertOk()->assertSee('href="'.$chart.'"', false);
            $response = $this->get($chart)->assertOk()->assertSee('Ver lance a lance');
            foreach (range(1, $count) as $seat) {
                $response->assertSee('Seat '.$seat)->assertSee('lchart-line s'.$seat, false);
            }
            $this->get(route('matches.detail', ['tournament', $m->id]))->assertOk()->assertSee('Gráfico de vida')->assertSee('lchart-line s'.$count, false);
        }
        $this->actingAs(User::factory()->create())->get($chart)->assertNotFound();
        $this->get(route('arena.public', $t->public_slug))->assertOk()->assertDontSee('href="'.$chart.'"', false);
        $this->app['auth']->forgetGuards();
        $this->get(route('arena.public', $t->public_slug))->assertOk()->assertDontSee('href="'.$chart.'"', false);
        $this->assertDatabaseCount('match_life_logs', 1);
    }
}
