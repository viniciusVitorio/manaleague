<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PairingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_round_robin_generates_each_pair_exactly_once(): void
    {
        $tournament = $this->tournamentWithPlayers(4);

        app(PairingService::class)->generateLeague($tournament);

        $matches = $tournament->matches()->whereNotNull('player_two_id')->get();
        $pairs = $matches->map(function (GameMatch $match): string {
            $ids = [$match->player_one_id, $match->player_two_id];
            sort($ids);

            return implode('-', $ids);
        });

        $this->assertCount(6, $matches);
        $this->assertCount(6, $pairs->unique());
        $this->assertSame(3, $matches->max('round'));
        $this->assertSame(Tournament::STATUS_LEAGUE, $tournament->fresh()->status);
    }

    public function test_odd_player_count_generates_one_bye_per_round(): void
    {
        $tournament = $this->tournamentWithPlayers(5);

        app(PairingService::class)->generateLeague($tournament);

        $this->assertSame(10, $tournament->matches()->whereNotNull('player_two_id')->count());
        $this->assertSame(5, $tournament->matches()->where('status', GameMatch::STATUS_BYE)->count());
        $this->assertSame(5, $tournament->matches()->max('round'));
    }

    public function test_completed_league_generates_final_and_third_place_match(): void
    {
        $tournament = $this->tournamentWithPlayers(4);
        app(PairingService::class)->generateLeague($tournament);

        foreach ($tournament->matches as $match) {
            $match->update([
                'player_one_games' => 2,
                'player_two_games' => 0,
                'status' => GameMatch::STATUS_FINISHED,
                'played_at' => now(),
            ]);
        }

        app(PairingService::class)->generateFinals($tournament->fresh());

        $this->assertDatabaseHas('matches', ['tournament_id' => $tournament->id, 'stage' => GameMatch::STAGE_FINAL]);
        $this->assertDatabaseHas('matches', ['tournament_id' => $tournament->id, 'stage' => GameMatch::STAGE_THIRD_PLACE]);
        $this->assertSame(Tournament::STATUS_FINALS, $tournament->fresh()->status);
    }

    private function tournamentWithPlayers(int $count): Tournament
    {
        $user = User::factory()->create();
        $tournament = $user->tournaments()->create([
            'name' => 'Liga de teste',
            'format' => 'Pauper',
            'public_token' => Str::random(40),
        ]);

        foreach (range(1, $count) as $number) {
            $tournament->players()->create(['name' => "Jogador {$number}"]);
        }

        return $tournament;
    }
}
