<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\User;
use App\Services\StandingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StandingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranking_uses_points_and_game_difference(): void
    {
        $user = User::factory()->create();
        $tournament = $user->tournaments()->create([
            'name' => 'Ranking',
            'format' => 'Pauper',
            'public_token' => Str::random(40),
        ]);
        $ana = $tournament->players()->create(['name' => 'Ana']);
        $bia = $tournament->players()->create(['name' => 'Bia']);
        $caio = $tournament->players()->create(['name' => 'Caio']);

        $this->finishedMatch($tournament, $ana->id, $bia->id, 2, 0);
        $this->finishedMatch($tournament, $bia->id, $caio->id, 2, 1);
        $this->finishedMatch($tournament, $caio->id, $ana->id, 1, 1);

        $ranking = app(StandingsService::class)->for($tournament);

        $this->assertSame('Ana', $ranking[0]['player']->name);
        $this->assertSame(4, $ranking[0]['points']);
        $this->assertSame('Bia', $ranking[1]['player']->name);
        $this->assertSame(3, $ranking[1]['points']);
        $this->assertSame('Caio', $ranking[2]['player']->name);
    }

    private function finishedMatch($tournament, int $one, int $two, int $oneGames, int $twoGames): void
    {
        $tournament->matches()->create([
            'round' => 1,
            'stage' => GameMatch::STAGE_LEAGUE,
            'player_one_id' => $one,
            'player_two_id' => $two,
            'player_one_games' => $oneGames,
            'player_two_games' => $twoGames,
            'status' => GameMatch::STATUS_FINISHED,
            'played_at' => now(),
        ]);
    }
}
