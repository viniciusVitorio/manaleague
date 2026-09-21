<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Tournament;
use Illuminate\Support\Collection;

class StandingsService
{
    public function for(Tournament $tournament): Collection
    {
        $players = $tournament->players()->get();
        $table = [];

        foreach ($players as $player) {
            $table[$player->id] = [
                'player' => $player,
                'played' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'games_for' => 0,
                'games_against' => 0,
                'game_diff' => 0,
                'points' => 0,
            ];
        }

        $matches = $tournament->matches()
            ->where('stage', GameMatch::STAGE_LEAGUE)
            ->whereIn('status', [GameMatch::STATUS_FINISHED, GameMatch::STATUS_BYE])
            ->get();

        foreach ($matches as $match) {
            $one = $match->player_one_id;
            $two = $match->player_two_id;
            $oneGames = (int) $match->player_one_games;
            $twoGames = (int) $match->player_two_games;

            $table[$one]['played']++;
            $table[$one]['games_for'] += $oneGames;
            $table[$one]['games_against'] += $twoGames;

            if ($two === null) {
                $table[$one]['wins']++;
                $table[$one]['points'] += 3;
                continue;
            }

            $table[$two]['played']++;
            $table[$two]['games_for'] += $twoGames;
            $table[$two]['games_against'] += $oneGames;

            if ($oneGames > $twoGames) {
                $table[$one]['wins']++;
                $table[$one]['points'] += 3;
                $table[$two]['losses']++;
            } elseif ($twoGames > $oneGames) {
                $table[$two]['wins']++;
                $table[$two]['points'] += 3;
                $table[$one]['losses']++;
            } else {
                $table[$one]['draws']++;
                $table[$two]['draws']++;
                $table[$one]['points']++;
                $table[$two]['points']++;
            }
        }

        foreach ($table as &$row) {
            $row['game_diff'] = $row['games_for'] - $row['games_against'];
        }
        unset($row);

        return collect(array_values($table))
            ->sort(function (array $left, array $right): int {
                return ($right['points'] <=> $left['points'])
                    ?: ($right['game_diff'] <=> $left['game_diff'])
                    ?: ($right['games_for'] <=> $left['games_for'])
                    ?: strcmp($left['player']->name, $right['player']->name);
            })
            ->values()
            ->map(function (array $row, int $index): array {
                $row['rank'] = $index + 1;

                return $row;
            });
    }
}
